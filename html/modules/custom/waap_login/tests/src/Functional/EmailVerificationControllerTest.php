<?php

namespace Drupal\Tests\waap_login\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\UserAuthInterface;

/**
 * Tests for EmailVerificationController.
 *
 * @coversDefaultClass \Drupal\waap_login\Controller\EmailVerificationController
 * @group waap_login
 */
class EmailVerificationControllerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['waap_login', 'user', 'system'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A test user for email verification.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $testUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a test user with ethereum address field.
    $this->testUser = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with valid token.
   *
   * @covers ::confirm
   */
  public function testConfirmValidToken() {
    $uid = $this->testUser->id();
    $timestamp = time();
    $email = 'test@example.com';

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt);

    // Store email in temp store.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => $email,
      'created' => $timestamp,
    ]);

    // Visit verification URL.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    // Should redirect to user page.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/user/' . $uid);

    // Check success message.
    $this->assertSession()->pageTextContains('Email verified successfully!');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with invalid UID.
   *
   * @covers ::confirm
   */
  public function testConfirmInvalidUid() {
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => 'invalid',
      'timestamp' => time(),
      'hash' => 'validhash',
    ]));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Invalid verification link.');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with invalid timestamp.
   *
   * @covers ::confirm
   */
  public function testConfirmInvalidTimestamp() {
    $uid = $this->testUser->id();
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => 'invalid',
      'hash' => 'validhash',
    ]));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Invalid verification link.');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with invalid hash.
   *
   * @covers ::confirm
   */
  public function testConfirmInvalidHash() {
    $uid = $this->testUser->id();
    $timestamp = time();
    $email = 'test@example.com';

    // Store email in temp store.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => $email,
      'created' => $timestamp,
    ]);

    // Generate different hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt);
    $invalidHash = md5('wrong' . $expectedHash);

    // Visit verification URL with invalid hash.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $invalidHash,
    ]));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Invalid verification link.');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with expired token.
   *
   * @covers ::confirm
   */
  public function testConfirmExpiredToken() {
    $uid = $this->testUser->id();
    $expiredTimestamp = time() - 86401; // 24 hours + 1 second ago

    // Store email in temp store with old timestamp.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => 'test@example.com',
      'created' => $expiredTimestamp,
    ]);

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $expiredTimestamp . ':' . 'test@example.com' . ':' . $hashSalt);

    // Visit verification URL with expired token.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $expiredTimestamp,
      'hash' => $expectedHash,
    ]));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Verification link has expired.');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with missing temp store data.
   *
   * @covers ::confirm
   */
  public function testConfirmMissingTempStoreData() {
    $uid = $this->testUser->id();
    $timestamp = time();

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . 'test@example.com' . ':' . $hashSalt);

    // Visit verification URL without temp store data.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Invalid verification link.');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with non-existent user.
   *
   * @covers ::confirm
   */
  public function testConfirmNonExistentUser() {
    $uid = 999999;
    $timestamp = time();

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . 'test@example.com' . ':' . $hashSalt);

    // Visit verification URL with non-existent user.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Invalid verification link.');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} redirects to user page on success.
   *
   * @covers ::confirm
   */
  public function testConfirmRedirectsToUserPage() {
    $uid = $this->testUser->id();
    $timestamp = time();
    $email = 'test@example.com';

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt);

    // Store email in temp store.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => $email,
      'created' => $timestamp,
    ]);

    // Visit verification URL.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    // Should redirect to user page.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/user/' . $uid);
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with username requirement.
   *
   * @covers ::confirm
   */
  public function testConfirmWithUsernameRequirement() {
    $uid = $this->testUser->id();
    $timestamp = time();
    $email = 'test@example.com';

    // Enable username requirement.
    $this->config('waap_login.settings')
      ->set('require_username', TRUE)
      ->save();

    // Store email in temp store.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => $email,
      'created' => $timestamp,
    ]);

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt);

    // Visit verification URL.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    // Should redirect to username creation form.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Please create a username');
    $this->assertSession()->addressMatches('/waap/create-username');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with already verified user.
   *
   * @covers ::confirm
   */
  public function testConfirmAlreadyVerifiedUser() {
    $uid = $this->testUser->id();
    $timestamp = time();
    $email = 'test@example.com';

    // Set user email.
    $this->testUser->setEmail($email)->save();

    // Store email in temp store.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => $email,
      'created' => $timestamp,
    ]);

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt);

    // Visit verification URL.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    // Should redirect to user page.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/user/' . $uid);
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with generated username.
   *
   * @covers ::confirm
   */
  public function testConfirmWithGeneratedUsername() {
    $uid = $this->testUser->id();
    $timestamp = time();
    $email = 'test@example.com';

    // User has auto-generated username.
    $this->testUser->set('name', 'waap_742d35')->save();

    // Store email in temp store.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => $email,
      'created' => $timestamp,
    ]);

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt);

    // Visit verification URL.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    // Should redirect to user page.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/user/' . $uid);
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} error handling.
   *
   * @covers ::confirm
   */
  public function testConfirmErrorHandling() {
    $uid = $this->testUser->id();
    $timestamp = time();

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . 'test@example.com' . ':' . $hashSalt);

    // Mock temp store to throw exception.
    $tempStore = $this->createMock('Drupal\Core\TempStore\PrivateTempStore');
    $tempStore->method('get')
      ->willThrowException(new \Exception('Temp store error'));

    $this->container->set('user.private_tempstore', $tempStore);

    // Visit verification URL.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    // Should show error message.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('An error occurred during email verification');
  }

  /**
   * Tests GET /waap/email-verification/{uid}/{timestamp}/{hash} with hash comparison using hash_equals.
   *
   * @covers ::confirm
   */
  public function testConfirmHashComparison() {
    $uid = $this->testUser->id();
    $timestamp = time();
    $email = 'test@example.com';

    // Store email in temp store.
    $tempStore = $this->container->get('user.private_tempstore')->get('waap_login');
    $tempStore->set('email_verification_' . $uid, [
      'email' => $email,
      'created' => $timestamp,
    ]);

    // Generate valid hash.
    $hashSalt = $this->config('system.site')->get('hash_salt');
    $expectedHash = md5($uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt);

    // Visit verification URL with valid hash.
    $this->drupalGet(Url::fromRoute('waap_login.email_verification_confirm', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $expectedHash,
    ]));

    // Should redirect to user page.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/user/' . $uid);
  }

}
