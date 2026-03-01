<?php

namespace Drupal\ai_file_to_text\Extractor;

/**
 * Extracts text and HTML from CSV files.
 */
class CsvExtractor implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['csv'];
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

    $handle = fopen($file_path, 'r');
    if ($handle === FALSE) {
      return '';
    }

    $lines = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
      $lines[] = implode("\t", $row);
    }
    fclose($handle);

    return implode("\n", $lines);
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    if (!file_exists($file_path)) {
      return '';
    }

    $handle = fopen($file_path, 'r');
    if ($handle === FALSE) {
      return '';
    }

    $html = '<table>' . "\n";
    $is_first_row = TRUE;
    while (($row = fgetcsv($handle)) !== FALSE) {
      $tag = $is_first_row ? 'th' : 'td';
      $html .= '<tr>';
      foreach ($row as $cell) {
        $html .= "<$tag>" . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . "</$tag>";
      }
      $html .= '</tr>' . "\n";
      $is_first_row = FALSE;
    }
    fclose($handle);
    $html .= '</table>';

    return $html;
  }

}
