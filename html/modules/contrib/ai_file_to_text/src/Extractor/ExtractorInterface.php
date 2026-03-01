<?php

namespace Drupal\ai_file_to_text\Extractor;

/**
 * Interface for file content extractors.
 *
 * Each extractor handles one or more file types and provides
 * methods to extract plain text and styled HTML from them.
 */
interface ExtractorInterface {

  /**
   * Check whether this extractor is available.
   *
   * @return bool
   *   TRUE if this extractor can be used.
   */
  public function isAvailable(): bool;

  /**
   * Get the file extensions this extractor supports.
   *
   * @return string[]
   *   An array of lowercase file extensions (e.g., ['docx', 'doc']).
   */
  public function getSupportedExtensions(): array;

  /**
   * Extract plain text from a file.
   *
   * @param string $file_path
   *   The real filesystem path to the file.
   * @param string $extension
   *   The file extension (lowercase).
   *
   * @return string
   *   The extracted text.
   */
  public function extractText(string $file_path, string $extension = ''): string;

  /**
   * Extract HTML from a file.
   *
   * @param string $file_path
   *   The real filesystem path to the file.
   * @param string $extension
   *   The file extension (lowercase).
   * @param array $options
   *   Optional extraction settings:
   *   - native_headings: bool Whether to use native H1 tags (default FALSE).
   *
   * @return string
   *   The extracted HTML.
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string;

}
