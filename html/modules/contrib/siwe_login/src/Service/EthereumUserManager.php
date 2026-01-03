<?php

namespace Drupal\siwe_login\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\user\UserInterface;

/**
 * Manages Ethereum wallet-based user accounts.
 */
class EthereumUserManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel for SIWE login.
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

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('siwe_login');
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
  }

  /**
   * Finds or creates a user by Ethereum address.
   *
   * @param string $address
   *   The Ethereum wallet address.
   * @param array $additional_data
   *   Additional user data from SIWE message.
   *
   * @return \Drupal\user\UserInterface
   *   The user entity.
   */
  public function findOrCreateUser(string $address, array $additional_data = []): UserInterface {
    $address = $this->normalizeAddress($address);

    // Try to find existing user.
    $user = $this->findUserByAddress($address);

    if (!$user) {
      $user = $this->createUserFromAddress($address, $additional_data);
    }
    else {
      // Update last login and any additional data.
      $this->updateUserData($user, $additional_data);
    }

    return $user;
  }

  /**
   * Finds a user by Ethereum address.
   */
  public function findUserByAddress(string $address): ?UserInterface {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['field_ethereum_address' => $this->normalizeAddress($address)]);

    return $users ? reset($users) : NULL;
  }

  /**
   * Creates a new user from an Ethereum address.
   */
  protected function createUserFromAddress(string $address, array $data): UserInterface {
    $normalized_address = $this->normalizeAddress($address);

    // Use ENS name from the verified SIWE message.
    $ens_name = $data['ensName'] ?? NULL;
    $username = $ens_name ?: $this->generateUsername($normalized_address);

    $user_storage = $this->entityTypeManager->getStorage('user');

    $user = $user_storage->create([
      'name' => $username,
      'mail' => $this->generateEmail($normalized_address),
      'field_ethereum_address' => $normalized_address,
      'status' => 1,
      'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
    ]);

    $user->save();

    $this->logger->info('Created new user <strong>@name</strong> for Ethereum address @address', [
      '@name' => $username,
      '@address' => $normalized_address,
    ]);

    /** @var \Drupal\user\UserInterface $user */
    return $user;
  }

  /**
   * Creates a temporary user with email but doesn't save it to the database.
   *
   * @param string $address
   *   The Ethereum wallet address.
   * @param array $data
   *   Additional user data including email.
   *
   * @return \Drupal\user\UserInterface
   *   The temporary user entity.
   */
  public function createTempUserWithEmail(string $address, array $data): UserInterface {
    $normalized_address = $this->normalizeAddress($address);

    // Use ENS name from the verified SIWE message.
    $ens_name = $data['ensName'] ?? NULL;
    $email = $data['email'] ?? $this->generateEmail($normalized_address);
    $username = $ens_name ?: $this->generateUsername($normalized_address);

    $user_storage = $this->entityTypeManager->getStorage('user');

    /** @var \Drupal\user\UserInterface $user */
    $user = $user_storage->create([
      'name' => $username,
      'mail' => $email,
      'field_ethereum_address' => $normalized_address,
      'status' => 1,
      'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
    ]);

    return $user;
  }

  /**
   * Creates a user with a specific username.
   *
   * @param string $address
   *   The Ethereum wallet address.
   * @param array $data
   *   Additional user data including username.
   *
   * @return \Drupal\user\UserInterface
   *   The user entity.
   */
  public function createUserWithUsername(string $address, array $data): UserInterface {
    $normalized_address = $this->normalizeAddress($address);

    // Use provided username or ENS name from the verified SIWE message.
    $username = $data['username'] ?? $data['ensName'] ?? $this->generateUsername($normalized_address);
    $email = $data['email'] ?? $this->generateEmail($normalized_address);

    $user_storage = $this->entityTypeManager->getStorage('user');

    $user = $user_storage->create([
      'name' => $username,
      'mail' => $email,
      'field_ethereum_address' => $normalized_address,
      'status' => 1,
      'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
    ]);

    $user->save();

    $this->logger->info('Created new user <strong>@name</strong> for Ethereum address @address', [
      'name' => $username,
      'address' => $normalized_address,
    ]);

    /** @var \Drupal\user\UserInterface $user */
    return $user;
  }

  /**
   * Updates a user's username.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity to update.
   * @param string $username
   *   The new username.
   *
   * @return \Drupal\user\UserInterface
   *   The updated user entity.
   */
  public function updateUserUsername(UserInterface $user, string $username): UserInterface {
    $user->set('name', $username);
    $user->save();

    $this->logger->info('Updated username for user <strong>@name</strong> with Ethereum address @address', [
      'name' => $username,
      'address' => $user->get('field_ethereum_address')->value,
    ]);

    return $user;
  }

  /**
   * Finds or creates a user by Ethereum address with a specific email.
   *
   * @param string $address
   *   The Ethereum wallet address.
   * @param array $data
   *   Additional user data including email.
   *
   * @return \Drupal\user\UserInterface
   *   The user entity.
   */
  public function findOrCreateUserWithEmail(string $address, array $data): UserInterface {
    $address = $this->normalizeAddress($address);

    // Try to find existing user.
    $user = $this->findUserByAddress($address);

    if (!$user) {
      $user = $this->createUserFromAddressWithEmail($address, $data);
    }
    else {
      // Update the user's email if provided.
      if (isset($data['email']) && !empty($data['email'])) {
        $user->set('mail', $data['email']);
        $user->save();
      }

      // Update last login and any additional data.
      $this->updateUserData($user, $data);
    }

    return $user;
  }

  /**
   * Creates a new user from an Ethereum address with a specific email.
   */
  protected function createUserFromAddressWithEmail(string $address, array $data): UserInterface {
    $normalized_address = $this->normalizeAddress($address);

    // Use ENS name from the verified SIWE message.
    $ens_name = $data['ensName'] ?? NULL;
    $email = $data['email'] ?? $this->generateEmail($normalized_address);
    $username = $ens_name ?: $this->generateUsername($normalized_address);

    $user_storage = $this->entityTypeManager->getStorage('user');

    $user = $user_storage->create([
      'name' => $username,
      'mail' => $email,
      'field_ethereum_address' => $normalized_address,
      'status' => 1,
      'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
    ]);

    $user->save();

    $this->logger->info('Created new user <strong>@name</strong> for Ethereum address @address with email @email', [
      '@name' => $username,
      '@address' => $normalized_address,
      '@email' => $email,
    ]);

    /** @var \Drupal\user\UserInterface $user */
    return $user;
  }

  /**
   * Updates user data from SIWE message.
   */
  protected function updateUserData(UserInterface $user, array $data): void {
    // Update ENS name from SIWE data.
    if (isset($data['ensName']) && $data['ensName'] !== $user->get('name')->value) {
      $user->set('name', $data['ensName']);

      // Save the user.
      $user->save();

      $this->logger->info('Updated ENS for Ethereum address @address to @ens_name', [
        '@address' => $user->get('field_ethereum_address')->value,
        '@ens_name' => $data['ensName'],
      ]);
    }
  }

  /**
   * Normalizes an Ethereum address.
   */
  protected function normalizeAddress(string $address): string {
    // Convert to checksummed address format.
    return strtolower(trim($address));
  }

  /**
   * Generates a unique username from address.
   *
   * Format: 0x{first4}...{last4} (e.g., 0xd8dA...6045)
   */
  public function generateUsername(string $address): string {
    // Remove 0x prefix if present for processing.
    $addr = strtolower($address);
    if (substr($addr, 0, 2) === '0x') {
      $addr = substr($addr, 2);
    }

    // Format: 0x{first4}...{last4}
    $first4 = substr($addr, 0, 4);
    $last4 = substr($addr, -4);
    $base_username = '0x' . $first4 . '...' . $last4;
    $username = $base_username;
    $i = 1;

    while ($this->usernameExists($username)) {
      $username = $base_username . '_' . $i++;
    }

    return $username;
  }

  /**
   * Generates email from address.
   */
  protected function generateEmail(string $address): string {
    return substr($address, 2, 12) . '@ethereum.local';
  }

  /**
   * Checks if username exists.
   */
  protected function usernameExists(string $username): bool {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);

    return !empty($users);
  }

  /**
   * Checks if a username looks like a generated one.
   *
   * Supports both old format (eth_{first8}) and new format (0x{first4}...{last4}).
   *
   * @param string $username
   *   The username to check.
   * @param string $address
   *   The Ethereum address (optional, for address-specific matching).
   *
   * @return bool
   *   TRUE if the username looks like a generated one, FALSE otherwise.
   */
  public function isGeneratedUsername(string $username, string $address = ''): bool {
    // Check for new format: 0x{4hex}...{4hex} with optional _N suffix
    if (preg_match('/^0x[a-fA-F0-9]{4}\.\.\.[a-fA-F0-9]{4}(_\d+)?$/', $username)) {
      return TRUE;
    }

    // Check for old format: eth_{8hex} with optional _N suffix
    if (preg_match('/^eth_[a-fA-F0-9]{8}(_\d+)?$/', $username)) {
      return TRUE;
    }

    // If address is provided, also check address-specific patterns.
    if (!empty($address)) {
      $normalized_address = $this->normalizeAddress($address);

      // Old format check with specific address.
      $old_base = 'eth_' . substr($normalized_address, 2, 8);
      if ($username === $old_base || preg_match('/^' . preg_quote($old_base, '/') . '_\d+$/', $username)) {
        return TRUE;
      }

      // New format check with specific address.
      $addr = substr($normalized_address, 2);
      $new_base = '0x' . substr($addr, 0, 4) . '...' . substr($addr, -4);
      if ($username === $new_base || preg_match('/^' . preg_quote($new_base, '/') . '_\d+$/', $username)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Updates a user's username to their ENS name.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   * @param string $ens_name
   *   The ENS name to set as username.
   *
   * @return bool
   *   TRUE if username was updated, FALSE if ENS name already exists as username.
   */
  public function updateUsernameToEns(UserInterface $user, string $ens_name): bool {
    // Check if the ENS name is already taken by another user.
    if ($this->usernameExists($ens_name) && $user->getAccountName() !== $ens_name) {
      $this->logger->warning('Cannot update username to @ens - already taken by another user', [
        '@ens' => $ens_name,
      ]);
      return FALSE;
    }

    $old_username = $user->getAccountName();
    $user->set('name', $ens_name);
    $user->save();

    $this->logger->info('Updated username from @old to @new for Ethereum address @address', [
      '@old' => $old_username,
      '@new' => $ens_name,
      '@address' => $user->get('field_ethereum_address')->value,
    ]);

    return TRUE;
  }

  /**
   * Checks if a username ends with .eth (ENS format).
   *
   * @param string $username
   *   The username to check.
   *
   * @return bool
   *   TRUE if username ends with .eth, FALSE otherwise.
   */
  public function isEnsUsername(string $username): bool {
    return (bool) preg_match('/\.eth$/i', $username);
  }

  /**
   * Validates that a username doesn't end in .eth (reserved for verified ENS).
   *
   * @param string $username
   *   The username to validate.
   *
   * @return bool
   *   TRUE if username is valid (doesn't end in .eth), FALSE otherwise.
   */
  public function validateUsernameNotEns(string $username): bool {
    return !$this->isEnsUsername($username);
  }

}
