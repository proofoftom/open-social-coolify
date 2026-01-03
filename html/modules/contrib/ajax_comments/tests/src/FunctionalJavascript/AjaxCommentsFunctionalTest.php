<?php

namespace Drupal\Tests\ajax_comments\FunctionalJavascript;

use Drupal\comment\Entity\Comment;
use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\views\Views;

/**
 * Javascript functional tests for ajax comments.
 *
 * @group ajax_comments
 */
class AjaxCommentsFunctionalTest extends WebDriverTestBase {

  use CommentTestTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * A test node to which comments will be posted.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $node;

  /**
   * An administrative user with permission to configure comment settings.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $adminUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'ajax_comments',
    'node',
    'comment',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Ensure an `article` node type exists.
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addDefaultCommentField('node', 'article');

    $comment_field = $this->entityTypeManager->getStorage('field_config')->load('node.article.comment');
    $comment_field->setSetting('per_page', 10);
    $comment_field->save();

    // Enable ajax comments on the comment field.
    $entity_view_display = EntityViewDisplay::load('node.article.default');
    $renderer = $entity_view_display->getRenderer('comment');
    $renderer->setThirdPartySetting('ajax_comments', 'enable_ajax_comments', '1');
    $entity_view_display->save();

    $format = FilterFormat::create([
      'format' => $this->randomMachineName(),
      'name' => $this->randomString(),
      'weight' => 1,
      'filters' => [],
    ]);
    $format->save();

    $this->adminUser = $this->drupalCreateUser([
      'access content',
      'access comments',
      // Usernames aren't shown in comment edit form autocomplete unless this
      // permission is granted.
      'access user profiles',
      'administer comments',
      'edit own comments',
      'post comments',
      'skip comment approval',
      $format->getPermissionName(),
    ]);

    $this->node = $this->drupalCreateNode([
      'type' => 'article',
      'comment' => CommentItemInterface::OPEN,
    ]);
  }

  /**
   * Tests that comments can be posted and edited over ajax without errors.
   */
  public function testCommentPosting() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet($this->node->toUrl());

    $page = $this->getSession()->getPage();

    // Post comments through ajax.
    for ($i = 1; $i < 12; $i++) {
      $comment_form = $this->assertSession()->waitForElement('css', 'form.comment-form');
      $comment_form->findField('comment_body[0][value]')->setValue("New comment $i");
      $comment_form->pressButton('Save');
      $this->assertSession()->waitForElement('css', "#comment-$i");
    }

    $this->assertSession()->pageTextContains('Your comment has been posted.');
    $this->assertSession()->pageTextContains('New comment 1');
    $this->assertSession()->pageTextContains('New comment 2');

    $current_url = $this->getSession()->getCurrentUrl();
    $parts = parse_url($current_url);
    $path = empty($parts['path']) ? '/' : $parts['path'];
    $current_path = preg_replace('/^\\/[^\\.\\/]+\\.php\\//', '/', $path);

    $this->assertSession()->linkByHrefExists($current_path . '?page=1');

    // Using prepareRequest() followed by refreshVariables() seems to help
    // refresh the route permissions for the ajax_comments.update route.
    $this->prepareRequest();
    $this->refreshVariables();

    // Test updating a comment through ajax.
    $this->clickLink('Edit');
    $edit_form = $this->assertSession()->waitForElementVisible('css', 'form.ajax-comments-form-edit');
    $edit_form->fillField('comment_body[0][value]', 'Updated comment');
    $edit_form->pressButton('Save');
    $this->assertSession()->waitForText('Updated comment');

    // Test the cancel button.
    $this->clickLink('Edit');
    $edit_form = $this->assertSession()->waitForElementVisible('css', 'form.ajax-comments-form-edit');
    $edit_form->pressButton('Cancel');

    // Test replying to a comment.
    $this->clickLink('Reply');
    $reply_form = $this->assertSession()->waitForElementVisible('css', 'form.ajax-comments-form-reply');
    $reply_form->fillField('comment_body[0][value]', 'Comment reply');
    $reply_form->pressButton('Save');
    $this->assertSession()->waitForText('Comment reply');

    // Test deleting a comment.
    $delete_link = $page->findLink('Delete');
    $this->assertNotNull($delete_link);
    $delete_url = $delete_link->getAttribute('href');
    $this->assertNotNull($delete_url);

    // Get the comment ID (in $matches[1]).
    preg_match('/comment\/(.+)\/delete/i', $delete_url, $matches);
    $this->assertTrue(isset($matches[1]));
    $comment_to_delete = Comment::load($matches[1]);
    $comment_to_delete_body = $comment_to_delete->get('comment_body')->value;

    $delete_form = $this->container
      ->get('entity_type.manager')
      ->getFormObject(
        $comment_to_delete->getEntityTypeId(), 'delete'
      );
    $delete_form->setEntity($comment_to_delete);
    // The delete confirmation question has tags stripped and is truncated
    // in the modal dialog box.
    $confirm_question = substr(strip_tags($delete_form->getQuestion()), 0, 50);

    $delete_link->click();

    $this->assertSession()->waitForText($confirm_question);
    $delete_button = $page->find('css', '.ui-dialog button.button--primary.js-form-submit');
    $this->assertNotNull($delete_button);
    $delete_button->click();
    $this->assertSession()->waitForText('The comment and all its replies have been deleted.');
    $this->assertSession()->pageTextNotContains($comment_to_delete_body);

    // Test removing the role's permission to post comments.
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = Role::loadMultiple($this->adminUser->getRoles());
    foreach ($roles as $role) {
      $role->revokePermission('post comments')->save();
    }
    $this->refreshVariables();

    // Now try to submit a new comment. We haven't reloaded the page after
    // changing permissions, so the comment field should still be visible.
    $comment_form = $this->assertSession()->waitForElement('css', 'form.comment-form');
    $comment_form->findField('comment_body[0][value]')->setValue('This should fail.');
    $comment_form->pressButton('Save');

    // Confirm that the new comment does not appear.
    $this->assertSession()->pageTextNotContains('This should fail.');
    // Confirm that the error message DOES appear.
    $this->assertSession()->waitForText('You do not have permission to post a comment.');

    // Restore the user's permission to post comments, and reload the page
    // so that the reply links are visible.
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = Role::loadMultiple($this->adminUser->getRoles());
    foreach ($roles as $role) {
      $role->grantPermission('post comments')->save();
    }
    $this->refreshVariables();

    // Reload the page.
    $this->drupalGet($this->node->toUrl());
    $reply_link = $page->findLink('Reply');
    $this->assertNotNull($reply_link);

    // Revoke the user's permission to post comments, again.
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = Role::loadMultiple($this->adminUser->getRoles());
    foreach ($roles as $role) {
      $role->revokePermission('post comments')->save();
    }
    $this->refreshVariables();

    // Click the link to reply to a comment. The link should still be present,
    // because we haven't reloaded the page since revoking the user's
    // permission.
    $reply_link->click();

    // Confirm that the error message appears.
    $this->assertSession()->waitForText('You do not have permission to post a comment.');

    // Again, restore the user's permission to post comments, and
    // reload the page so that the reply links are visible.
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = Role::loadMultiple($this->adminUser->getRoles());
    foreach ($roles as $role) {
      $role->grantPermission('post comments')->save();
    }
    $this->refreshVariables();

    // Reload the page.
    $this->drupalGet($this->node->toUrl());

    // Click the link to reply to a comment.
    $this->clickLink('Reply');

    // The reply form should load. Enter a comment in the reply field.
    $reply_form = $this->assertSession()->waitForElementVisible('css', 'form.ajax-comments-form-reply');
    $reply_form->fillField('comment_body[0][value]', 'This reply should fail.');

    // Revoke the user's permission to post comments without reloading the page.
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = Role::loadMultiple($this->adminUser->getRoles());
    foreach ($roles as $role) {
      $role->revokePermission('post comments')->save();
    }
    $this->refreshVariables();

    // Now try to click the 'Save' button on the reply form.
    $reply_form->pressButton('Save');

    // Confirm that the new comment does not appear.
    $this->assertSession()->pageTextNotContains('This reply should fail.');
    // Confirm that the error message DOES appear.
    $this->assertSession()->waitForText('You do not have permission to post a comment.');
  }

  /**
   * Tests the administrative interface with Ajax Comments.
   */
  public function testAdminInterface(): void {
    // @todo remove toolbar dependency once #3464431 is resolved.
    \Drupal::service('module_installer')->install([
      'toolbar',
      'views',
    ]);
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, [
      'access toolbar',
    ]);
    $view = Views::getView('comment');
    $view->storage->enable()->save();
    \Drupal::service('router.builder')->rebuildIfNeeded();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet($this->node->toUrl());

    $comment_form = $this->assertSession()->waitForElement('css', 'form.comment-form');
    $comment_form->findField('comment_body[0][value]')->setValue('Test comment');
    $comment_form->pressButton('Save');
    $this->assertSession()->waitForElement('css', "#comment-1");

    $this->drupalGet('admin/content/comment');
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $page->find('css', '.dropbutton-toggle button')->click();
    $page->clickLink('Delete');

    $this->assertEquals('Are you sure you want to delete the comment Test comment?', $assert_session->waitForElement('css', '.ui-dialog-title')->getText());
    $page->find('css', '.ui-dialog-buttonset')->pressButton('Delete');

    $this->assertSession()->pageTextContains('The comment and all its replies have been deleted.');
    $this->assertSession()->pageTextContains('No comments available.');
  }

}
