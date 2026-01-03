<?php

declare(strict_types=1);

namespace Drupal\private_message\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines the private message thread member widget.
 */
#[FieldWidget(
  id: 'private_message_thread_member_widget',
  label: new TranslatableMarkup('Private message members autocomplete'),
  description: new TranslatableMarkup('An autocomplete text field with tagging support.'),
  field_types: ['entity_reference'],
  multiple_values: TRUE,
)]
class PrivateMessageThreadMemberWidget extends EntityReferenceAutocompleteWidget implements ContainerFactoryPluginInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $config;

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    protected RequestStack $requestStack,
    protected EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);

    $this->config = $config_factory->get('private_message.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): PrivateMessageThreadMemberWidget {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    return $field_definition->getFieldStorageDefinition()
      ->getTargetEntityTypeId() == 'private_message_thread' && $field_definition->getFieldStorageDefinition()
      ->getSetting('target_type') == 'user';
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'max_members' => 0,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   *
   * The settings summary is returned empty, as the parent settings have no
   * effect on this form.
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();

    unset($summary[0]);

    $summary[] = $this->t('Maximum thread members: @count', ['@count' => $this->getSetting('max_members')]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $form = parent::settingsForm($form, $form_state);

    $form['max_members'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum number of thread members'),
      '#description' => $this->t('The maximum number of members that can be added to the private message conversation. Set to zero (0) to allow unlimited members'),
      '#default_value' => $this->getSetting('max_members'),
      '#min' => 0,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    $maxMembers = $this->getSetting('max_members');

    $element['target_id']['#tags'] = TRUE;
    $element['target_id']['#title'] = $this->t('To');
    $element['target_id']['#required'] = TRUE;
    $element['target_id']['#default_value'] = $items->referencedEntities();
    $element['target_id']['#selection_handler'] = 'private_message:not_blocked_user';
    $element['target_id']['#selection_settings'] = [
      'include_anonymous' => FALSE,
    ];
    $element['target_id']['#validate_reference'] = TRUE;

    $recipient = $this->getDefaultRecipient();
    if ($recipient) {
      $element['target_id']['#default_value'] = $recipient;
    }

    if ($recipient && $this->config->get('hide_recipient_field_when_prefilled')) {
      $maxMembers = 1;
    }

    if ($maxMembers) {
      $element['#element_validate'][] = [__CLASS__, 'validateFormElement'];
      $element['#max_members'] = $maxMembers;
    }

    return $element;
  }

  /**
   * Gets default recipient.
   *
   * @return \Drupal\user\UserInterface|null
   *   Recipient.
   */
  protected function getDefaultRecipient(): ?UserInterface {
    $recipientId = $this->requestStack->getCurrentRequest()->get('recipient');
    if (!$recipientId) {
      return NULL;
    }

    return $this->entityTypeManager
      ->getStorage('user')
      ->load($recipientId);
  }

  /**
   * Validates the form element for number of users.
   *
   * Validates the form element to ensure that no more than the maximum number
   * of allowed users has been entered. This is because the field itself is
   * created as an unlimited cardinality field, but the widget allows for
   * setting a maximum number of users.
   */
  public static function validateFormElement(array $element, FormStateInterface $form_state): void {
    $inputExists = FALSE;
    $input = NestedArray::getValue($form_state->getValues(), $element['#parents'], $inputExists);
    $values = $input['target_id'] ?? [];
    if ($inputExists && count($values) > $element['#max_members']) {
      $form_state->setError($element, t('Private messages threads cannot have more than @count members', ['@count' => $element['#max_members']]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    return $values['target_id'];
  }

}
