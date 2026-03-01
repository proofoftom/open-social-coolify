<?php

namespace Drupal\ai_file_to_text\Extractor;

/**
 * Extracts text and HTML from plain text (TXT) files.
 */
class TxtExtractor implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['txt'];
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function extractText(string $file_path, string $extension = ''): string {
    if (!file_exists($file_path)) {
      return '';
    }

    $content = file_get_contents($file_path);
    return $content !== FALSE ? $content : '';
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    if (!file_exists($file_path)) {
      return '';
    }

    $content = file_get_contents($file_path);
    if ($content === FALSE) {
      return '';
    }

    // Convert plain text to HTML: split on blank lines into paragraphs,
    // and convert single newlines to <br>.
    $escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    $paragraphs = preg_split('/\n{2,}/', $escaped);
    $html = '';
    foreach ($paragraphs as $paragraph) {
      $paragraph = trim($paragraph);
      if ($paragraph !== '') {
        $html .= '<p>' . nl2br($paragraph) . '</p>' . "\n";
      }
    }

    return trim($html);
  }

}
