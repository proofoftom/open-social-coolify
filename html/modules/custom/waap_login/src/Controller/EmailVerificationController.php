<?php

namespace Drupal\waap_login\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\UserAuthInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for email verification confirmation.
 *
 * Handles the GET endpoint for confirming email verification
 * tokens sent to users during the WaaP authentication flow.
 */
class EmailVerificationController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The private temp store factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger channel for WaaP login.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The user authentication service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * Email verification token expiration time in seconds (24 hours).
   *
   * @var int
   */
  const TOKEN_EXPIRATION = 86400;

  /**
   * Constructs a new EmailVerificationController.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The private temp store factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\user\UserAuthInterface $user_auth
   *   The user authentication service.
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    UserAuthInterface $user_auth
  ) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('waap_login');
    $this->configFactory = $config_factory;
    $this->userAuth = $user_auth;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('user.auth')
    );
  }

  /**
   * Confirms email verification token.
   *
   * This endpoint handles GET requests to verify email tokens.
   * It validates the token, updates user's email verification status,
   * and redirects to the appropriate page.
   *
   * The URL format is: /waap/email-verification/{uid}/{timestamp}/{hash}
   *
   * The hash is generated using: md5($uid . ':' . $timestamp . ':' . $email . ':' . $drupal_hash_salt)
   *
   * @param int $uid
   *   The user ID.
   * @param int $timestamp
   *   The timestamp when the token was generated.
   * @param string $hash
   *   The verification hash.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to appropriate page after verification.
   */
  public function confirm($uid, $timestamp, $hash) {
    try {
      // Validate UID format.
      if (!is_numeric($uid) || $uid <= 0) {
        $this->logger->warning('Invalid UID in email verification: @uid', [
          '@uid' => $uid,
        ]);
        $this->messenger->addError($this->t('Invalid verification link.'));
        return $this->redirect('<front>');
      }

      // Validate timestamp format.
      if (!is_numeric($timestamp) || $timestamp <= 0) {
        $this->logger->warning('Invalid timestamp in email verification: @timestamp', [
          '@timestamp' => $timestamp,
        ]);
        $this->messenger->addError($this->t('Invalid verification link.'));
        return $this->redirect('<front>');
      }

      // Check if token has expired.
      if (time() - $timestamp > self::TOKEN_EXPIRATION) {
        $this->logger->warning('Expired email verification token for user @uid', [
          '@uid' => $uid,
        ]);
        $this->messenger->addError($this->t('Verification link has expired. Please request a new verification email.'));
        return $this->redirect('user.login');
      }

      // Load the user.
      $user = $this->entityTypeManager->getStorage('user')->load($uid);

      if (!$user) {
        $this->logger->warning('User not found in email verification: @uid', [
          '@uid' => $uid,
        ]);
        $this->messenger->addError($this->t('Invalid verification link.'));
        return $this->redirect('<front>');
      }

      // Get email from temp store.
      $tempStore = $this->tempStoreFactory->get('waap_login');
      $emailData = $tempStore->get('email_verification_' . $uid);

      if (!$emailData || !isset($emailData['email'])) {
        $this->logger->warning('Email data not found in temp store for user @uid', [
          '@uid' => $uid,
        ]);
        $this->messenger->addError($this->t('Invalid verification link. Please request a new verification email.'));
        return $this->redirect('user.login');
      }

      $email = $emailData['email'];

      // Verify the hash.
      $expectedHash = $this->generateVerificationHash($uid, $timestamp, $email);

      if (!hash_equals($expectedHash, $hash)) {
        $this->logger->warning('Invalid hash in email verification for user @uid', [
          '@uid' => $uid,
        ]);
        $this->messenger->addError($this->t('Invalid verification link.'));
        return $this->redirect('<front>');
      }

      // Update user's email.
      $user->setEmail($email);
      $user->set('status', 1); // Activate user if not already active.
      $user->save();

      // Clear the temp store data.
      $tempStore->delete('email_verification_' . $uid);

      $this->logger->info('Email verified for user @uid', [
        '@uid' => $uid,
      ]);

      // Show success message.
      $this->messenger->addStatus($this->t('Email verified successfully!'));

      // Check if username creation is required.
      $config = $this->configFactory->get('waap_login.settings');
      $requireUsername = $config->get('require_username');

      if ($requireUsername) {
        // Check if username is auto-generated.
        $address = $user->get('field_ethereum_address')->value ?? '';
        $username = $user->getAccountName();

        if ($this->isGeneratedUsername($username, $address)) {
          $this->messenger->addStatus($this->t('Please create a username to complete your account setup.'));
          return $this->redirect('waap_login.username_creation_form');
        }
      }

      // Log in the user.
      $this->userAuth->finalizeLogin($user);

      $this->logger->info('User @uid logged in after email verification', [
        '@uid' => $uid,
      ]);

      // Redirect to user dashboard.
      return $this->redirect('entity.user.canonical', ['user' => $uid]);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error in email verification: @message', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      $this->messenger->addError($this->t('An error occurred during email verification. Please try again.'));
      return $this->redirect('user.login');
    }
  }

  /**
   * Generates verification hash for email confirmation.
   *
   * Uses Drupal's hash salt to generate a secure hash that
   * cannot be forged without access to the server.
   *
   * @param int $uid
   *   The user ID.
   * @param int $timestamp
   *   The timestamp.
   * @param string $email
   *   The email address.
   *
   * @return string
   *   The verification hash.
   */
  protected function generateVerificationHash($uid, $timestamp, $email): string {
    $hashSalt = $this->configFactory->get('system.site')->get('hash_salt');
    if (!$hashSalt) {
      // Fallback to DRUPAL_HASH_SALT constant if config not available.
      $hashSalt = defined('DRUPAL_HASH_SALT') ? DRUPAL_HASH_SALT : '';
    }

    $data = $uid . ':' . $timestamp . ':' . $email . ':' . $hashSalt;
    return md5($data);
  }

  /**
   * Checks if a username is auto-generated from wallet address.
   *
   * Auto-generated usernames follow the format: waap_<first_6_chars_of_address>
   *
   * @param string $username
   *   The username to check.
   * @param string $address
   *   The wallet address.
   *
   * @return bool
   *   TRUE if username is auto-generated, FALSE otherwise.
   */
  protected function isGeneratedUsername(string $username, string $address): bool {
    if (empty($address)) {
      return FALSE;
    }

    // Remove 0x prefix and take first 6 characters.
    $shortAddress = substr(strtolower($address), 2, 6);
    $expectedUsername = 'waap_' . $shortAddress;

    // Check if username matches expected pattern.
    return $username === $expectedUsername;
  }

}
