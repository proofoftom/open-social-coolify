<?php

namespace Drupal\waap_login\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserAuthInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Form for username creation during WaaP authentication.
 *
 * This form allows users to create or customize their username
 * after email verification is complete. The username field is
 * pre-filled with an auto-generated username based on the
 * wallet address.
 */
class UsernameCreationForm extends FormBase {

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
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The user authentication service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * Minimum username length.
   *
   * @var int
   */
  const USERNAME_MIN_LENGTH = 3;

  /**
   * Maximum username length.
   *
   * @var int
   */
  const USERNAME_MAX_LENGTH = 60;

  /**
   * Constructs a new UsernameCreationForm.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The private temp store factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\user\UserAuthInterface $user_auth
   *   The user authentication service.
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user,
    UserAuthInterface $user_auth
  ) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('waap_login');
    $this->currentUser = $current_user;
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
      $container->get('current_user'),
      $container->get('user.auth')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'waap_username_creation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $tempStore = $this->tempStoreFactory->get('waap_login');

    // Get pending user ID from temp store.
    $pendingUserId = $tempStore->get('waap_pending_user');

    // If no pending user, redirect to login.
    if (!$pendingUserId) {
      $this->logger->warning('No pending user ID found in temp store during username creation form build.');
      $this->messenger->addError($this->t('Authentication session expired. Please try logging in again.'));
      return $this->redirect('user.login');
    }

    // Load pending user.
    $user = $this->entityTypeManager->getStorage('user')->load($pendingUserId);

    if (!$user) {
      $this->logger->error('Pending user not found during username creation: @uid', [
        '@uid' => $pendingUserId,
      ]);
      $this->messenger->addError($this->t('An error occurred. Please try again.'));
      return $this->redirect('user.login');
    }

    // Get wallet address and email from temp store.
    $walletAddress = $tempStore->get('waap_pending_address');
    $email = $tempStore->get('waap_pending_email');

    // Generate auto-generated username.
    $generatedUsername = $this->generateUsername($walletAddress);

    $form['#attached']['library'][] = 'waap_login/waap_login';

    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('Please create a username to complete your account setup.'),
      '#prefix' => '<div class="waap-form-info">',
      '#suffix' => '</div>',
    ];

    $form['user_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['waap-user-info']],
    ];

    if ($email) {
      $form['user_info']['email'] = [
        '#type' => 'item',
        '#title' => $this->t('Email'),
        '#markup' => htmlspecialchars($email),
      ];
    }

    if ($walletAddress) {
      $form['user_info']['wallet'] = [
        '#type' => 'item',
        '#title' => $this->t('Wallet Address'),
        '#markup' => '<code>' . htmlspecialchars($walletAddress) . '</code>',
      ];
    }

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Choose your username (3-60 characters, alphanumeric and underscore only).'),
      '#required' => TRUE,
      '#default_value' => $generatedUsername,
      '#maxlength' => self::USERNAME_MAX_LENGTH,
      '#size' => 30,
      '#attributes' => [
        'autocomplete' => 'off',
      ],
      '#ajax' => [
        'callback' => '::validateUsernameAjax',
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Checking username availability...'),
        ],
      ],
    ];

    $form['username_requirements'] = [
      '#type' => 'item',
      '#markup' => $this->t('<strong>Username requirements:</strong><ul>
        <li>3-60 characters long</li>
        <li>Alphanumeric characters (a-z, A-Z, 0-9) only</li>
        <li>Underscore (_) is allowed</li>
        <li>No spaces or special characters</li>
      </ul>'),
      '#prefix' => '<div class="waap-username-requirements">',
      '#suffix' => '</div>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Username & Complete Setup'),
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

    $username = trim($form_state->getValue('username'));

    // Validate username length.
    if (mb_strlen($username) < self::USERNAME_MIN_LENGTH) {
      $form_state->setErrorByName('username', $this->t('Username must be at least @min characters long.', [
        '@min' => self::USERNAME_MIN_LENGTH,
      ]));
      return;
    }

    if (mb_strlen($username) > self::USERNAME_MAX_LENGTH) {
      $form_state->setErrorByName('username', $this->t('Username must not exceed @max characters.', [
        '@max' => self::USERNAME_MAX_LENGTH,
      ]));
      return;
    }

    // Validate username format (alphanumeric and underscore only).
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
      $form_state->setErrorByName('username', $this->t('Username can only contain letters, numbers, and underscores.'));
      return;
    }

    // Check if username starts with a number (not recommended).
    if (ctype_digit($username[0])) {
      $form_state->setErrorByName('username', $this->t('Username should not start with a number.'));
      return;
    }

    // Check if username is reserved.
    if ($this->isReservedUsername($username)) {
      $form_state->setErrorByName('username', $this->t('This username is reserved. Please choose a different one.'));
      return;
    }

    // Check username uniqueness.
    if ($this->usernameExists($username)) {
      $form_state->setErrorByName('username', $this->t('This username is already taken. Please choose a different one.'));
      $this->logger->info('Username already taken during validation: @username', [
        '@username' => $username,
      ]);
    }
  }

  /**
   * Ajax callback for real-time username validation.
   *
   * Checks username availability without full form submission.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with validation status.
   */
  public function validateUsernameAjax(array &$form, FormStateInterface $form_state) {
    $response = [
      'valid' => TRUE,
      'message' => '',
    ];

    $username = trim($form_state->getValue('username'));

    if (empty($username)) {
      $response['valid'] = FALSE;
      $response['message'] = $this->t('Username is required.');
      return new JsonResponse($response);
    }

    // Validate length.
    if (mb_strlen($username) < self::USERNAME_MIN_LENGTH) {
      $response['valid'] = FALSE;
      $response['message'] = $this->t('Username must be at least @min characters long.', [
        '@min' => self::USERNAME_MIN_LENGTH,
      ]);
      return new JsonResponse($response);
    }

    if (mb_strlen($username) > self::USERNAME_MAX_LENGTH) {
      $response['valid'] = FALSE;
      $response['message'] = $this->t('Username must not exceed @max characters.', [
        '@max' => self::USERNAME_MAX_LENGTH,
      ]);
      return new JsonResponse($response);
    }

    // Validate format.
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
      $response['valid'] = FALSE;
      $response['message'] = $this->t('Username can only contain letters, numbers, and underscores.');
      return new JsonResponse($response);
    }

    // Check if reserved.
    if ($this->isReservedUsername($username)) {
      $response['valid'] = FALSE;
      $response['message'] = $this->t('This username is reserved.');
      return new JsonResponse($response);
    }

    // Check availability.
    if ($this->usernameExists($username)) {
      $response['valid'] = FALSE;
      $response['message'] = $this->t('This username is already taken.');
      return new JsonResponse($response);
    }

    $response['message'] = $this->t('Username is available!');
    return new JsonResponse($response);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $username = trim($form_state->getValue('username'));
      $tempStore = $this->tempStoreFactory->get('waap_login');

      // Get pending user ID from temp store.
      $pendingUserId = $tempStore->get('waap_pending_user');

      if (!$pendingUserId) {
        $this->logger->error('No pending user ID in temp store during username creation submission.');
        $this->messenger->addError($this->t('Authentication session expired. Please try logging in again.'));
        $form_state->setRedirect('user.login');
        return;
      }

      // Load pending user.
      $user = $this->entityTypeManager->getStorage('user')->load($pendingUserId);

      if (!$user) {
        $this->logger->error('Pending user not found during username creation: @uid', [
          '@uid' => $pendingUserId,
        ]);
        $this->messenger->addError($this->t('An error occurred. Please try again.'));
        $form_state->setRedirect('user.login');
        return;
      }

      // Update username.
      $oldUsername = $user->getAccountName();
      $user->setUsername($username);
      $user->set('status', 1); // Ensure user is active.
      $user->save();

      $this->logger->info('Username updated for user @uid: @old -> @new', [
        '@uid' => $pendingUserId,
        '@old' => $oldUsername,
        '@new' => $username,
      ]);

      // Clear temp store data.
      $tempStore->delete('waap_pending_address');
      $tempStore->delete('waap_pending_email');
      $tempStore->delete('waap_pending_user');
      $tempStore->delete('waap_login_type');
      $tempStore->delete('waap_session_data');
      $tempStore->delete('waap_login_status');

      // Log in user.
      $this->userAuth->finalizeLogin($user);

      $this->logger->info('User @uid logged in after username creation', [
        '@uid' => $pendingUserId,
      ]);

      // Show success message.
      $this->messenger->addStatus($this->t('Your account has been created successfully! Welcome, @username!', [
        '@username' => $username,
      ]));

      // Redirect to user dashboard.
      $form_state->setRedirect('entity.user.canonical', ['user' => $pendingUserId]);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error in username creation form submission: @message', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger->addError($this->t('An error occurred. Please try again.'));
      $form_state->setRedirect('user.login');
    }
  }

  /**
   * Generates username from wallet address.
   *
   * Format: waap_<first_6_chars_of_address>
   *
   * @param string $address
   *   The Ethereum wallet address.
   *
   * @return string
   *   The generated username.
   */
  protected function generateUsername(string $address): string {
    // Remove 0x prefix and take first 6 characters.
    $shortAddress = substr(strtolower($address), 2, 6);
    $baseUsername = 'waap_' . $shortAddress;

    // Ensure uniqueness.
    $username = $baseUsername;
    $counter = 1;
    while ($this->usernameExists($username)) {
      $username = $baseUsername . '_' . $counter;
      $counter++;
    }

    return $username;
  }

  /**
   * Checks if a username already exists in the system.
   *
   * @param string $username
   *   The username to check.
   *
   * @return bool
   *   TRUE if username exists, FALSE otherwise.
   */
  protected function usernameExists(string $username): bool {
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => $username]);

    return !empty($users);
  }

  /**
   * Checks if a username is reserved.
   *
   * Reserved usernames include common system names and
   * Drupal default usernames.
   *
   * @param string $username
   *   The username to check.
   *
   * @return bool
   *   TRUE if username is reserved, FALSE otherwise.
   */
  protected function isReservedUsername(string $username): bool {
    $reserved = [
      'admin',
      'administrator',
      'root',
      'system',
      'user',
      'users',
      'guest',
      'anonymous',
      'authenticated',
      'drupal',
      'test',
      'testing',
      'demo',
      'example',
      'sample',
      'waap',
      'login',
      'logout',
      'register',
      'password',
      'settings',
      'config',
      'api',
    ];

    return in_array(strtolower($username), $reserved, TRUE);
  }

}
