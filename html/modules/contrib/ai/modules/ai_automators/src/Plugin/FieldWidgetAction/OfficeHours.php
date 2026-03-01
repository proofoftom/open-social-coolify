<?php

namespace Drupal\ai_automators\Plugin\FieldWidgetAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;

/**
 * The Office Hours action.
 */
#[FieldWidgetAction(
  id: 'automator_office_hours',
  label: new TranslatableMarkup('Automator Office Hours'),
  widget_types: ['office_hours_default', 'office_hours_list'],
  field_types: ['office_hours'],
)]
class OfficeHours extends AutomatorBaseAction {

  /**
   * {@inheritdoc}
   */
  public string $formElementProperty = 'value';

  /**
   * Ajax handler for Automators.
   */
  public function aiAutomatorsAjax(array &$form, FormStateInterface $form_state) {
    // Get the triggering element, as it contains the settings.
    $triggering_element = $form_state->getTriggeringElement();
    $array_parents = $triggering_element['#array_parents'];
    array_pop($array_parents);
    $array_parents[] = $this->formElementProperty;
    $raw_key = $array_parents[2] ?? NULL;
    $key = is_numeric($raw_key) ? (int) $raw_key : NULL;
    $form_key = $array_parents[0];
    return $this->populateAutomatorValues($form, $form_state, $form_key, $key);
  }

  /**
   * {@inheritdoc}
   */
  protected function saveFormValues(array &$form, string $form_key, $entity, ?int $key = NULL): array {
    $properties = ['day', 'starthours', 'endhours'];

    if (is_null($key)) {
      // If no key is provided, we should iterate through all items.
      foreach ($entity->get($form_key) as $index => $item) {
        foreach ($properties as $property) {
          if ($item->get($property)) {
            // Check if the widget has this property as a direct element.
            if (isset($form[$form_key]['widget'][$index][$property])) {
              $form[$form_key]['widget'][$index][$property]['#default_value'] = $item->get($property)->getValue();
            }
          }
        }
      }
    }
    else {
      // Handle specific key/index.
      if (isset($entity->get($form_key)[$key])) {
        $item = $entity->get($form_key)[$key];
        foreach ($properties as $property) {
          if ($item && $item->get($property)) {
            // Check if the widget has this property as a direct element.
            if (isset($form[$form_key]['widget'][$key][$property])) {
              $form[$form_key]['widget'][$key][$property]['#default_value'] = $item->get($property)->getValue();
            }
          }
        }
      }
    }

    return $form[$form_key];
  }

}
