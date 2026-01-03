<?php

declare(strict_types=1);

namespace Drupal\private_message\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\Plugin\EntityReferenceSelection\UserSelection;
use Drupal\user\RoleInterface;

/**
 * Provides reference selection for not blocked users.
 */
#[EntityReferenceSelection(
  id: "private_message:not_blocked_user",
  label: new TranslatableMarkup("Not blocked user selection"),
  group: "private_message",
  weight: 3,
  entity_types: ["user"]
)]
class NotBlockedUserSelection extends UserSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS'): QueryInterface {
    $query = parent::buildEntityQuery($match, $match_operator);

    $rids = $this->getCanUseRids();
    // Provide condition which will not return any results.
    if (empty($rids)) {
      $query->condition('uid');
      return $query;
    }

    $subquery = $this->connection->select('private_message_ban', 'pmb')
      ->fields('pmb', ['target']);
    $subquery->condition('owner', $this->currentUser->id());

    $query->condition('uid', $subquery, 'NOT IN');
    if (!in_array('authenticated', $rids)) {
      $query->condition('roles', $rids, 'IN');
    }

    $query->condition('uid', $this->currentUser->id(), '<>');

    return $query;
  }

  /**
   * Returns role ids with permission to use PM system.
   *
   * @return int[]|string[]
   *   Array of role IDs.
   */
  protected function getCanUseRids(): array {
    $use_pm_permission = 'use private messaging system';
    $roles = array_filter(
      $this->entityTypeManager->getStorage('user_role')->loadMultiple(),
      fn(RoleInterface $role): bool => $role->hasPermission($use_pm_permission),
    );
    return array_keys($roles);
  }

}
