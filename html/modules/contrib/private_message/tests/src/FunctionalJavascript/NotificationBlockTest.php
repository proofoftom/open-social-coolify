<?php

declare(strict_types=1);

namespace Drupal\Tests\private_message\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\private_message\Entity\PrivateMessageBan;
use Drupal\Tests\private_message\Traits\PrivateMessageTestTrait;

/**
 * JS tests for Private Message Notification block functionalities.
 *
 * @group private_message
 */
class NotificationBlockTest extends WebDriverTestBase {

  use PrivateMessageTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'private_message'];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->attachFullNameField();
    $this->createTestingUsers(3);

    $this->createThreadWithMessages([
      $this->users['a'],
      $this->users['b'],
    ], $this->users['b'], 2);
  }

  /**
   * Tests count functionality.
   *
   * @dataProvider countTypeProvider
   */
  public function testCount(string $countMethod, string $initialCount, string $ajaxCount): void {
    $settings = [
      'ajax_refresh_rate' => 1,
      'count_method' => $countMethod,
    ];
    $this->drupalPlaceBlock('private_message_notification_block', $settings);

    $this->drupalLogin($this->users['a']);
    $this->assertSession()
      ->elementTextContains('css', 'a.private-message-page-link', $initialCount);

    // Add more messages.
    $this->createThreadWithMessages([
      $this->users['a'],
      $this->users['c'],
    ], $this->users['c'], 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()
      ->elementTextContains('css', 'a.private-message-page-link', $ajaxCount);
    $this->assertStringContainsString(
      '(' . $ajaxCount . ')',
      $this->getSession()->getDriver()->getWebDriverSession()->title(),
    );

    // Reset count.
    $this->drupalGet('private-messages');
    $this->assertSession()
      ->elementTextContains('css', 'a.private-message-page-link', '0');
    $this->assertEquals(
      'Private Messages | Drupal',
      $this->getSession()->getDriver()->getWebDriverSession()->title(),
    );
  }

  /**
   * Tests count functionality with after ban.
   *
   * @dataProvider countTypeProvider
   */
  public function testCountForBanned(string $countMethod, string $initialCount): void {
    $settings = [
      'ajax_refresh_rate' => 1,
      'count_method' => $countMethod,
    ];
    $this->drupalPlaceBlock('private_message_notification_block', $settings);

    $this->drupalLogin($this->users['a']);
    $this->assertSession()
      ->elementTextContains('css', 'a.private-message-page-link', $initialCount);

    PrivateMessageBan::create([
      'owner' => $this->users['a'],
      'target' => $this->users['b'],
    ])->save();

    // User should not see a notification.
    $this->drupalGet('<front>');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()
      ->elementTextContains('css', 'a.private-message-page-link', '0');
  }

  /**
   * Data for the testCount().
   */
  public static function countTypeProvider(): array {
    return [
      'Count threads' => ['threads', '1', '2'],
      'Count messages' => ['messages', '2', '4'],
    ];
  }

}
