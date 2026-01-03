<?php

declare(strict_types=1);

namespace Drupal\hux_auto_test\Hooks;

use Drupal\hux\Attribute\Hook;
use Drupal\hux_test\HuxTestCallTracker;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * A hooks class with autoconfiguration.
 */
final class HuxAutoconfigure implements LoggerAwareInterface {

  use LoggerAwareTrait;

  /**
   * Implements hook_test_hook().
   */
  #[Hook('test_hook')]
  public function testAutoconfigure(string $something): void {
    assert($this->logger !== NULL);
    $this->logger->info('Log!');
    // Pass along the class to ensure this is not NULL.
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something, $this->logger::class]);
  }

}
