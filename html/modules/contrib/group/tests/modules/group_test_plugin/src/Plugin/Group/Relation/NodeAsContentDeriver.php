<?php

namespace Drupal\group_test_plugin\Plugin\Group\Relation;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeInterface;

/**
 * Deriver for page and article content type.
 */
class NodeAsContentDeriver extends DeriverBase {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    assert($base_plugin_definition instanceof GroupRelationTypeInterface);
    $this->derivatives = [];

    $this->derivatives['page'] = clone $base_plugin_definition;
    $this->derivatives['page']->set('entity_bundle', 'page');
    $this->derivatives['page']->set('label', t('Pages as content'));
    $this->derivatives['page']->set('description', t('Adds pages to groups.'));
    $this->derivatives['page']->set('admin_permission', 'administer node_as_content:page');

    $this->derivatives['article'] = clone $base_plugin_definition;
    $this->derivatives['article']->set('entity_bundle', 'article');
    $this->derivatives['article']->set('label', t('Article as content'));
    $this->derivatives['article']->set('description', t('Adds articles to groups.'));
    $this->derivatives['article']->set('admin_permission', 'administer node_as_content:article');

    return $this->derivatives;
  }

}
