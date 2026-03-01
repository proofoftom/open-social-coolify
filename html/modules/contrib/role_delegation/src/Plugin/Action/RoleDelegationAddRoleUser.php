<?php

namespace Drupal\role_delegation\Plugin\Action;

use Drupal\user\Plugin\Action\AddRoleUser;

/**
 * Alternate action plugin for 'user_add_role_action'.
 *
 * This plugin makes sure the add role action also works without
 * the 'administer users' permission.
 *
 * @see \Drupal\user\Plugin\Action\AddRoleUser
 */
class RoleDelegationAddRoleUser extends AddRoleUser {

  use RoleDelegationManagerRoleUserTrait;

}
