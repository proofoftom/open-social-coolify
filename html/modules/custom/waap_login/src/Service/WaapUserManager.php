<?php

namespace Drupal\waap_login\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\user\UserInterface;

/**
 * Manages WaaP user accounts and wallet address linking.
 *
 * This service handles user account creation, wallet address linking,
 * username generation, and user field updates. It uses the existing
 * field_ethereum_address field from the siwe_login module for
 * compatibility.
 */
class WaapUserManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel for WaaP login.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The password generator.
   *
   * @var \Drupal\Core\Password\PasswordGeneratorInterface
   */
  protected $passwordGenerator;

  /**
   * Constructs a new WaapUserManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Password\PasswordGeneratorInterface $password_generator
   *   The password generator.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager,
    PasswordGeneratorInterface $password_generator
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('waap_login');
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->passwordGenerator = $password_generator;
  }

  /**
   * Finds a user by Ethereum wallet address.
   *
   * This method searches for an existing user with the given
   * wallet address stored in the field_ethereum_address field.
   *
   * @param string $address
   *   Ethereum wallet address (checksummed).
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity if found, NULL otherwise.
   */
  public function findUserByAddress(string $address): ?UserInterface {
    try {
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['field_ethereum_address' => $address]);

      return $users ? reset($users) : NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to find user by address @address: @message', [
        '@address' => $address,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Creates a new user with wallet address.
   *
   * @param string $address
   *   Ethereum wallet address.
   * @param array $data
   *   Additional user data (email, username, etc.).
   *
   * @return \Drupal\user\UserInterface|null
   *   The created user entity, or NULL on failure.
   */
  public function createUser(string $address, array $data = []): ?UserInterface {
    try {
      $user_storage = $this->entityTypeManager->getStorage('user');

      // Generate username from address if not provided.
      $username = $data['username'] ?? $this->generateUsername($address);

      // Generate email from address if not provided.
      $email = $data['email'] ?? $this->generateEmail($address);

      $user = $user_storage->create([
        'name' => $username,
        'mail' => $email,
        'field_ethereum_address' => $address,
        'status' => 1,
        'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
      ]);

      $user->save();

      $this->logger->info('Created new user <strong>@name</strong> for Ethereum address @address', [
        '@name' => $username,
        '@address' => $address,
      ]);

      /** @var \Drupal\user\UserInterface $user */
      return $user;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create user for address @address: @message', [
        '@address' => $address,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Finds or creates a user by Ethereum address.
   *
   * This is a convenience method that combines finding an existing
   * user or creating a new one. It uses the existing
   * field_ethereum_address field for compatibility with siwe_login.
   *
   * @param string $address
   *   Ethereum wallet address.
   * @param array $data
   *   Additional user data.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user entity, or NULL on failure.
   */
  public function findOrCreateUser(string $address, array $data = []): ?UserInterface {
    $user = $this->findUserByAddress($address);

    if (!$user) {
      $user = $this->createUser($address, $data);
    }

    return $user;
  }

  /**
   * Generates a username from Ethereum address.
   *
   * Format: waap_<first_6_chars_of_address>
   * Example: waap_742d35
   *
   * @param string $address
   *   Ethereum wallet address.
   *
   * @return string
   *   Generated username.
   */
  public function generateUsername(string $address): string {
    // Remove 0x prefix if present.
    $addr = strtolower($address);
    if (substr($addr, 0, 2) === '0x') {
      $addr = substr($addr, 2);
    }

    // Take first 6 characters.
    $shortAddress = substr($addr, 0, 6);
    $baseUsername = 'waap_' . $shortAddress;

    // Ensure uniqueness with collision handling.
    $username = $baseUsername;
    $counter = 1;

    while ($this->usernameExists($username)) {
      $username = $baseUsername . '_' . $counter++;
    }

    return $username;
  }

  /**
   * Checks if a username looks like a generated one.
   *
   * Format: waap_<first_6_chars_of_address> with optional _N suffix.
   *
   * @param string $username
   *   The username to check.
   * @param string $address
   *   The Ethereum address for address-specific matching.
   *
   * @return bool
   *   TRUE if username looks like a generated one, FALSE otherwise.
   */
  public function isGeneratedUsername(string $username, string $address): bool {
    // Remove 0x prefix if present.
    $addr = strtolower($address);
    if (substr($addr, 0, 2) === '0x') {
      $addr = substr($addr, 2);
    }

    // Take first 6 characters.
    $shortAddress = substr($addr, 0, 6);
    $baseUsername = 'waap_' . $shortAddress;

    // Check for exact match.
    if ($username === $baseUsername) {
      return TRUE;
    }

    // Check for pattern with counter suffix.
    if (preg_match('/^waap_' . preg_quote($shortAddress, '/') . '_\d+$/', $username)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Updates a user's wallet address.
   *
   * This method updates the field_ethereum_address field for a user.
   * It uses the entity API to ensure proper cache invalidation.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity to update.
   * @param string $address
   *   The new wallet address.
   *
   * @return bool
   *   TRUE if update was successful, FALSE otherwise.
   */
  public function updateWalletAddress(UserInterface $user, string $address): bool {
    try {
      $user->set('field_ethereum_address', $address);
      $user->save();

      $this->logger->info('Updated wallet address for user @uid to @address', [
        '@uid' => $user->id(),
        '@address' => $address,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update wallet address for user @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Generates an email from Ethereum address.
   *
   * Format: <first_12_chars_of_address>@ethereum.local
   * This is used as a placeholder for users without email.
   *
   * @param string $address
   *   Ethereum wallet address.
   *
   * @return string
   *   Generated email address.
   */
  protected function generateEmail(string $address): string {
    // Remove 0x prefix.
    $addr = strtolower($address);
    if (substr($addr, 0, 2) === '0x') {
      $addr = substr($addr, 2);
    }

    // Take first 12 characters.
    $localPart = substr($addr, 0, 12);

    return $localPart . '@ethereum.local';
  }

  /**
   * Checks if a username already exists.
   *
   * @param string $username
   *   The username to check.
   *
   * @return bool
   *   TRUE if username exists, FALSE otherwise.
   */
  protected function usernameExists(string $username): bool {
    try {
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['name' => $username]);

      return !empty($users);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check username existence for @username: @message', [
        '@username' => $username,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
