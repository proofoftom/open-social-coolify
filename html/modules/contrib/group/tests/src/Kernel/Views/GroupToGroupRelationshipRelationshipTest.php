<?php

namespace Drupal\Tests\group\Kernel\Views;

use Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface;

/**
 * Tests the group_to_group_content relationship handler.
 *
 * @see \Drupal\group\Plugin\views\relationship\GroupToGroupRelationship
 *
 * @group group
 */
class GroupToGroupRelationshipRelationshipTest extends GroupViewsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group_test_plugin', 'node'];

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_group_to_group_content_relationship'];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    parent::setUp($import_test_views);
    $this->installEntitySchema('node');

    // Enable the user_as_content plugin on the test group type.
    $storage = $this->entityTypeManager->getStorage('group_content_type');
    assert($storage instanceof GroupRelationshipTypeStorageInterface);
    $storage->createFromPlugin($this->groupType, 'user_as_content')->save();
  }

  /**
   * Tests that a group's owner (default member) is returned by the view.
   */
  public function testGroupOwnerIsListed() {
    $this->assertEquals(0, count($this->getViewResults()), 'The view displays no members.');
    $this->createGroup();
    $this->assertEquals(1, count($this->getViewResults()), 'The view displays the default member.');
  }

  /**
   * Tests that an extra group member is returned by the view.
   *
   * @depends testGroupOwnerIsListed
   */
  public function testAddedMemberIsListed() {
    $group = $this->createGroup();
    $group->addMember($this->createUser());
    $this->assertEquals(2, count($this->getViewResults()), 'The view displays both the default and the added member.');
  }

  /**
   * Tests that any other relationship is not returned by the view.
   *
   * @depends testGroupOwnerIsListed
   */
  public function testOtherContentIsNotListed() {
    $group = $this->createGroup();
    $group->addRelationship($this->createUser(), 'user_as_content');
    $this->assertEquals(1, count($this->getViewResults()), 'The view only displays the default member and not the user that was added as content.');
  }

}
