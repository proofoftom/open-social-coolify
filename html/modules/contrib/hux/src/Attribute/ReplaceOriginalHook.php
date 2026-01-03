<?php

declare(strict_types=1);

namespace Drupal\hux\Attribute;

/**
 * A hook.
 *
 * This instructs the module handler to ignore the original procedural
 * hook implementation and replace it with this implementation.
 *
 * The original implementation will be passed as the last argument.
 *
 * This does not extend the Hook attribute to simplify things.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
// @codingStandardsIgnoreLine
final class ReplaceOriginalHook {

  /**
   * Constructs a ReplaceOriginalHook.
   *
   * When implementing this attribute, you must ensure named parameters are used
   * with the third parameter and onwards, to avoid breakage.
   *
   * @param string $hook
   *   The hook to implement.
   * @param string $moduleName
   *   The original implementation module name.
   * @param bool $originalInvoker
   *   Whether you want to receive a callable to the original implementation as
   *   the first parameter.
   */
  public function __construct(
    public string $hook,
    public string $moduleName,
    public bool $originalInvoker = FALSE,
  ) {
    if ($originalInvoker) {
      @trigger_error(sprintf(
        "Requesting \$originalInvoker with %s is deprecated, and will be removed in Hux 2.0. Instead, tag the callable parameter to receive the original invoker with %s.",
        ReplaceOriginalHook::class,
        OriginalInvoker::class,
      ), \E_USER_DEPRECATED);
    }
  }

}
