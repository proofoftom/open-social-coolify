<?php

namespace Drupal\Tests\waap_login\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\waap_login\Service\WaapSessionValidator;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Tests for WaapSessionValidator.
 *
 * @coversDefaultClass \Drupal\waap_login\Service\WaapSessionValidator
 * @group waap_login
 */
class WaapSessionValidatorTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\waap_login\Service\WaapSessionValidator
   */
  protected $sessionValidator;

  /**
   * Mock session service.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $session;

  /**
   * Mock logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * Mock logger channel.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mock config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mock config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * Mock key-value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $keyValueStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mocks for all dependencies.
    $this->session = $this->createMock('Symfony\Component\HttpFoundation\Session\SessionInterface');
    $this->logger = $this->createMock('Psr\Log\LoggerInterface');
    $this->loggerFactory = $this->createMock('Drupal\Core\Logger\LoggerChannelFactoryInterface');
    $this->config = $this->createMock('Drupal\Core\Config\ImmutableConfig');
    $this->configFactory = $this->createMock('Drupal\Core\Config\ConfigFactoryInterface');
    $this->keyValueStore = $this->createMock('Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface');

    // Configure config factory mock.
    $this->configFactory->method('get')
      ->with('waap_login.settings')
      ->willReturn($this->config);

    // Configure logger factory mock.
    $this->loggerFactory->method('get')
      ->with('waap_login')
      ->willReturn($this->logger);

    // Create the key-value store mock for sessions.
    $mockSessionStore = $this->createMock('Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface');
    $mockSessionStore->method('get')
      ->with('waap_login.sessions')
      ->willReturn($this->keyValueStore);

    // Create the service under test.
    $this->sessionValidator = new WaapSessionValidator(
      $this->session,
      $this->loggerFactory,
      $this->configFactory,
      $mockSessionStore
    );
  }

  /**
   * Tests validateSession() with valid session data.
   *
   * @covers ::validateSession
   */
  public function testValidateSessionValid() {
    $sessionData = [
      'login_type' => 'waap',
      'timestamp' => time(),
      'login_method' => 'email',
      'provider' => 'google',
    ];

    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(86400);

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertTrue($result, 'Valid session should return TRUE');
  }

  /**
   * Tests validateSession() with all valid login types.
   *
   * @covers ::validateSession
   * @dataProvider loginTypeProvider
   */
  public function testValidateSessionValidLoginTypes($loginType) {
    $sessionData = [
      'login_type' => $loginType,
      'timestamp' => time(),
    ];

    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(86400);

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertTrue($result, "Valid login type {$loginType} should return TRUE");
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
   * Tests validateSession() with empty session data.
   *
   * @covers ::validateSession
   */
  public function testValidateSessionEmpty() {
    $sessionData = [];

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertFalse($result, 'Empty session should return FALSE');
  }

  /**
   * Tests validateSession() with missing login_type.
   *
   * @covers ::validateSession
   */
  public function testValidateSessionMissingLoginType() {
    $sessionData = [
      'timestamp' => time(),
    ];

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertFalse($result, 'Session missing login_type should return FALSE');
  }

  /**
   * Tests validateSession() with missing timestamp.
   *
   * @covers ::validateSession
   */
  public function testValidateSessionMissingTimestamp() {
    $sessionData = [
      'login_type' => 'waap',
    ];

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertFalse($result, 'Session missing timestamp should return FALSE');
  }

  /**
   * Tests validateSession() with invalid login type.
   *
   * @covers ::validateSession
   */
  public function testValidateSessionInvalidLoginType() {
    $sessionData = [
      'login_type' => 'invalid_type',
      'timestamp' => time(),
    ];

    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(86400);

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertFalse($result, 'Invalid login type should return FALSE');
  }

  /**
   * Tests validateSession() with expired timestamp.
   *
   * @covers ::validateSession
   */
  public function testValidateSessionExpired() {
    $expiredTimestamp = time() - 86401; // 24 hours + 1 second ago
    $sessionData = [
      'login_type' => 'waap',
      'timestamp' => $expiredTimestamp,
    ];

    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(86400);

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertFalse($result, 'Expired session should return FALSE');
  }

  /**
   * Tests validateSession() with exception handling.
   *
   * @covers ::validateSession
   */
  public function testValidateSessionException() {
    $sessionData = [
      'login_type' => 'waap',
      'timestamp' => time(),
    ];

    $this->config->method('get')
      ->willThrowException(new \Exception('Config error'));

    $result = $this->sessionValidator->validateSession($sessionData);

    $this->assertFalse($result, 'Should return FALSE on exception');
  }

  /**
   * Tests storeSession() with valid data.
   *
   * @covers ::storeSession
   */
  public function testStoreSession() {
    $uid = 123;
    $sessionData = [
      'login_type' => 'waap',
      'login_method' => 'email',
      'provider' => 'google',
      'timestamp' => time(),
    ];

    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(86400);

    $this->keyValueStore->expects($this->once())
      ->method('set')
      ->with($uid, $this->callback(function ($data) use ($sessionData) {
        return isset($data['login_type']) &&
          isset($data['timestamp']) &&
          isset($data['expires']) &&
          $data['login_type'] === $sessionData['login_type'] &&
          $data['timestamp'] === $sessionData['timestamp'];
      }));

    $this->sessionValidator->storeSession($uid, $sessionData);

    // No return value to assert, just verify the method was called.
    $this->assertTrue(TRUE, 'storeSession should complete without error');
  }

  /**
   * Tests storeSession() with default values.
   *
   * @covers ::storeSession
   */
  public function testStoreSessionDefaults() {
    $uid = 123;
    $sessionData = [];

    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(86400);

    $this->keyValueStore->expects($this->once())
      ->method('set')
      ->with($uid, $this->callback(function ($data) {
        return isset($data['login_type']) &&
          $data['login_type'] === 'unknown' &&
          isset($data['login_method']) &&
          $data['login_method'] === 'unknown' &&
          isset($data['timestamp']);
      }));

    $this->sessionValidator->storeSession($uid, $sessionData);

    $this->assertTrue(TRUE, 'storeSession should use defaults');
  }

  /**
   * Tests storeSession() with exception handling.
   *
   * @covers ::storeSession
   */
  public function testStoreSessionException() {
    $uid = 123;
    $sessionData = ['login_type' => 'waap'];

    $this->config->method('get')
      ->willReturn(86400);

    $this->keyValueStore->expects($this->once())
      ->method('set')
      ->willThrowException(new \Exception('Storage error'));

    $this->sessionValidator->storeSession($uid, $sessionData);

    $this->assertTrue(TRUE, 'storeSession should handle exception gracefully');
  }

  /**
   * Tests getSession() with existing session.
   *
   * @covers ::getSession
   */
  public function testGetSessionExisting() {
    $uid = 123;
    $sessionData = [
      'login_type' => 'waap',
      'timestamp' => time(),
      'expires' => time() + 86400,
    ];

    $this->keyValueStore->expects($this->once())
      ->method('get')
      ->with($uid)
      ->willReturn($sessionData);

    $result = $this->sessionValidator->getSession($uid);

    $this->assertEquals($sessionData, $result, 'Should return session data');
  }

  /**
   * Tests getSession() with non-existing session.
   *
   * @covers ::getSession
   */
  public function testGetSessionNotExisting() {
    $uid = 999;

    $this->keyValueStore->expects($this->once())
      ->method('get')
      ->with($uid)
      ->willReturn(NULL);

    $result = $this->sessionValidator->getSession($uid);

    $this->assertNull($result, 'Should return NULL for non-existing session');
  }

  /**
   * Tests getSession() with expired session.
   *
   * @covers ::getSession
   */
  public function testGetSessionExpired() {
    $uid = 123;
    $expiredSession = [
      'login_type' => 'waap',
      'timestamp' => time() - 86401,
      'expires' => time() - 86401,
    ];

    $this->keyValueStore->expects($this->once())
      ->method('get')
      ->with($uid)
      ->willReturn($expiredSession);

    $this->keyValueStore->expects($this->once())
      ->method('delete')
      ->with($uid);

    $result = $this->sessionValidator->getSession($uid);

    $this->assertNull($result, 'Should return NULL and clear expired session');
  }

  /**
   * Tests getSession() with exception handling.
   *
   * @covers ::getSession
   */
  public function testGetSessionException() {
    $uid = 123;

    $this->keyValueStore->expects($this->once())
      ->method('get')
      ->willThrowException(new \Exception('Storage error'));

    $result = $this->sessionValidator->getSession($uid);

    $this->assertNull($result, 'Should return NULL on exception');
  }

  /**
   * Tests clearSession().
   *
   * @covers ::clearSession
   */
  public function testClearSession() {
    $uid = 123;

    $this->keyValueStore->expects($this->once())
      ->method('delete')
      ->with($uid);

    $this->sessionValidator->clearSession($uid);

    $this->assertTrue(TRUE, 'clearSession should complete without error');
  }

  /**
   * Tests clearSession() with exception handling.
   *
   * @covers ::clearSession
   */
  public function testClearSessionException() {
    $uid = 123;

    $this->keyValueStore->expects($this->once())
      ->method('delete')
      ->willThrowException(new \Exception('Storage error'));

    $this->sessionValidator->clearSession($uid);

    $this->assertTrue(TRUE, 'clearSession should handle exception gracefully');
  }

  /**
   * Tests isSessionExpired() protected method via reflection.
   *
   * @covers ::isSessionExpired
   */
  public function testIsSessionExpired() {
    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(86400);

    $reflection = new \ReflectionClass($this->sessionValidator);
    $method = $reflection->getMethod('isSessionExpired');
    $method->setAccessible(TRUE);

    // Test with expired timestamp.
    $expiredTimestamp = time() - 86401;
    $result = $method->invoke($this->sessionValidator, $expiredTimestamp);
    $this->assertTrue($result, 'Should return TRUE for expired timestamp');

    // Test with valid timestamp.
    $validTimestamp = time() - 3600; // 1 hour ago
    $result = $method->invoke($this->sessionValidator, $validTimestamp);
    $this->assertFalse($result, 'Should return FALSE for valid timestamp');

    // Test with boundary timestamp.
    $boundaryTimestamp = time() - 86400; // Exactly TTL seconds ago
    $result = $method->invoke($this->sessionValidator, $boundaryTimestamp);
    $this->assertFalse($result, 'Should return FALSE for boundary timestamp');
  }

  /**
   * Tests isSessionExpired() with zero TTL.
   *
   * @covers ::isSessionExpired
   */
  public function testIsSessionExpiredZeroTtl() {
    $this->config->method('get')
      ->with('session_ttl')
      ->willReturn(0);

    $reflection = new \ReflectionClass($this->sessionValidator);
    $method = $reflection->getMethod('isSessionExpired');
    $method->setAccessible(TRUE);

    $pastTimestamp = time() - 1;
    $result = $method->invoke($this->sessionValidator, $pastTimestamp);

    $this->assertTrue($result, 'Should return TRUE when TTL is zero');
  }

}
