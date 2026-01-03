<?php

declare(strict_types=1);

namespace Drupal\Tests\hux\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\hux_test\HuxTestCallTracker;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests alters.
 *
 * @group hux
 * @coversDefaultClass \Drupal\hux\HuxModuleHandler
 */
final class HuxAlterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'hux',
    'hux_test',
    'hux_alter_test',
  ];

  /**
   * Tests alter invocation.
   *
   * @covers ::alter
   * @see \Drupal\hux_alter_test\HuxAlterTestHooks::testAlter1
   * @see \hux_alter_test_fizz_alter
   */
  public function testAlterTypeIsString(): void {
    $data = __FUNCTION__;
    $context1 = 'context one';
    $context2 = 'context two';
    $this->moduleHandler()->alter('fizz', $data, $context1, $context2);
    $this->assertEquals([
      'hux_alter_test_fizz_alter',
      [
        'Drupal\hux_alter_test\HuxAlterTestHooks',
        'testAlter1',
        'hux_alter_test_fizz_alter hit',
        'context one',
        'context two',
      ],
    ], HuxTestCallTracker::$calls);

    $this->assertEquals('testAlter1 hit', $data);
    $this->assertEquals('context one', $context1);
    $this->assertEquals('context two', $context2);
  }

  /**
   * Tests alter invocation.
   *
   * @covers ::alter
   * @see \Drupal\hux_alter_test\HuxAlterTestHooks::testAlter1
   * @see \Drupal\hux_alter_test\HuxAlterTestHooks::testAlter2
   * @see \hux_alter_test_fizz_alter
   * @see \hux_alter_test_buzz_alter
   */
  public function testAlterTypeIsArray(): void {
    $data = __FUNCTION__;
    $context1 = 'context one';
    $context2 = 'context two';
    $this->moduleHandler()->alter([
      'fizz',
      'buzz',
    ], $data, $context1, $context2);
    $this->assertEquals([
      'hux_alter_test_fizz_alter',
      'hux_alter_test_buzz_alter',
      [
        'Drupal\hux_alter_test\HuxAlterTestHooks',
        'testAlter1',
        'hux_alter_test_buzz_alter hit',
        'context one',
        'context two',
      ],
      [
        'Drupal\hux_alter_test\HuxAlterTestHooks',
        'testAlter2',
        'testAlter1 hit',
        'context one',
        'context two',
      ],
    ], HuxTestCallTracker::$calls);

    $this->assertEquals('testAlter2 hit', $data);
    $this->assertEquals('context one', $context1);
    $this->assertEquals('context two', $context2);
  }

  /**
   * @covers \Drupal\hux\HuxDiscovery::discovery
   * @see \Drupal\hux_alter_test\HuxAlterTestHooks::testAlterMultiListener
   */
  public function testMultiListener(): void {
    $data = __FUNCTION__;
    // Treat as a counter.
    $context1 = 0;
    $context2 = 'context two';
    $this->moduleHandler()->alter([
      'multi_listener',
      'multi_listener2',
    ], $data, $context1, $context2);

    $this->assertEquals([
      [
        'Drupal\hux_alter_test\HuxAlterTestHooks',
        'testAlterMultiListener',
        'testMultiListener',
        1,
        'context two',
      ],
      [
        'Drupal\hux_alter_test\HuxAlterTestHooks',
        'testAlterMultiListener',
        'testMultiListener',
        2,
        'context two',
      ],
    ], HuxTestCallTracker::$calls);
  }

  /**
   * The module installer.
   */
  private function moduleInstaller(): ModuleInstallerInterface {
    return \Drupal::service('module_installer');
  }

  /**
   * The module handler.
   */
  private function moduleHandler(): ModuleHandlerInterface {
    return \Drupal::service('module_handler');
  }

}
