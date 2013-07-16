<?php
/**
 * @author  richard.gooding
 */

namespace Redis;

class Sentinel
{
  const DEFAULT_PORT = 26379;
  const CONNECT_TIMEOUT = 5;

  protected $_hosts;
  protected $_currentHost;
  protected $_socket;
  protected $_connected;

  public function __construct($hosts)
  {
    $this->_hosts = [];
    $this->_socket = null;
    $this->_currentHost = null;
    $this->_connected = false;

    if(!is_array($hosts))
    {
      $hosts = [$hosts];
    }

    foreach($hosts as $host)
    {
      if(strpos($host, ':') !== false)
      {
        list($host, $port) = explode(":", $host);
      }
      else
      {
        $port = static::DEFAULT_PORT;
      }
      $this->_hosts[] = ['host' => $host, 'port' => $port];
    }
  }

  public function __destruct()
  {
    $this->_close();
  }

  public function masters()
  {
    return $this->_executeCommand('SENTINEL masters');
  }

  public function slaves($masterName)
  {
    return $this->_executeCommand('SENTINEL slaves ' . $masterName);
  }

  protected function _connect()
  {
    // Connect to a random host
    while(! $this->_connected)
    {
      $hostIdx = rand(0, count($this->_hosts) - 1);
      $host = $this->_hosts[$hostIdx];

      $sock = fsockopen(
        $host['host'], $host['port'], $errno, $errstr, static::CONNECT_TIMEOUT
      );

      if($sock)
      {
        $this->_socket = $sock;
        $this->_connected = true;
      }
      else
      {
        unset($this->_hosts[$hostIdx]);
        if(count($this->_hosts) == 0)
        {
          throw new SentinelException(
            'Error connecting to Redis Sentinel: Could not connect to any hosts'
          );
        }
      }
    }
  }

  protected function _executeCommand($command, $decodeResponse = true)
  {
    $this->_connect();
    $this->_send($command);
    $this->_send("QUIT");
    $response = $this->_receive();
    $this->_close();
    return $decodeResponse ? $this->_decodeResponse($response) : $response;
  }

  protected function _close()
  {
    if($this->_connected)
    {
      fclose($this->_socket);
      $this->_socket = null;
      $this->_connected = false;
    }
  }

  protected function _send($message)
  {
    if(! $this->_connected)
    {
      throw new SentinelException('Not connected');
    }
    fputs($this->_socket, trim($message) . "\r\n");
  }

  protected function _receive()
  {
    if(! $this->_connected)
    {
      throw new SentinelException('Not connected');
    }

    $data = "";
    while(! feof($this->_socket))
    {
      $data .= fgets($this->_socket);
    }
    return $data;
  }

  protected function _decodeResponse($responseStr)
  {
    $responseStr = str_replace(array("\r\n", "\r"), "\n", $responseStr);
    $lines = explode("\n", $responseStr);

    $numLines = count($lines);
    $current = [];
    $response = [];
    $i = 0;
    while($i < $numLines)
    {
      $line = trim($lines[$i]);
      if(($line != "") && ($line != "+OK"))
      {
        if($line[0] == '*')
        {
          if($current)
          {
            $response[] = $current;
          }
          $current = [];
        }
        else if($line[0] == '$')
        {
          $i++; // Skip key length
          $key = $lines[$i];
          $i++; // Skip value length
          $i++;
          $value = $lines[$i];
          $current[$key] = $value;
        }
      }
      $i++;
    }
    if($current)
    {
      $response[] = $current;
    }
    return $response;
  }
}
