<?php

namespace Drupal\ginvite;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationship;
use Drupal\group\Entity\GroupRelationshipInterface;

/**
 * Group Invitation Manager class.
 */
class GroupInvitationManager {

  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Group relationship type.
   *
   * @var \Drupal\group\Entity\GroupRelationshipTypeStorageInterface
   */
  protected $groupRelationTypeStorage;

  /**
   * Group Invitation Manager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->groupRelationTypeStorage = $this->entityTypeManager->getStorage('group_content_type');
  }

  /**
   * Create group invitation group relationship.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   * @param string $email
   *   Email.
   * @param int $user_id
   *   User id.
   * @param array $group_roles
   *   Group roles.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface
   *   Group invitation group relationship.
   */
  public function createInvitation(GroupInterface $group, $email = NULL, $user_id = 0, array $group_roles = []) {
    $group_type = $group->getGroupType();
    if (!$group_type->hasPlugin('group_invitation')) {
      throw new \Exception("Group invitation is not install for group type {$group_type->label()}");
    }

    $group_content_roles = [];
    if (!empty($group_roles)) {
      $group_type_roles = array_keys($group_type->getRoles());
      foreach ($group_roles as $group_role) {
        if (!in_array($group_role, $group_type_roles)) {
          throw new \Exception("Group role $group_role does not exist for group type {$group_type->label()}");
        }

        $group_content_roles[] = ['target_id' => $group_role];
      }
    }

    $plugin_id = 'group_invitation';
    return GroupRelationship::create([
      'type' => $this->groupRelationTypeStorage->getRelationshipTypeId($group_type->id(), $plugin_id),
      'gid' => $group->id(),
      'group_type' => $group_type->id(),
      'entity_id' => $user_id,
      'invitee_mail' => $email,
      'group_roles' => $group_content_roles,
      'plugin_id' => $plugin_id,
    ]);
  }

  /**
   * Create group membership based on group invitation.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $group_invitation_relationship
   *   Group.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface
   *   Group membership relationship.
   */
  public function createMember(GroupRelationshipInterface $group_invitation_relationship) {
    $group = $group_invitation_relationship->getGroup();
    $group_type = $group->getGroupType();
    $group_type_id = $group_type->id();

    $group_membership = $group->getMember($group_invitation_relationship->getEntity());
    // User already a member.
    if (!empty($group_membership)) {
      return $group_membership->getGroupRelationship();
    }

    $plugin_id = 'group_membership';

    $group_membership = GroupRelationship::create([
      'type' => $this->groupRelationTypeStorage->getRelationshipTypeId($group_type_id, $plugin_id),
      'entity_id' => $group_invitation_relationship->getEntityId(),
      'plugin_id' => $plugin_id,
      'group_type' => $group_type_id,
      'gid' => $group->id(),
      'uid' => $group_invitation_relationship->getOwnerId(),
      'group_roles' => $group_invitation_relationship->get('group_roles')->getValue(),
    ]);

    return $group_membership;
  }

}
