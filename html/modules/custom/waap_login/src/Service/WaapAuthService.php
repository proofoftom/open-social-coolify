<?php

namespace Drupal\waap_login\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\SessionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;

/**
 * Service for handling WaaP authentication.
 *
 * This service orchestrates WaaP authentication flow, including
 * wallet address validation, user authentication, and multi-step
 * authentication requirements (email verification, username creation).
 */
class WaapAuthService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The session service.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * The user authentication service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * The logger channel for WaaP login.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The WaaP user manager service.
   *
   * @var \Drupal\waap_login\Service\WaapUserManager
   */
  protected $userManager;

  /**
   * The WaaP session validator service.
   *
   * @var \Drupal\waap_login\Service\WaapSessionValidator
   */
  protected $sessionValidator;

  /**
   * The WaaP login configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The flood control service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * Constructs a new WaapAuthService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\SessionInterface $session
   *   The session service.
   * @param \Drupal\user\UserAuthInterface $user_auth
   *   The user authentication service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\waap_login\Service\WaapUserManager $user_manager
   *   The WaaP user manager service.
   * @param \Drupal\waap_login\Service\WaapSessionValidator $session_validator
   *   The WaaP session validator service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood control service.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    SessionInterface $session,
    UserAuthInterface $user_auth,
    LoggerChannelFactoryInterface $logger_factory,
    WaapUserManager $user_manager,
    WaapSessionValidator $session_validator,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    FloodInterface $flood,
    CsrfTokenGenerator $csrf_token
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->session = $session;
    $this->userAuth = $user_auth;
    $this->logger = $logger_factory->get('waap_login');
    $this->userManager = $user_manager;
    $this->sessionValidator = $session_validator;
    $this->config = $config_factory->get('waap_login.settings');
    $this->moduleHandler = $module_handler;
    $this->flood = $flood;
    $this->csrfToken = $csrf_token;
  }

  /**
   * Authenticates a user with WaaP wallet address.
   *
   * This method handles complete authentication flow including:
   * - Address validation
   * - Session validation
   * - User lookup or creation
   * - Multi-step authentication (email verification, username creation)
   * - Hook invocation for module integration
   *
   * @param array $data
   *   Data containing:
   *   - address: Ethereum wallet address (required)
   *   - loginType: waap|injected|walletconnect (required)
   *   - sessionData: Optional WaaP session metadata
   *
   * @return array
   *   Array with keys:
   *   - user: UserInterface|null - The authenticated user
   *   - redirect: string|null - Optional redirect URL for multi-step auth
   *   - error: string|null - Error message if authentication failed
   */
  public function authenticate(array $data): array {
    $result = [];
    $result['user'] = NULL;
    $result['redirect'] = NULL;
    $result['error'] = NULL;

    try {
      // Validate required fields.
      if (empty($data['address'])) {
        $result['error'] = 'Wallet address is required';
        $this->logger->warning('WaaP authentication attempt missing address');
        return $result;
      }

      if (empty($data['loginType'])) {
        $result['error'] = 'Login type is required';
        $this->logger->warning('WaaP authentication attempt missing login type');
        return $result;
      }

      $address = $data['address'];
      $loginType = $data['loginType'];
      $sessionData = $data['sessionData'] ?? [];

      // Validate Ethereum address format.
      if (!$this->validateAddress($address)) {
        $result['error'] = 'Invalid wallet address format';
        $this->logger->warning('Invalid Ethereum address format: @address', [
          '@address' => $address,
        ]);
        return $result;
      }

      // Validate WaaP session if provided.
      if (!empty($sessionData) && !$this->sessionValidator->validateSession($sessionData)) {
        $result['error'] = 'Invalid or expired WaaP session';
        $this->logger->warning('Invalid WaaP session for address @address', [
          '@address' => $address,
        ]);
        return $result;
      }

      // Find or create user by address.
      $user = $this->userManager->findOrCreateUser($address, $data);

      if (!$user) {
        $result['error'] = 'Failed to create user account';
        $this->logger->error('Failed to create user for address @address', [
          '@address' => $address,
        ]);
        return $result;
      }

      // Check if email verification is required for new users.
      if ($this->isEmailVerificationRequired() && empty($user->getEmail())) {
        $result['redirect'] = '/waap/email-verification';
        $result['user'] = $user;
        $this->logger->info('Email verification required for user @uid', [
          '@uid' => $user->id(),
        ]);
        return $result;
      }

      // Check if username creation is required.
      $username = $user->getAccountName();
      if ($this->isUsernameRequired() && $this->userManager->isGeneratedUsername($username, $address)) {
        $result['redirect'] = '/waap/create-username';
        $result['user'] = $user;
        $this->logger->info('Username creation required for user @uid', [
          '@uid' => $user->id(),
        ]);
        return $result;
      }

      // Store WaaP session metadata.
      $this->sessionValidator->storeSession($user->id(), [
        'login_type' => $loginType,
        'login_method' => $sessionData['loginMethod'] ?? 'unknown',
        'provider' => $sessionData['provider'] ?? NULL,
        'timestamp' => time(),
      ]);

      // Allow other modules to alter authentication response.
      $response_data = [];
      $response_data['user'] = $user;
      $response_data['address'] = $address;
      $response_data['loginType'] = $loginType;
      $response_data['sessionData'] = $sessionData;
      $this->moduleHandler->alter('waap_login_response', $response_data);

      // Check if a redirect was added by another module.
      if (isset($response_data['redirect'])) {
        $result['redirect'] = $response_data['redirect'];
      }

      $result['user'] = $user;

      $this->logger->info('User @uid authenticated via WaaP (@type)', [
        '@uid' => $user->id(),
        '@type' => $loginType,
      ]);

      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('WaaP authentication failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $result['error'] = 'Authentication failed: ' . $e->getMessage();
      return $result;
    }
  }

  /**
   * Validates an Ethereum wallet address format.
   *
   * This method validates address format according to EIP-55 checksum
   * specification. A valid Ethereum address must:
   * - Be 42 characters long (0x + 40 hex characters)
   * - Start with "0x"
   * - Contain only hexadecimal characters
   * - Optionally be checksummed (EIP-55)
   *
   * @param string $address
   *   The Ethereum wallet address to validate.
   *
   * @return bool
   *   TRUE if address is valid, FALSE otherwise.
   */
  public function validateAddress(string $address): bool {
    // Check basic format: 0x followed by 40 hex characters.
    if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
      return FALSE;
    }

    // Validate EIP-55 checksum if enabled.
    if ($this->config->get('validate_checksum')) {
      return $this->validateChecksum($address);
    }

    return TRUE;
  }

  /**
   * Validates EIP-55 checksum for an Ethereum address.
   *
   * EIP-55 checksum validation ensures that an address is properly
   * capitalized to prevent typos and phishing attacks.
   *
   * @param string $address
   *   The Ethereum address to validate.
   *
   * @return bool
   *   TRUE if checksum is valid, FALSE otherwise.
   */
  protected function validateChecksum(string $address): bool {
    // Remove 0x prefix for processing.
    $addr = substr($address, 2);

    // Hash the lowercase address using SHA3-256.
    // Note: PHP doesn't have native Keccak-256, so we use SHA3-256.
    $hash = hash('sha3-256', strtolower($addr));

    // Validate each character against the hash.
    for ($i = 0; $i < 40; $i++) {
      $hashChar = $hash[$i];
      $addressChar = $addr[$i];

      // Numeric characters are always valid.
      if (ctype_digit($addressChar)) {
        continue;
      }

      // Check if character should be uppercase.
      $shouldBeUpperCase = intval($hashChar, 16) > 7;
      $isUpperCase = ctype_upper($addressChar);

      if ($isUpperCase !== $shouldBeUpperCase) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks if email verification is required for new users.
   *
   * @return bool
   *   TRUE if email verification is required, FALSE otherwise.
   */
  public function isEmailVerificationRequired(): bool {
    return (bool) $this->config->get('require_email_verification');
  }

  /**
   * Checks if custom username creation is required for new users.
   *
   * @return bool
   *   TRUE if username creation is required, FALSE otherwise.
   */
  public function isUsernameRequired(): bool {
    return (bool) $this->config->get('require_username');
  }

  /**
   * Checks if automatic user creation is enabled.
   *
   * @return bool
   *   TRUE if auto-create is enabled, FALSE otherwise.
   */
  public function isAutoCreateEnabled(): bool {
    return (bool) $this->config->get('auto_create_users');
  }

  /**
   * Gets the session TTL in seconds.
   *
   * @return int
   *   The session TTL in seconds.
   */
  public function getSessionTtl(): int {
    return (int) $this->config->get('session_ttl');
  }

  /**
   * Registers a flood control event for authentication attempts.
   *
   * @param string $identifier
   *   The identifier for the flood event (typically IP address).
   */
  public function registerFloodEvent(string $identifier): void {
    $this->flood->register('waap_login.verify', $this->getSessionTtl(), $identifier);
  }

  /**
   * Checks if flood control allows an authentication attempt.
   *
   * @param string $identifier
   *   The identifier for the flood event (typically IP address).
   *
   * @return bool
   *   TRUE if attempt is allowed, FALSE otherwise.
   */
  public function isFloodAllowed(string $identifier): bool {
    return $this->flood->isAllowed('waap_login.verify', 5, 3600, $identifier);
  }

  /**
   * Generates a CSRF token for form submissions.
   *
   * @param string $value
   *   The value to generate a token for.
   *
   * @return string
   *   The CSRF token.
   */
  public function getCsrfToken(string $value = ''): string {
    return $this->csrfToken->get($value);
  }

  /**
   * Validates a CSRF token.
   *
   * @param string $token
   *   The token to validate.
   * @param string $value
   *   The value the token was generated for.
   *
   * @return bool
   *   TRUE if token is valid, FALSE otherwise.
   */
  public function validateCsrfToken(string $token, string $value = ''): bool {
    return $this->csrfToken->validate($token, $value);
  }

  /**
   * Gets the user manager service.
   *
   * @return \Drupal\waap_login\Service\WaapUserManager
   *   The user manager.
   */
  public function getUserManager(): WaapUserManager {
    return $this->userManager;
  }

  /**
   * Gets the session validator service.
   *
   * @return \Drupal\waap_login\Service\WaapSessionValidator
   *   The session validator.
   */
  public function getSessionValidator(): WaapSessionValidator {
    return $this->sessionValidator;
  }

}
