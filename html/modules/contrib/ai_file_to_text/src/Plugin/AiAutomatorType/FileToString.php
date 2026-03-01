<?php

namespace Drupal\ai_file_to_text\Plugin\AiAutomatorType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_automators\Attribute\AiAutomatorType;

/**
 * The rules for a string_long field.
 */
#[AiAutomatorType(
  id: 'file_to_text_string_long',
  label: new TranslatableMarkup('File to Text'),
  field_rule: 'string_long',
  target: '',
)]
class FileToString extends FileToTextBase {

  /**
   * {@inheritDoc}
   */
  public $title = 'File to Text';

}
