<?php

namespace Drupal\waap_login\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Utility\Token;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for email verification during WaaP authentication.
 *
 * This form collects the user's email address during the multi-step
 * authentication flow, generates a verification link, and sends
 * a verification email.
 */
class EmailVerificationForm extends FormBase {

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
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Email verification token expiration time in seconds (24 hours).
   *
   * @var int
   */
  const TOKEN_EXPIRATION = 86400;

  /**
   * Constructs a new EmailVerificationForm.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The private temp store factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    MailManagerInterface $mail_manager,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
    Token $token
  ) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('waap_login');
    $this->mailManager = $mail_manager;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->token = $token;
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
      $container->get('plugin.manager.mail'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'waap_email_verification_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get wallet address from temp store.
    $tempStore = $this->tempStoreFactory->get('waap_login');
    $walletAddress = $tempStore->get('waap_pending_address');

    // If no wallet address in temp store, redirect to login.
    if (empty($walletAddress)) {
      $this->logger->warning('No wallet address found in temp store during email verification form build.');
      $this->messenger->addError($this->t('Authentication session expired. Please try logging in again.'));
      return $this->redirect('user.login');
    }

    // Get pending user ID from temp store.
    $pendingUserId = $tempStore->get('waap_pending_user');
    $user = NULL;
    if ($pendingUserId) {
      $user = $this->entityTypeManager->getStorage('user')->load($pendingUserId);
    }

    $form['#attached']['library'][] = 'waap_login/waap_login';

    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('Please verify your email address to complete your account setup.'),
      '#prefix' => '<div class="waap-form-info">',
      '#suffix' => '</div>',
    ];

    if ($user) {
      $form['wallet_info'] = [
        '#type' => 'item',
        '#title' => $this->t('Wallet Address'),
        '#markup' => '<code>' . htmlspecialchars($walletAddress) . '</code>',
        '#prefix' => '<div class="waap-wallet-info">',
        '#suffix' => '</div>',
      ];
    }

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#description' => $this->t('Enter your email address to verify your account.'),
      '#required' => TRUE,
      '#maxlength' => 254,
      '#attributes' => [
        'autocomplete' => 'email',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Verification Email'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->urlGenerator->generate('user.login'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $email = $form_state->getValue('email');

    // Validate email format.
    if (!\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
      return;
    }

    // Check if email is already used by another user.
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    // Get pending user ID from temp store.
    $tempStore = $this->tempStoreFactory->get('waap_login');
    $pendingUserId = $tempStore->get('waap_pending_user');

    // Remove the pending user from the list if present.
    if ($pendingUserId && isset($users[$pendingUserId])) {
      unset($users[$pendingUserId]);
    }

    if (!empty($users)) {
      $form_state->setErrorByName('email', $this->t('This email address is already in use. Please use a different email address or log in with your existing account.'));
      $this->logger->warning('Email already in use during verification: @email', [
        '@email' => $email,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $email = trim($form_state->getValue('email'));
      $tempStore = $this->tempStoreFactory->get('waap_login');

      // Get pending user ID from temp store.
      $pendingUserId = $tempStore->get('waap_pending_user');

      if (!$pendingUserId) {
        $this->logger->error('No pending user ID in temp store during email verification submission.');
        $this->messenger->addError($this->t('Authentication session expired. Please try logging in again.'));
        $form_state->setRedirect('user.login');
        return;
      }

      // Load the pending user.
      $user = $this->entityTypeManager->getStorage('user')->load($pendingUserId);

      if (!$user) {
        $this->logger->error('Pending user not found during email verification: @uid', [
          '@uid' => $pendingUserId,
        ]);
        $this->messenger->addError($this->t('An error occurred. Please try again.'));
        $form_state->setRedirect('user.login');
        return;
      }

      // Store email in temp store for verification.
      $tempStore->set('email_verification_' . $pendingUserId, [
        'email' => $email,
        'timestamp' => time(),
      ]);

      // Store email in pending data.
      $tempStore->set('waap_pending_email', $email);

      // Generate verification link.
      $timestamp = time();
      $hash = $this->generateVerificationHash($pendingUserId, $timestamp, $email);
      $verificationUrl = $this->urlGenerator->generateFromRoute('waap_login.email_verification_confirm', [
        'uid' => $pendingUserId,
        'timestamp' => $timestamp,
        'hash' => $hash,
      ], ['absolute' => TRUE]);

      // Send verification email.
      $langcode = $user->getPreferredLangcode();
      $params = [
        'user' => $user,
        'verification_url' => $verificationUrl,
      ];

      $result = $this->mailManager->mail('waap_login', 'email_verification', $email, $langcode, $params);

      if ($result['result']) {
        $this->logger->info('Verification email sent to @email for user @uid', [
          '@email' => $email,
          '@uid' => $pendingUserId,
        ]);

        $this->messenger->addStatus($this->t('A verification email has been sent to @email. Please check your inbox and click the link to verify your account.', [
          '@email' => $email,
        ]));

        // Store pending status.
        $tempStore->set('waap_login_status', 'email_pending');
      }
      else {
        $this->logger->error('Failed to send verification email to @email for user @uid', [
          '@email' => $email,
          '@uid' => $pendingUserId,
        ]);
        $this->messenger->addError($this->t('Failed to send verification email. Please try again.'));
      }

      $form_state->setRedirect('<front>');
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error in email verification form submission: @message', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger->addError($this->t('An error occurred. Please try again.'));
      $form_state->setRedirect('user.login');
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

}
