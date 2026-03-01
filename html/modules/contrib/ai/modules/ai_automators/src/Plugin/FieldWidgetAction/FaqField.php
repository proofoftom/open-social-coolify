<?php

namespace Drupal\ai_automators\Plugin\FieldWidgetAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;

/**
 * The FAQ Field action.
 */
#[FieldWidgetAction(
  id: 'automator_faqfield',
  label: new TranslatableMarkup('Automator FAQ Field'),
  widget_types: ['faqfield_default'],
  field_types: ['faqfield'],
)]
class FaqField extends AutomatorBaseAction {

  /**
   * {@inheritdoc}
   */
  public string $formElementProperty = 'question';

  /**
   * Ajax handler for Automators.
   */
  public function aiAutomatorsAjax(array &$form, FormStateInterface $form_state) {
    // Get the triggering element, as it contains the settings.
    $triggering_element = $form_state->getTriggeringElement();

    // Attempt to get context directly from the triggering element properties.
    $form_key = $triggering_element['#field_widget_action_field_name'] ?? NULL;

    // Fallback logic if properties are missing.
    if ($form_key === NULL) {
      $array_parents = $triggering_element['#array_parents'];
      array_pop($array_parents);
      // Determine form key from parents (usually index 0).
      $form_key = $array_parents[0];
    }

    $this->populateAutomatorValues($form, $form_state, $form_key, NULL);

    return $form[$form_key];
  }

  /**
   * {@inheritdoc}
   */
  protected function saveFormValues(array &$form, string $form_key, $entity, ?int $key = NULL): array {
    $setValues = function ($index, $item) use (&$form, $form_key) {
      if (isset($form[$form_key]['widget'][$index])) {
        // Set Question.
        if (isset($form[$form_key]['widget'][$index]['question'])) {
          $form[$form_key]['widget'][$index]['question']['#value'] = $item->question;
        }
        // Set Answer.
        if (isset($form[$form_key]['widget'][$index]['answer'])) {
          if (isset($form[$form_key]['widget'][$index]['answer']['value'])) {
            // Handle text_format structure.
            $form[$form_key]['widget'][$index]['answer']['value']['#value'] = $item->answer;
          }
          else {
            // Handle simple textarea/textfield structure.
            $form[$form_key]['widget'][$index]['answer']['#value'] = $item->answer;
          }
        }
      }
    };

    if (is_null($key)) {
      foreach ($entity->get($form_key) as $index => $item) {
        // Only set if we have values.
        if ($item->question || $item->answer) {
          $setValues($index, $item);
        }
      }
    }
    else {
      if (isset($entity->get($form_key)[$key])) {
        $item = $entity->get($form_key)[$key];
        if ($item->question || $item->answer) {
          $setValues($key, $item);
        }
      }
    }

    return $form[$form_key];
  }

}
