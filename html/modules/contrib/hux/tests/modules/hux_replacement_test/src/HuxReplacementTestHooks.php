<?php

declare(strict_types=1);

namespace Drupal\hux_replacement_test;

use Drupal\hux\Attribute\OriginalInvoker;
use Drupal\hux\Attribute\ReplaceOriginalHook;
use Drupal\hux_test\HuxTestCallTracker;

/**
 * Replacement test hooks.
 */
final class HuxReplacementTestHooks {

  /**
   * Replaces hux_test_foo().
   *
   * @see hux_test_foo()
   */
  #[ReplaceOriginalHook('foo', moduleName: 'hux_test')]
  public function myReplacement(string $something): mixed {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
    return __FUNCTION__ . ' return';
  }

  /**
   * Replaces hux_test_foo2().
   *
   * @see hux_test_foo2()
   */
  #[ReplaceOriginalHook('foo2', moduleName: 'hux_test', originalInvoker: TRUE)]
  public function myReplacementWithOriginal(callable $originalInvoker, string $something): mixed {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
    $originalInvoker($something);
    return __FUNCTION__ . ' return';
  }

  /**
   * Replaces one function and two Hux hooks.
   *
   * @see \hux_test_test_hook_single_invoke_return()
   * @see \Drupal\hux_test\HuxTestHooks::testHookSingleReturn1
   * @see \Drupal\hux_test\HuxTestHooks::testHookSingleReturn2
   */
  #[ReplaceOriginalHook('test_hook_single_invoke_return', moduleName: 'hux_test', originalInvoker: TRUE)]
  public function myReplacementWithOriginalMultipleImplementations(callable $originalInvoker, string $something): mixed {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
    return __FUNCTION__ . ' passed down ' . $originalInvoker($something);
  }

  /**
   * Replaces multiple hooks.
   *
   * Replaces hook_original_invoker_attribute().
   */
  #[ReplaceOriginalHook('original_invoker_attribute_first', moduleName: 'hux_test')]
  public function originalInvokerAttributeFirst(#[OriginalInvoker] callable $originalInvoker, string $something1, string $something2): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something1, $something2]);
    $originalInvoker($something1, $something2);
  }

  /**
   * Replaces hook_original_invoker_attribute().
   */
  #[ReplaceOriginalHook('original_invoker_attribute_middle', moduleName: 'hux_test')]
  public function originalInvokerAttributeMiddle(string $something1, #[OriginalInvoker] callable $originalInvoker, string $something2): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something1, $something2]);
    $originalInvoker($something1, $something2);
  }

  /**
   * Replaces hook_original_invoker_attribute().
   */
  #[ReplaceOriginalHook('original_invoker_attribute_last', moduleName: 'hux_test')]
  public function originalInvokerAttributeLast(string $something1, string $something2, #[OriginalInvoker] callable $originalInvoker): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something1, $something2]);
    $originalInvoker($something1, $something2);
  }

  /**
   * Replaces multiple hooks.
   *
   * Replaces hux_test_foo3().
   * Replaces hux_test_foo4().
   *
   * Tests a hook replacement listening for multiple hooks.
   */
  #[
    ReplaceOriginalHook('foo3', moduleName: 'hux_test'),
    ReplaceOriginalHook('foo4', moduleName: 'hux_test'),
  ]
  public function testHookMultiListener(string $something): void {
    HuxTestCallTracker::record([__CLASS__, __FUNCTION__, $something]);
  }

}
