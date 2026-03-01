<?php

namespace Drupal\Tests\ginvite\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Tests the general behavior of group relationship group_invitation.
 *
 * @group ginvite
 */
class GroupInviteTest extends GroupKernelTestBase {

  /**
   * The invitation loader.
   *
   * @var \Drupal\ginvite\GroupInvitationLoaderInterface
   */
  protected $invitationLoader;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The invitation manager.
   *
   * @var \Drupal\ginvite\GroupInvitationManager
   */
  protected $groupInvitationManager;

  /**
   * The group we will use to test methods on.
   *
   * @var \Drupal\group\Entity\Group
   */
  protected $group;

  /**
   * The group type
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * The normal user we will use.
   *
   * @var \Drupal\group\Entity\GroupRoleInterface
   */
  protected $groupIndividualRole;

  /**
   * The group relationship type for group membership request.
   *
   * @var \Drupal\group\Entity\GroupRelationshipTypeInterface
   */
  protected $groupRelationshipType;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['ginvite'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('user');

    $this->installConfig([
      'ginvite',
    ]);

    $this->invitationLoader = $this->container->get('ginvite.invitation_loader');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->groupInvitationManager = $this->container->get('ginvite.group_invitation_manager');

    $this->groupType = $this->createGroupType();
    $this->group = $this->createGroup(['type' => $this->groupType->id()]);

    $config = [
      'group_cardinality' => 0,
      'entity_cardinality' => 1,
      'remove_invitation' => 0,
    ];
    // Enable group membership request group relationship plugin.
    $this->groupRelationshipType = $this->entityTypeManager->getStorage('group_content_type')->createFromPlugin($this->groupType, 'group_invitation', $config);
    $this->groupRelationshipType->save();

    $this->groupIndividualRole = $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'permissions' => ['view group'],
    ]);

    $this->createGroupRole([
      'group_type' => $this->group->getGroupType()->id(),
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => [
        'view group',
      ],
    ]);

    $this->createGroupRole([
      'group_type' => $this->group->getGroupType()->id(),
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => [
        'view group',
      ],
    ]);
  }

  /**
   * Creates group invitation.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   * @param \Drupal\user\UserInterface $user
   *   User.
   *
   * @return \Drupal\group\Entity\GroupRelationship
   *   Group relationship invitation.
   */
  private function createInvitation(GroupInterface $group, UserInterface $user) {
    $group_relationship = $this->groupInvitationManager->createInvitation($group, $user->getEmail(), $user->id());
    $group_relationship->save();
    return $group_relationship;
  }

  /**
   * Test group invitation removal with disabled settings.
   */
  public function testRequestRemovalWithDisabledSettings() {
    $account = $this->createUser();

    // Add an invitation.
    $this->createInvitation($this->group, $account);

    // Add the user as member.
    $this->group->addMember($account);

    // Since removal is enabled we should not find any invitations.
    $group_invitations = $this->invitationLoader->loadByProperties([
      'gid' => $this->group->id(),
      'entity_id' => $account->id(),
    ]);
    $this->assertCount(1, $group_invitations);
  }

  /**
   * Test group invitation removal with enabled settings.
   */
  public function testInvitationRemovalWithEnabledSettings() {
    $config = [
      'group_cardinality' => 0,
      'entity_cardinality' => 1,
      'remove_invitation' => 1,
    ];
    $this->groupRelationshipType->updatePlugin($config);
    $account = $this->createUser();

    // Add an invitation.
    $this->createInvitation($this->group, $account);

    // Add the user as member.
    $this->group->addMember($account);

    // Since removal is enabled we should not find any invitations.
    $group_invitations = $this->invitationLoader->loadByProperties([
      'gid' => $this->group->id(),
      'entity_id' => $account->id(),
    ]);
    $this->assertCount(0, $group_invitations);
  }

  /**
   * Test auto acceptation of invitations.
   */
  public function testInvitationAutoAcceptation() {
    $config = [
      'group_cardinality' => 0,
      'entity_cardinality' => 1,
      'autoaccept_invitees' => 1,
    ];
    $this->groupRelationshipType->updatePlugin($config);
    $account = $this->createUser();

    // Add an invitation.
    $this->createInvitation($this->group, $account);

    // It will call the same function, which is called during the login.
    $account->save();

    $member = $this->group->getMember($account);
    $this->assertNotNull($member);
  }

  /**
   * Test user removal.
   */
  public function testUserRemoval() {
    $account = $this->createUser();
    $user_id = $account->id();

    // Add an invitation.
    $this->createInvitation($this->group, $account);

    $account->delete();

    // When user removed the invitations, should be removed too.
    $group_invitations = $this->invitationLoader->loadByProperties([
      'gid' => $this->group->id(),
      'entity_id' => $user_id,
    ]);
    $this->assertCount(0, $group_invitations);
  }

  /**
   * Group invitation validation.
   */
  public function testGroupInvitationValidation() {
    $account = $this->createUser();

    $group_relationship = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail(), $account->id());
    $this->assertCount(0, $group_relationship->validate());
    $group_relationship->save();

    $group_relationship_duplicated = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail());
    $error_message = 'Invitation to this user has been already sent.';
    $check = FALSE;
    foreach ($group_relationship_duplicated->validate() as $violation) {
      if ($violation->getMessage() == $error_message) {
        $check = TRUE;
      }
    }
    $this->assertTrue($check);

    $group_relationship_duplicated = $this->groupInvitationManager->createInvitation($this->group, NULL, $account->id());
    $check = FALSE;
    foreach ($group_relationship_duplicated->validate() as $violation) {
      if ($violation->getMessage() == $error_message) {
        $check = TRUE;
      }
    }
    $this->assertTrue($check);

    $account = $this->createUser();
    $this->group->addMember($account);
    $error_message = new TranslatableMarkup('User with such email is already a member of @group.', ['@group' => $this->group->label()]);

    $group_relationship = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail());
    $check = FALSE;
    foreach ($group_relationship->validate() as $violation) {
      if ($violation->getMessage()->render() == $error_message->render()) {
        $check = TRUE;
      }
    }
    $this->assertTrue($check);

    $error_message = new TranslatableMarkup('User with such email is already a member of @group.', ['@group' => $this->group->label()]);
    $group_relationship = $this->groupInvitationManager->createInvitation($this->group, NULL, $account->id());
    $check = FALSE;
    foreach ($group_relationship->validate() as $violation) {
      if ($violation->getMessage()->render() == $error_message->render()) {
        $check = TRUE;
      }
    }
    $this->assertTrue($check);
  }

  /**
   * Test group invitation creation method.
   */
  public function testGroupInvitationCreationMethod() {
    $account = $this->createUser();

    // Create group invitation with existing role.
    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail(), $account->id(), [$this->groupIndividualRole->id()]);
    $group_invitation->save();
    $group_invitation_roles = $group_invitation->get('group_roles')->getValue();
    $this->assertTrue($this->groupIndividualRole->id() == $group_invitation_roles[0]['target_id']);

    // Create group invitation with non-existing role.
    $non_existing_role = 'random-role';
    $this->expectExceptionMessage("Group role $non_existing_role does not exist for group type {$this->groupType->label()}");
    $this->groupInvitationManager->createInvitation($this->group, $account->getEmail(), $account->id(), [$non_existing_role]);

    // Create group invitation without group invitation plugin.
    $group_type = $this->createGroupType();
    $another_group = $this->createGroup([
      'type' => $group_type->id(),
    ]);

    $this->expectExceptionMessage("Group invitation is not install for group type {$group_type->label()}");
    $this->groupInvitationManager->createInvitation($another_group, $account->getEmail(), $account->id());
  }

}
