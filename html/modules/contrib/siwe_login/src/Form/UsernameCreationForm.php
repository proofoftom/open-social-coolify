<?php

namespace Drupal\siwe_login\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\siwe_login\Service\EthereumUserManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Form for username creation during SIWE authentication.
 */
class UsernameCreationForm extends FormBase {

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
   * The Ethereum user manager.
   *
   * @var \Drupal\siwe_login\Service\EthereumUserManager
   */
  protected $userManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new UsernameCreationForm.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\siwe_login\Service\EthereumUserManager $user_manager
   *   The Ethereum user manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    PrivateTempStoreFactory $temp_store_factory,
    EthereumUserManager $user_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->currentUser = $current_user;
    $this->tempStore = $temp_store_factory->get('siwe_login');
    $this->userManager = $user_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('tempstore.private'),
      $container->get('siwe_login.user_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'siwe_username_creation_form';
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

    $form['#title'] = $this->t('Create Username');

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Please create a username for your account. This will be used to identify you on the site.'),
      '#required' => TRUE,
      '#default_value' => '',
      '#maxlength' => 60,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Account'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $username = $form_state->getValue('username');

    // Check if username is already in use.
    $user_storage = $this->entityTypeManager->getStorage('user');
    $existing_users = $user_storage->loadByProperties(['name' => $username]);

    // Remove the current user from the list if they have the same username.
    if ($this->currentUser->isAuthenticated()) {
      unset($existing_users[$this->currentUser->id()]);
    }

    if (!empty($existing_users)) {
      $form_state->setErrorByName('username', $this->t('This username is already in use.'));
    }

    // Validate username format.
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
      $form_state->setErrorByName('username', $this->t('Username can only contain letters, numbers, periods, underscores, and hyphens.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $username = $form_state->getValue('username');

    /**
     * Get the pending SIWE data in tempstore.
     * @var array $siwe_data
     */
    $siwe_data = $this->tempStore->get('pending_siwe_data');
    if (!$siwe_data) {
      $this->messenger()->addError($this->t('No pending SIWE authentication found.'));
      $form_state->setRedirect('<front>');
      return;
    }

    // Add username to the data passed to user manager.
    $siwe_data['username'] = $username;

    // Find or create user account with the provided username.
    $user_manager = $this->userManager;

    // Try to find existing user first.
    $user = $user_manager->findUserByAddress($siwe_data['address']);

    if ($user) {
      // Update existing user's username.
      $user = $user_manager->updateUserUsername($user, $username);
    }
    else {
      // Create new user account with the provided username.
      $user = $user_manager->createUserWithUsername($siwe_data['address'], $siwe_data);
    }

    if ($user) {
      // Clear the tempstore.
      $this->tempStore->delete('pending_siwe_data');

      // Authenticate the user.
      user_login_finalize($user);

      $this->messenger()->addStatus($this->t('Your account has been created successfully.'));

      // Redirect to homepage.
      $form_state->setRedirect('<front>');
    }
    else {
      $this->messenger()->addError($this->t('Unable to create user account.'));
      $form_state->setRedirect('siwe_login.username_creation_form');
    }
  }

}
