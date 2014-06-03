<?php
/**
 * @author  richard.gooding
 */

namespace Redis;

use Cubex\ServiceManager\IService;
use Cubex\ServiceManager\ServiceConfigTrait;
use Predis\Client;

/**
 * Redis service class
 * Configuration options:
 * hosts[]  List of hosts to connect to. Hosts can be specified by hostname or
 *          IP with an optional port.
 * mode     Either "standalone" or "sentinel".
 *          In "standalone" mode hosts[] is a list of Redis hosts to connect to.
 *          In "sentinel" mode hosts[] is a list of Sentinel hosts that will
 *          be used to find the master and slave Redis servers.
 *
 * @package Redis
 */
class RedisService implements IService
{
  use ServiceConfigTrait;

  const CONNECTION_MODE_SENTINEL = "sentinel";
  const CONNECTION_MODE_STANDALONE = "standalone";

  const DEFAULT_REDIS_PORT = 6379;
  const DEFAULT_SENTINEL_PORT = 26379;

  const SENTINEL_CACHE_FILE = '/dev/shm/cubex-redis-sentinel-cache';
  const USE_SENTINEL_CACHE = true;

  /**
   * @var Client
   */
  protected $_client = null;
  /**
   * @var Sentinel
   */
  protected $_sentinel = null;
  protected $_mode;

  /**
   * @return Client
   * @throws \Exception
   */
  public function client()
  {
    if($this->_client === null)
    {
      $hosts = $this->config()->getArr("hosts");
      $this->_mode = $this->config()->getStr("mode", "standalone");

      if($this->_mode == self::CONNECTION_MODE_STANDALONE)
      {
        $this->_client = new Client(
          $this->_cleanHosts($hosts, self::DEFAULT_REDIS_PORT)
        );
      }
      else if($this->_mode == self::CONNECTION_MODE_SENTINEL)
      {
        $this->_sentinel = new Sentinel(
          $this->_cleanHosts($hosts, self::DEFAULT_SENTINEL_PORT)
        );
        $this->_client = $this->_buildFailoverClient($this->_sentinel);
      }
      else
      {
        throw new \Exception(
          'Connection mode ' . $this->_mode . ' not supported'
        );
      }
    }

    return $this->_client;
  }

  public function sentinel()
  {
    return $this->_sentinel;
  }

  public function disconnect()
  {
    $this->_client->disconnect();
    $this->_client = null;
    $this->_sentinel = null;
  }

  private function _cleanHosts($hosts, $defaultPort)
  {
    $hostsArr = [];
    foreach($hosts as $host)
    {
      if(strpos($host, ':') !== false)
      {
        list($host, $port) = explode(":", $host);
      }
      else
      {
        $port = $defaultPort;
      }
      $hostsArr[] = ['host' => $host, 'port' => $port];
    }
    return $hostsArr;
  }

  protected static function _buildFailoverClient(
    Sentinel $sentinel, array $options = []
  )
  {
    if(self::USE_SENTINEL_CACHE
      && file_exists(self::SENTINEL_CACHE_FILE)
      && (filemtime(self::SENTINEL_CACHE_FILE) > (time() - 60))
    )
    {
      $hosts = unserialize(file_get_contents(self::SENTINEL_CACHE_FILE));
    }
    else
    {
      $masters = $sentinel->masters();
      if((count($masters) > 0) &&
        isset($masters[0]['name']) &&
        isset($masters[0]['ip']) &&
        isset($masters[0]['port'])
      )
      {
        $hosts  = [
          [
            'host'  => $masters[0]['ip'],
            'port'  => $masters[0]['port'],
            'alias' => 'master',
            'persistent' => true,
            //'async_connect' => true
          ]
        ];
        $slaves = $sentinel->slaves($masters[0]['name']);
        foreach($slaves as $slave)
        {
          $hosts[] = [
            'host' => $slave['ip'],
            'port' => $slave['port'],
            'persistent' => true,
            //'async_connect' => true
          ];
        }

        if(self::USE_SENTINEL_CACHE)
        {
          file_put_contents(self::SENTINEL_CACHE_FILE, serialize($hosts));
        }
      }
      else
      {
        throw new \Exception('No master servers found');
      }
    }

    $options = array_merge($options, ['replication' => true]);
    return new Client($hosts, $options);
  }
}
