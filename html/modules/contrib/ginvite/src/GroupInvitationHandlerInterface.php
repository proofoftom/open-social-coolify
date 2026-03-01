<?php

namespace Drupal\ginvite;

use Drupal\group\Entity\GroupRelationshipInterface;

/**
 * Defines the group invitation handler interface.
 */
interface GroupInvitationHandlerInterface {

  /**
   * Handle group invitations.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $group_relationship
   *   The relationship for which to send invitation.
   */
  public function handleGroupInvitation(GroupRelationShipInterface $group_relationship);

}
