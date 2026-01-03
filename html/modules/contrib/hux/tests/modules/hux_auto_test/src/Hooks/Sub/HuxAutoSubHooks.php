<?php

declare(strict_types=1);

namespace Drupal\hux_auto_test\Hooks\Sub;

use Drupal\hux\Attribute\Hook;
use Drupal\hux_test\HuxTestCallTracker;

/**
 * Hooks in a subdirectory.
 */
final class HuxAutoSubHooks {

  /**
   * Implements hook_test_hook().
   */
  #[Hook('test_hook')]
  public function testHook(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
  }

}
