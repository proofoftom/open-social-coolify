<?php

namespace Drupal\ai_automators\Plugin\FieldWidgetAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;

/**
 * The Chart action.
 */
#[FieldWidgetAction(
  id: 'automator_chart',
  label: new TranslatableMarkup('Automator Chart'),
  widget_types: ['chart_config_default'],
  field_types: ['chart_config'],
)]
class Chart extends AutomatorBaseAction {

  /**
   * {@inheritdoc}
   */
  public string $formElementProperty = 'config';

  /**
   * Ajax handler for Automators.
   */
  public function aiAutomatorsAjax(array &$form, FormStateInterface $form_state) {
    // Get the triggering element, as it contains the settings.
    $triggering_element = $form_state->getTriggeringElement();
    $array_parents = $triggering_element['#array_parents'];
    array_pop($array_parents);
    $array_parents[] = $this->formElementProperty;
    $key = $array_parents[2] ?? 0;
    $form_key = $array_parents[0];

    return $this->populateAutomatorValues($form, $form_state, $form_key, $key);
  }

  /**
   * {@inheritdoc}
   */
  protected function saveFormValues(array &$form, string $form_key, $entity, ?int $key = NULL): array {
    if (is_null($key)) {
      // If no key is provided, we should iterate through all items.
      foreach ($entity->get($form_key) as $index => $item) {
        if ($item->get($this->formElementProperty)) {
          $value = $item->get($this->formElementProperty)->getValue();
          if (isset($value['series']['data_collector_table'])) {
            $table_element = &$form[$form_key]['widget'][$index]['config']['series']['data_collector_table'];
            $this->populateTableValues($table_element, $value['series']['data_collector_table']);
          }
        }
      }
    }
    else {
      // Handle specific key/index.
      if (isset($entity->get($form_key)[$key])) {
        $item = $entity->get($form_key)[$key];
        if ($item && $item->get($this->formElementProperty)) {
          $value = $item->get($this->formElementProperty)->getValue();
          if (isset($value['series']['data_collector_table'])) {
            $table_element = &$form[$form_key]['widget'][$key]['config']['series']['data_collector_table'];
            $this->populateTableValues($table_element, $value['series']['data_collector_table']);
          }
        }
      }
    }

    return $form[$form_key];
  }

  /**
   * Helper to populate table values.
   */
  protected function populateTableValues(array &$element, array $values) {
    // Ensure values is actually an array.
    if (!is_array($values)) {
      return;
    }

    if (!empty($values)) {
      $element['#value'] = $values;
      $element['#default_value'] = $values;
      $template_data = NULL;
      $template_color = NULL;

      // Try to find a template in existing children.
      if (isset($element[0][0]['data'])) {
        $template_data = $element[0][0]['data'];
        unset($template_data['#value'], $template_data['#default_value']);
      }
      else {
        $template_data = [
          '#type' => 'textfield',
          '#title' => '',
          '#title_display' => 'invisible',
          '#size' => 10,
        ];
      }

      if (isset($element[0][0]['color'])) {
        $template_color = $element[0][0]['color'];
        unset($template_color['#value'], $template_color['#default_value']);
      }
      else {
        $template_color = [
          '#type' => 'textfield',
          '#title' => '',
          '#title_display' => 'invisible',
          '#size' => 10,
        ];
      }

      $existing_col_keys = array_filter(array_keys($element), function ($k) {
        return strpos((string) $k, '#') !== 0;
      });
      $existing_col_keys = array_values($existing_col_keys);

      foreach ($values as $col_key => $col_data) {
        $target_col_key = $existing_col_keys[$col_key] ?? $col_key;

        // Ensure col_data is an array before processing.
        if (!is_array($col_data)) {
          continue;
        }

        if (!isset($element[$target_col_key])) {
          $element[$target_col_key] = [];
        }

        $existing_row_keys = array_filter(array_keys($element[$target_col_key]), function ($k) {
          return strpos((string) $k, '#') !== 0;
        });
        $existing_row_keys = array_values($existing_row_keys);

        foreach ($col_data as $row_key => $cell_data) {
          $target_row_key = $existing_row_keys[$row_key] ?? $row_key;

          // Normalize cell_data - handle cases where it might be a
          // TranslatableMarkup or string.
          if (!is_array($cell_data)) {
            $cell_data = [
              'data' => $cell_data instanceof TranslatableMarkup ? $cell_data->__toString() : (string) $cell_data,
            ];
          }

          if (!isset($element[$target_col_key][$target_row_key]['data']) && $template_data) {
            $element[$target_col_key][$target_row_key]['data'] = $template_data;
          }
          if (!isset($element[$target_col_key][$target_row_key]['data']) && empty($template_data)) {
            $element[$target_col_key][$target_row_key]['data'] = [];
          }
          if (!isset($element[$target_col_key][$target_row_key]['color']) && $template_color && isset($cell_data['color'])) {
            $element[$target_col_key][$target_row_key]['color'] = $template_color;
          }

          if (isset($element[$target_col_key][$target_row_key]['data']) && isset($cell_data['data'])) {
            $data_value = $cell_data['data'] instanceof TranslatableMarkup ? $cell_data['data']->__toString() : $cell_data['data'];
            $element[$target_col_key][$target_row_key]['data']['#value'] = $data_value;
            $element[$target_col_key][$target_row_key]['data']['#default_value'] = $data_value;
          }
          if (isset($element[$target_col_key][$target_row_key]['color']) && isset($cell_data['color'])) {
            $color_value = $cell_data['color'] instanceof TranslatableMarkup ? $cell_data['color']->__toString() : $cell_data['color'];
            $element[$target_col_key][$target_row_key]['color']['#value'] = $color_value;
            $element[$target_col_key][$target_row_key]['color']['#default_value'] = $color_value;
          }
        }
      }
    }
  }

}
