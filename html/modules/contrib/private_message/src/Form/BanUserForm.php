<?php

namespace Drupal\private_message\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\private_message\Service\PrivateMessageBanManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Private Message users banning form.
 */
class BanUserForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The private message configuration.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Private Message Ban manager.
   *
   * @var \Drupal\private_message\Service\PrivateMessageBanManagerInterface
   */
  private PrivateMessageBanManagerInterface $privateMessageBanManager;

  /**
   * Constructs a BanUserForm object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory service.
   * @param \Drupal\private_message\Service\PrivateMessageBanManagerInterface $privateMessageBanManager
   *   The Private Message Ban manager.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    PrivateMessageBanManagerInterface $privateMessageBanManager,
  ) {
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
    $this->privateMessageBanManager = $privateMessageBanManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('private_message.ban_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'block_user_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->get('private_message.settings');

    $form['banned_user'] = [
      '#title' => $this->t('Select User'),
      '#required' => TRUE,
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#tags' => FALSE,
      '#selection_handler' => 'private_message:not_blocked_user',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $config->get('ban_label'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $userId = $form_state->getValue('banned_user');
    // Add security to prevent blocking ourselves.
    if ($userId === $this->currentUser->id()) {
      $form_state->setErrorByName($userId, $this->t("You can't block yourself."));
    }

    // Add a security if the user id is unknown.
    if (empty($userId) ||
      empty($this->entityTypeManager->getStorage('user')->load($userId))) {
      $form_state->setErrorByName($userId, $this->t('The user id is unknown.'));
    }

    if (!empty($userId) && $this->privateMessageBanManager->isBanned($userId)) {
      $form_state->setErrorByName($userId, $this->t('The user is already blocked.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $userId = $form_state->getValue('banned_user');
    $this->privateMessageBanManager->banUser($userId);
    $this->messenger()->addStatus($this->t('The user %name has been banned.', [
      '%name' => $this->entityTypeManager->getStorage('user')
        ->load($userId)
        ->getDisplayName(),
    ]));
  }

}
