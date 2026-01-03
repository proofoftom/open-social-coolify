<?php

declare(strict_types=1);

namespace Drupal\views_ef_fieldset;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Class for sorting and transforming Views EF Fieldset data.
 */
class ViewsEFFieldsetData {
  use StringTranslationTrait;

  /**
   * The data.
   *
   * @var array
   */
  private $data;

  /**
   * The element.
   *
   * @var array
   */
  private $elements;

  /**
   * The form.
   *
   * @var array
   */
  private $form;

  /**
   * ViewsEFFieldsetData constructor.
   *
   * @param array $data
   *   The data.
   * @param array $form
   *   The form.
   */
  public function __construct(array $data, array &$form = []) {
    $this->data = $data;
    $this->elements = $data;
    $this->form = &$form;
  }

  /**
   * Build a flat array.
   *
   * @return array
   *   The flatten array.
   */
  public function buildFlat() {
    $data = [];

    $recursive_iter_iter = new \RecursiveIteratorIterator(
      new ArrayDataItemIterator($this->buildTreeData()),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($recursive_iter_iter as $item) {
      $item['item']['depth'] = $recursive_iter_iter->getDepth();
      $data[] = $item;
    }

    return $data;
  }

  /**
   * Built the tree data.
   *
   * @return array
   *   The tree data.
   */
  public function buildTreeData() {
    return $this->parseTree($this->elements);
  }

  /**
   * Convert the tree data into form api data.
   *
   * @return array
   *   The array of elements.
   */
  public function treetofapi() {
    $elements = [];

    $this->recursivetreetofapi($this->buildTreeData(), $this->form, $elements);

    return $elements;
  }

  /**
   * Parse the tree.
   *
   * @param array $elements
   *   The elements.
   * @param string $rootParentID
   *   The root parent ID.
   * @param int $depth
   *   The depth.
   *
   * @return array
   *   The array.
   */
  private function parseTree(array &$elements, $rootParentID = '', $depth = -1) {
    $branch = [];
    $depth++;

    foreach ($elements as $element) {
      $element['depth'] = $depth;
      if ($element['pid'] !== $rootParentID) {
        continue;
      }
      $branch[] = [
        'item' => $element,
        'children' => $this->parseTree($elements, $element['id'], $depth),
      ];
    }

    // Automatically get sorted results.
    usort($branch, [$this, 'sortByWeight']);

    return empty($branch) ? [] : $branch;
  }

  /**
   * Helper function that moves a form element.
   *
   * @param string $field_name
   *   The field name.
   * @param array $item
   *   The item.
   * @param array $form_info
   *   The form info.
   * @param array $form
   *   The form.
   * @param bool $operator_element
   *   Are we moving an operator element?
   *
   * @return array
   *   The converted element.
   */
  private function moveFormElement($field_name, array $item, array $form_info, array &$form, $operator_element = FALSE): array {
    $element = $form[$field_name] +
      [
        '#weight' => $operator_element ? $item['item']['weight'] - 1 : $item['item']['weight'],
        '#title' => $form_info['label'] ?: '',
        '#description' => $form_info['description'] ?: '',
      ];
    unset($form['#info']['filter-' . $item['item']['id']]);
    unset($form[$field_name]);

    return $element;
  }

  /**
   * Tree to FAPI recursive.
   *
   * @param array $data
   *   The data.
   * @param array $form
   *   The form.
   * @param array $element
   *   The element.
   */
  private function recursivetreetofapi(array $data, array &$form, array &$element = []) {
    foreach ($data as $item) {

      // If it's a filter field.
      if ($item['item']['type'] === 'filter') {
        $form_info = $form['#info']['filter-' . $item['item']['id']] ?? NULL;

        $field_name = $form_info['value'] ?? $item['item']['id'];
        if (isset($form[$field_name]) && is_array($form[$field_name])) {
          $element[$field_name] = $this->moveFormElement($field_name, $item, $form_info, $form);
          // Check if there's an operator exposed and handle it as well.
          $field_name_op = $field_name . '_op';
          if (array_key_exists($field_name_op, $form) && is_array($form[$field_name_op])) {
            $element[$field_name_op] = $this->moveFormElement($field_name_op, $item, $form_info, $form, TRUE);
          }
        }
        else {
          // Since issue #2625136 in Drupal 9,
          // exposed operators are placed inside a wrapper.
          $field_name_wrapper = $field_name . '_wrapper';
          if (array_key_exists($field_name_wrapper, $form) && is_array($form[$field_name_wrapper])) {
            $element[$field_name_wrapper] = $this->moveFormElement($field_name_wrapper, $item, $form_info, $form, TRUE);
          }
        }
      }

      // If it's a sort field.
      if ($item['item']['type'] === 'sort') {
        $field_name = $item['item']['id'];

        if (isset($form[$field_name]) && is_array($form[$field_name])) {
          $element[$field_name] = $form[$field_name];
          $element[$field_name]['#weight'] = $item['item']['weight'];
          unset($form[$field_name]);
        }
      }

      // If it's the action buttons.
      if ($item['item']['type'] === 'buttons') {
        $field_name = $item['item']['id'];

        if (isset($form['actions'][$field_name]) && is_array($form['actions'][$field_name])) {
          $button = $form['actions'][$field_name];
          $button['#weight'] = $item['item']['weight'];
          $button['#access'] = TRUE;
          $element[$field_name] = $button;
          $form['actions'][$field_name]['#attributes']['style'][] = 'display:none;';
          // unset($form['actions'][$field_name]);.
        }
      }

      if (!empty($item['children']) && $item['item']['type'] === 'container') {
        $element['container-' . $item['item']['id']] = [
          '#type' => $item['item']['container_type'],
          '#title' => $this->t('@title', ['@title' => $item['item']['title']]),
          '#group' => 'container-' . $item['item']['pid'],
          '#description' => $this->t('@description', ['@description' => $item['item']['description']]),
          '#open' => (bool) $item['item']['open'],
          '#attributes' => [
            'class' => [
              'views-ef-fieldset-container',
              'views-ef-fieldset-' . $item['item']['id'],
            ],
          ],
          '#weight' => $item['item']['weight'],
        ];
        $element['container-' . $item['item']['id']]['children'] = [];
        $this->recursivetreetofapi($item['children'], $form, $element['container-' . $item['item']['id']]['children']);
      }
    }
  }

  /**
   * Internal function used to sort array items by weight.
   *
   * @param array $a
   *   First element.
   * @param array $b
   *   Second element.
   *
   * @return int
   *   The weight.
   */
  private function sortByWeight(array $a, array $b) {
    if ($a['item']['weight'] === $b['item']['weight']) {
      return 0;
    }

    return $a['item']['weight'] < $b['item']['weight'] ? -1 : 1;
  }

}
