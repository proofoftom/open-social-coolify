<?php

namespace Drupal\data_policy;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_policy\Entity\DataPolicyInterface;
use Drupal\data_policy\Entity\UserConsentInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Redirection subscriber.
 *
 * @package Drupal\data_policy
 */
class RedirectSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The current active route match object.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The redirect destination helper.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $destination;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Data Policy consent manager.
   *
   * @var \Drupal\data_policy\DataPolicyConsentManagerInterface
   */
  protected $dataPolicyConsentManager;

  /**
   * The module handler interface.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  public $database;

  /**
   * RedirectSubscriber constructor.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current active route match object.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $destination
   *   The redirect destination helper.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\data_policy\DataPolicyConsentManagerInterface $data_policy_manager
   *   The Data Policy consent manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler interface.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    RouteMatchInterface $route_match,
    RedirectDestinationInterface $destination,
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    DataPolicyConsentManagerInterface $data_policy_manager,
    ModuleHandlerInterface $module_handler,
    Connection $database
  ) {
    $this->routeMatch = $route_match;
    $this->destination = $destination;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->dataPolicyConsentManager = $data_policy_manager;
    $this->moduleHandler = $module_handler;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['checkForRedirection', '28'];
    return $events;
  }

  /**
   * This method is called when the KernelEvents::REQUEST event is dispatched.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event.
   */
  public function checkForRedirection(RequestEvent $event): void {
    $request = $event->getRequest();

    // Skip AJAX requests (e.g., Views / Facet updates).
    if ($request->isXmlHttpRequest()) {
      return;
    }

    // Check if a data policy is set.
    if (!$this->dataPolicyConsentManager->isDataPolicy()) {
      return;
    }

    // A non-Drupal UI application is making requests, lets leave the redirect to that
    // application instead, as we don't want to bring a dependency towards simple_oauth
    // we use the interface_exists to ensure it actually can be used.
    if (interface_exists(TokenAuthUserInterface::class) && $this->currentUser->getAccount() instanceof TokenAuthUserInterface) {
      return;
    }

    // Check if the current route is the data policy agreement page.
    if (($route_name = $this->routeMatch->getRouteName()) === 'data_policy.data_policy.agreement') {
      // The current route is the data policy agreement page. We don't need
      // a redirect response.
      return;
    }

    $route_names = [
      'entity.user.cancel_form',
      'data_policy.data_policy',
      'image.style_private',
      'system.403',
      'system.404',
      'system.batch_page.html',
      'system.batch_page.json',
      'system.css_asset',
      'system.js_asset',
      'user.cancel_confirm',
      'user.logout',
      'entity_sanitizer_image_fallback.generator',
    ];

    if (in_array($route_name, $route_names, TRUE)) {
      return;
    }

    if ($this->currentUser->hasPermission('without consent')) {
      return;
    }

    // Check if entity tokens exist in the consent text in the settings form.
    $entity_ids = $this->dataPolicyConsentManager->getEntityIdsFromConsentText();
    if (empty($entity_ids)) {
      return;
    }

    // At least one data policy entity should exist.
    $existing_data_policy = $this->entityTypeManager
      ->getStorage('data_policy')
      ->getQuery()
      ->accessCheck()
      ->execute();
    if (empty($existing_data_policy)) {
      return;
    }

    $existing_user_consents = $this->dataPolicyConsentManager->getExistingUserConsents($this->currentUser->id());
    $is_required_id = $this->dataPolicyConsentManager->isRequiredEntityInEntities($entity_ids);
    $user_agreed_on_required = $this->dataPolicyConsentManager->didUserAgreeOnRequiredEntities($entity_ids);

    // Do redirect if the user did not agree on required consents.
    if ($is_required_id && !$user_agreed_on_required) {
      $this->doRedirect($event);
      return;
    }

    // Get the data policy revisions.
    $revisions = $this->dataPolicyConsentManager->getRevisionsByEntityIds($entity_ids, TRUE);

    // If a new data policy was created then we should display a link or do
    // redirect to the agreement page.
    $user_revisions = $this->dataPolicyConsentManager->getActiveUserRevisionData($entity_ids);

    $existing_revisions = array_column($user_revisions, 'data_policy_revision_id_value');
    $diff = array_diff($revisions, $existing_revisions);
    $is_new_consents = array_keys(array_diff($revisions, $existing_revisions));

    // If new consent is created, and if it's required, then redirect
    // to the agreement page.
    if (!empty($diff) && !empty($is_new_consents)) {
      $is_new_required = $this->dataPolicyConsentManager->isRequiredEntityInEntities($is_new_consents);

      if ($is_new_required) {
        $this->doRedirect($event);
        return;
      }
    }

    // If no new consent and no pending required consent, do nothing.
    if (empty($diff) && empty($this->dataPolicyConsentManager->getActiveUserRevisionData($entity_ids, TRUE)
      ->condition('state', UserConsentInterface::STATE_UNDECIDED)
      ->execute()
      ->fetchAll()) && empty($is_new_consents)) {
      return;
    }

    $this->addStatusLink();
  }

  /**
   * Do redirect to the agreement page.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event.
   */
  private function doRedirect(RequestEvent $event): void {
    // Set the destination that redirects the user after accepting the
    // data policy agreements.
    $destination = $this->getDestination();

    // Check if there are hooks to invoke that do an override.
    if ($this->moduleHandler->hasImplementations('data_policy_destination_alter')) {
      $implementations = $this->moduleHandler->invokeAll('data_policy_destination_alter', [
        $this->currentUser,
        $this->getDestination(),
      ]);

      $destination = end($implementations);
    }

    $url = Url::fromRoute('data_policy.data_policy.agreement', [], [
      'query' => $destination->getAsArray(),
    ]);

    $response = new RedirectResponse($url->toString());
    $event->setResponse($response);
  }

  /**
   * Add the status link.
   */
  private function addStatusLink() {
    if ($this->routeMatch->getRouteName() !== 'data_policy.data_policy.agreement') {
      $link = Link::createFromRoute($this->t('here'), 'data_policy.data_policy.agreement');
      $this->messenger->addStatus($this->t('We published a new version of the data protection statement. You can review the data protection statement @url.', [
        '@url' => $link->toString(),
      ]));
    }
  }

  /**
   * Get the redirect destination.
   *
   * @return \Drupal\Core\Routing\RedirectDestinationInterface
   *   The redirect destination.
   */
  protected function getDestination(): RedirectDestinationInterface {
    return $this->destination;
  }

}
