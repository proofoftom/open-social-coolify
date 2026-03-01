<?php

namespace Drupal\ginvite\Plugin\Group\RelationHandler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Access\GroupAccessResult;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface;
use Drupal\group\Plugin\Group\RelationHandler\AccessControlInterface;
use Drupal\group\Plugin\Group\RelationHandler\AccessControlTrait;

/**
 * Checks access for the group_invitation relation plugin.
 */
class GroupInvitationAccessControl implements AccessControlInterface {

  use AccessControlTrait;

  /**
   * Constructs a new GroupInvitationAccessControl.
   *
   * @param \Drupal\group\Plugin\Group\RelationHandler\AccessControlInterface $parent
   *   The parent access control handler.
   * @param \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $groupRelationTypeManager
   *   The group relation type manager.
   */
  public function __construct(AccessControlInterface $parent, GroupRelationTypeManagerInterface $groupRelationTypeManager) {
    $this->parent = $parent;
    $this->groupRelationTypeManager = $groupRelationTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsOperation($operation, $target) {
    // Close access to edit group invitations.
    // It will not be supported for now.
    if ($operation === 'update' && $target === 'relationship') {
      return FALSE;
    }
    return $this->parent->supportsOperation($operation, $target);
  }

  /**
   * {@inheritdoc}
   */
  public function relationshipAccess(GroupRelationshipInterface $group_relationship, $operation, AccountInterface $account, $return_as_object = FALSE) {
    if (!$this->chainSupportsOperation($operation, 'relationship')) {
      return $return_as_object ? AccessResult::neutral() : FALSE;
    }

    if (GroupAccessResult::allowedIfHasGroupPermissions($group_relationship->getGroup(), $account, $this->getInvitePermissions(), 'OR')->isAllowed()) {
      return $return_as_object ? AccessResult::allowed() : FALSE;
    }

    if ($operation == 'view') {
      // Anonymous users should not get access through pending invitations.
      // Invitations for non-existent users are stored with entity_id = 0,
      // which would incorrectly match all anonymous users.
      if ($account->isAnonymous()) {
        return AccessResult::neutral();
      }

      // Check if the account is the owner.
      if ($group_relationship->getEntityId() !== $account->id()) {
        return $return_as_object ? AccessResult::neutral() : FALSE;
      }
    }

    return $this->parent->relationshipAccess($group_relationship, $operation, $account, $return_as_object);
  }

  /**
   * Get invite permissions.
   *
   * @return string[]
   *   Permissions.
   */
  protected function getInvitePermissions() {
    return [
      'invite users to group',
      'administer members',
      'administer group invitations',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function relationshipCreateAccess(GroupInterface $group, AccountInterface $account, $return_as_object = FALSE) {
    return GroupAccessResult::allowedIfHasGroupPermissions($group, $account, $this->getInvitePermissions(), 'OR');
  }

  /**
   * Checks operation support across the entire decorator chain.
   *
   * Instead of checking whether this specific access control handler supports
   * the operation, we check the entire decorator chain. This avoids a lot of
   * copy-pasted code to manually support an operation in a decorator further
   * down the chain.
   *
   * @param string $operation
   *   The permission operation. Usually "create", "view", "update" or "delete".
   * @param string $target
   *   The target of the operation. Can be 'relationship' or 'entity'.
   *
   * @return bool
   *   Whether the operation is supported.
   */
  protected function chainSupportsOperation($operation, $target) {
    $access_control_chain = $this->groupRelationTypeManager->getAccessControlHandler($this->pluginId);
    return $access_control_chain->supportsOperation($operation, $target);
  }

}
