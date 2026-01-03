<?php

namespace Drupal\group_test_plugin\Plugin\Group\Relation;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Plugin\Attribute\GroupRelationType;
use Drupal\group\Plugin\Group\Relation\GroupRelationBase;

/**
 * Provides a group relation type for users.
 */
#[GroupRelationType(
  id: 'user_as_content',
  entity_type_id: 'user',
  label: new TranslatableMarkup('Group user'),
  description: new TranslatableMarkup('Adds users to groups without making them members.'),
  reference_label: new TranslatableMarkup('Username'),
  reference_description: new TranslatableMarkup('The name of the user you want to add to the group'),
  admin_permission: 'administer user_as_content',
  pretty_path_key: 'user'
)]
class UserAsContent extends GroupRelationBase {
}
