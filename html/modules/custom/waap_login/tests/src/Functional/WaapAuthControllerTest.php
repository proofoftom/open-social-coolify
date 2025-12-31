<?php

namespace Drupal\Tests\waap_login\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\waap_login\Service\WaapAuthService;
use Drupal\user\UserInterface;

/**
 * Tests for WaapAuthController.
 *
 * @coversDefaultClass \Drupal\waap_login\Controller\WaapAuthController
 * @group waap_login
 */
class WaapAuthControllerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['waap_login', 'user', 'system'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An authenticated user for testing.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $authenticatedUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a test user with ethereum address field.
    $this->authenticatedUser = $this->createUser([
      'name' => 'waap_test_user',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    $this->drupalPlaceBlock('waap_login_block', 'content');
  }

  /**
   * Tests POST /waap/verify with valid authentication.
   *
   * @covers ::verify
   */
  public function testVerifyValidAuthentication() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
    $loginType = 'waap';

    $this->drupalLogin($this->authenticatedUser);

    $response = $this->drupalPostJson('/waap/verify', [
      'address' => $address,
      'loginType' => $loginType,
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertArrayHasKey('user', $data);
    $this->assertEquals(200, $this->getSession()->getPage()->getStatusCode());
  }

  /**
   * Tests POST /waap/verify with invalid JSON.
   *
   * @covers ::verify
   */
  public function testVerifyInvalidJson() {
    $response = $this->drupalPostRaw('/waap/verify', 'invalid json');

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('INVALID_JSON', $data['code'] ?? '');
  }

  /**
   * Tests POST /waap/verify with missing address.
   *
   * @covers ::verify
   */
  public function testVerifyMissingAddress() {
    $response = $this->drupalPostJson('/waap/verify', [
      'loginType' => 'waap',
    ]);

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('MISSING_ADDRESS', $data['code'] ?? '');
    $this->assertEquals('Wallet address is required', $data['error'] ?? '');
  }

  /**
   * Tests POST /waap/verify with missing login type.
   *
   * @covers ::verify
   */
  public function testVerifyMissingLoginType() {
    $response = $this->drupalPostJson('/waap/verify', [
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    ]);

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('MISSING_LOGIN_TYPE', $data['code'] ?? '');
    $this->assertEquals('Login type is required', $data['error'] ?? '');
  }

  /**
   * Tests POST /waap/verify with invalid login type.
   *
   * @covers ::verify
   */
  public function testVerifyInvalidLoginType() {
    $response = $this->drupalPostJson('/waap/verify', [
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'loginType' => 'invalid_type',
    ]);

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('INVALID_LOGIN_TYPE', $data['code'] ?? '');
  }

  /**
   * Tests POST /waap/verify with invalid address format.
   *
   * @covers ::verify
   */
  public function testVerifyInvalidAddressFormat() {
    $response = $this->drupalPostJson('/waap/verify', [
      'address' => 'invalid_address',
      'loginType' => 'waap',
    ]);

    $this->assertSession()->statusCodeEquals(401);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('AUTH_FAILED', $data['code'] ?? '');
  }

  /**
   * Tests POST /waap/verify with all valid login types.
   *
   * @covers ::verify
   * @dataProvider loginTypeProvider
   */
  public function testVerifyValidLoginTypes($loginType) {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $this->drupalLogin($this->authenticatedUser);

    $response = $this->drupalPostJson('/waap/verify', [
      'address' => $address,
      'loginType' => $loginType,
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertArrayHasKey('user', $data);
  }

  /**
   * Data provider for login type tests.
   *
   * @return array
   *   Test cases for login types.
   */
  public function loginTypeProvider(): array {
    return [
      'waap' => ['waap'],
      'injected' => ['injected'],
      'walletconnect' => ['walletconnect'],
    ];
  }

  /**
   * Tests POST /waap/verify with email verification redirect.
   *
   * @covers ::verify
   */
  public function testVerifyEmailVerificationRedirect() {
    // Enable email verification requirement.
    $this->config('waap_login.settings')
      ->set('require_email_verification', TRUE)
      ->set('auto_create_users', TRUE)
      ->save();

    // Create a user without email.
    $userWithoutEmail = $this->createUser([
      'name' => 'waap_no_email',
      'mail' => 'noemail@example.com',
      'field_ethereum_address' => '0x1234567890abcdef1234567890abcdef1234567890',
      'status' => 1,
    ]);

    $this->drupalLogin($userWithoutEmail);

    $response = $this->drupalPostJson('/waap/verify', [
      'address' => '0x1234567890abcdef1234567890abcdef1234567890',
      'loginType' => 'waap',
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertArrayHasKey('redirect', $data);
    $this->assertEquals('/waap/email-verification', $data['redirect'] ?? '');
    $this->assertEquals('email_verification', $data['next_step'] ?? '');
  }

  /**
   * Tests POST /waap/verify with username creation redirect.
   *
   * @covers ::verify
   */
  public function testVerifyUsernameCreationRedirect() {
    // Enable username requirement.
    $this->config('waap_login.settings')
      ->set('require_username', TRUE)
      ->set('require_email_verification', FALSE)
      ->set('auto_create_users', TRUE)
      ->save();

    // Create a user with auto-generated username.
    $userWithGeneratedUsername = $this->createUser([
      'name' => 'waap_742d35',
      'mail' => 'test@example.com',
      'field_ethereum_address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'status' => 1,
    ]);

    $this->drupalLogin($userWithGeneratedUsername);

    $response = $this->drupalPostJson('/waap/verify', [
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'loginType' => 'waap',
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertArrayHasKey('redirect', $data);
    $this->assertEquals('/waap/create-username', $data['redirect'] ?? '');
    $this->assertEquals('username_creation', $data['next_step'] ?? '');
  }

  /**
   * Tests GET /waap/status for authenticated user.
   *
   * @covers ::getStatus
   */
  public function testGetStatusAuthenticated() {
    $this->drupalLogin($this->authenticatedUser);

    $response = $this->drupalGet('/waap/status');

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['authenticated'] ?? FALSE);
    $this->assertArrayHasKey('user', $data);
    $this->assertEquals($this->authenticatedUser->id(), $data['user']['uid'] ?? 0);
    $this->assertEquals('waap_test_user', $data['user']['name'] ?? '');
    $this->assertEquals('0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb', $data['user']['address'] ?? '');
  }

  /**
   * Tests GET /waap/status for anonymous user.
   *
   * @covers ::getStatus
   */
  public function testGetStatusAnonymous() {
    $this->drupalLogout();

    $response = $this->drupalGet('/waap/status');

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['authenticated'] ?? TRUE);
    $this->assertArrayHasKey('message', $data);
    $this->assertEquals('No active session', $data['message'] ?? '');
  }

  /**
   * Tests POST /waap/logout for authenticated user.
   *
   * @covers ::logout
   */
  public function testLogoutAuthenticated() {
    $this->drupalLogin($this->authenticatedUser);

    $response = $this->drupalPostJson('/waap/logout', []);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertEquals('Logout successful', $data['message'] ?? '');

    // Verify user is logged out.
    $this->assertSession()->addressNotEquals($this->authenticatedUser->id());
  }

  /**
   * Tests POST /waap/logout for anonymous user.
   *
   * @covers ::logout
   */
  public function testLogoutAnonymous() {
    $this->drupalLogout();

    $response = $this->drupalPostJson('/waap/logout', []);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertEquals('No active session to logout', $data['message'] ?? '');
  }

  /**
   * Tests POST /waap/verify with malformed JSON.
   *
   * @covers ::verify
   */
  public function testVerifyMalformedJson() {
    $response = $this->drupalPostRaw('/waap/verify', '{"invalid": json}');

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('INVALID_JSON', $data['code'] ?? '');
  }

  /**
   * Tests POST /waap/verify with empty JSON.
   *
   * @covers ::verify
   */
  public function testVerifyEmptyJson() {
    $response = $this->drupalPostRaw('/waap/verify', '{}');

    $this->assertSession()->statusCodeEquals(400);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
  }

  /**
   * Tests POST /waap/verify with session data.
   *
   * @covers ::verify
   */
  public function testVerifyWithSessionData() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
    $sessionData = [
      'loginMethod' => 'email',
      'provider' => 'google',
    ];

    $this->drupalLogin($this->authenticatedUser);

    $response = $this->drupalPostJson('/waap/verify', [
      'address' => $address,
      'loginType' => 'waap',
      'sessionData' => $sessionData,
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertArrayHasKey('user', $data);
  }

  /**
   * Tests POST /waap/verify with new user creation.
   *
   * @covers ::verify
   */
  public function testVerifyNewUserCreation() {
    $newAddress = '0x1234567890abcdef1234567890abcdef1234567890';

    $this->drupalLogin($this->authenticatedUser);

    $response = $this->drupalPostJson('/waap/verify', [
      'address' => $newAddress,
      'loginType' => 'waap',
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertTrue($data['success'] ?? FALSE);
    $this->assertArrayHasKey('user', $data);

    // Verify new user was created.
    $newUser = \Drupal::entityTypeManager()->getStorage('user')
      ->loadByProperties(['name' => 'waap_123456']);
    $this->assertNotNull($newUser, 'New user should be created');
  }

  /**
   * Tests POST /waap/verify returns proper JSON structure.
   *
   * @covers ::verify
   */
  public function testVerifyJsonStructure() {
    $this->drupalLogin($this->authenticatedUser);

    $response = $this->drupalPostJson('/waap/verify', [
      'address' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
      'loginType' => 'waap',
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $data = json_decode($response, TRUE);

    $this->assertIsArray($data);
    $this->assertArrayHasKey('success', $data);
    $this->assertArrayHasKey('user', $data);
    $this->assertArrayHasKey('message', $data);
    $this->assertArrayHasKey('next_step', $data);

    $this->assertArrayHasKey('uid', $data['user']);
    $this->assertArrayHasKey('name', $data['user']);
    $this->assertArrayHasKey('email', $data['user']);
  }

  /**
   * Tests POST /waap/verify with flood control.
   *
   * @covers ::verify
   */
  public function testVerifyFloodControl() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    // Make multiple requests to trigger flood control.
    for ($i = 0; $i < 6; $i++) {
      $this->drupalPostJson('/waap/verify', [
        'address' => $address,
        'loginType' => 'waap',
      ]);
    }

    // The 6th request should trigger flood control.
    $response = $this->drupalPostJson('/waap/verify', [
      'address' => $address,
      'loginType' => 'waap',
    ]);

    $this->assertSession()->statusCodeEquals(429);
    $data = json_decode($response, TRUE);

    $this->assertFalse($data['success'] ?? TRUE);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('RATE_LIMIT_EXCEEDED', $data['code'] ?? '');
  }

}
