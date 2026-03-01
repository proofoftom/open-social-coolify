<?php

namespace Drupal\role_delegation\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\role_delegation\DelegatableRolesInterface;
use Drupal\role_delegation\PermissionGenerator;

/**
 * Checks access for the /user/%/roles page.
 */
class RoleDelegationAccessCheck implements AccessInterface {

  /**
   * The delegatable_roles service.
   *
   * @var \Drupal\role_delegation\DelegatableRolesInterface
   */
  protected $delegatableRoles;

  /**
   * The permission generator service.
   *
   * @var \Drupal\role_delegation\PermissionGenerator
   */
  protected $permissionGenerator;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The Role Delegation access check.
   *
   * @param \Drupal\role_delegation\DelegatableRolesInterface $delegatable_roles
   *   The delegatable_roles service.
   * @param \Drupal\role_delegation\PermissionGenerator $permission_generator
   *   The role delegation service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(DelegatableRolesInterface $delegatable_roles, PermissionGenerator $permission_generator, AccountInterface $current_user) {
    $this->delegatableRoles = $delegatable_roles;
    $this->permissionGenerator = $permission_generator;
    $this->currentUser = $current_user;
  }

  /**
   * Custom access check for the /user/%/roles page.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(?AccountInterface $account = NULL): AccessResultInterface {
    if ($account === NULL) {
      $account = $this->currentUser;
    }

    // Deny access when the current user has the 'administer users'
    // permission. Roles can be edited on the user edit page.
    if ($account->hasPermission('administer users')) {
      return AccessResult::neutral()->cachePerPermissions();
    }

    // Deny access when the user is not allowed to assign any roles.
    if (!$this->delegatableRoles->getAssignableRoles($account)) {
      return AccessResult::neutral()->cachePerPermissions();
    }

    return AccessResult::allowed()->cachePerPermissions();
  }

}
