<?php

/**
 * @file
 * Defines hooks for the Role Delegation module.
 */

/**
 * Alters the list of assignable roles for role delegation.
 *
 * @param array $assignable_roles
 *   An array of roles that can be assigned. Keys are machine names and values
 *   are labels.
 * @param array $all_roles
 *   An array of all roles available in the system. Keys are machine names and
 *   values are labels.
 * @param \Drupal\Core\Session\AccountInterface $account
 *   The account for which the assignable roles are being determined.
 */
function hook_role_delegation_assignable_roles_alter(array &$assignable_roles, array &$all_roles, \Drupal\Core\Session\AccountInterface $account) {
  // Example: Remove the 'editor' role from the list of assignable roles.
  unset($assignable_roles['editor']);
}
