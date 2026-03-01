<?php

declare(strict_types=1);

namespace Drupal\ai_file_to_text\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Drupal\ai_file_to_text\Extractor\FileExtractorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the File to Text function call.
 */
#[FunctionCall(
  id: 'ai_file_to_text:file_to_text',
  function_name: 'file_to_text',
  name: 'File to Text',
  description: 'This method extracts text, HTML, or Markdown from a Word (.docx, .doc, .odt), ODS spreadsheet (.ods), PDF (.pdf), CSV, TXT, or Markdown (.md) document given its file ID or location. Only one of file_id or file_location is required.',
  group: 'information_tools',
  context_definitions: [
    'file_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("File ID"),
      description: new TranslatableMarkup("The Drupal file ID of the document to extract text from (supports .docx, .doc, .odt, .ods, .pdf, .csv, .txt, .md)."),
      required: FALSE,
    ),
    'file_location' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("File Location"),
      description: new TranslatableMarkup("The location (URI/URL) of the document to extract text from (supports .docx, .doc, .odt, .ods, .pdf, .csv, .txt, .md)."),
      required: FALSE,
    ),
    'output_format' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Output Format"),
      description: new TranslatableMarkup("The output format: 'text' for plain text (default), 'html' for HTML markup, or 'markdown' for Markdown."),
      required: FALSE,
    ),
  ],
)]
class WordToText extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Constructs a new WordToText instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\ai\Utility\ContextDefinitionNormalizer $context_definition_normalizer
   *   The context definition normalizer service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check access for.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\ai_file_to_text\Extractor\FileExtractorManager $extractorManager
   *   The file extractor manager service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    ContextDefinitionNormalizer $context_definition_normalizer,
    protected AccountInterface $account,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FileExtractorManager $extractorManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $context_definition_normalizer);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('ai_file_to_text.extractor_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // Collect the context values.
    $file_id = $this->getContextValue('file_id');
    $file_location = $this->getContextValue('file_location');
    $output_format = $this->getContextValue('output_format') ?? 'text';

    if (empty($file_id) && empty($file_location)) {
      $this->setOutput("Either 'file_id' or 'file_location' must be provided.");
      return;
    }
    if (!empty($file_id) && !empty($file_location)) {
      $this->setOutput("Please provide only one of 'file_id' or 'file_location', not both.");
      return;
    }

    // Get file location from file ID if provided.
    if (!empty($file_id)) {
      /** @var \Drupal\file\Entity\File $file */
      $file = $this->entityTypeManager->getStorage('file')->load($file_id);
      if (!$file) {
        $this->setOutput("File with ID '$file_id' not found.");
        return;
      }
      // Make sure the user has access to the file.
      if (!$file->access('view', $this->account)) {
        $this->setOutput("You do not have permission to access the file with ID '$file_id'.");
        return;
      }
      $file_location = $file->getFileUri();
    }

    // Check the file extension is supported.
    $extension = strtolower(pathinfo($file_location, PATHINFO_EXTENSION));
    if (!$this->extractorManager->isSupported($extension)) {
      $this->setOutput("Unsupported file type '.$extension'. Supported types: .docx, .doc, .odt, .ods, .pdf, .csv, .txt, .md");
      return;
    }

    // Resolve the file URI to a real path.
    $real_path = $this->fileSystem->realpath($file_location);
    if (!$real_path || !file_exists($real_path)) {
      $this->setOutput("Could not resolve file location to a readable path.");
      return;
    }

    try {
      $text = $this->extractText($real_path, $extension, $output_format);
      if ($text === '') {
        $this->setOutput("No text could be extracted from the file.");
        return;
      }
      $this->setOutput($text);
    }
    catch (\Exception $e) {
      $this->setOutput("Failed to extract text from the document: " . $e->getMessage());
      return;
    }
  }

  /**
   * Extract text, HTML, or Markdown from a file using the extractor manager.
   *
   * @param string $real_path
   *   The real filesystem path to the file.
   * @param string $extension
   *   The file extension (lowercase).
   * @param string $output_format
   *   The output format: 'text', 'html', or 'markdown'.
   *
   * @return string
   *   The extracted content.
   */
  protected function extractText(string $real_path, string $extension, string $output_format): string {
    return $this->extractorManager->extract($real_path, $extension, $output_format) ?? '';
  }

}
