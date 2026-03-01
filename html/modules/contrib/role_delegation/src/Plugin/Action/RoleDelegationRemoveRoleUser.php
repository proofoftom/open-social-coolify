<?php

namespace Drupal\role_delegation\Plugin\Action;

use Drupal\user\Plugin\Action\RemoveRoleUser;

/**
 * Alternate action plugin for 'user_remove_role_action'.
 *
 * This plugin makes sure the remove role action also works without
 * the 'administer users' permission.
 *
 * @see \Drupal\user\Plugin\Action\RemoveRoleUser
 */
class RoleDelegationRemoveRoleUser extends RemoveRoleUser {

  use RoleDelegationManagerRoleUserTrait;

}
