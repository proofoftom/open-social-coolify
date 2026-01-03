<?php

declare(strict_types=1);

namespace Drupal\Tests\private_message\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\private_message\Traits\PrivateMessageTestTrait;

/**
 * Tests for the unread count.
 *
 * @group private_message
 */
class PrivateMessageUnreadCountTest extends BrowserTestBase {

  use PrivateMessageTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'private_message_test'];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->attachFullNameField();
    $this->createTestingUsers();
    $this->drupalPlaceBlock('private_message_notification_block');
  }

  /**
   * Tests that the receiving user gets a notification.
   */
  public function testUnreadCounts(): void {
    $this->drupalLogin($this->users['a']);

    $this->drupalGet('/private-message/create');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'members[target_id]' => $this->getAutocompleteLabel($this->users['b']),
      'message[0][value]' => $this->getRandomGenerator()->sentences(5),
    ], 'Send');
    $this->assertSession()->statusCodeEquals(200);
    // I should not see a notification for my own message.
    $this->assertSession()->elementTextContains('css', 'a.private-message-page-link', '0');
    // When going to a different page, I should still not see a notification for
    // my own message.
    $this->drupalGet('<front>');
    $this->assertSession()->elementTextContains('css', 'a.private-message-page-link', '0');

    // User B should see a notification.
    $this->drupalLogin($this->users['b']);
    $this->assertSession()->elementTextContains('css', 'a.private-message-page-link', '1');

    // We visit the thread directly.
    $this->drupalGet('private-messages/1');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextContains('css', 'a.private-message-page-link', '0');

    // We are not already looking at the thread.
    $this->submitForm([
      'message[0][value]' => $this->getRandomGenerator()->sentences(5),
    ], 'Send');
    $this->assertSession()->statusCodeEquals(200);
    // I should not see a notification for my own message.
    $this->assertSession()->elementTextContains('css', 'a.private-message-page-link', '0');

    // When going to a different page, I should still not see a notification for
    // my own message.
    $this->drupalGet('<front>');
    $this->assertSession()->elementTextContains('css', 'a.private-message-page-link', '0');
  }

}
