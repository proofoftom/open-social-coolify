<?php

declare(strict_types=1);

namespace Drupal\hux_auto_test\Hooks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\hux\Attribute\Hook;
use Drupal\hux_test\HuxTestCallTracker;

/**
 * A hooks class with autowire container injection.
 */
final class HuxAutowireContainerInjection {

  /**
   * Constructor.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   *   In recent versions of Drupal 10, most services are registered by their
   *   interface name, in addition to their original service id, allowing for
   *   autowire.
   */
  public function __construct(
    protected TimeInterface $time,
  ) {}

  /**
   * Implements hook_test_hook().
   */
  #[Hook('test_hook')]
  public function testHook(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something, $this->time->getRequestTime()]);
  }

}
