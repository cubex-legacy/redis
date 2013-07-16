<?php
/**
 * @author  richard.gooding
 */

namespace Redis\Facade;

use Cubex\Facade\BaseFacade;

class Redis extends BaseFacade
{
  /**
   * @param string $name
   *
   * @return \Redis\RedisService
   */
  public static function getAccessor($name = 'redis')
  {
    return static::getServiceManager()->get($name);
  }
}
