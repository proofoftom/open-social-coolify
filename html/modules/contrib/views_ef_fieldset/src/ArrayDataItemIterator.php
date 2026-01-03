<?php

declare(strict_types=1);

namespace Drupal\views_ef_fieldset;

/**
 * Iterator for iterating over Views EF Fieldset data in tree structure.
 */
class ArrayDataItemIterator extends \ArrayIterator implements \RecursiveIterator {

  /**
   * Get children.
   *
   * @return null|\RecursiveIterator
   *   The children.
   */
  public function getChildren(): ?\RecursiveIterator {
    $item = $this->current();

    return new ArrayDataItemIterator($item['children']);
  }

  /**
   * Check if the item has children.
   *
   * @return bool
   *   True if it has children, false otherwise.
   */
  public function hasChildren(): bool {
    $item = $this->current();

    return !empty($item['children']);
  }

}
