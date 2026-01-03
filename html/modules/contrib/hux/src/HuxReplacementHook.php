<?php

declare(strict_types=1);

namespace Drupal\hux;

/**
 * Replacement hook.
 *
 * @property callable $replacement
 *
 * @internal
 *   For internal use only, behavior and serialized data structure may change at
 *   any time.
 */
final class HuxReplacementHook {

  /**
   * Constructs a replacement hook.
   *
   * @param callable $replacement
   *   The replacement callable.
   * @param int[] $originalInvokerPositions
   *   Argument positions of original invokers.
   */
  // @codingStandardsIgnoreLine
  public function __construct(
    public $replacement,
    public array $originalInvokerPositions,
  ) {
  }

  /**
   * Gets a callable to the replacement hook.
   *
   * @param callable $original
   *   A callable to the original hook implementation.
   *
   * @return callable
   *   A callable to the replacement hook implementation, optionally adding
   *   a callable to the original hook implementation before the argument list.
   */
  public function getCallable(callable $original): callable {
    if (count($this->originalInvokerPositions) === 0) {
      return $this->replacement;
    }

    return function (...$args) use ($original) {
      foreach ($this->originalInvokerPositions as $position) {
        array_splice($args, $position, 0, $original);
      }
      return ($this->replacement)(...$args);
    };
  }

}
