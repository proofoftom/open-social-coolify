<?php

namespace Drupal\ai_file_to_text\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ai_automators\PluginBaseClasses\ExternalBase;
use Drupal\ai_automators\PluginInterfaces\AiAutomatorTypeInterface;
use Drupal\ai_file_to_text\Extractor\FileExtractorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a base class for converting document files to text.
 *
 */
class FileToTextBase extends ExternalBase implements AiAutomatorTypeInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The file extractor manager service.
   *
   * @var \Drupal\ai_file_to_text\Extractor\FileExtractorManager
   */
  protected FileExtractorManager $extractorManager;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->fileSystem = $container->get('file_system');
    $instance->extractorManager = $container->get('ai_file_to_text.extractor_manager');
    return $instance;
  }

  /**
   * {@inheritDoc}
   */
  public function needsPrompt() {
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function advancedMode() {
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function placeholderText() {
    return "";
  }

  /**
   * {@inheritDoc}
   */
  public function allowedInputs() {
    return [
      'file',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function extraFormFields(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, FormStateInterface $formState, array $defaultValues = []) {
    $form = parent::extraFormFields($entity, $fieldDefinition, $formState, $defaultValues);

    $form['automator_output_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Output Format'),
      '#options' => [
        'text' => $this->t('Plain Text'),
        'html' => $this->t('HTML'),
        'markdown' => $this->t('Markdown'),
      ],
      '#description' => $this->t('Plain text returns unformatted text. HTML includes tags (headings, lists, tables, bold, italic, etc.). Markdown returns Markdown-formatted output.'),
      '#default_value' => $defaultValues['automator_output_format'] ?? 'text',
      '#weight' => 20,
    ];

    $form['automator_native_headings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use class-based heading 1'),
      '#description' => $this->t('When checked, H1 headings are rendered as &lt;p class="h1"&gt; instead of native &lt;h1&gt; tags. Useful when the surrounding page already provides its own H1. H2–H6 always keep their native hN tags with an additional class="hN".'),
      '#default_value' => $defaultValues['automator_native_headings'] ?? TRUE,
      '#weight' => 21,
      '#states' => [
        'visible' => [
          ':input[name="automator_output_format"]' => ['value' => 'html'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function generate(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig) {
    $output_format = $automatorConfig['output_format'] ?? 'text';
    // Checkbox "Use class-based heading 1" only applies to HTML output.
    // When checked and format is HTML, H1 uses <p class="h1">.
    // For text/markdown output the checkbox is ignored (native headings).
    $options = [];
    if ($output_format === 'html') {
      $class_based_h1 = $automatorConfig['native_headings'] ?? TRUE;
      $options['native_headings'] = !$class_based_h1;
    }
    $values = [];
    foreach ($entity->{$automatorConfig['base_field']} as $entity_wrapper) {
      if ($entity_wrapper->entity) {
        $file_entity = $entity_wrapper->entity;
        $file_uri = $file_entity->getFileUri();
        $text = $this->extractText($file_uri, $output_format, $options);
        if ($text !== NULL) {
          $values[] = $text;
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritDoc}
   */
  public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $fieldDefinition, $automatorConfig) {
    // Should be a string.
    if (!is_string($value)) {
      return FALSE;
    }
    // Otherwise it is ok.
    return TRUE;
  }

  /**
   * Extract text from a file based on its extension.
   *
   * @param string $file_uri
   *   The file URI.
   * @param string $output_format
   *   The output format: 'text', 'html', or 'markdown'.
   * @param array $options
   *   Optional extraction settings:
   *   - native_headings: bool Whether to use native H1 tags (default FALSE).
   *
   * @return string|null
   *   The extracted text, HTML, or Markdown, or NULL if unsupported.
   */
  public function extractText(string $file_uri, string $output_format = 'text', array $options = []): ?string {
    $extension = strtolower(pathinfo($file_uri, PATHINFO_EXTENSION));

    // Resolve Drupal stream wrappers to real filesystem paths.
    $real_path = $this->fileSystem->realpath($file_uri);
    if (!$real_path) {
      return NULL;
    }

    return $this->extractorManager->extract($real_path, $extension, $output_format, $options);
  }

}
