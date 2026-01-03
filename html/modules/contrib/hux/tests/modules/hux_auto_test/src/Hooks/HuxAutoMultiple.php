<?php

declare(strict_types=1);

namespace Drupal\hux_auto_test\Hooks;

use Drupal\hux\Attribute\Hook;
use Drupal\hux_test\HuxTestCallTracker;

/**
 * A hooks class with multiple hooks.
 */
final class HuxAutoMultiple {

  /**
   * Implements hook_test_hook().
   */
  #[Hook('test_hook')]
  public function testHook1(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
  }

  /**
   * Implements hook_test_hook().
   */
  #[Hook('test_hook')]
  public function testHook2(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
  }

}
