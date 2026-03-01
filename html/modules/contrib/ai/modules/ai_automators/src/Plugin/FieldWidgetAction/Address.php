<?php

declare(strict_types=1);

namespace Drupal\ai_automators\Plugin\FieldWidgetAction;

use Drupal\address\Element\Address as AddressElement;
use Drupal\address\Element\Country;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;

/**
 * The Address Field Widget Action.
 */
#[FieldWidgetAction(
  id: 'automator_address',
  label: new TranslatableMarkup('Automator Address'),
  widget_types: ['address_default'],
  field_types: ['address'],
)]
class Address extends AutomatorBaseAction {

  /**
   * {@inheritdoc}
   */
  protected bool $clearEntity = FALSE;

  /**
   * Stores form state for use in populateAddressComponents().
   *
   * @var \Drupal\Core\Form\FormStateInterface|null
   */
  protected ?FormStateInterface $currentFormState = NULL;

  /**
   * Ajax handler for Automators.
   */
  public function aiAutomatorsAjax(array &$form, FormStateInterface $form_state): array {
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
  public function populateAutomatorValues(array &$form, FormStateInterface $form_state, string $form_key, ?int $key = NULL): array {
    $this->currentFormState = $form_state;
    try {
      return parent::populateAutomatorValues($form, $form_state, $form_key, $key);
    }
    finally {
      $this->currentFormState = NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function saveFormValues(array &$form, string $form_key, $entity, ?int $key = NULL): array {
    if ($key === NULL) {
      $this->populateAllItems($form, $form_key, $entity);
    }
    else {
      $this->populateSingleItem($form, $form_key, $entity, $key);
    }

    return $form[$form_key];
  }

  /**
   * Populate all address field items.
   *
   * @param array $form
   *   The form array.
   * @param string $form_key
   *   The form key for the field.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being worked on.
   */
  protected function populateAllItems(array &$form, string $form_key, ContentEntityInterface $entity): void {
    foreach ($entity->get($form_key) as $index => $item) {
      $this->populateAddressComponents($form, $form_key, $index, $item);
    }
  }

  /**
   * Populate a single address field item.
   *
   * @param array $form
   *   The form array.
   * @param string $form_key
   *   The form key for the field.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being worked on.
   * @param int $key
   *   The key for the field item.
   */
  protected function populateSingleItem(array &$form, string $form_key, ContentEntityInterface $entity, int $key): void {
    $items = $entity->get($form_key);
    if (!isset($items[$key])) {
      return;
    }

    $item = $items[$key];
    if ($item) {
      $this->populateAddressComponents($form, $form_key, $key, $item);
    }
  }

  /**
   * Populate address components for a field item.
   *
   * Re-runs processAddress() and processCountry() so that all address
   * sub-fields are rebuilt with the automator-provided values.
   *
   * @param array $form
   *   The form array.
   * @param string $form_key
   *   The form key for the field.
   * @param int $index
   *   The item index.
   * @param mixed $item
   *   The field item.
   */
  protected function populateAddressComponents(array &$form, string $form_key, int $index, $item): void {
    if (!isset($form[$form_key]['widget'][$index]['address'])) {
      return;
    }

    $address_element = &$form[$form_key]['widget'][$index]['address'];
    $address_values = $item->toArray();

    // Set values on the parent element for processAddress() to pick up.
    foreach ($address_values as $property_name => $value) {
      if ($value === NULL) {
        continue;
      }
      $address_element['#value'][$property_name] = $value;
      $address_element['#default_value'][$property_name] = $value;
    }

    // Clean stale children and re-process the address element.
    $this->cleanProcessedChildren($address_element);
    $complete_form = $form;
    $address_element = AddressElement::processAddress($address_element, $this->currentFormState, $complete_form);

    // Process the country element to build its select dropdown.
    if (isset($address_element['country_code'])) {
      $address_element['country_code'] = Country::processCountry($address_element['country_code'], $this->currentFormState, $complete_form);
      if (isset($address_element['country_code']['country_code'])) {
        $address_element['country_code']['country_code']['#value'] = $address_values['country_code'] ?? '';
      }
    }

    // Explicitly set #value on each sub-field since doBuildForm() won't run
    // on elements created mid-AJAX. Skip country_code and langcode as they
    // are handled separately above.
    $skip = ['country_code', 'langcode'];
    foreach ($address_values as $property_name => $value) {
      if ($value === NULL || in_array($property_name, $skip, TRUE) || !isset($address_element[$property_name])) {
        continue;
      }
      $address_element[$property_name]['#value'] = $value;
    }
  }

  /**
   * Removes child elements from a previous processAddress() call.
   *
   * @param array $element
   *   The address element to clean.
   */
  protected function cleanProcessedChildren(array &$element): void {
    $address_properties = [
      'country_code', 'langcode', 'given_name', 'additional_name',
      'family_name', 'organization', 'address_line1', 'address_line2',
      'address_line3', 'postal_code', 'sorting_code', 'dependent_locality',
      'locality', 'administrative_area',
    ];

    foreach ($address_properties as $property) {
      unset($element[$property]);
    }

    // Remove inline grouping containers.
    foreach (array_keys($element) as $key) {
      if (is_string($key) && str_starts_with($key, 'container')) {
        unset($element[$key]);
      }
    }

    // Remove structural properties to prevent duplicate wrapping.
    unset(
      $element['#prefix'],
      $element['#suffix'],
      $element['#wrapper_id'],
      $element['#tree'],
      $element['#parsed_field_overrides']
    );
  }

}
