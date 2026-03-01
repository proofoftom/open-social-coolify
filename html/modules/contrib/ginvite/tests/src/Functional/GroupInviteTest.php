<?php

namespace Drupal\Tests\ginvite\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ginvite\Plugin\Group\Relation\GroupInvitation;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\group\Functional\GroupBrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;

/**
 * Tests the behavior of the group invite functionality.
 *
 * @group group
 */
class GroupInviteTest extends GroupBrowserTestBase {

  use StringTranslationTrait;

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
   * The normal user we will use.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $account;

  /**
   * The normal user we will use.
   *
   * @var \Drupal\group\Entity\GroupRoleInterface
   */
  protected $groupIndividualRole;

  /**
   * The outsider role.
   *
   * @var \Drupal\group\Entity\GroupRoleInterface
   */
  protected $groupOutsiderRole;

  /**
   * The member role.
   *
   * @var \Drupal\group\Entity\GroupRoleInterface
   */
  protected $groupMemberRole;

  /**
   * The group relationship type for group membership request.
   *
   * @var \Drupal\group\Entity\GroupRelationshipTypeInterface
   */
  protected $groupRelationshipType;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'group_test_config',
    'ginvite',
  ];

  /**
   * Gets the global (site) permissions for the group creator.
   *
   * @return string[]
   *   The permissions.
   */
  protected function getGlobalPermissions() {
    return [
      'view the administration theme',
      'access administration pages',
      'access group overview',
      'create default group',
      'administer group',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setUpAccount();

    $this->group = $this->createGroup([
      'uid' => $this->groupCreator->id(),
      'type' => 'default',
    ]);

    $this->groupInvitationManager = $this->container->get('ginvite.group_invitation_manager');

    $this->account = $this->drupalCreateUser();
    $this->group->addMember($this->account);
    $this->group->save();

    $this->groupIndividualRole = $this->createGroupRole([
      'group_type' => $this->group->getGroupType()->id(),
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'permissions' => ['view group'],
    ]);

    $this->installPlugin();
  }

  /**
   * Install group invitation plugin.
   */
  private function installPlugin() {
    // Install and configure the Group Invitation plugin.
    $this->drupalGet('/admin/group/content/install/default/group_invitation');
    $this->submitForm([], 'Install plugin');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/admin/group/content/manage/default-group_invitation');
    $this->submitForm(['invitation_bypass_form' => 1], 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    $this->groupRelationshipType = $this->entityTypeManager->getStorage('group_content_type')->load('default-group_invitation');

    // Add permissions to invite users to members of the group.
    $this->groupOutsiderRole = $this->createGroupRole([
      'group_type' => $this->group->getGroupType()->id(),
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => [
        'view group invitations',
        'view group',
      ],
    ]);

    $this->groupMemberRole = $this->createGroupRole([
      'group_type' => $this->group->getGroupType()->id(),
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => [
        'view group invitations',
        'view group',
      ],
    ]);

    drupal_flush_all_caches();
  }

  /**
   * Check routes access.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $group_invitation
   *   Group invitation.
   * @param int $status
   *   HTTP status.
   */
  private function checkRoutesAccess(GroupRelationshipInterface $group_invitation, $status) {
    $this->drupalGet("/ginvite/{$group_invitation->id()}/accept");
    $this->assertSession()->statusCodeEquals($status);

    $this->drupalGet("/ginvite/{$group_invitation->id()}/decline");
    $this->assertSession()->statusCodeEquals($status);
  }

  /**
   * Create invites and test general group invite behavior.
   */
  public function testInviteRolePermission() {

    $this->drupalLogin($this->account);

    $this->groupMemberRole->grantPermissions(['invite users to group']);
    $this->groupMemberRole->save();

    $this->drupalGet("/group/{$this->group->id()}/content/add/group_invitation");
    $this->assertSession()->fieldNotExists("group_roles[{$this->groupIndividualRole->id()}]");

    $this->groupMemberRole->grantPermissions(['administer members']);
    $this->groupMemberRole->save();

    $this->drupalGet("/group/{$this->group->id()}/content/add/group_invitation");
    $this->assertSession()->fieldExists("group_roles[{$this->groupIndividualRole->id()}]");

    $this->groupMemberRole->revokePermissions(['administer members', 'invite users to group']);
    $this->groupMemberRole->save();
  }

  /**
   * We want to be sure user gets the group role.
   */
  public function testRoleAssigment() {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail(), $account->id(), [$this->groupIndividualRole->id()]);
    $group_invitation->save();

    // Install and configure the Group Invitation plugin.
    $this->drupalGet("/ginvite/{$group_invitation->id()}/accept");
    $this->assertSession()->statusCodeEquals(200);

    $group_membership = $this->group->getMember($account);
    $this->assertTrue(in_array($this->groupIndividualRole->id(), array_keys($group_membership->getRoles())), 'Role has been found');
  }

  /**
   * Owner can access own invitations.
   */
  public function testAccessOwnInvitation() {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail(), $account->id());
    $group_invitation->save();

    $this->drupalGet("/ginvite/{$group_invitation->id()}/accept");
    $this->assertSession()->statusCodeEquals(200);

    // We need to create another invitation, because the previous is accepted.
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail(), $account->id());
    $group_invitation->save();

    $this->drupalGet("/ginvite/{$group_invitation->id()}/decline");
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Not owner can't access invitations.
   */
  public function testNotOwnerAccessRoutes() {

    $account = $this->drupalCreateUser();

    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $account->getEmail(), $account->id());
    $group_invitation->save();

    $not_owner_user = $this->drupalCreateUser();
    $this->drupalLogin($not_owner_user);

    // As not owner of invitation I can't accept or decline it.
    $this->checkRoutesAccess($group_invitation, 403);
  }

  /**
   * Access not pending invitation.
   */
  public function testAccessNotPendingInvitation() {

    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $this->account->getEmail(), $this->account->id());
    $group_invitation->save();

    $account = $this->drupalCreateUser();

    $this->drupalLogin($account);

    // As not owner of invitation I can't accept or decline it.
    $this->checkRoutesAccess($group_invitation, 403);

    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $this->account->getEmail(), $this->account->id());
    $group_invitation->save();
    $group_invitation->set('invitation_status', GroupInvitation::INVITATION_ACCEPTED)->save();

    $this->checkRoutesAccess($group_invitation, 403);
  }

  /**
   * Check bulk operations routes access.
   */
  public function testBulkInvitationRoutes() {
    $account = $this->drupalCreateUser();

    $this->drupalLogin($account);

    $this->groupOutsiderRole->grantPermission('invite users to group');
    $this->groupOutsiderRole->save();

    $this->drupalGet("/group/{$this->group->id()}/invite-members");
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet("/group/{$this->group->id()}/invite-members/confirm");
    $this->assertSession()->statusCodeEquals(403);

    $this->groupOutsiderRole->grantPermission('bulk invite users to group');
    $this->groupOutsiderRole->save();

    $this->drupalGet("/group/{$this->group->id()}/invite-members");
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet("/group/{$this->group->id()}/invite-members/confirm");
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Unblock user during registration, if unblock_invitees option enabled.
   *
   * @param int $unblock_invitees
   *   Option unblock_invitees.
   * @param bool $user_status
   *   Current user status.
   * @param bool $expected_user_status
   *   Expected user status.
   *
   * @dataProvider groupInvitationUserRegistrationData
   */

  public function testUnlbockRegisteredUser($unblock_invitees, $user_status, $expected_user_status) {
    $email = $this->randomMachineName() . '@domain.com';

    // Enable unblock_invitees option.
    $this->groupRelationshipType->updatePlugin(['unblock_invitees' => $unblock_invitees]);

    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $email);
    $group_invitation->save();

    $account = $this->drupalCreateUser([], NULL, FALSE, [
      'mail' => $email,
      'status' => $user_status,
    ]);

    // Reload account.
    $user = User::load($account->id());

    if ($expected_user_status) {
      $this->assertTrue($user->isActive());
    }
    else {
      $this->assertFalse($user->isActive());
    }
  }

  /**
   * Data provider for testUnlbockRegisteredUser().
   *
   * @return array
   *   Data to check unlock functionality.
   */
  public static function groupInvitationUserRegistrationData() {
    return [
      // Each array contains
      // [unblock_invitees option, user status, expected user status].
      [1, FALSE, TRUE],
      [1, TRUE, TRUE],
      [0, FALSE, FALSE],
      [0, TRUE, TRUE],
    ];
  }

  /**
   * Test group access with pending invitations for anonymous users.
   */
  public function testGroupAccessWithPendingInvitationsForAnonymousUsers() {
    $this->drupalLogout();

    $this->groupOutsiderRole->revokePermission('view group');

    // Group is not accessible for anonymous users by default.
    $this->drupalGet("/group/{$this->group->id()}");
    $this->assertSession()->statusCodeEquals(403);

    // Create group invitation with existing role.
    $email = $this->randomMachineName() . '@example.com';
    $group_invitation = $this->groupInvitationManager->createInvitation($this->group, $email);
    $group_invitation->save();

    // Group is still not accessible for anonymous users.
    $this->drupalGet("/group/{$this->group->id()}");
    $this->assertSession()->statusCodeEquals(403);

    // Accept invitation route is not accessible for anonymous users.
    // Decline invitation route is not accessible for anonymous users.
    $this->checkRoutesAccess($group_invitation, 403);

    $account = $this->drupalCreateUser([], $this->randomString(), FALSE, ['mail' => $email]);
    $this->drupalLogin($account);

    // Users with invitation can see group.
    $this->drupalGet("/group/{$this->group->id()}");
    $this->assertSession()->statusCodeEquals(200);
  }

}
