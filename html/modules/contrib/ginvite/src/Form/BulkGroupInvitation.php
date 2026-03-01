<?php

namespace Drupal\ginvite\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ginvite\GroupInvitationLoader;
use Drupal\group\GroupMembershipLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk operations related with invitation entity.
 */
class BulkGroupInvitation extends FormBase implements ContainerInjectionInterface {

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Group.
   *
   * @var \Drupal\group\Entity\Group
   */
  protected $group;

  /**
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoaderInterface
   */
  protected $groupMembershipLoader;

  /**
   * Group invitations loader.
   *
   * @var \Drupal\ginvite\GroupInvitationLoader
   */
  protected $groupInvitationLoader;

  /**
   * List of users loaded by username.
   *
   * @var array
   */
  protected $users;

  /**
   * Constructs a new BulkGroupInvitation Form.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\group\GroupMembershipLoaderInterface $group_membership_loader
   *   The group membership loader.
   * @param \Drupal\ginvite\GroupInvitationLoader $invitation_loader
   *   Invitations loader service.
   */
  public function __construct(
    RouteMatchInterface $route_match,
    EntityTypeManagerInterface $entity_type_manager,
    PrivateTempStoreFactory $temp_store_factory,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
    GroupMembershipLoaderInterface $group_membership_loader,
    GroupInvitationLoader $invitation_loader,
  ) {
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->tempStoreFactory = $temp_store_factory;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
    $this->groupMembershipLoader = $group_membership_loader;
    $this->groupInvitationLoader = $invitation_loader;
    $this->group = $this->routeMatch->getParameter('group');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('group.membership_loader'),
      $container->get('ginvite.invitation_loader')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bulk_group_invitation';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['invitees'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Select invitees'),
      '#description' => $this->t('You can copy/paste multiple emails or usernames, enter one email or username per line.'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    $form['actions']['submit_cancel'] = [
      '#type' => 'submit',
      '#weight' => 999,
      '#value' => $this->t('Back to group'),
      '#submit' => [[$this, 'cancelForm']],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Cancel form taking you back to a group.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('entity.group.canonical', [
      'group' => $this->group->id(),
      [],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $invitees = $this->getSubmittedInvitees($form_state);

    $error_message = $this->validateInvitees($invitees);
    $error_message .= $this->validateExistingMembers($invitees);
    $error_message .= $this->validateInviteDuplication($invitees);

    if (!empty($error_message)) {
      $form_state->setErrorByName('invitees', new FormattableMarkup($error_message, []));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $group_type = $this->group->getGroupType();
    $relation_type_id = $this->entityTypeManager->getStorage('group_content_type')->getRelationshipTypeId($group_type->id(), 'group_invitation');
    // Prepare params to store them in tempstore.
    $params = [];
    $params['gid'] = $this->group->id();
    $params['plugin'] = $relation_type_id;
    $params['invitees'] = $this->getSubmittedInvitees($form_state);

    $tempstore = $this->tempStoreFactory->get('ginvite_bulk_invitation');

    try {
      $tempstore->set('params', $params);
      // Redirect to confirm form.
      $form_state->setRedirect('ginvite.invitation.bulk.confirm', ['group' => $this->group->id()]);
    }
    catch (\Exception $error) {
      $this->loggerFactory->get('ginvite')->alert($this->t('@err', ['@err' => $error]));
      $this->messenger->addWarning($this->t('Unable to proceed, please try again.'));
    }
  }

  /**
   * Get array of submitted invitees.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   List of invitees.
   */
  private function getSubmittedInvitees(FormStateInterface $form_state) {
    return array_map('trim', array_unique(explode("\r\n", trim($form_state->getValue('invitees')))));
  }

  /**
   * Validate emails or usernames display error message if not valid.
   *
   * @param array $invitees
   *   List of emails or usernames.
   *
   * @return string
   *   Error message.
   */
  private function validateInvitees(array $invitees) {
    $invalid_invitees = [];
    $error_message = '';

    foreach ($invitees as $line => $invitee) {
      if (filter_var($invitee, FILTER_VALIDATE_EMAIL) === FALSE && empty($this->getUserByUsername($invitee))) {
        $invalid_invitees[$line + 1] = $invitee;
      }
    }

    if (!empty($invalid_invitees)) {
      $message_singular = 'The @error_message is not a valid email or username.<br />';
      $message_plural = 'The emails or usernames: @error_message are not valid.<br />';

      $error_message .= $this->getErrorMessage($invalid_invitees, $message_singular, $message_plural);
    }

    return $error_message;
  }

  /**
   * Validate if emails or usernames belong to existing group member.
   *
   * @param array $invitees
   *   List of emails or usernames.
   *
   * @return string
   *   Error message.
   */
  private function validateExistingMembers(array $invitees) {
    $invalid_emails = [];
    $invalid_usernames = [];
    $error_message = '';

    foreach ($invitees as $line => $invitee) {
      if ($user = user_load_by_mail($invitee)) {
        $membership = $this->groupMembershipLoader->load($this->group, $user);
        if (!empty($membership)) {
          $invalid_emails[$line + 1] = $invitee;
        }
      }
      elseif ($user = $this->getUserByUsername($invitee)) {
        $membership = $this->groupMembershipLoader->load($this->group, $user);
        if (!empty($membership)) {
          $invalid_usernames[$line + 1] = $invitee;
        }
      }
    }

    if (!empty($invalid_emails)) {
      $message_singular = 'User with @error_message e-mail is already a member of this group.<br />';
      $message_plural = 'Users with: @error_message e-mails are already members of this group.<br />';

      $error_message .= $this->getErrorMessage($invalid_emails, $message_singular, $message_plural);
    }

    if (!empty($invalid_usernames)) {
      $message_singular = 'User with @error_message username is already a member of this group.<br />';
      $message_plural = 'Users with: @error_message usernames are already members of this group.<br />';

      $error_message .= $this->getErrorMessage($invalid_usernames, $message_singular, $message_plural);
    }

    return $error_message;
  }

  /**
   * Validate if emails or usernames have already been invited.
   *
   * @param array $invitees
   *   List of emails or usernames.
   *
   * @return string
   *   Error message.
   */
  private function validateInviteDuplication(array $invitees) {
    $invalid_emails = [];
    $invalid_usernames = [];
    $error_message = '';

    foreach ($invitees as $line => $invitee) {
      $user = $this->getUserByUsername($invitee);
      if (!empty($user) && $this->groupInvitationLoader->loadByGroup($this->group, NULL, $user->getEmail())) {
        $invalid_usernames[$line + 1] = $invitee;
      }
      elseif ($this->groupInvitationLoader->loadByGroup($this->group, NULL, $invitee)) {
        $invalid_emails[$line + 1] = $invitee;
      }
    }

    if (!empty($invalid_emails)) {
      $message_singular = 'Invitation to user with @error_message email has already been sent.<br />';
      $message_plural = 'Invitations to users with: @error_message emails have already been sent.<br />';

      $error_message .= $this->getErrorMessage($invalid_emails, $message_singular, $message_plural);
    }

    if (!empty($invalid_usernames)) {
      $message_singular = 'Invitation to user with @error_message username has already been sent.<br />';
      $message_plural = 'Invitations to users with: @error_message usernames have already been sent.<br />';

      $error_message .= $this->getErrorMessage($invalid_usernames, $message_singular, $message_plural);
    }

    return $error_message;
  }

  /**
   * Prepares form error message if there is invalid invitees.
   *
   * @param array $invalid_invitees
   *   List of invalid invitees.
   * @param string $message_singular
   *   Error message for one invalid invitee.
   * @param string $message_plural
   *   Error message for multiple invalid invitees.
   */
  private function getErrorMessage(array $invalid_invitees, $message_singular, $message_plural) {
    if (($count = count($invalid_invitees)) > 1) {
      $error_message = '<ul>';
      foreach ($invalid_invitees as $line => $invalid_invitee) {
        $error_message .= "<li>{$invalid_invitee} on the line {$line}</li>";
      }
      $error_message .= '</ul>';
      return $this->formatPlural(
        $count,
        $message_singular,
        $message_plural,
        [
          '@error_message' => new FormattableMarkup($error_message, []),
        ]
      );
    }
    elseif ($count == 1) {
      $error_message = reset($invalid_invitees);
      return $this->formatPlural(
        $count,
        $message_singular,
        $message_plural,
        [
          '@error_message' => $error_message,
        ]
      );
    }
  }

  /**
   * Get user by username.
   *
   * @param string $username
   *   Username.
   *
   * @return \Drupal\user\UserInterface|false|mixed
   *   User or null.
   */
  private function getUserByUsername($username) {
    if (!isset($this->users[$username])) {
      $this->users[$username] = user_load_by_name($username);
    }

    return $this->users[$username];
  }

}
