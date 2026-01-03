<?php

namespace Drupal\siwe_login\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;

/**
 * Service for handling SIWE authentication.
 */
class SiweAuthService {

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
   * The logger channel for SIWE login.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The SIWE message validator.
   *
   * @var \Drupal\siwe_login\Service\SiweMessageValidator
   */
  protected $messageValidator;

  /**
   * The Ethereum user manager.
   *
   * @var \Drupal\siwe_login\Service\EthereumUserManager
   */
  protected $userManager;

  /**
   * The SIWE login configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The ENS resolver service.
   *
   * @var \Drupal\siwe_login\Service\EnsResolver
   */
  protected $ensResolver;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    SessionInterface $session,
    UserAuthInterface $user_auth,
    LoggerChannelFactoryInterface $logger_factory,
    SiweMessageValidator $message_validator,
    EthereumUserManager $user_manager,
    ConfigFactoryInterface $config_factory,
    EnsResolver $ens_resolver,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->session = $session;
    $this->userAuth = $user_auth;
    $this->logger = $logger_factory->get('siwe_login');
    $this->messageValidator = $message_validator;
    $this->userManager = $user_manager;
    $this->config = $config_factory->get('siwe_login.settings');
    $this->ensResolver = $ens_resolver;
  }

  /**
   * Generates a nonce for SIWE.
   */
  public function generateNonce(): string {
    // Generate a cryptographically secure random nonce.
    return bin2hex(random_bytes(16));
  }

  /**
   * Authenticates a user using SIWE.
   *
   * @param array $data
   *   The SIWE message data.
   *
   * @return array
   *   Array with keys:
   *   - 'user': The authenticated user or NULL on failure.
   *   - 'ens_warning': Optional warning message if user has ENS but custom username.
   */
  public function authenticate(array $data): array {
    $result = [
      'user' => NULL,
      'ens_warning' => NULL,
    ];

    try {
      // Validate the SIWE message.
      $is_valid = $this->messageValidator->validateMessage($data);

      if (!$is_valid) {
        return $result;
      }

      // Extract ENS name from the raw message.
      $ensName = $this->extractEnsNameFromMessage($data['message']);

      // If no ENS in message AND reverse lookup is enabled, try reverse resolution.
      if (empty($ensName) && $this->shouldDoReverseLookup()) {
        $ensName = $this->ensResolver->resolveAddress($data['address']);

        if ($ensName) {
          $this->logger->info('Reverse ENS lookup found @ens for @address', [
            '@ens' => $ensName,
            '@address' => $data['address'],
          ]);
        }
      }

      // Add ENS name to the data passed to user manager.
      $data['ensName'] = $ensName;

      // Find or create user.
      $user = $this->userManager->findOrCreateUser($data['address'], $data);

      // Handle ENS username updates for existing users.
      if ($user && $ensName) {
        $result['ens_warning'] = $this->handleEnsUsernameUpdate($user, $ensName, $data['address']);
      }

      $result['user'] = $user;
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('SIWE authentication failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $result;
    }
  }

  /**
   * Legacy authenticate method for backward compatibility.
   *
   * @param array $data
   *   The SIWE message data.
   *
   * @return \Drupal\user\UserInterface|null
   *   The authenticated user or NULL on failure.
   *
   * @deprecated Use authenticate() which returns array with user and warnings.
   */
  public function authenticateUser(array $data): ?UserInterface {
    $result = $this->authenticate($data);
    return $result['user'];
  }

  /**
   * Handles updating username to ENS name or warning for custom usernames.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   * @param string $ens_name
   *   The ENS name discovered for the user.
   * @param string $address
   *   The Ethereum address.
   *
   * @return string|null
   *   Warning message if user has custom username, NULL otherwise.
   */
  protected function handleEnsUsernameUpdate(UserInterface $user, string $ens_name, string $address): ?string {
    $current_username = $user->getAccountName();

    // If username is already the ENS name, nothing to do.
    if ($current_username === $ens_name) {
      return NULL;
    }

    // Check if current username is auto-generated.
    if ($this->userManager->isGeneratedUsername($current_username, $address)) {
      // Auto-update to ENS name.
      $updated = $this->userManager->updateUsernameToEns($user, $ens_name);
      if ($updated) {
        $this->logger->info('Auto-updated username from @old to @ens for address @address', [
          '@old' => $current_username,
          '@ens' => $ens_name,
          '@address' => $address,
        ]);
      }
      return NULL;
    }

    // User has a custom username - return a warning.
    return t('Your wallet has an ENS name (@ens). Consider updating your username for stronger identity.', [
      '@ens' => $ens_name,
    ]);
  }

  /**
   * Checks if reverse ENS lookup should be performed.
   *
   * @return bool
   *   TRUE if reverse lookup is enabled, FALSE otherwise.
   */
  public function shouldDoReverseLookup(): bool {
    return (bool) $this->config->get('enable_ens_validation')
      && (bool) $this->config->get('enable_reverse_ens_lookup');
  }

  /**
   * Gets the ENS resolver service.
   *
   * @return \Drupal\siwe_login\Service\EnsResolver
   *   The ENS resolver.
   */
  public function getEnsResolver(): EnsResolver {
    return $this->ensResolver;
  }

  /**
   * Checks if email verification is required for new users.
   *
   * @return bool
   *   TRUE if email verification is required, FALSE otherwise.
   */
  public function isEmailVerificationRequired(): bool {
    return $this->config->get('require_email_verification');
  }

  /**
   * Checks if ENS or username is required for new users.
   *
   * @return bool
   *   TRUE if ENS or username is required, FALSE otherwise.
   */
  public function isEnsOrUsernameRequired(): bool {
    return $this->config->get('require_ens_or_username');
  }

  /**
   * Extracts ENS name from SIWE message resources.
   */
  private function extractEnsNameFromMessage(string $message): ?string {
    try {
      // Parse the message to extract resources.
      $parsed = $this->messageValidator->parseSiweMessage($message);

      // Extract ENS name from resources if available.
      if (isset($parsed['resources']) && !empty($parsed['resources'])) {
        foreach ($parsed['resources'] as $resource) {
          if (strpos($resource, 'ens:') === 0) {
            // Remove 'ens:' prefix.
            return substr($resource, 4);
          }
        }
      }

      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to extract ENS name from SIWE message: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets the message validator.
   *
   * @return \Drupal\siwe_login\Service\SiweMessageValidator
   *   The message validator.
   */
  public function getMessageValidator(): SiweMessageValidator {
    return $this->messageValidator;
  }

  /**
   * Gets the user manager.
   *
   * @return \Drupal\siwe_login\Service\EthereumUserManager
   *   The user manager.
   */
  public function getUserManager(): EthereumUserManager {
    return $this->userManager;
  }

}
