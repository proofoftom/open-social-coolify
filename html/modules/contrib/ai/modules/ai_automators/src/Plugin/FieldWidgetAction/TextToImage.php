<?php

namespace Drupal\ai_automators\Plugin\FieldWidgetAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;

/**
 * The Text to Image action.
 */
#[FieldWidgetAction(
  id: 'text_to_image',
  label: new TranslatableMarkup('Text to Image'),
  widget_types: ['image_image'],
  field_types: ['image'],
)]
class TextToImage extends AutomatorBaseAction {

  /**
   * {@inheritdoc}
   */
  public string $formElementProperty = 'target_id';

  /**
   * Ajax handler for Automators.
   */
  public function aiAutomatorsAjax(array &$form, FormStateInterface $form_state) {
    // Get the triggering element, as it contains the settings.
    $triggering_element = $form_state->getTriggeringElement();

    // Attempt to get context directly from the triggering element properties
    // set in FieldWidgetActionBase::actionButton.
    $key = $triggering_element['#field_widget_action_field_delta'] ?? NULL;
    $form_key = $triggering_element['#field_widget_action_field_name'] ?? NULL;

    // Fallback logic if properties are missing.
    if ($form_key === NULL) {
      $array_parents = $triggering_element['#array_parents'];
      array_pop($array_parents);
      // Determine form key from parents (usually index 0).
      $form_key = $array_parents[0];

      if ($key === NULL) {
        // Try to guess delta from array parents (usually index 2).
        $potentialKey = $array_parents[2] ?? 0;
        $key = is_numeric($potentialKey) ? (int) $potentialKey : NULL;
      }
    }

    // Ensure key is strictly int or NULL.
    if ($key !== NULL) {
      $key = (int) $key;
    }

    $this->populateAutomatorValues($form, $form_state, $form_key, $key);

    return $form[$form_key];
  }

  /**
   * {@inheritdoc}
   */
  protected function saveFormValues(array &$form, string $form_key, $entity, ?int $key = NULL): array {
    // Helper to set values on a managed_file widget.
    $setValue = function ($index, $item) use (&$form, $form_key) {
      // We need to set the value for the managed_file element.
      // The structure of image_image widget puts the managed_file properties
      // at the root of the widget element for that delta.
      if (isset($form[$form_key]['widget'][$index])) {
        $fid = $item->target_id;
        // 'fids' is used by ManagedFile to track files.
        // We set '#value' which is used during rendering.
        $form[$form_key]['widget'][$index]['#value'] = ['fids' => [$fid]];
        // We also set the 'fids' child element if it exists (hidden input).
        if (isset($form[$form_key]['widget'][$index]['fids'])) {
          $form[$form_key]['widget'][$index]['fids']['#value'] = [$fid];
        }
        // Also update alt/title if available.
        if (isset($form[$form_key]['widget'][$index]['alt']) && $item->alt) {
          $form[$form_key]['widget'][$index]['alt']['#value'] = $item->alt;
        }
        if (isset($form[$form_key]['widget'][$index]['title']) && $item->title) {
          $form[$form_key]['widget'][$index]['title']['#value'] = $item->title;
        }
      }
    };

    if (is_null($key)) {
      foreach ($entity->get($form_key) as $index => $item) {
        if ($item->target_id) {
          $setValue($index, $item);
        }
      }
    }
    else {
      if (isset($entity->get($form_key)[$key])) {
        $item = $entity->get($form_key)[$key];
        if ($item->target_id) {
          $setValue($key, $item);
        }
      }
    }

    return $form[$form_key];
  }

}
