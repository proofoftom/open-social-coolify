<?php

declare(strict_types=1);

namespace Drupal\Tests\hux\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\hux_auto_test\Hooks\HuxAutoconfigure;
use Drupal\hux_auto_test\Hooks\HuxAutoContainerInjection;
use Drupal\hux_auto_test\Hooks\HuxAutoMultiple;
use Drupal\hux_auto_test\Hooks\HuxAutoSingle;
use Drupal\hux_auto_test\Hooks\HuxAutowireContainerInjection;
use Drupal\hux_auto_test\Hooks\Sub\HuxAutoSubHooks;
use Drupal\hux_auto_test\TimeMachine;
use Drupal\hux_test\HuxTestCallTracker;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests automatic discovery of classes in Hooks directories.
 *
 * @group hux
 * @coversDefaultClass \Drupal\hux\HuxCompilerPass
 */
final class HuxAutoTest extends KernelTestBase {

  private string $time = '123';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'hux',
    'hux_auto_test',
  ];

  /**
   * Tests classes with hooks are discovered.
   *
   * @covers ::process
   * @covers ::getHuxClasses
   */
  public function testDiscovery(): void {
    $this->moduleHandler()->invokeAll('test_hook', ['bar']);
    $this->assertEqualsCanonicalizing([
      // 'HuxAutoEmpty' must not be present.
      [
        HuxAutoSingle::class,
        'testHook',
        'bar',
      ],
      [
        HuxAutoMultiple::class,
        'testHook1',
        'bar',
      ],
      [
        HuxAutoMultiple::class,
        'testHook2',
        'bar',
      ],
      [
        HuxAutoSubHooks::class,
        'testHook',
        'bar',
      ],
      [
        HuxAutoContainerInjection::class,
        'testHook',
        'bar',
        $this->time,
      ],
      [
        HuxAutoconfigure::class,
        'testAutoconfigure',
        'bar',
        LoggerChannel::class,
      ],
      [
        HuxAutowireContainerInjection::class,
        'testHook',
        'bar',
        $this->time,
      ],
    ], HuxTestCallTracker::$calls);
  }

  /**
   * The module handler.
   */
  private function moduleHandler(): ModuleHandlerInterface {
    return \Drupal::service('module_handler');
  }

  /**
   * {@inheritdoc}
   *
   * Register a database cache backend rather than memory-based.
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->getDefinition('datetime.time')
      ->setClass(TimeMachine::class)
      ->setArgument(0, '@' . $this->time);
  }

}
