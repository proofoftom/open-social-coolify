<?php

namespace Drupal\group\Entity\Views;

use Drupal\views\EntityViewsData;

/**
 * Provides the views data for the group entity type.
 */
class GroupViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    $data['groups_field_data']['id']['argument'] = [
      'id' => 'group_id',
      'name field' => 'label',
      'numeric' => TRUE,
    ];

    $data['groups_field_data']['group_content_id']['relationship'] = [
      'title' => $this->t('Group relationship'),
      'help' => $this->t('Relate to the group relationship entities. From there you can relate to the actual grouped entities.'),
      'id' => 'group_to_group_content',
      'base' => 'group_relationship_field_data',
      'base field' => 'gid',
      'field' => 'id',
      'label' => $this->t('Group relationship'),
    ];

    return $data;
  }

}
