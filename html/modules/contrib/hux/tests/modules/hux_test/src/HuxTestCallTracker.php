<?php

declare(strict_types=1);

namespace Drupal\hux_test;

/**
 * Tracks arbitrary data, usually invocations of methods.
 */
final class HuxTestCallTracker {

  /**
   * @var mixed[]
   */
  public static $calls;

  /**
   * Statically record arbitrary data.
   *
   * @param mixed $data
   *   Any data.
   */
  public static function record(mixed $data): void {
    static::$calls[] = $data;
  }

}
