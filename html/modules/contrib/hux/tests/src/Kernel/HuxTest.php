<?php

declare(strict_types=1);

namespace Drupal\Tests\hux\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\hux_test\HuxTestCallTracker;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests hooks.
 *
 * @group hux
 * @coversDefaultClass \Drupal\hux\HuxModuleHandler
 */
final class HuxTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'hux',
    'hux_test',
  ];

  /**
   * Tests hook is invoked.
   *
   * @covers ::invokeAll
   * @see \Drupal\hux_test\HuxTestHooks::testHook
   */
  public function testInvokeAllInvoked(): void {
    $this->moduleHandler()->invokeAll('test_hook', ['bar']);
    $this->assertEquals([
      [
        'Drupal\hux_test\HuxTestHooks',
        'testHook',
        'bar',
      ],
    ], HuxTestCallTracker::$calls);
  }

  /**
   * Tests hook return value.
   *
   * @covers ::invokeAll
   * @see \Drupal\hux_test\HuxTestHooks::testHookReturns
   */
  public function testInvokeAllReturn(): void {
    $result = $this->moduleHandler()->invokeAll('test_hook_returns');
    $this->assertEquals([
      [
        'Drupal\hux_test\HuxTestHooks',
        'testHookReturns',
      ],
    ], HuxTestCallTracker::$calls);
    $this->assertEquals([
      'testHookReturns return',
    ], $result);
  }

  /**
   * Tests single invoke defers to default implementation when no Hux hooks.
   *
   * @covers ::invoke
   * @see \hux_test_single_invoke()
   */
  public function testInvokeNoHux(): void {
    $result = $this->moduleHandler()->invoke('hux_test', 'single_invoke');
    $this->assertEquals([
      'hux_test_single_invoke',
    ], HuxTestCallTracker::$calls);
    $this->assertEquals('hux_test_single_invoke return', $result);
  }

  /**
   * Tests single invoke defers to default implementation with Hux hooks.
   *
   * @covers ::invoke
   * @see \hux_test_test_hook_single_invoke_return()
   * @see \Drupal\hux_test\HuxTestHooks::testHookSingleReturn1
   * @see \Drupal\hux_test\HuxTestHooks::testHookSingleReturn2
   */
  public function testInvokeWithHux(): void {
    $result = $this->moduleHandler()->invoke('hux_test', 'test_hook_single_invoke_return');
    $this->assertEquals([
      'hux_test_test_hook_single_invoke_return',
      [
        'Drupal\hux_test\HuxTestHooks',
        'testHookSingleReturn1',
      ],
      [
        'Drupal\hux_test\HuxTestHooks',
        'testHookSingleReturn2',
      ],
    ], HuxTestCallTracker::$calls);
    // Only the last invocation return value is returned.
    $this->assertEquals('testHookSingleReturn2 return', $result);
  }

  /**
   * Tests single invoke context is mutated and passed by reference.
   *
   * @covers ::invoke
   * @see \hux_test_test_hook_single_invoke_return()
   * @see \Drupal\hux_test\HuxTestHooks::testHookSingleReturn1
   * @see \Drupal\hux_test\HuxTestHooks::testHookSingleReturn2
   */
  public function testInvokeWithHuxMutatesByReference(): void {
    $something = 0;
    $this->moduleHandler()->invoke('hux_test', 'single_invoke_argument_reference', [&$something]);
    $this->assertEquals([
      [
        'hux_test_single_invoke_argument_reference',
        1,
      ],
      [
        'Drupal\hux_test\HuxTestHooks',
        'testInvokeWithHuxMutatesByReference',
        2,
      ],
    ], HuxTestCallTracker::$calls);
    // Only the last invocation return value is returned.
    $this->assertEquals(2, $something);
  }

  /**
   * @covers \Drupal\hux\HuxDiscovery::discovery
   * @see \Drupal\hux_test\HuxTestHooks::testHookMultiListener
   */
  public function testMultiListener(): void {
    $this->moduleHandler()->invokeAll('test_hook_multi_listener', ['bar1']);
    $this->moduleHandler()->invokeAll('test_hook_multi_listener2', ['bar2']);
    $this->assertEquals([
      [
        'Drupal\hux_test\HuxTestHooks',
        'testHookMultiListener',
        'bar1',
      ],
      [
        'Drupal\hux_test\HuxTestHooks',
        'testHookMultiListener',
        'bar2',
      ],
    ], HuxTestCallTracker::$calls);
    $this->moduleHandler()->invokeAll('test_hook', ['bar']);
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
