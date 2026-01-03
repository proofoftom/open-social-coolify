<?php

declare(strict_types=1);

namespace Drupal\hux\Attribute;

/**
 * Indicates the original invoker should be passed to this parameter.
 *
 * The parameter should be typehint with `callable`.
 *
 * This parameter is used in conjunction with ReplaceOriginalHook methods.
 *
 * @see \Drupal\hux\Attribute\ReplaceOriginalHook
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
// @codingStandardsIgnoreLine
final class OriginalInvoker {

  /**
   * Constructs an OriginalInvoker.
   */
  final public function __construct() {}

}
