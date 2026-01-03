<?php

declare(strict_types=1);

namespace Drupal\hux\Attribute;

/**
 * An alter.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
// @codingStandardsIgnoreLine
class Alter {

  /**
   * Constructs a new Alter.
   *
   * @param string $alter
   *   The alter name, without the 'hook_' or '_alter' components.
   */
  public function __construct(
    public string $alter,
  ) {
    assert(!str_starts_with($alter, 'hook_'));
    assert(!str_ends_with($alter, '_alter'));
    if (in_array($alter, [
      // This hook is invoked by \Drupal\Core\Extension\ModuleHandler::alter.
      'module_implements',
    ], TRUE)) {
      // If this alter becomes supported, and Hux is still throwing this
      // exception, then you can temporarily extend this class and constructor
      // to workaround this check.
      throw new \Exception($alter . ' is an unsupported alter implementation.');
    }
  }

}
