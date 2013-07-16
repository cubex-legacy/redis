<?php
/**
 * @author  richard.gooding
 */

namespace Redis;

use Predis\Client;

class ClientBuilder
{
  public static function buildFailoverClient(
    Sentinel $sentinel, array $options = []
  )
  {
    $masters = $sentinel->masters();
    if((count($masters) > 0) &&
      isset($masters[0]['name']) &&
      isset($masters[0]['ip']) &&
      isset($masters[0]['port']))
    {
      $hosts = [
        [
          'host' => $masters[0]['ip'],
          'port' => $masters[0]['port'],
          'alias' => 'master'
        ]
      ];
      $slaves = $sentinel->slaves($masters[0]['name']);
      foreach($slaves as $slave)
      {
        $hosts[] = ['host' => $slave['ip'], 'port' => $slave['port']];
      }

      $options = array_merge($options, ['replication' => true]);
      return new Client($hosts, $options);
    }
    else
    {
      throw new \Exception('No master servers found');
    }
  }
}
