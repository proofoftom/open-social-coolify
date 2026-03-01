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

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    SessionInterface $session,
    UserAuthInterface $user_auth,
    LoggerChannelFactoryInterface $logger_factory,
    SiweMessageValidator $message_validator,
    EthereumUserManager $user_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->session = $session;
    $this->userAuth = $user_auth;
    $this->logger = $logger_factory->get('siwe_login');
    $this->messageValidator = $message_validator;
    $this->userManager = $user_manager;
    $this->config = $config_factory->get('siwe_login.settings');
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
   */
  public function authenticate(array $data): ?UserInterface {
    try {
      // Validate the SIWE message.
      $is_valid = $this->messageValidator->validateMessage($data);

      if (!$is_valid) {
        return NULL;
      }

      // Extract ENS name from the raw message.
      $ensName = $this->extractEnsNameFromMessage($data['message']);

      // Add ENS name to the data passed to user manager.
      $data['ensName'] = $ensName;

      // Find or create user.
      $user = $this->userManager->findOrCreateUser($data['address'], $data);

      return $user;
    }
    catch (\Exception $e) {
      $this->logger->error('SIWE authentication failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
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
