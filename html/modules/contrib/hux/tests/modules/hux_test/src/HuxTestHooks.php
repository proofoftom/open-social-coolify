<?php

declare(strict_types=1);

namespace Drupal\hux_test;

use Drupal\hux\Attribute\Hook;

/**
 * Test hooks.
 */
final class HuxTestHooks {

  /**
   * Implements hook_test_hook().
   *
   * Tests a hook without any side effects.
   */
  #[Hook('test_hook')]
  public function testHook(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
  }

  /**
   * Implements hook_test_hook_returns().
   *
   * Tests a hook with output side effects.
   */
  #[Hook('test_hook_returns')]
  public function testHookReturns(): string {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__]);
    return __FUNCTION__ . ' return';
  }

  /**
   * Implements test_hook_single_invoke_return().
   *
   * Tests the return value of using ::invoke with multiple implementations.
   */
  #[Hook('test_hook_single_invoke_return')]
  public function testHookSingleReturn1(): string {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__]);
    return __FUNCTION__ . ' return';
  }

  /**
   * Implements test_hook_single_invoke_return().
   *
   * Tests the return value of using ::invoke with multiple implementations.
   */
  #[Hook('test_hook_single_invoke_return')]
  public function testHookSingleReturn2(): string {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__]);
    return __FUNCTION__ . ' return';
  }

  /**
   * Implements hook_single_invoke_argument_reference().
   *
   * Tests arguments are passed by reference.
   */
  #[Hook('single_invoke_argument_reference')]
  public function testInvokeWithHuxMutatesByReference(int &$something): void {
    $something++;
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
  }

  /**
   * Implements multiple hooks.
   *
   * Implements test_hook_multi_listener().
   * Implements test_hook_multi_listener2().
   *
   * Tests a hook listening for multiple hooks.
   */
  #[
    Hook('test_hook_multi_listener'),
    Hook('test_hook_multi_listener2'),
  ]
  public function testHookMultiListener(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
  }

}
