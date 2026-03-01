<?php

namespace Drupal\ginvite\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationship;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Bulk operations related with invitation entity.
 */
class BulkGroupInvitationConfirm extends ConfirmFormBase implements ContainerInjectionInterface {

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
   * Data from previous Form.
   *
   * @var array
   */
  protected $tempstore;

  /**
   * Constructs a new BulkGroupInvitationConfirm Form.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
  ) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;

    // Redirect user to previous form if params are not available.
    if (!$this->tempstore = $this->tempStoreFactory->get('ginvite_bulk_invitation')->get('params')) {
      $group = $this->getRouteMatch()->getParameter('group');
      if ($group instanceof GroupInterface) {
        $destination = new Url('ginvite.invitation.bulk', ['group' => $group->id()]);
      }
      else {
        $destination = new Url('<front>');
      }
      $redirect = new RedirectResponse($destination->toString());
      $this->messenger->addWarning($this->t('Unable to proceed, please try again.'));
      $redirect->send();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private'),
      $container->get('logger.factory'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bulk_group_invitation_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    $group_id = $this->tempstore['gid'] ?? NULL;
    if (!empty($group_id)) {
      $group = $this->getRouteMatch()->getParameter('group');
      if ($group instanceof GroupInterface) {
        $group_id = $group->id();
      }
    }
    if (!empty($group_id)) {
      $destination = new Url('ginvite.invitation.bulk', ['group' => $group_id]);
    }
    else {
      $destination = new Url('<front>');
    }
    return $destination;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to send an invitation to all invitees listed below?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $invitee_list_markup = '';
    foreach ($this->tempstore['invitees'] as $invitee) {
      $invitee_list_markup .= "{$invitee} <br />";
    }

    return $this->t('Invitation recipients: <br /> @invitee_list',
      [
        '@invitee_list' => new FormattableMarkup($invitee_list_markup, []),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $batch = [
      'title' => $this->t('Inviting Members'),
      'operations' => [],
      'init_message'     => $this->t('Sending Invites'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message'    => $this->t('An error occurred during processing'),
      'finished' => 'Drupal\ginvite\Form\BulkGroupInvitationConfirm::batchFinished',
    ];

    foreach ($this->tempstore['invitees'] as $invitee) {

      // Check if it is an email, not to load user everytime.
      if (filter_var($invitee, FILTER_VALIDATE_EMAIL)) {
        $invitee_email = $invitee;
      }
      else {
        $user = user_load_by_name($invitee);
        $invitee_email = $user->getEmail();
      }

      $values = [
        'type' => $this->tempstore['plugin'],
        'gid' => $this->tempstore['gid'],
        'invitee_mail' => $invitee_email,
        'entity_id' => 0,
      ];

      $batch['operations'][] = [
        '\Drupal\ginvite\Form\BulkGroupInvitationConfirm::batchCreateInvite',
        [$values],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch callback to create invitations.
   */
  public static function batchCreateInvite($values, &$context) {
    $invitation = GroupRelationship::create($values);
    $invitation->save();
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      try {
        $tempstore = \Drupal::service('tempstore.private')->get('ginvite_bulk_invitation');
        $destination = new Url('view.group_invitations.page_1', ['group' => $tempstore->get('params')['gid']]);
        $redirect = new RedirectResponse($destination->toString());
        $tempstore->delete('params');
        $redirect->send();
      }
      catch (\Exception $error) {
        \Drupal::service('logger.factory')->get('ginvite')->alert(new TranslatableMarkup('@err', ['@err' => $error]));
      }

    }
    else {
      $error_operation = reset($operations);
      \Drupal::service('messenger')->addMessage(new TranslatableMarkup('An error occurred while processing @operation with arguments : @args', [
        '@operation' => $error_operation[0],
        '@args' => print_r($error_operation[0]),
      ]));
    }
  }

}
