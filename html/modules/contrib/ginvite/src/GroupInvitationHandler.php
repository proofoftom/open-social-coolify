<?php

namespace Drupal\ginvite;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ginvite\Plugin\Group\Relation\GroupInvitation;
use Drupal\group\Entity\GroupRelationshipInterface;

/**
 * Group Invitation Handler.
 *
 * Takes care of group invitation relationship creation for mail
 * or existing user, send invitation emails.
 */
class GroupInvitationHandler implements GroupInvitationHandlerInterface {

  use StringTranslationTrait;

  /**
   * Constructs a GroupInviteHandler object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   Mail manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected MailManagerInterface $mailManager,
    protected MessengerInterface $messenger,
    protected LanguageManagerInterface $languageManager
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function handleGroupInvitation(GroupRelationshipInterface $group_relationship) {
    if ($group_relationship->getPluginId() == 'group_invitation' && $group_relationship->isNew()) {
      $group_relationship->set('invitation_status', GroupInvitation::INVITATION_PENDING);

      $mail = $group_relationship->invitee_mail->value;

      // If invited user has no mail, don't try to send one.
      if (empty($mail)) {
        // "Invitee mail" can be hidden.
        // More info: https://www.drupal.org/project/ginvite/issues/3206103
        // Try to get user from "Invitee" field.
        $invitee = $group_relationship->get('entity_id')->entity;
        // We want to be sure user exists.
        if (!empty($invitee)) {
          $mail = $invitee->getEmail();
          $group_relationship->set('invitee_mail', $mail);
          $this->sendmail($group_relationship, $mail, TRUE);
        }
      }
      else {
        if ($invitee = user_load_by_mail($mail)) {
          $group_relationship->set('entity_id', $invitee);
          $this->sendmail($group_relationship, $mail, TRUE);
        }
        else {
          $this->sendmail($group_relationship, $mail);
        }
      }
    }
  }

  /**
   * Sends mail.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $group_relationship
   *   Group relationship.
   * @param string $mail
   *   Mail.
   * @param bool $existing_user
   *   Send email to existing user.
   */
  protected function sendmail(GroupRelationshipInterface $group_relationship, $mail, $existing_user = FALSE) {
    $group_invite_config = $group_relationship->getPlugin()->getConfiguration();

    $send_email_existing_users = $existing_user && $group_invite_config['send_email_existing_users'];
    $send_email_not_existing_users = !$existing_user && $group_invite_config['send_email_not_existing_users'];

    if (!$send_email_existing_users && !$send_email_not_existing_users) {
      // Skip email sending, just show a message.
      $this->messenger->addMessage($this->t('Invitation has been created.'));
      return;
    }

    $group = $group_relationship->getGroup();

    if ($existing_user && !empty($group_relationship->getEntity())) {
      $langcode = $group_relationship->getEntity()->getPreferredLangcode();
    }
    else {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }

    $params = [
      'group' => $group,
      'group_content' => $group_relationship,
      'existing_user' => $existing_user,
    ];

    $this->mailManager->mail('ginvite', 'invite', $mail, $langcode, $params);
    $this->messenger->addMessage($this->t('Invite sent to %mail', ['%mail' => $mail]));
  }

}
