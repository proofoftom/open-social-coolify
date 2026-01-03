<?php

declare(strict_types=1);

namespace Drupal\Tests\private_message\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\private_message\Traits\PrivateMessageTestTrait;
use Drupal\private_message\Entity\PrivateMessage;
use Drupal\private_message\Entity\PrivateMessageThread;

/**
 * JS tests for Private Message functionalities.
 *
 * @group private_message
 */
class PrivateMessageJavascriptTest extends WebDriverTestBase {

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
  protected function setUp(): void {
    parent::setUp();
    $this->attachFullNameField();
    $this->createTestingUsers(3);
  }

  /**
   * Tests threads and inbox.
   */
  public function testThreadsInbox(): void {
    $this->drupalPlaceBlock('private_message_inbox_block');
    $this->drupalLogin($this->users['a']);

    // Create 2 threads.
    PrivateMessageThread::create([
      'members' => [$this->users['a'], $this->users['b']],
      'subject' => $this->getRandomGenerator()->word(10),
      // Set updated time for threads to be ordered in a predictable way
      // (sort granularity: seconds).
      'updated' => \Drupal::time()->getRequestTime() - 3600,
      'private_messages' => [
        PrivateMessage::create([
          'owner' => $this->users['a'],
          'message' => [
            'value' => $this->getRandomGenerator()->sentences(5),
            'format' => 'plain_text',
          ],
        ]),
      ],
    ])->save();

    PrivateMessageThread::create([
      'members' => [$this->users['a'], $this->users['c']],
      'subject' => $this->getRandomGenerator()->word(10),
      'updated' => \Drupal::time()->getRequestTime(),
      'private_messages' => [
        PrivateMessage::create([
          'owner' => $this->users['a'],
          'message' => [
            'value' => $this->getRandomGenerator()->sentences(5),
            'format' => 'plain_text',
          ],
        ]),
      ],
    ])->save();

    $threads_map = [
      1 => $this->users['b'],
      2 => $this->users['c'],
    ];

    // Test loading threads by clicking inbox links.
    $this->drupalGet('/private-messages');
    foreach ($threads_map as $threadId => $expectedUser) {
      $this->click(".private-message-thread-inbox[data-thread-id=$threadId] .private-message-inbox-thread-link");
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->elementTextContains('css', '#private-message-page .private-message-recipients article:nth-of-type(1) .username', $expectedUser->getDisplayName());
    }

    // Test loading threads with JS.
    $this->drupalGet('/private-messages');
    foreach ($threads_map as $threadId => $expectedUser) {
      $this->getSession()->executeScript("Drupal.PrivateMessages.loadThread({$threadId});");
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->elementTextContains('css', '#private-message-page .private-message-recipients article:nth-of-type(1) .username', $expectedUser->getDisplayName());
    }

    // Test loading new messages with JS.
    $this->drupalGet('/private-messages/1');
    $message = PrivateMessage::create([
      'owner' => $this->users['b'],
      'message' => [
        'value' => 'Response from user B',
        'format' => 'plain_text',
      ],
    ]);
    $message->save();
    PrivateMessageThread::load(1)->addMessage($message)->save();
    $this->getSession()->executeScript('Drupal.PrivateMessages.getNewMessages();');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementTextContains('css', '.private-message-thread-messages', 'Response from user B');

    // Test loading old messages with JS (load previous).
    $thread = PrivateMessageThread::load(1);
    for ($i = 0; $i < 20; $i++) {
      $message = PrivateMessage::create([
        'owner' => $this->users['b'],
        'message' => [
          'value' => $this->getRandomGenerator()->sentences(5),
          'format' => 'plain_text',
        ],
      ]);
      $message->save();
      $thread->addMessage($message);
    }
    $thread->save();
    $this->drupalGet('/private-messages/1');
    // Default message_count: 7.
    $this->assertSession()->elementsCount('css', '.private-message-thread-messages .private-message', 7);
    $load_previous = $this->assertSession()->elementExists('css', '.private-message-thread-messages #load-previous-messages');
    $load_previous->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    // Default ajax_previous_load_count: 5.
    $this->assertSession()->elementsCount('css', '.private-message-thread-messages .private-message', 12);

    // Test loading new thread with JS.
    $this->drupalGet('/private-messages');
    PrivateMessageThread::create([
      'members' => array_values($this->users),
      'subject' => 'Hi all',
      'private_messages' => [
        PrivateMessage::create([
          'owner' => $this->users['b'],
          'message' => [
            'value' => 'Howdy all, I am user B.',
            'format' => 'plain_text',
          ],
        ]),
      ],
    ])->save();
    $this->getSession()->executeScript('Drupal.PrivateMessageInbox.updateInbox();');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementsCount('css', '.block-private-message-inbox-block .private-message-thread', 3);
    $this->assertSession()->elementTextContains('css', '.block-private-message-inbox-block', 'Howdy all, I am user B.');

    // Test loading old inbox threads with JS (load previous).
    for ($i = 0; $i < 10; $i++) {
      PrivateMessageThread::create([
        'members' => array_values($this->users),
        'subject' => $this->getRandomGenerator()->word(10),
        // Ensure threads have different timestamps.
        'updated' => \Drupal::time()->getRequestTime() - 3600 + $i,
        'private_messages' => [
          PrivateMessage::create([
            'owner' => $this->users['b'],
            'message' => [
              'value' => $this->getRandomGenerator()->sentences(5),
              'format' => 'plain_text',
            ],
          ]),
        ],
      ])->save();
    }
    $this->drupalGet('/private-messages');
    $this->assertSession()->elementsCount('css', '.block-private-message-inbox-block .private-message-thread', 5);
    $load_previous = $this->assertSession()->elementExists('css', '.block-private-message-inbox-block #load-previous-threads-button');
    $load_previous->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementsCount('css', '.block-private-message-inbox-block .private-message-thread', 10);
  }

  /**
   * Tests submission after message deletion.
   */
  public function testSubmissionAfterMessageDeletion(): void {
    $thread = $this->createThreadWithMessages([
      $this->users['a'],
      $this->users['b'],
    ], $this->users['b'], 2);

    $this->drupalLogin($this->users['a']);
    $this->drupalGet('private-messages');

    // Delete a previous message.
    $messages = $thread->getMessages();
    $firstMessage = reset($messages);
    $firstMessage->delete();

    $helloTest = 'Hello a user!';
    $this->getSession()->getPage()->fillField('Message', $helloTest);
    $this->getSession()->getPage()->pressButton('Send');

    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()
      ->pageTextContains($helloTest);
  }

}
