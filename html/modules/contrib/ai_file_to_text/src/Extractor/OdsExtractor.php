<?php

namespace Drupal\ai_file_to_text\Extractor;

/**
 * Extracts text and HTML from ODS (OpenDocument Spreadsheet) files.
 *
 * ODS files are ZIP archives containing content.xml with table data.
 * This parser uses ZipArchive + DOMDocument to read the spreadsheet
 * without any external library dependency.
 */
class OdsExtractor implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['ods'];
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
    $sheets = $this->parseSheets($file_path);
    if (empty($sheets)) {
      return '';
    }

    $output = [];
    foreach ($sheets as $sheet) {
      $lines = [];
      foreach ($sheet['rows'] as $row) {
        $lines[] = implode("\t", $row);
      }
      $output[] = implode("\n", $lines);
    }

    return trim(implode("\n\n", $output));
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    $sheets = $this->parseSheets($file_path);
    if (empty($sheets)) {
      return '';
    }

    $multiple_sheets = count($sheets) > 1;
    $html = '';

    foreach ($sheets as $sheet) {
      if ($multiple_sheets && !empty($sheet['name'])) {
        $html .= '<h2 class="h2">' . htmlspecialchars($sheet['name'], ENT_QUOTES, 'UTF-8') . '</h2>' . "\n";
      }

      if (empty($sheet['rows'])) {
        continue;
      }

      $html .= '<table>' . "\n";
      $is_first_row = TRUE;
      foreach ($sheet['rows'] as $row) {
        $tag = $is_first_row ? 'th' : 'td';
        $html .= '<tr>';
        foreach ($row as $cell) {
          $html .= "<$tag>" . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . "</$tag>";
        }
        $html .= '</tr>' . "\n";
        $is_first_row = FALSE;
      }
      $html .= '</table>' . "\n";
    }

    return trim($html);
  }

  /**
   * Parse all sheets from an ODS file.
   *
   * @param string $file_path
   *   The real filesystem path to the ODS file.
   *
   * @return array
   *   An array of sheets, each with 'name' and 'rows' keys.
   *   Each row is an array of cell values (strings).
   */
  protected function parseSheets(string $file_path): array {
    if (!file_exists($file_path)) {
      return [];
    }

    $zip = new \ZipArchive();
    if ($zip->open($file_path) !== TRUE) {
      return [];
    }

    $xml_content = $zip->getFromName('content.xml');
    $zip->close();

    if ($xml_content === FALSE) {
      return [];
    }

    $dom = new \DOMDocument();
    $dom->loadXML($xml_content);
    $xpath = new \DOMXPath($dom);

    // Register ODS namespaces.
    $xpath->registerNamespace('office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
    $xpath->registerNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
    $xpath->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');

    $sheets = [];
    $table_nodes = $xpath->query('//office:body/office:spreadsheet/table:table');

    if ($table_nodes === FALSE) {
      return [];
    }

    foreach ($table_nodes as $table_node) {
      $sheet_name = $table_node->getAttribute('table:name');
      $rows = [];
      $max_cols = 0;

      $row_nodes = $xpath->query('table:table-row', $table_node);
      if ($row_nodes === FALSE) {
        continue;
      }

      foreach ($row_nodes as $row_node) {
        $row = $this->parseRow($xpath, $row_node);

        // Track maximum column count for consistent row widths.
        $col_count = count($row);
        if ($col_count > $max_cols) {
          $max_cols = $col_count;
        }

        $rows[] = $row;
      }

      // Trim trailing empty rows.
      while (!empty($rows) && $this->isEmptyRow(end($rows))) {
        array_pop($rows);
      }

      // Skip completely empty sheets.
      if (empty($rows)) {
        continue;
      }

      // Pad all rows to the same column count.
      foreach ($rows as &$row) {
        while (count($row) < $max_cols) {
          $row[] = '';
        }
      }

      $sheets[] = [
        'name' => $sheet_name,
        'rows' => $rows,
      ];
    }

    return $sheets;
  }

  /**
   * Parse a single table row into an array of cell values.
   *
   * Handles table:number-columns-repeated for repeated empty cells.
   *
   * @param \DOMXPath $xpath
   *   The XPath instance.
   * @param \DOMElement $row_node
   *   The table:table-row element.
   *
   * @return array
   *   Array of cell value strings.
   */
  protected function parseRow(\DOMXPath $xpath, \DOMElement $row_node): array {
    $row = [];
    $cell_nodes = $xpath->query('table:table-cell', $row_node);

    if ($cell_nodes === FALSE) {
      return $row;
    }

    foreach ($cell_nodes as $cell_node) {
      $repeat = (int) $cell_node->getAttribute('table:number-columns-repeated');
      if ($repeat < 1) {
        $repeat = 1;
      }

      // Extract text from all <text:p> children within the cell.
      $text_nodes = $xpath->query('text:p', $cell_node);
      $cell_text = '';
      if ($text_nodes && $text_nodes->length > 0) {
        $parts = [];
        foreach ($text_nodes as $text_node) {
          $parts[] = $text_node->textContent;
        }
        $cell_text = implode("\n", $parts);
      }

      // Cap repeated empty cells to avoid inflating row arrays.
      if ($cell_text === '' && $repeat > 100) {
        $repeat = 1;
      }

      for ($i = 0; $i < $repeat; $i++) {
        $row[] = $cell_text;
      }
    }

    return $row;
  }

  /**
   * Check if a row is entirely empty.
   *
   * @param array $row
   *   An array of cell values.
   *
   * @return bool
   *   TRUE if all cells are empty strings.
   */
  protected function isEmptyRow(array $row): bool {
    foreach ($row as $cell) {
      if ($cell !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

}
