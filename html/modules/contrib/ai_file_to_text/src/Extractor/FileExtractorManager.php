<?php

namespace Drupal\ai_file_to_text\Extractor;

/**
 * Manages file content extractors and dispatches extraction by extension.
 *
 * Collects all ExtractorInterface services, builds an extension-to-extractor
 * map, and provides a single entry point for extracting text, HTML, or
 * Markdown from supported file types.
 */
class FileExtractorManager {

  /**
   * Map of file extensions to their extractor service.
   *
   * @var array<string, \Drupal\ai_file_to_text\Extractor\ExtractorInterface>
   */
  protected array $extensionMap = [];

  /**
   * The Markdown extractor (also provides htmlToMarkdown utility).
   *
   * @var \Drupal\ai_file_to_text\Extractor\MdExtractor
   */
  protected MdExtractor $mdExtractor;

  /**
   * Constructs a FileExtractorManager.
   *
   * @param \Drupal\ai_file_to_text\Extractor\MdExtractor $mdExtractor
   *   The Markdown extractor service.
   * @param \Drupal\ai_file_to_text\Extractor\ExtractorInterface ...$extractors
   *   All extractor services.
   */
  public function __construct(MdExtractor $mdExtractor, ExtractorInterface ...$extractors) {
    $this->mdExtractor = $mdExtractor;
    foreach ($extractors as $extractor) {
      if (!$extractor->isAvailable()) {
        continue;
      }

      foreach ($extractor->getSupportedExtensions() as $ext) {
        $this->extensionMap[$ext] = $extractor;
      }
    }
  }

  /**
   * Get the list of all supported file extensions.
   *
   * @return string[]
   *   Supported extensions (e.g. ['docx', 'doc', 'odt', ...]).
   */
  public function getSupportedExtensions(): array {
    return array_keys($this->extensionMap);
  }

  /**
   * Check if a file extension is supported.
   *
   * @param string $extension
   *   The file extension (lowercase, no dot).
   *
   * @return bool
   *   TRUE if the extension is supported.
   */
  public function isSupported(string $extension): bool {
    return isset($this->extensionMap[$extension]);
  }

  /**
   * Extract content from a file in the requested format.
   *
   * @param string $file_path
   *   The real filesystem path to the file.
   * @param string $extension
   *   The file extension (lowercase, no dot).
   * @param string $format
   *   The output format: 'text', 'html', or 'markdown'.
   * @param array $options
   *   Optional extraction settings:
   *   - native_headings: bool Whether to use native H1 tags (default FALSE).
   *
   * @return string|null
   *   The extracted content, or NULL if the extension is unsupported.
   */
  public function extract(string $file_path, string $extension, string $format = 'text', array $options = []): ?string {
    if (!isset($this->extensionMap[$extension])) {
      return NULL;
    }

    $extractor = $this->extensionMap[$extension];

    if ($format === 'text') {
      return $extractor->extractText($file_path, $extension);
    }

    // Both 'html' and 'markdown' need HTML first.
    // For markdown output, always use native heading tags in the
    // intermediate HTML so the markdown converter produces proper
    // # headings instead of plain paragraphs.
    if ($format === 'markdown') {
      $options['native_headings'] = TRUE;
    }
    $html = $extractor->extractHtml($file_path, $extension, $options);

    if ($format === 'markdown') {
      return $this->htmlToMarkdown($html);
    }

    return $html;
  }

  /**
   * Convert HTML content to Markdown.
   *
   * Delegates to the MdExtractor service which uses league/html-to-markdown.
   *
   * @param string $html
   *   The HTML content to convert.
   *
   * @return string
   *   The Markdown output.
   */
  public function htmlToMarkdown(string $html): string {
    return $this->mdExtractor->htmlToMarkdown($html);
  }

}
