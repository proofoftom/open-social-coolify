<?php

declare(strict_types=1);

namespace Drupal\hux_auto_test\Hooks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\hux\Attribute\Hook;
use Drupal\hux_test\HuxTestCallTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A hooks class with container injection.
 */
final class HuxAutoContainerInjection implements ContainerInjectionInterface {

  /**
   * Creates a new HuxAutoContainerInjection.
   */
  public function __construct(
    protected TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('datetime.time'),
    );
  }

  /**
   * Implements hook_test_hook().
   */
  #[Hook('test_hook')]
  public function testHook(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something, $this->time->getRequestTime()]);
  }

}
