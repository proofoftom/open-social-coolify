<?php

namespace Drupal\ai_file_to_text\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_automators\Attribute\AiAutomatorType;

/**
 * The rules for a text_long field.
 */
#[AiAutomatorType(
  id: 'file_to_text_text_long',
  label: new TranslatableMarkup('File to Text'),
  field_rule: 'text_long',
  target: '',
)]
class FileToText extends FileToTextBase {

  /**
   * {@inheritDoc}
   */
  public $title = 'File to Text';

  /**
   * {@inheritDoc}
   */
  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition, array $automatorConfig) {
    // Get text format only for HTML output.
    $output_format = $automatorConfig['output_format'] ?? 'text';
    $text_format = ($output_format === 'html') ? $this->getTextFormat($fieldDefinition) : NULL;

    $cleaned_values = [];
    foreach ($values as $value) {
      $item = ['value' => $value];
      if ($text_format) {
        $item['format'] = $text_format;
      }
      $cleaned_values[] = $item;
    }
    $entity->set($fieldDefinition->getName(), $cleaned_values);
  }

  /**
   * Get text format.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition.
   *
   * @return string|null
   *   The format.
   */
  protected function getTextFormat(FieldDefinitionInterface $fieldDefinition) {
    $all_formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    if (empty($all_formats)) {
      return NULL;
    }
    $format = $fieldDefinition->getSetting('allowed_formats');
    return $format[0] ?? key($all_formats);
  }

}
