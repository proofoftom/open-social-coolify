<?php

namespace Drupal\siwe_login\Form;

use Drupal\Core\PrivateKey;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\Component\Datetime\Time;
use Drupal\siwe_login\Service\SiweMessageValidator;
use Drupal\siwe_login\Service\EthereumUserManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for email verification during SIWE authentication.
 */
class EmailVerificationForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The private tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStore;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The SIWE message validator.
   *
   * @var \Drupal\siwe_login\Service\SiweMessageValidator
   */
  protected $messageValidator;

  /**
   * The user manager.
   *
   * @var \Drupal\siwe_login\Service\EthereumUserManager
   */
  protected $userManager;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The datetime time service.
   *
   * @var \Drupal\Component\Datetime\Time
   */
  protected $dateTime;

  /**
   * The private key service.
   *
   * @var \Drupal\Core\PrivateKey
   */
  protected $privateKey;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs a new EmailVerificationForm.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\siwe_login\Service\SiweMessageValidator $message_validator
   *   The SIWE message validator.
   * @param \Drupal\siwe_login\Service\EthereumUserManager $user_manager
   *   The user manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Datetime\Time $date_time
   *   The datetime time service.
   * @param \Drupal\Core\PrivateKey $private_key
   *   The private key service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    PrivateTempStoreFactory $temp_store_factory,
    EntityTypeManagerInterface $entity_type_manager,
    SiweMessageValidator $message_validator,
    EthereumUserManager $user_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    Time $date_time,
    PrivateKey $private_key,
    MailManagerInterface $mail_manager,
  ) {
    $this->currentUser = $current_user;
    $this->tempStore = $temp_store_factory->get('siwe_login');
    $this->entityTypeManager = $entity_type_manager;
    $this->messageValidator = $message_validator;
    $this->userManager = $user_manager;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->dateTime = $date_time;
    $this->privateKey = $private_key;
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('siwe_login.message_validator'),
      $container->get('siwe_login.user_manager'),
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('private_key'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'siwe_email_verification_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check if we have a pending SIWE authentication.
    $siwe_data = $this->tempStore->get('pending_siwe_data');
    if (!$siwe_data) {
      $this->messenger()->addError($this->t('No pending SIWE authentication found.'));
      return $this->redirect('<front>');
    }

    $form['#title'] = $this->t('Email Verification');

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#description' => $this->t('Please provide your email address. This will be used to send you updates and notifications.'),
      '#required' => TRUE,
      '#default_value' => '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify and Continue'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $email = $form_state->getValue('email');

    // Check if email is already in use by another user.
    $user_storage = $this->entityTypeManager->getStorage('user');
    $existing_users = $user_storage->loadByProperties(['mail' => $email]);

    // Remove the current user from the list if they have the same email.
    if ($this->currentUser->isAuthenticated()) {
      unset($existing_users[$this->currentUser->id()]);
    }

    if (!empty($existing_users)) {
      $form_state->setErrorByName('email', $this->t('This email address is already in use.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $email = $form_state->getValue('email');

    /**
     * Get the pending SIWE data stored in tempstore.
     * @var array $siwe_data
     */
    $siwe_data = $this->tempStore->get('pending_siwe_data');
    if (!$siwe_data) {
      $this->messenger()->addError($this->t('No pending SIWE authentication found.'));
      $form_state->setRedirect('<front>');
      return;
    }

    // Extract ENS name from the raw message.
    $ensName = NULL;
    if (isset($siwe_data['message'])) {
      try {
        $validator = $this->messageValidator;
        $parsed = $validator->parseSiweMessage($siwe_data['message']);

        if (isset($parsed['resources']) && !empty($parsed['resources'])) {
          foreach ($parsed['resources'] as $resource) {
            if (strpos($resource, 'ens:') === 0) {
              // Remove 'ens:' prefix.
              $ensName = substr($resource, 4);
              break;
            }
          }
        }
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('siwe_login')->warning('Failed to extract ENS name from SIWE message: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Add ENS name and email to the data passed to user manager.
    $siwe_data['ensName'] = $ensName;
    $siwe_data['email'] = $email;

    // Create a temporary user account with the provided email.
    $user_manager = $this->userManager;
    $user = $user_manager->createTempUserWithEmail($siwe_data['address'], $siwe_data);

    if ($user) {
      // Send verification email.
      if ($this->sendVerificationEmail($user, $siwe_data)) {
        // Clear the tempstore.
        $this->tempStore->delete('pending_siwe_data');

        $this->messenger()->addStatus($this->t('A verification email has been sent to @email. Please check your inbox and click the verification link to complete your registration.', [
          '@email' => $email,
        ]));

        // Redirect to homepage with message.
        $form_state->setRedirect('<front>');
      }
      else {
        $this->messenger()->addError($this->t('Unable to send verification email. Please try again later.'));
        $form_state->setRedirect('siwe_login.email_verification_form');
      }
    }
    else {
      $this->messenger()->addError($this->t('Unable to create temporary user account.'));
      $form_state->setRedirect('siwe_login.email_verification_form');
    }
  }

  /**
   * Sends a verification email to the user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user account.
   * @param array $siwe_data
   *   The SIWE data.
   *
   * @return bool
   *   TRUE if the email was sent successfully, FALSE otherwise.
   */
  protected function sendVerificationEmail(UserInterface $user, array $siwe_data) {
    try {
      // Generate verification URL.
      $verification_url = $this->generateVerificationUrl($user, $siwe_data);

      // Prepare email parameters.
      $params = [
        'account' => $user,
        'siwe_data' => $siwe_data,
        'verification_url' => $verification_url,
      ];

      // Get the custom site notification email to use as the from email address
      // if it has been set.
      $site_mail = $this->configFactory->get('system.site')->get('mail_notification');
      // If the custom site notification email has not been set, we use the site
      // default for this.
      if (empty($site_mail)) {
        $site_mail = $this->configFactory->get('system.site')->get('mail');
      }
      if (empty($site_mail)) {
        $site_mail = ini_get('sendmail_from');
      }

      // Send the email.
      $mail = $this->mailManager->mail(
        'siwe_login',
        'email_verification',
        $user->getEmail(),
        $user->getPreferredLangcode(),
        $params,
        $site_mail
      );

      return !empty($mail['result']);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('siwe_login')->error('Failed to send verification email: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Generates a verification URL for the user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user account.
   * @param array $siwe_data
   *   The SIWE data.
   *
   * @return string
   *   The verification URL.
   */
  protected function generateVerificationUrl(UserInterface $user, array $siwe_data) {
    $timestamp = $this->dateTime->getRequestTime();

    // Create a hash based on user data and SIWE data.
    $data = $timestamp . ':' . $user->id() . ':' . $user->getEmail();
    if (isset($siwe_data['address'])) {
      $data .= ':' . $siwe_data['address'];
    }
    $hash = Crypt::hmacBase64($data, $this->privateKey->get() . $user->getPassword());

    // Store SIWE data in tempstore with a key based on the hash.
    $tempstore = $this->tempStore;
    $tempstore->set('verification_' . $hash, $siwe_data);

    // Generate URL - make sure uid is an integer.
    $uid = $user->id() ?: 0;

    // Ensure timestamp and hash are not null.
    $timestamp = $timestamp ?: time();
    $hash = $hash ?: uniqid();

    // Generate URL.
    return Url::fromRoute('siwe_login.email_verification_confirm', [
      'uid' => (int) $uid,
      'timestamp' => (int) $timestamp,
      'hash' => $hash,
    ], [
      'absolute' => TRUE,
    ])->toString();
  }

}
