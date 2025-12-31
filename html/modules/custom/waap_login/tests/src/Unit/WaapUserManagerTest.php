<?php

namespace Drupal\Tests\waap_login\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\waap_login\Service\WaapUserManager;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;

/**
 * Tests for WaapUserManager.
 *
 * @coversDefaultClass \Drupal\waap_login\Service\WaapUserManager
 * @group waap_login
 */
class WaapUserManagerTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\waap_login\Service\WaapUserManager
   */
  protected $userManager;

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mock user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $userStorage;

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
   * Mock current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mock language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $languageManager;

  /**
   * Mock password generator.
   *
   * @var \Drupal\Core\Password\PasswordGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $passwordGenerator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mocks for all dependencies.
    $this->entityTypeManager = $this->createMock('Drupal\Core\Entity\EntityTypeManagerInterface');
    $this->userStorage = $this->createMock('Drupal\Core\Entity\EntityStorageInterface');
    $this->logger = $this->createMock('Psr\Log\LoggerInterface');
    $this->loggerFactory = $this->createMock('Drupal\Core\Logger\LoggerChannelFactoryInterface');
    $this->currentUser = $this->createMock('Drupal\Core\Session\AccountProxyInterface');
    $this->languageManager = $this->createMock('Drupal\Core\Language\LanguageManagerInterface');
    $this->passwordGenerator = $this->createMock('Drupal\Core\Password\PasswordGeneratorInterface');

    // Configure entity type manager mock.
    $this->entityTypeManager->method('getStorage')
      ->with('user')
      ->willReturn($this->userStorage);

    // Configure logger factory mock.
    $this->loggerFactory->method('get')
      ->with('waap_login')
      ->willReturn($this->logger);

    // Create the service under test.
    $this->userManager = new WaapUserManager(
      $this->entityTypeManager,
      $this->loggerFactory,
      $this->currentUser,
      $this->languageManager,
      $this->passwordGenerator
    );
  }

  /**
   * Tests findUserByAddress() with existing user.
   *
   * @covers ::findUserByAddress
   */
  public function testFindUserByAddressExisting() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('id')->willReturn(123);

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['field_ethereum_address' => $address])
      ->willReturn([$mockUser]);

    $result = $this->userManager->findUserByAddress($address);

    $this->assertSame($mockUser, $result, 'Should return the found user');
  }

  /**
   * Tests findUserByAddress() with non-existing user.
   *
   * @covers ::findUserByAddress
   */
  public function testFindUserByAddressNotExisting() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['field_ethereum_address' => $address])
      ->willReturn([]);

    $result = $this->userManager->findUserByAddress($address);

    $this->assertNull($result, 'Should return NULL when user not found');
  }

  /**
   * Tests findUserByAddress() with exception handling.
   *
   * @covers ::findUserByAddress
   */
  public function testFindUserByAddressException() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->willThrowException(new \Exception('Database error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to find user by address @address: @message',
        $this->callback(function ($context) use ($address) {
          return $context['@address'] === $address && isset($context['@message']);
        })
      );

    $result = $this->userManager->findUserByAddress($address);

    $this->assertNull($result, 'Should return NULL on exception');
  }

  /**
   * Tests createUser() with valid data.
   *
   * @covers ::createUser
   */
  public function testCreateUserValid() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
    $username = 'testuser';
    $email = 'test@example.com';

    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('save')->willReturn($mockUser);

    $this->userStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function ($user_data) use ($address, $username, $email) {
        return isset($user_data['name']) &&
          isset($user_data['mail']) &&
          isset($user_data['field_ethereum_address']) &&
          $user_data['status'] === 1;
      }))
      ->willReturn($mockUser);

    $mockLanguage = $this->createMock('Drupal\Core\Language\LanguageInterface');
    $mockLanguage->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($mockLanguage);

    $result = $this->userManager->createUser($address, [
      'username' => $username,
      'email' => $email,
    ]);

    $this->assertSame($mockUser, $result, 'Should return created user');
  }

  /**
   * Tests createUser() with auto-generated username.
   *
   * @covers ::createUser
   */
  public function testCreateUserAutoGeneratedUsername() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('save')->willReturn($mockUser);

    $this->userStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function ($user_data) {
        return isset($user_data['name']) &&
          substr($user_data['name'], 0, 10) === 'waap_742d35';
      }))
      ->willReturn($mockUser);

    $mockLanguage = $this->createMock('Drupal\Core\Language\LanguageInterface');
    $mockLanguage->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($mockLanguage);

    $result = $this->userManager->createUser($address);

    $this->assertSame($mockUser, $result, 'Should return created user with auto-generated username');
  }

  /**
   * Tests createUser() with auto-generated email.
   *
   * @covers ::createUser
   */
  public function testCreateUserAutoGeneratedEmail() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('save')->willReturn($mockUser);

    $this->userStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function ($user_data) use ($address) {
        return isset($user_data['mail']) &&
          $user_data['mail'] === '742d35cc6634@ethereum.local';
      }))
      ->willReturn($mockUser);

    $mockLanguage = $this->createMock('Drupal\Core\Language\LanguageInterface');
    $mockLanguage->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($mockLanguage);

    $result = $this->userManager->createUser($address);

    $this->assertSame($mockUser, $result, 'Should return created user with auto-generated email');
  }

  /**
   * Tests createUser() with exception handling.
   *
   * @covers ::createUser
   */
  public function testCreateUserException() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $this->userStorage->expects($this->once())
      ->method('create')
      ->willThrowException(new \Exception('Database error'));

    $mockLanguage = $this->createMock('Drupal\Core\Language\LanguageInterface');
    $mockLanguage->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($mockLanguage);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to create user for address @address: @message',
        $this->callback(function ($context) use ($address) {
          return $context['@address'] === $address && isset($context['@message']);
        })
      );

    $result = $this->userManager->createUser($address);

    $this->assertNull($result, 'Should return NULL on exception');
  }

  /**
   * Tests findOrCreateUser() with existing user.
   *
   * @covers ::findOrCreateUser
   */
  public function testFindOrCreateUserExisting() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
    $mockUser = $this->createMock('Drupal\user\UserInterface');

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['field_ethereum_address' => $address])
      ->willReturn([$mockUser]);

    $result = $this->userManager->findOrCreateUser($address);

    $this->assertSame($mockUser, $result, 'Should return existing user');
  }

  /**
   * Tests findOrCreateUser() with new user creation.
   *
   * @covers ::findOrCreateUser
   */
  public function testFindOrCreateUserNew() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('save')->willReturn($mockUser);

    $this->userStorage->expects($this->atMost(2))
      ->method('loadByProperties')
      ->with(['field_ethereum_address' => $address])
      ->willReturn([]);

    $this->userStorage->expects($this->once())
      ->method('create')
      ->willReturn($mockUser);

    $mockLanguage = $this->createMock('Drupal\Core\Language\LanguageInterface');
    $mockLanguage->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($mockLanguage);

    $result = $this->userManager->findOrCreateUser($address);

    $this->assertSame($mockUser, $result, 'Should return newly created user');
  }

  /**
   * Tests generateUsername() with valid address.
   *
   * @covers ::generateUsername
   */
  public function testGenerateUsername() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['name' => 'waap_742d35'])
      ->willReturn([]);

    $result = $this->userManager->generateUsername($address);

    $this->assertEquals('waap_742d35', $result, 'Should generate username from address');
  }

  /**
   * Tests generateUsername() with collision handling.
   *
   * @covers ::generateUsername
   */
  public function testGenerateUsernameCollisionHandling() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    // First call returns existing user, second call doesn't.
    $this->userStorage->expects($this->exactly(2))
      ->method('loadByProperties')
      ->willReturnCallback(function ($args) {
        if ($args[0]['name'] === 'waap_742d35') {
          return [$this->createMock('Drupal\user\UserInterface')];
        }
        return [];
      });

    $result = $this->userManager->generateUsername($address);

    $this->assertEquals('waap_742d35_1', $result, 'Should handle username collision');
  }

  /**
   * Tests generateUsername() with multiple collisions.
   *
   * @covers ::generateUsername
   */
  public function testGenerateUsernameMultipleCollisions() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    // Simulate multiple collisions.
    $callCount = 0;
    $this->userStorage->expects($this->atLeastOnce())
      ->method('loadByProperties')
      ->willReturnCallback(function ($args) use (&$callCount) {
        $callCount++;
        if ($callCount <= 3) {
          return [$this->createMock('Drupal\user\UserInterface')];
        }
        return [];
      });

    $result = $this->userManager->generateUsername($address);

    $this->assertEquals('waap_742d35_3', $result, 'Should handle multiple collisions');
  }

  /**
   * Tests generateUsername() with lowercase address.
   *
   * @covers ::generateUsername
   */
  public function testGenerateUsernameLowercaseAddress() {
    $address = '0xfb6916095ca1df60bb79ce92ce3ea74c37c5d359';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['name' => 'waap_fb6916'])
      ->willReturn([]);

    $result = $this->userManager->generateUsername($address);

    $this->assertEquals('waap_fb6916', $result, 'Should generate username from lowercase address');
  }

  /**
   * Tests generateUsername() with address without 0x prefix.
   *
   * @covers ::generateUsername
   */
  public function testGenerateUsernameNoPrefix() {
    $address = '742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['name' => 'waap_742d35'])
      ->willReturn([]);

    $result = $this->userManager->generateUsername($address);

    $this->assertEquals('waap_742d35', $result, 'Should handle address without 0x prefix');
  }

  /**
   * Tests isGeneratedUsername() with generated username.
   *
   * @covers ::isGeneratedUsername
   */
  public function testIsGeneratedUsernameTrue() {
    $username = 'waap_742d35';
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $result = $this->userManager->isGeneratedUsername($username, $address);

    $this->assertTrue($result, 'Should return TRUE for generated username');
  }

  /**
   * Tests isGeneratedUsername() with generated username with counter.
   *
   * @covers ::isGeneratedUsername
   */
  public function testIsGeneratedUsernameWithCounter() {
    $username = 'waap_742d35_2';
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $result = $this->userManager->isGeneratedUsername($username, $address);

    $this->assertTrue($result, 'Should return TRUE for generated username with counter');
  }

  /**
   * Tests isGeneratedUsername() with custom username.
   *
   * @covers ::isGeneratedUsername
   */
  public function testIsGeneratedUsernameFalse() {
    $username = 'alice';
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $result = $this->userManager->isGeneratedUsername($username, $address);

    $this->assertFalse($result, 'Should return FALSE for custom username');
  }

  /**
   * Tests isGeneratedUsername() with wrong address.
   *
   * @covers ::isGeneratedUsername
   */
  public function testIsGeneratedUsernameWrongAddress() {
    $username = 'waap_742d35';
    $address = '0x1234567890abcdef1234567890abcdef1234567890';

    $result = $this->userManager->isGeneratedUsername($username, $address);

    $this->assertFalse($result, 'Should return FALSE for wrong address');
  }

  /**
   * Tests isGeneratedUsername() with empty address.
   *
   * @covers ::isGeneratedUsername
   */
  public function testIsGeneratedUsernameEmptyAddress() {
    $username = 'waap_742d35';
    $address = '';

    $result = $this->userManager->isGeneratedUsername($username, $address);

    $this->assertFalse($result, 'Should return FALSE for empty address');
  }

  /**
   * Tests updateWalletAddress() with valid update.
   *
   * @covers ::updateWalletAddress
   */
  public function testUpdateWalletAddress() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('save')->willReturn($mockUser);
    $mockUser->method('id')->willReturn(123);

    $result = $this->userManager->updateWalletAddress($mockUser, $address);

    $this->assertTrue($result, 'Should return TRUE on successful update');

    $mockUser->expects($this->once())
      ->method('set')
      ->with('field_ethereum_address', $address);
  }

  /**
   * Tests updateWalletAddress() with exception handling.
   *
   * @covers ::updateWalletAddress
   */
  public function testUpdateWalletAddressException() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('save')->willThrowException(new \Exception('Database error'));
    $mockUser->method('id')->willReturn(123);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to update wallet address for user @uid: @message',
        $this->callback(function ($context) {
          return $context['@uid'] === 123 && isset($context['@message']);
        })
      );

    $result = $this->userManager->updateWalletAddress($mockUser, $address);

    $this->assertFalse($result, 'Should return FALSE on exception');
  }

  /**
   * Tests generateEmail() protected method via reflection.
   *
   * @covers ::generateEmail
   */
  public function testGenerateEmail() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $reflection = new \ReflectionClass($this->userManager);
    $method = $reflection->getMethod('generateEmail');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->userManager, $address);

    $this->assertEquals('742d35cc6634@ethereum.local', $result, 'Should generate email from address');
  }

  /**
   * Tests generateEmail() with lowercase address.
   *
   * @covers ::generateEmail
   */
  public function testGenerateEmailLowercase() {
    $address = '0xfb6916095ca1df60bb79ce92ce3ea74c37c5d359';

    $reflection = new \ReflectionClass($this->userManager);
    $method = $reflection->getMethod('generateEmail');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->userManager, $address);

    $this->assertEquals('fb6916095ca1@ethereum.local', $result, 'Should generate email from lowercase address');
  }

  /**
   * Tests generateEmail() with address without 0x prefix.
   *
   * @covers ::generateEmail
   */
  public function testGenerateEmailNoPrefix() {
    $address = '742d35Cc6634C0532925a3b844Bc9e7595f0bEb';

    $reflection = new \ReflectionClass($this->userManager);
    $method = $reflection->getMethod('generateEmail');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->userManager, $address);

    $this->assertEquals('742d35cc6634@ethereum.local', $result, 'Should generate email from address without prefix');
  }

  /**
   * Tests usernameExists() protected method via reflection.
   *
   * @covers ::usernameExists
   */
  public function testUsernameExistsTrue() {
    $username = 'existinguser';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['name' => $username])
      ->willReturn([$this->createMock('Drupal\user\UserInterface')]);

    $reflection = new \ReflectionClass($this->userManager);
    $method = $reflection->getMethod('usernameExists');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->userManager, $username);

    $this->assertTrue($result, 'Should return TRUE for existing username');
  }

  /**
   * Tests usernameExists() with non-existing username.
   *
   * @covers ::usernameExists
   */
  public function testUsernameExistsFalse() {
    $username = 'nonexistentuser';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['name' => $username])
      ->willReturn([]);

    $reflection = new \ReflectionClass($this->userManager);
    $method = $reflection->getMethod('usernameExists');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->userManager, $username);

    $this->assertFalse($result, 'Should return FALSE for non-existing username');
  }

  /**
   * Tests usernameExists() with exception handling.
   *
   * @covers ::usernameExists
   */
  public function testUsernameExistsException() {
    $username = 'testuser';

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->willThrowException(new \Exception('Database error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to check username existence for @username: @message',
        $this->callback(function ($context) use ($username) {
          return $context['@username'] === $username && isset($context['@message']);
        })
      );

    $reflection = new \ReflectionClass($this->userManager);
    $method = $reflection->getMethod('usernameExists');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->userManager, $username);

    $this->assertFalse($result, 'Should return FALSE on exception');
  }

  /**
   * Tests findOrCreateUser() with custom data.
   *
   * @covers ::findOrCreateUser
   */
  public function testFindOrCreateUserWithCustomData() {
    $address = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
    $customUsername = 'customuser';
    $customEmail = 'custom@example.com';

    $mockUser = $this->createMock('Drupal\user\UserInterface');
    $mockUser->method('save')->willReturn($mockUser);

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['field_ethereum_address' => $address])
      ->willReturn([]);

    $this->userStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function ($user_data) use ($customUsername, $customEmail, $address) {
        return $user_data['name'] === $customUsername &&
          $user_data['mail'] === $customEmail &&
          $user_data['field_ethereum_address'] === $address;
      }))
      ->willReturn($mockUser);

    $mockLanguage = $this->createMock('Drupal\Core\Language\LanguageInterface');
    $mockLanguage->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($mockLanguage);

    $result = $this->userManager->findOrCreateUser($address, [
      'username' => $customUsername,
      'email' => $customEmail,
    ]);

    $this->assertSame($mockUser, $result, 'Should return user with custom data');
  }

}
