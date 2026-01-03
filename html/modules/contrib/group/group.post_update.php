<?php

/**
 * @file
 * Post update functions for Group.
 */

use Drupal\Core\Field\Entity\BaseFieldOverride;

/**
 * Updates stale references to Drupal\group\Entity\Context in field overrides.
 */
function group_post_update_modify_base_field_author_override() {
  $uid_fields = \Drupal::entityTypeManager()
    ->getStorage('base_field_override')
    ->getQuery()
    ->condition('entity_type', 'group_content')
    ->condition('field_name', 'uid')
    ->condition('default_value_callback', 'GroupContent', 'CONTAINS')
    ->execute();
  foreach (BaseFieldOverride::loadMultiple($uid_fields) as $base_field_override) {
    $base_field_override->setDefaultValueCallback('Drupal\group\Entity\GroupRelationship::getDefaultEntityOwner')->save();
  }
}
