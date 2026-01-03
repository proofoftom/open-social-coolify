<?php

declare(strict_types=1);

namespace Drupal\Tests\private_message\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\private_message\Traits\PrivateMessageTestTrait;

/**
 * JS tests for Private Message Inbox block functionalities.
 *
 * @group private_message
 */
class InboxBlockTest extends WebDriverTestBase {

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
   * Threads.
   *
   * @var \Drupal\private_message\Entity\PrivateMessageThreadInterface[]
   */
  protected array $threads;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->attachFullNameField();
    $this->createTestingUsers(3);

    $this->threads[] = $this->createThreadWithMessages([
      $this->users['a'],
      $this->users['b'],
    ], $this->users['b']);

    $this->threads[] = $this->createThreadWithMessages([
      $this->users['a'],
      $this->users['c'],
    ], $this->users['c']);
  }

  /**
   * Tests inbox click functionality.
   */
  public function testInboxClick(): void {
    $settings = [
      'thread_count' => 2,
      'ajax_load_count' => 1,
      'ajax_refresh_rate' => 15,
    ];
    $this->drupalPlaceBlock('private_message_inbox_block', $settings);

    $this->drupalLogin($this->users['a']);

    // Latest thead must be active.
    $lastThread = $this->threads[count($this->threads) - 1];
    $element = $this->assertSession()
      ->elementExists('css', '.private-message-thread-inbox[data-thread-id="' . $lastThread->id() . '"]');
    $this->assertStringContainsString(
      'active-thread',
      $element->getAttribute('class'),
    );

    foreach ($this->threads as $thread) {
      $this->drupalGet('<front>');
      $this->click('.private-message-inbox-thread-link[data-thread-id="' . $thread->id() . '"]');
      $this->assertEquals(
        $this->getAbsoluteUrl('/private-messages/' . $thread->id()),
        $this->getUrl(),
      );
    }
  }

  /**
   * Tests load previous functionality.
   */
  public function testLoadPrevious(): void {
    $this->createThreadWithMessages([
      $this->users['a'],
      $this->users['b'],
      $this->users['c'],
    ], $this->users['c']);

    $settings = [
      'thread_count' => 1,
      'ajax_load_count' => 1,
      'ajax_refresh_rate' => 15,
    ];

    $this->drupalPlaceBlock('private_message_inbox_block', $settings);
    $this->drupalLogin($this->users['a']);

    $elements = $this->getSession()
      ->getPage()
      ->findAll('css', '.private-message-thread-inbox');
    $this->assertEquals(1, count($elements));
    $this->assertSession()
      ->pageTextContains('Load Previous');

    $this->click('#load-previous-threads-button-wrapper');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $elements = $this->getSession()
      ->getPage()
      ->findAll('css', '.private-message-thread-inbox');
    $this->assertEquals(2, count($elements));
    $this->assertSession()
      ->pageTextContains('Load Previous');

    $this->click('#load-previous-threads-button-wrapper');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $elements = $this->getSession()
      ->getPage()
      ->findAll('css', '.private-message-thread-inbox');
    $this->assertEquals(3, count($elements));
    $this->assertSession()
      ->pageTextNotContains('Load Previous');
  }

  /**
   * Tests display of a new thread without reloading page.
   */
  public function testNewThread(): void {
    $settings = [
      'thread_count' => 1,
      'ajax_load_count' => 1,
      'ajax_refresh_rate' => 1,
    ];

    $this->drupalPlaceBlock('private_message_inbox_block', $settings);
    $this->drupalLogin($this->users['a']);

    $lastThread = $this->createThreadWithMessages([
      $this->users['a'],
      $this->users['b'],
      $this->users['c'],
    ], $this->users['c']);

    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSession()
      ->elementExists('css', '.private-message-thread-inbox[data-thread-id="' . $lastThread->id() . '"]');
    $elements = $this->getSession()
      ->getPage()
      ->findAll('css', '.private-message-thread-inbox');
    $this->assertEquals(2, count($elements));
  }

}
