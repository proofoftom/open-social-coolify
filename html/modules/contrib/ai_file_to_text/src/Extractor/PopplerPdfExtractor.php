<?php

namespace Drupal\ai_file_to_text\Extractor;

/**
 * Extracts text and HTML from PDF files using Poppler command-line tools.
 *
 * Requires pdftotext and pdftohtml from the poppler-utils package.
 * These C++ tools handle PDF layout, fonts, and link extraction far
 * more accurately than PHP-based parsers. The XML output from pdftohtml
 * provides per-text-element font metadata (size, family, color) and
 * hyperlink annotations, which this class processes into clean semantic
 * HTML with headings, bold/italic, lists, links, and tables.
 */
class PopplerPdfExtractor implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['pdf'];
  }

  /**
   * {@inheritdoc}
   *
   * PopplerPdfExtractor requires system binaries (poppler-utils).
   * Only register it if the binaries are actually available on
   * this host; otherwise the pure-PHP PdfExtractor stays as
   * the fallback for the 'pdf' extension.
   */
  public function isAvailable(): bool {
    $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
    $pdftohtml = trim(shell_exec('which pdftohtml 2>/dev/null') ?? '');
    return $pdftotext !== '' && $pdftohtml !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function extractText(string $file_path, string $extension = ''): string {
    if (!file_exists($file_path) || !$this->isAvailable()) {
      return '';
    }

    $escaped = escapeshellarg($file_path);
    $output = shell_exec("pdftotext -layout -enc UTF-8 {$escaped} - 2>/dev/null");

    return trim($output ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    if (!file_exists($file_path) || !$this->isAvailable()) {
      return '';
    }

    $xml = $this->extractXml($file_path);
    if ($xml === '') {
      return '';
    }

    $native_headings = !empty($options['native_headings']);
    return $this->xmlToHtml($xml, $native_headings);
  }

  /**
   * Run pdftohtml -xml and return the raw XML string.
   *
   * @param string $file_path
   *   Path to the PDF file.
   *
   * @return string
   *   The XML output, or empty string on failure.
   */
  protected function extractXml(string $file_path): string {
    $escaped = escapeshellarg($file_path);
    // pdftohtml writes to a file, not stdout. Use a temp file.
    $tmp_base = sys_get_temp_dir() . '/ai_pdf_' . uniqid();
    $tmp_base_escaped = escapeshellarg($tmp_base);
    shell_exec("pdftohtml -xml -enc UTF-8 {$escaped} {$tmp_base_escaped} 2>/dev/null");

    $xml_file = $tmp_base . '.xml';
    $output = '';
    if (file_exists($xml_file)) {
      $output = file_get_contents($xml_file);
      unlink($xml_file);
    }

    // Clean up any other temp files pdftohtml may have created.
    foreach (glob($tmp_base . '*') as $tmp_file) {
      @unlink($tmp_file);
    }

    return trim($output);
  }

  /**
   * Convert pdftohtml XML output into clean semantic HTML.
   *
   * The XML structure is:
   *   <pdf2xml>
   *     <page number="N" ...>
   *       <fontspec id="N" size="N" family="..." color="#..."/>
   *       <text top="N" left="N" width="N" height="N" font="N">
   *         content with possible <b>, <i>, <a href="..."> tags
   *       </text>
   *     </page>
   *   </pdf2xml>
   *
   * @param string $xml
   *   The XML from pdftohtml -xml.
   * @param bool $native_headings
   *   If TRUE, use <h1>-<h6> tags. If FALSE, use <p class="hN">.
   *
   * @return string
   *   Clean semantic HTML.
   */
  protected function xmlToHtml(string $xml, bool $native_headings): string {
    $doc = @simplexml_load_string($xml);
    if ($doc === FALSE) {
      return '';
    }

    // Build a GLOBAL font spec lookup across all pages.
    // Poppler only defines fontspecs on the first page where a font appears,
    // but text elements on subsequent pages reference those same font IDs.
    $fonts = [];
    foreach ($doc->page as $page) {
      foreach ($page->fontspec as $fs) {
        $attrs = $fs->attributes();
        $fid = (string) $attrs['id'];
        if (!isset($fonts[$fid])) {
          $fonts[$fid] = [
            'size' => (int) $attrs['size'],
            'family' => (string) $attrs['family'],
            'color' => (string) $attrs['color'],
          ];
        }
      }
    }

    // Collect ALL text elements across all pages with a virtual Y offset
    // so that cross-page content stays properly ordered and connected.
    $all_elements = [];
    $y_offset = 0;

    foreach ($doc->page as $page) {
      $page_attrs = $page->attributes();
      $page_height = (int) ($page_attrs['height'] ?? 1000);

      foreach ($page->text as $text_el) {
        $attrs = $text_el->attributes();
        $top = (int) $attrs['top'] + $y_offset;
        $left = (int) $attrs['left'];
        $width = (int) $attrs['width'];
        $height = (int) $attrs['height'];
        $font_id = (string) $attrs['font'];

        // Get inner XML (preserves <b>, <i>, <a> tags).
        $inner_xml = $this->getInnerXml($text_el);
        // Get plain text for analysis.
        $plain_text = trim(strip_tags($inner_xml));

        $font_info = $fonts[$font_id] ?? [
          'size' => 12,
          'family' => '',
          'color' => '#000000',
        ];

        $all_elements[] = [
          'top' => $top,
          'left' => $left,
          'width' => $width,
          'height' => $height,
          'fontSize' => $font_info['size'],
          'fontFamily' => $font_info['family'],
          'color' => $font_info['color'],
          'innerXml' => $inner_xml,
          'plainText' => $plain_text,
        ];
      }

      // Offset next page's Y coordinates so pages stack vertically.
      $y_offset += $page_height + 50;
    }

    if (empty($all_elements)) {
      return '';
    }

    return $this->buildPageHtml($all_elements, $native_headings);
  }

  /**
   * Build semantic HTML from a page's text elements.
   *
   * Groups elements by vertical position into lines, detects headings
   * by font size, detects lists by bullet/number prefixes, and merges
   * continuation lines into paragraphs.
   *
   * @param array $elements
   *   Array of text element data from the XML.
   * @param bool $native_headings
   *   Whether to use native heading tags.
   *
   * @return string
   *   HTML for this page.
   */
  protected function buildPageHtml(array $elements, bool $native_headings): string {
    // Detect body font size (most common size by element count).
    $size_counts = [];
    foreach ($elements as $el) {
      $size = $el['fontSize'];
      $size_counts[$size] = ($size_counts[$size] ?? 0) + mb_strlen($el['plainText']);
    }
    arsort($size_counts);
    $body_size = array_key_first($size_counts) ?: 12;

    // Group elements into lines by Y position (tolerance of 5px).
    $lines = $this->groupIntoLines($elements);

    // Classify each line.
    $classified = [];
    foreach ($lines as $line) {
      $classified[] = $this->classifyLine($line, $body_size);
    }

    // Build HTML blocks.
    return $this->renderClassifiedLines($classified, $native_headings);
  }

  /**
   * Group text elements into lines by vertical position.
   *
   * Elements within 5px vertical distance are considered the same line.
   * Within each line, elements are sorted left-to-right.
   *
   * @param array $elements
   *   Text elements with 'top' and 'left' positions.
   *
   * @return array
   *   Array of lines, each containing its elements sorted by X.
   */
  protected function groupIntoLines(array $elements): array {
    // Sort by top, then left.
    usort($elements, function ($a, $b) {
      $diff = $a['top'] - $b['top'];
      return $diff !== 0 ? $diff : $a['left'] - $b['left'];
    });

    $lines = [];
    $current_line = [];
    $current_top = NULL;

    foreach ($elements as $el) {
      if ($current_top !== NULL && abs($el['top'] - $current_top) <= 5) {
        $current_line[] = $el;
      }
      else {
        if (!empty($current_line)) {
          usort($current_line, fn($a, $b) => $a['left'] - $b['left']);
          $lines[] = $current_line;
        }
        $current_line = [$el];
        $current_top = $el['top'];
      }
    }
    if (!empty($current_line)) {
      usort($current_line, fn($a, $b) => $a['left'] - $b['left']);
      $lines[] = $current_line;
    }

    return $lines;
  }

  /**
   * Classify a line of text elements into a semantic type.
   *
   * @param array $line_elements
   *   Elements on this line, sorted by X position.
   * @param int $body_size
   *   The detected body font size.
   *
   * @return array
   *   Classified line with keys: type, html, fontSize, level.
   */
  protected function classifyLine(array $line_elements, int $body_size): array {
    // Combine inner XML of all elements on the line.
    $parts = [];
    foreach ($line_elements as $el) {
      $parts[] = $el['innerXml'];
    }
    $combined_xml = implode('', $parts);
    $combined_text = trim(strip_tags($combined_xml));

    // Clean up &#160; to regular spaces for analysis.
    $analysis_text = str_replace(['&#160;', "\xC2\xA0"], ' ', $combined_text);
    $analysis_text = trim($analysis_text);

    // Use the dominant (largest) font size on the line.
    $max_size = 0;
    $left_most = PHP_INT_MAX;
    foreach ($line_elements as $el) {
      if ($el['fontSize'] > $max_size) {
        $max_size = $el['fontSize'];
      }
      if ($el['left'] < $left_most) {
        $left_most = $el['left'];
      }
    }

    // Clean the combined HTML.
    $html = $this->cleanInnerHtml($combined_xml);

    // Skip empty or whitespace-only lines.
    if ($analysis_text === '') {
      return ['type' => 'empty', 'html' => '', 'fontSize' => 0, 'level' => 0, 'left' => 0];
    }

    // Detect heading by font size ratio.
    $heading_level = 0;
    $ratio = $body_size > 0 ? $max_size / $body_size : 1;
    if ($ratio >= 1.75) {
      $heading_level = 1;
    }
    elseif ($ratio >= 1.4) {
      $heading_level = 2;
    }
    elseif ($ratio >= 1.1) {
      $heading_level = 3;
    }

    // Validate heading: not too long, not a bullet, and not starting
    // with lowercase (unless it has a clear font size advantage).
    // Do NOT disqualify lines that start with numbers — those are
    // numbered section headings like "4. Initial Onboarding Process".
    if ($heading_level > 0) {
      if (mb_strlen($analysis_text) > 100
        || $this->startsWithBullet($analysis_text)) {
        $heading_level = 0;
      }
      // Only reject lowercase-starting text at the weaker heading levels.
      if ($heading_level === 3 && preg_match('/^[a-z]/', $analysis_text)) {
        $heading_level = 0;
      }
    }

    if ($heading_level > 0) {
      return [
        'type' => 'heading',
        'html' => $html,
        'fontSize' => $max_size,
        'level' => $heading_level,
        'left' => $left_most,
      ];
    }

    // Detect bullet list items.
    $bullet_chars = ['●', '•', '◦', '▪', '▸', '►', '○', '■', '◆', '➤', '–', '—'];
    foreach ($bullet_chars as $bullet) {
      if (str_starts_with($analysis_text, $bullet)) {
        $is_sub = in_array($bullet, ['◦', '○', '▪', '▸', '►'], TRUE);
        // Remove bullet from HTML.
        $item_html = $this->removeBulletFromHtml($html, $bullet);
        return [
          'type' => 'bullet',
          'html' => $item_html,
          'fontSize' => $max_size,
          'level' => 0,
          'left' => $left_most,
          'isSub' => $is_sub,
        ];
      }
    }

    // Detect numbered list items.
    if (preg_match('/^(\d+)\.\s/', $analysis_text, $m)) {
      $item_html = preg_replace('/^\d+\.\s*/', '', $html, 1);
      // Remove zero-width spaces that Poppler sometimes inserts.
      $item_html = str_replace("\u{200b}", '', $item_html);
      return [
        'type' => 'numbered',
        'html' => trim($item_html),
        'fontSize' => $max_size,
        'level' => 0,
        'left' => $left_most,
        'number' => (int) $m[1],
      ];
    }

    // Detect table rows: multiple column groups with large X-gaps.
    $table_info = $this->detectTableRow($line_elements, $body_size);
    if ($table_info !== NULL) {
      return [
        'type' => 'table_row',
        'html' => $html,
        'fontSize' => $max_size,
        'level' => 0,
        'left' => $left_most,
        'cells' => $table_info['cells'],
        'colStarts' => $table_info['colStarts'],
      ];
    }

    // Regular text (paragraph continuation).
    return [
      'type' => 'text',
      'html' => $html,
      'fontSize' => $max_size,
      'level' => 0,
      'left' => $left_most,
    ];
  }

  /**
   * Detect if a line represents a table row.
   *
   * Looks for multiple groups of elements separated by large X-gaps
   * (>80px between end of one group and start of next).
   *
   * @param array $line_elements
   *   Elements on this line, sorted by X.
   * @param int $body_size
   *   The body font size.
   *
   * @return array|null
   *   Array with 'cells' and 'colStarts' if it looks like a table row,
   *   or NULL if it's not a table row.
   */
  protected function detectTableRow(array $line_elements, int $body_size): ?array {
    if (count($line_elements) < 2) {
      return NULL;
    }

    // Group elements into columns by X-gap.
    $gap_threshold = 60;
    $groups = [[$line_elements[0]]];
    for ($i = 1; $i < count($line_elements); $i++) {
      $prev = end($groups[count($groups) - 1]);
      $prev_end = $prev['left'] + $prev['width'];
      $cur_start = $line_elements[$i]['left'];
      $gap = $cur_start - $prev_end;

      if ($gap > $gap_threshold) {
        $groups[] = [];
      }
      $groups[count($groups) - 1][] = $line_elements[$i];
    }

    // Need at least 3 column groups to be a table row.
    if (count($groups) < 3) {
      return NULL;
    }

    // Check that first element isn't a bullet.
    $first_text = trim(strip_tags($line_elements[0]['innerXml']));
    if ($this->startsWithBullet($first_text)) {
      return NULL;
    }

    // Build cell texts.
    $cells = [];
    $col_starts = [];
    foreach ($groups as $group) {
      $cell_parts = [];
      foreach ($group as $el) {
        $cell_parts[] = $this->cleanInnerHtml($el['innerXml']);
      }
      $cells[] = trim(implode(' ', $cell_parts));
      $col_starts[] = $group[0]['left'];
    }

    return ['cells' => $cells, 'colStarts' => $col_starts];
  }

  /**
   * Render classified lines into final HTML output.
   *
   * Merges consecutive text lines into paragraphs, groups list items,
   * and groups table rows.
   *
   * @param array $classified
   *   Array of classified line data.
   * @param bool $native_headings
   *   Whether to use native heading tags.
   *
   * @return string
   *   Final HTML.
   */
  protected function renderClassifiedLines(array $classified, bool $native_headings): string {
    $html = '';
    $i = 0;
    $count = count($classified);

    while ($i < $count) {
      $line = $classified[$i];

      if ($line['type'] === 'empty') {
        $i++;
        continue;
      }

      // Headings.
      if ($line['type'] === 'heading') {
        $level = $line['level'];
        if ($native_headings) {
          $html .= "<h{$level}>" . $line['html'] . "</h{$level}>\n";
        }
        else {
          $tag = $level === 1 ? 'p' : "h{$level}";
          $html .= "<{$tag} class=\"h{$level}\">" . $line['html'] . "</{$tag}>\n";
        }
        $i++;
        continue;
      }

      // Bullet lists — gather consecutive bullet items.
      if ($line['type'] === 'bullet') {
        $html .= "<ul>\n";
        while ($i < $count && $classified[$i]['type'] === 'bullet') {
          $item = $classified[$i];
          // Check for continuation lines (indented text lines).
          $item_html = $item['html'];
          $j = $i + 1;
          while ($j < $count && $classified[$j]['type'] === 'text'
            && $classified[$j]['left'] > $item['left'] + 10) {
            $item_html .= '<br>' . $classified[$j]['html'];
            $j++;
          }
          // Check for sub-items.
          if (!empty($item['isSub'])) {
            $html .= "<li>" . $item_html . "</li>\n";
          }
          else {
            // Look ahead for sub-items that belong to this parent.
            $sub_html = '';
            $k = $j;
            while ($k < $count && $classified[$k]['type'] === 'bullet'
              && !empty($classified[$k]['isSub'])) {
              $sub_html .= "<li>" . $classified[$k]['html'] . "</li>\n";
              $k++;
            }
            if ($sub_html !== '') {
              $html .= "<li>" . $item_html . "\n<ul>\n" . $sub_html . "</ul>\n</li>\n";
              $j = $k;
            }
            else {
              $html .= "<li>" . $item_html . "</li>\n";
            }
          }
          $i = $j;
        }
        $html .= "</ul>\n";
        continue;
      }

      // Numbered lists — gather consecutive numbered items.
      if ($line['type'] === 'numbered') {
        $html .= "<ol>\n";
        while ($i < $count && $classified[$i]['type'] === 'numbered') {
          $item = $classified[$i];
          $item_html = $item['html'];
          // Gather continuation text and sub-bullets.
          $j = $i + 1;
          $sub_items = [];
          while ($j < $count) {
            if ($classified[$j]['type'] === 'text'
              && $classified[$j]['left'] > $item['left'] + 10) {
              $item_html .= '<br>' . $classified[$j]['html'];
              $j++;
            }
            elseif ($classified[$j]['type'] === 'bullet') {
              $sub_items[] = $classified[$j]['html'];
              $j++;
            }
            else {
              break;
            }
          }
          if (!empty($sub_items)) {
            $item_html .= "\n<ul>\n";
            foreach ($sub_items as $sub) {
              $item_html .= "<li>" . $sub . "</li>\n";
            }
            $item_html .= "</ul>";
          }
          $html .= "<li>" . $item_html . "</li>\n";
          $i = $j;
        }
        $html .= "</ol>\n";
        continue;
      }

      // Table rows — gather consecutive table rows with compatible cols.
      if ($line['type'] === 'table_row') {
        $table_rows = [];
        while ($i < $count && $classified[$i]['type'] === 'table_row') {
          $table_rows[] = $classified[$i];
          $i++;
        }
        if (count($table_rows) >= 2) {
          $html .= $this->renderTable($table_rows);
        }
        else {
          // Single row — just render as paragraph.
          $html .= '<p>' . $table_rows[0]['html'] . "</p>\n";
        }
        continue;
      }

      // Regular text — merge consecutive text lines into a paragraph.
      $para_parts = [];
      while ($i < $count
        && ($classified[$i]['type'] === 'text'
          || $classified[$i]['type'] === 'empty')) {
        if ($classified[$i]['type'] === 'text') {
          $para_parts[] = $classified[$i]['html'];
        }
        $i++;
      }
      if (!empty($para_parts)) {
        $html .= '<p>' . implode('<br>', $para_parts) . "</p>\n";
      }
    }

    return $html;
  }

  /**
   * Render a set of table rows as an HTML table.
   *
   * @param array $rows
   *   Array of classified table row data.
   *
   * @return string
   *   HTML table markup.
   */
  protected function renderTable(array $rows): string {
    // Find the maximum column count.
    $max_cols = 0;
    foreach ($rows as $row) {
      $col_count = count($row['cells'] ?? []);
      if ($col_count > $max_cols) {
        $max_cols = $col_count;
      }
    }

    $html = "<table>\n<thead>\n<tr>";
    // First row as header.
    $first = $rows[0]['cells'] ?? [];
    for ($c = 0; $c < $max_cols; $c++) {
      $cell = $first[$c] ?? '';
      $html .= '<th>' . $cell . '</th>';
    }
    $html .= "</tr>\n</thead>\n<tbody>\n";

    for ($r = 1; $r < count($rows); $r++) {
      $cells = $rows[$r]['cells'] ?? [];
      $html .= '<tr>';
      for ($c = 0; $c < $max_cols; $c++) {
        $cell = $cells[$c] ?? '';
        $html .= '<td>' . $cell . '</td>';
      }
      $html .= "</tr>\n";
    }

    $html .= "</tbody>\n</table>\n";
    return $html;
  }

  /**
   * Get the inner XML of a SimpleXML element as a string.
   *
   * @param \SimpleXMLElement $element
   *   The XML element.
   *
   * @return string
   *   The inner XML content.
   */
  protected function getInnerXml(\SimpleXMLElement $element): string {
    $xml = $element->asXML();
    // Strip the outer <text ...> and </text> tags.
    $xml = preg_replace('/^<text[^>]*>/', '', $xml);
    $xml = preg_replace('/<\/text>$/', '', $xml);
    return $xml;
  }

  /**
   * Clean inner HTML from Poppler XML output.
   *
   * Replaces &#160; with spaces, trims whitespace, and normalizes
   * multiple spaces.
   *
   * @param string $html
   *   Raw inner XML/HTML.
   *
   * @return string
   *   Cleaned HTML.
   */
  protected function cleanInnerHtml(string $html): string {
    // Replace non-breaking spaces with regular spaces.
    $html = str_replace(['&#160;', "\xC2\xA0"], ' ', $html);
    // Remove zero-width spaces.
    $html = str_replace("\u{200b}", '', $html);
    // Remove empty/whitespace-only <a> tags that Poppler inserts.
    // Poppler often outputs: <a href="url"> </a><a href="url">Text</a>
    // The first anchor with just whitespace is useless.
    $html = preg_replace('/<a\s+href="[^"]*">\s*<\/a>/', '', $html);
    // Collapse multiple spaces (but not inside tags).
    $html = preg_replace('/  +/', ' ', $html);
    return trim($html);
  }

  /**
   * Check if text starts with a bullet character.
   *
   * @param string $text
   *   The text to check.
   *
   * @return bool
   *   TRUE if it starts with a recognized bullet.
   */
  protected function startsWithBullet(string $text): bool {
    $bullets = ['●', '•', '◦', '▪', '▸', '►', '○', '■', '◆', '➤'];
    foreach ($bullets as $b) {
      if (str_starts_with($text, $b)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Remove the leading bullet character from HTML content.
   *
   * @param string $html
   *   The HTML content.
   * @param string $bullet
   *   The bullet character to remove.
   *
   * @return string
   *   HTML with the leading bullet removed.
   */
  protected function removeBulletFromHtml(string $html, string $bullet): string {
    // Remove the bullet and any trailing whitespace/zero-width chars.
    $html = preg_replace(
      '/^' . preg_quote($bullet, '/') . '[\s\x{200b}]*/u',
      '',
      $html
    );
    return trim($html);
  }

}
