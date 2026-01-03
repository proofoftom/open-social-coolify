<?php

namespace Drupal\private_message\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\private_message\Form\BanUserForm;
use Drupal\private_message\Service\PrivateMessageServiceInterface;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Private message page controller. Returns render arrays for the page.
 */
class PrivateMessageController extends ControllerBase implements PrivateMessageControllerInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The form builder interface.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The user data service.
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected $userData;

  /**
   * The private message service.
   *
   * @var \Drupal\private_message\Service\PrivateMessageServiceInterface
   */
  protected $privateMessageService;

  /**
   * The configuration factory service.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a PrivateMessageForm object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity manager service.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder service.
   * @param \Drupal\user\UserDataInterface $userData
   *   The user data service.
   * @param \Drupal\private_message\Service\PrivateMessageServiceInterface $privateMessageService
   *   The private message service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(AccountProxyInterface $currentUser, EntityTypeManagerInterface $entityTypeManager, FormBuilderInterface $formBuilder, UserDataInterface $userData, PrivateMessageServiceInterface $privateMessageService, ConfigFactoryInterface $configFactory) {
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->formBuilder = $formBuilder;
    $this->userData = $userData;
    $this->privateMessageService = $privateMessageService;
    $this->config = $configFactory->get('private_message.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('user.data'),
      $container->get('private_message.service'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function privateMessagePage() {
    $this->privateMessageService->updateLastCheckTime();

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load($this->currentUser->id());

    $private_message_thread = $this->privateMessageService->getFirstThreadForUser($user);

    if ($private_message_thread) {
      $view_builder = $this->entityTypeManager->getViewBuilder('private_message_thread');
      // No wrapper is provided, as the full view mode of the entity already
      // provides the #private-message-page wrapper.
      $page = $view_builder->view($private_message_thread);
    }
    else {
      $page = [
        '#prefix' => '<div id="private-message-page">',
        '#suffix' => '</div>',
        'no_threads' => [
          '#prefix' => '<p>',
          '#suffix' => '</p>',
          '#markup' => $this->t('You do not have any messages'),
        ],
      ];
    }

    return $page;
  }

  /**
   * {@inheritdoc}
   */
  public function pmSettingsPage() {
    $url = Url::fromRoute('private_message.admin_config.config')->toString();
    $message = $this->t('You can find module settings here: <a href="@url">page</a>', ['@url' => $url]);
    return [
      '#markup' => $message,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function pmThreadSettingsPage() {
    return [
      '#markup' => $this->t('Private Message Threads'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function configPage() {
    return [
      '#prefix' => '<div id="private_message_configuration_page">',
      '#suffix' => '</div>',
      'form' => $this->formBuilder->getForm('Drupal\private_message\Form\ConfigForm'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function adminUninstallPage() {
    return [
      'message' => [
        '#prefix' => '<div id="private_message_admin_uninstall_page">',
        '#suffix' => '</div>',
        '#markup' => $this->t('The private message module cannot be uninstalled if there is private message content in the database.'),
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Delete all private message content'),
        '#url' => Url::fromRoute('private_message.admin_config.uninstall_confirm'),
        '#attributes' => [
          'class' => ['button'],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function banUnbanPage(): array {
    $user_has_permission = $this->currentUser->hasPermission('use private messaging system')
      && $this->currentUser->hasPermission('access user profiles');

    $table = [];

    if ($user_has_permission) {
      $rows = [];
      $header = [t('User'), t('Operations')];

      /** @var \Drupal\private_message\Entity\PrivateMessageBan[] $private_message_bans */
      $private_message_bans = $this->entityTypeManager
        ->getStorage('private_message_ban')
        ->loadByProperties(['owner' => $this->currentUser->id()]);

      $destination = Url::fromRoute('<current>')->getInternalPath();
      foreach ($private_message_bans as $private_message_ban) {
        $label = $this->config->get('unban_label');
        $url = Url::fromRoute('private_message.unban_user_form',
          ['user' => $private_message_ban->getTargetId()],
          ['query' => ['destination' => $destination]],
        );
        $unban_link = Link::fromTextAndUrl($label, $url);

        $rows[] = [$private_message_ban->getTarget()->toLink(), $unban_link];
      }

      $table = [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => t('No data found'),
      ];
    }

    return [
      '#prefix' => '<div id="private_message_ban_page">',
      '#suffix' => '</div>',
      'content' => [
        $table,
        [
          'form' => $this->formBuilder->getForm(BanUserForm::class),
        ],
      ],
    ];
  }

}
