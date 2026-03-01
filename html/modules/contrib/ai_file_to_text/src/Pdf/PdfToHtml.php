<?php

namespace Drupal\ai_file_to_text\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;

/**
 * Core PDF-to-HTML conversion engine.
 *
 * Orchestrates the full extraction pipeline: parsing PDF with
 * smalot/pdfparser, extracting text and font metadata, detecting
 * colors and structural tags, matching links, detecting tables,
 * and rendering semantic HTML.
 *
 * Uses a hybrid approach:
 * - getText() for properly spaced text content (handles CID/Type0 fonts
 *   where each character is a separate positioned segment).
 * - DataTm API for per-line font metadata (size, bold, italic detection).
 * - Raw content stream parsing for color and structural tag extraction
 *   (rg/sc/k/g color operators and BDC marked content tags).
 * - Link annotation extraction from /Annots for proper hyperlinks.
 *
 * This class has no Drupal dependencies and can be used standalone.
 */
class PdfToHtml {

  /**
   * The table detector.
   *
   * @var \Drupal\ai_file_to_text\Pdf\TableDetector
   */
  protected TableDetector $tableDetector;

  /**
   * The link extractor.
   *
   * @var \Drupal\ai_file_to_text\Pdf\LinkExtractor
   */
  protected LinkExtractor $linkExtractor;

  /**
   * The style analyzer.
   *
   * @var \Drupal\ai_file_to_text\Pdf\StyleAnalyzer
   */
  protected StyleAnalyzer $styleAnalyzer;

  /**
   * The HTML renderer.
   *
   * @var \Drupal\ai_file_to_text\Pdf\HtmlRenderer
   */
  protected HtmlRenderer $htmlRenderer;

  /**
   * Constructs a PdfToHtml engine.
   *
   * If no sub-components are provided, default instances are created.
   *
   * @param \Drupal\ai_file_to_text\Pdf\TableDetector|null $table_detector
   *   The table detector, or NULL for default.
   * @param \Drupal\ai_file_to_text\Pdf\LinkExtractor|null $link_extractor
   *   The link extractor, or NULL for default.
   * @param \Drupal\ai_file_to_text\Pdf\StyleAnalyzer|null $style_analyzer
   *   The style analyzer, or NULL for default.
   * @param \Drupal\ai_file_to_text\Pdf\HtmlRenderer|null $html_renderer
   *   The HTML renderer, or NULL for default.
   */
  public function __construct(
    ?TableDetector $table_detector = NULL,
    ?LinkExtractor $link_extractor = NULL,
    ?StyleAnalyzer $style_analyzer = NULL,
    ?HtmlRenderer $html_renderer = NULL,
  ) {
    $this->styleAnalyzer = $style_analyzer ?? new StyleAnalyzer();
    $this->tableDetector = $table_detector ?? new TableDetector();
    $this->linkExtractor = $link_extractor ?? new LinkExtractor($this->styleAnalyzer);
    $this->htmlRenderer = $html_renderer ?? new HtmlRenderer($this->styleAnalyzer);
  }

  /**
   * Extract plain text from a PDF file.
   *
   * @param string $file_path
   *   The real filesystem path to the PDF.
   *
   * @return string
   *   The extracted text.
   */
  public function extractText(string $file_path): string {
    if (!file_exists($file_path)) {
      return '';
    }

    $parser = new Parser();
    $pdf = $parser->parseFile($file_path);

    return trim($pdf->getText());
  }

  /**
   * Extract semantic HTML from a PDF file.
   *
   * @param string $file_path
   *   The real filesystem path to the PDF.
   * @param array $options
   *   Extraction options:
   *   - native_headings: bool Use native <h1>…<h6> tags (default FALSE).
   *
   * @return string
   *   The generated HTML.
   */
  public function extractHtml(string $file_path, array $options = []): string {
    if (!file_exists($file_path)) {
      return '';
    }

    // Parse twice: once for getText() (default config), once for DataTm.
    $text_parser = new Parser();
    $text_pdf = $text_parser->parseFile($file_path);
    $text_pages = $text_pdf->getPages();

    $config = new Config();
    $config->setDataTmFontInfoHasToBeIncluded(TRUE);
    $meta_parser = new Parser([], $config);
    $meta_pdf = $meta_parser->parseFile($file_path);
    $meta_pages = $meta_pdf->getPages();

    if (empty($text_pages)) {
      return '';
    }

    // Build line records: text from getText(), font info from DataTm,
    // color from raw content stream. NULL entries act as page-break markers.
    $all_lines = [];
    $page_count = min(count($text_pages), count($meta_pages));

    // First pass: collect all segment colors across pages to detect the
    // global link color (the most common non-body, non-black color).
    $all_segment_colors = [];
    $page_data = [];
    for ($pi = 0; $pi < $page_count; $pi++) {
      $fonts = $meta_pages[$pi]->getFonts();
      $data_tm = $meta_pages[$pi]->getDataTm();
      $segment_count = count($data_tm);
      $segment_colors = $this->extractSegmentColors($meta_pages[$pi], $segment_count);
      $page_links = $this->linkExtractor->extractPageLinks($meta_pages[$pi]);
      $table_regions = $this->tableDetector->detectTableRegions($data_tm);

      $page_data[$pi] = [
        'fonts' => $fonts,
        'data_tm' => $data_tm,
        'segment_colors' => $segment_colors,
        'page_links' => $page_links,
        'table_regions' => $table_regions,
      ];

      foreach ($segment_colors as $sc) {
        if ($sc['color'] !== NULL) {
          $all_segment_colors[] = $sc;
        }
      }
    }

    // Detect the global body color and link color.
    $global_body_color = $this->styleAnalyzer->detectBodyColorFromSegments($all_segment_colors);
    $global_link_color = $this->styleAnalyzer->detectLinkColor($all_segment_colors, $global_body_color);

    // Second pass: build lines using the global link color for matching.
    for ($pi = 0; $pi < $page_count; $pi++) {
      if (!empty($all_lines)) {
        $all_lines[] = NULL;
      }

      // Get properly spaced text lines from getText().
      $page_text = $text_pages[$pi]->getText();
      $text_lines = $this->splitTextIntoLines($page_text);

      $fonts = $page_data[$pi]['fonts'];
      $data_tm = $page_data[$pi]['data_tm'];
      $segment_colors = $page_data[$pi]['segment_colors'];
      $page_links = $page_data[$pi]['page_links'];
      $table_regions = $page_data[$pi]['table_regions'];

      // Build font lines with color included, plus X-coordinate data.
      $font_lines_raw = $this->buildFontLinesWithColorAndPosition(
        $data_tm, $fonts, $segment_colors
      );

      // Match link-colored segments to link annotations using global
      // link color for consistent matching across pages.
      $font_lines_raw = $this->linkExtractor->matchLinksToText(
        $font_lines_raw, $page_links, $global_link_color,
        $segment_colors, $data_tm
      );

      // Collect Y-positions of all table region rows for filtering.
      $table_y_set = [];
      foreach ($table_regions as $region) {
        foreach ($region['yValues'] as $ty) {
          $table_y_set[] = $ty;
        }
      }

      // PHASE 1: Generate table rows directly from DataTm font lines.
      // This avoids getText()/DataTm line count mismatch issues.
      $table_entries = [];
      foreach ($font_lines_raw as $font_line) {
        $table_info = $this->tableDetector->findTableForLine(
          $font_line['yPos'], $table_regions
        );
        if ($table_info !== NULL) {
          $cells = $this->tableDetector->buildTableCellTexts(
            $font_line['columns'],
            $table_info['colStarts'],
            $table_info['numCols']
          );
          $table_entries[] = [
            'type' => 'table_row',
            'cells' => $cells,
            'fontSize' => $font_line['fontSize'],
            'isBold' => $font_line['isBold'],
            'isItalic' => $font_line['isItalic'],
            'color' => $font_line['color'],
            'tag' => $font_line['tag'] ?? '',
            'tableId' => $table_info['tableId'],
            'yPos' => $font_line['yPos'],
          ];
        }
      }

      // PHASE 2: Filter font lines to only non-table entries for text
      // matching. This keeps font_idx in sync with getText() lines.
      $non_table_font_lines = [];
      foreach ($font_lines_raw as $font_line) {
        $in_table = FALSE;
        foreach ($table_y_set as $ty) {
          if (abs($ty - $font_line['yPos']) <= 2) {
            $in_table = TRUE;
            break;
          }
        }
        if (!$in_table) {
          $non_table_font_lines[] = $font_line;
        }
      }

      // PHASE 3: Match getText() lines to non-table font lines using
      // text-content similarity. Sequential matching drifts when
      // getText() merges/splits lines differently from DataTm Y-groups.
      // Instead, for each text line we find the best-matching font line
      // by comparing space-stripped text content.

      // Build normalized signatures for each table row (full row text)
      // so we can skip getText() lines that are duplicates of table
      // content. We match against the full concatenated row text to
      // avoid false positives from short cell words like "sit", "amet"
      // matching inside unrelated body paragraphs.
      $table_row_sigs = [];
      foreach ($table_entries as $entry) {
        $row_text = implode('', $entry['cells']);
        $sig = strtolower(preg_replace('/\s+/', '', $row_text));
        if ($sig !== '') {
          $table_row_sigs[] = $sig;
        }
      }

      // Build normalized signatures for font lines. Strip all
      // non-alphanumeric characters so that punctuation differences
      // between getText() and DataTm (e.g. dots, colons) don't
      // cause matching failures.
      $font_sigs = [];
      foreach ($non_table_font_lines as $fi => $fl) {
        $concat = '';
        foreach ($fl['columns'] as $seg) {
          $concat .= $seg['text'] ?? '';
        }
        $font_sigs[$fi] = mb_strtolower(preg_replace('/[^a-z0-9\p{L}●•◦▪▸►○■◆➤]/iu', '', $concat));
      }

      // Match each text line to the best font line using a greedy
      // forward scan: the matched font index must be >= min_fi to
      // preserve document order. When a font line contains text from
      // multiple getText() lines (merged Y-group), allow subsequent
      // text lines to also match the same font line.
      $min_fi = 0;
      $last_matched_fi = -1;
      $page_lines = [];
      foreach ($text_lines as $text) {
        if (trim($text) === '') {
          continue;
        }

        // Skip getText() lines that are duplicates of table row content.
        // Only skip if the entire text line is contained within a table
        // row signature (or vice versa), preventing false matches from
        // short words like "sit" or "amet" appearing in body text.
        $normalized_text = strtolower(preg_replace('/\s+/', '', trim($text)));
        $is_table_text = FALSE;
        foreach ($table_row_sigs as $row_sig) {
          // The getText() line must be a substantial portion of the
          // table row (>= 60% overlap) to be considered a duplicate.
          $overlap = 0;
          $shorter = mb_strlen($normalized_text) <= mb_strlen($row_sig) ? $normalized_text : $row_sig;
          $longer = mb_strlen($normalized_text) <= mb_strlen($row_sig) ? $row_sig : $normalized_text;
          if (str_contains($longer, $shorter) && mb_strlen($shorter) > 0) {
            $overlap = mb_strlen($shorter) / mb_strlen($longer);
          }
          if ($overlap >= 0.6) {
            $is_table_text = TRUE;
            break;
          }
        }
        if ($is_table_text) {
          continue;
        }

        // Find the best matching font line from min_fi onward.
        // Score by overlap: how much of the font line's text appears
        // in the text line (or vice versa).
        $text_sig = mb_strtolower(preg_replace('/[^a-z0-9\p{L}●•◦▪▸►○■◆➤]/iu', '', trim($text)));
        $best_fi = NULL;
        $best_score = 0;
        // Also check last_matched_fi (allows a font line to match
        // multiple text lines when it contains merged content).
        $start_fi = ($last_matched_fi >= 0 && $last_matched_fi >= $min_fi - 1) ? $last_matched_fi : $min_fi;
        // Search within a window to avoid O(n^2).
        $search_limit = min($min_fi + 5, count($font_sigs));
        for ($fi = $start_fi; $fi < $search_limit; $fi++) {
          if (!isset($font_sigs[$fi])) {
            continue;
          }
          $fs = $font_sigs[$fi];
          if ($fs === '') {
            continue;
          }
          // Score: check if text contains font sig or vice versa.
          if ($text_sig === $fs) {
            $score = 100;
          }
          elseif (str_contains($text_sig, $fs)) {
            $score = 90 * mb_strlen($fs) / max(mb_strlen($text_sig), 1);
          }
          elseif (str_contains($fs, $text_sig)) {
            $score = 90 * mb_strlen($text_sig) / max(mb_strlen($fs), 1);
          }
          else {
            // Check prefix overlap (first N chars match).
            $min_len = min(mb_strlen($text_sig), mb_strlen($fs));
            $prefix_match = 0;
            for ($ci = 0; $ci < $min_len; $ci++) {
              if (mb_substr($text_sig, $ci, 1) === mb_substr($fs, $ci, 1)) {
                $prefix_match++;
              }
              else {
                break;
              }
            }
            $score = $min_len > 0 ? 80 * $prefix_match / $min_len : 0;
          }

          if ($score > $best_score) {
            $best_score = $score;
            $best_fi = $fi;
          }
        }

        // Get matching font properties (fall back to defaults).
        $font_props = [
          'fontSize' => 0,
          'isBold' => FALSE,
          'isItalic' => FALSE,
          'color' => NULL,
          'tag' => '',
          'yPos' => 0,
          'columns' => [],
        ];
        if ($best_fi !== NULL && $best_score >= 30) {
          $font_props = $non_table_font_lines[$best_fi];
          $last_matched_fi = $best_fi;
          // Only advance past this font line if the match is near-exact
          // (the text line covers most of the font line). If the text
          // line is much shorter (partial match), keep min_fi so the
          // next text line can also match this same font line.
          $fl_len = mb_strlen($font_sigs[$best_fi]);
          $tl_len = mb_strlen($text_sig);
          if ($tl_len >= $fl_len * 0.7 || $best_score >= 85) {
            $min_fi = $best_fi + 1;
          }
          else {
            // Partial match — don't advance past this font line.
            $min_fi = max($min_fi, $best_fi);
          }
        }
        else {
          // No good match found; advance min_fi to avoid getting stuck.
          $min_fi++;
        }

        $page_lines[] = [
          'text' => trim($text),
          'fontSize' => $font_props['fontSize'],
          'isBold' => $font_props['isBold'],
          'isItalic' => $font_props['isItalic'],
          'color' => $font_props['color'],
          'tag' => $font_props['tag'] ?? '',
          'linkSpans' => $font_props['linkSpans'] ?? [],
          'yPos' => $font_props['yPos'] ?? 0,
        ];
      }

      // PHASE 4: Insert table entries at the correct document position.
      // Text lines from getText() are already in correct reading order,
      // so we keep that order and insert table entries at the position
      // where the table's minimum Y falls relative to text line Y values.
      // We find the last text line whose yPos is <= the table's first
      // row yPos and insert the table entries after it.
      if (!empty($table_entries)) {
        // Get the Y of the first table row.
        $table_min_y = PHP_INT_MAX;
        foreach ($table_entries as $entry) {
          if (($entry['yPos'] ?? 0) < $table_min_y) {
            $table_min_y = $entry['yPos'];
          }
        }

        // Find the insertion index: the position after the last text
        // line whose yPos is less than the table's first row.
        $insert_idx = 0;
        foreach ($page_lines as $idx => $line) {
          if (($line['yPos'] ?? 0) > 0 && ($line['yPos'] ?? 0) < $table_min_y) {
            $insert_idx = $idx + 1;
          }
        }

        // Insert table entries into page_lines at the correct position.
        array_splice($page_lines, $insert_idx, 0, $table_entries);
      }

      foreach ($page_lines as $line) {
        $all_lines[] = $line;
      }
    }

    if (empty($all_lines)) {
      return '';
    }

    // Determine the body font size (the most common size).
    $body_size = $this->styleAnalyzer->detectBodyFontSize($all_lines);

    // Determine the body text color (the most common color).
    $body_color = $this->styleAnalyzer->detectBodyColor($all_lines);

    // Build HTML by merging consecutive lines into paragraphs.
    return $this->htmlRenderer->buildHtml($all_lines, $body_size, $body_color, $options);
  }

  /**
   * Split a page's getText() output into non-empty lines.
   *
   * @param string $page_text
   *   Raw text from Page::getText().
   *
   * @return array
   *   An array of text lines (may include empty strings).
   */
  protected function splitTextIntoLines(string $page_text): array {
    // getText() uses \n for line breaks.
    return explode("\n", $page_text);
  }

  /**
   * Extract per-segment color from the raw PDF content stream.
   *
   * The pdfparser library filters out color operators (rg, sc, g, k) from
   * its extractRawData/getDataCommands pipeline. To extract color we parse
   * the raw content stream directly, tracking the graphics state (color and
   * q/Q save/restore stack) and associating each Tj/TJ text command with
   * the currently active non-stroking color.
   *
   * Also extracts BDC marked content tags (H1, H2, P, Span, etc.) which
   * carry semantic structure information from tagged PDFs.
   *
   * @param \Smalot\PdfParser\Page $page
   *   The page object.
   * @param int $segment_count
   *   Expected number of text segments (from DataTm).
   *
   * @return array
   *   Array indexed by segment number. Each entry has:
   *   - color: array [r, g, b] with values 0.0-1.0, or NULL.
   *   - tag: string BDC tag name (e.g., "H1", "P") or empty string.
   */
  protected function extractSegmentColors(Page $page, int $segment_count): array {
    $raw_content = $this->getPageRawContent($page);
    if ($raw_content === '') {
      return array_fill(0, $segment_count, ['color' => NULL, 'tag' => '']);
    }

    // Find all relevant operators by position in the content stream.
    // We collect: color commands, q/Q state, BDC tags, and Tj/TJ text ops.
    $events = [];

    // Non-stroking RGB: r g b rg
    if (preg_match_all('/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+rg\b/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'color',
          'color' => [(float) $match[1][0], (float) $match[2][0], (float) $match[3][0]],
        ];
      }
    }

    // Non-stroking color space (3-component sc): r g b sc
    if (preg_match_all('/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+sc\b/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'color',
          'color' => [(float) $match[1][0], (float) $match[2][0], (float) $match[3][0]],
        ];
      }
    }

    // Non-stroking gray: v g (careful not to match font refs like /G3 gs).
    if (preg_match_all('/(?<=\s)([\d.]+)\s+g(?=\s)/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $v = (float) $match[1][0];
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'color',
          'color' => [$v, $v, $v],
        ];
      }
    }

    // Non-stroking CMYK: c m y k k (convert to RGB).
    if (preg_match_all('/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+k\b/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $c = (float) $match[1][0];
        $mm = (float) $match[2][0];
        $y = (float) $match[3][0];
        $kk = (float) $match[4][0];
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'color',
          'color' => [
            (1 - $c) * (1 - $kk),
            (1 - $mm) * (1 - $kk),
            (1 - $y) * (1 - $kk),
          ],
        ];
      }
    }

    // BDC marked content: /TagName <<...>> BDC
    if (preg_match_all('/\/(\w+)\s+<<[^>]*>>\s*BDC/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'bdc',
          'tag' => $match[1][0],
        ];
      }
    }

    // Graphics state save: q (word boundary to avoid matching inside text).
    if (preg_match_all('/(?<=\s|^)q(?=\s|$)/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'q',
        ];
      }
    }

    // Graphics state restore: Q.
    if (preg_match_all('/(?<=\s|^)Q(?=\s|$)/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'Q',
        ];
      }
    }

    // Text operators: Tj and TJ (each corresponds to one DataTm segment).
    if (preg_match_all('/\bTj\b/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'text',
        ];
      }
    }
    if (preg_match_all('/\bTJ\b/', $raw_content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $match) {
        $events[] = [
          'pos' => $match[0][1],
          'type' => 'text',
        ];
      }
    }

    // Sort all events by position in the content stream.
    usort($events, fn($a, $b) => $a['pos'] <=> $b['pos']);

    // Walk events in order, tracking state.
    $current_color = NULL;
    $current_tag = '';
    $color_stack = [];
    $tag_stack = [];
    $results = [];

    foreach ($events as $event) {
      switch ($event['type']) {
        case 'color':
          $current_color = $event['color'];
          break;

        case 'bdc':
          $current_tag = $event['tag'];
          break;

        case 'q':
          $color_stack[] = $current_color;
          $tag_stack[] = $current_tag;
          break;

        case 'Q':
          if (!empty($color_stack)) {
            $current_color = array_pop($color_stack);
          }
          if (!empty($tag_stack)) {
            $current_tag = array_pop($tag_stack);
          }
          break;

        case 'text':
          $results[] = [
            'color' => $current_color,
            'tag' => $current_tag,
          ];
          break;
      }
    }

    // Pad to match expected segment count.
    while (count($results) < $segment_count) {
      $results[] = ['color' => NULL, 'tag' => ''];
    }

    return $results;
  }

  /**
   * Get raw content stream from a page.
   *
   * @param \Smalot\PdfParser\Page $page
   *   The page object.
   *
   * @return string
   *   The raw content stream.
   */
  protected function getPageRawContent(Page $page): string {
    $contents = $page->get('Contents');
    if (!$contents) {
      return '';
    }

    if ($contents instanceof PDFObject) {
      $elements = $contents->getHeader()->getElements();
      if (is_numeric(key($elements))) {
        $raw = '';
        foreach ($elements as $element) {
          if ($element instanceof ElementXRef) {
            $raw .= $element->getObject()->getContent();
          }
          else {
            $raw .= $element->getContent();
          }
        }
        return $raw;
      }
      return $contents->getContent() ?? '';
    }

    if ($contents instanceof ElementArray) {
      $raw = '';
      foreach ($contents->getContent() as $content) {
        $raw .= $content->getContent() . "\n";
      }
      return $raw;
    }

    return '';
  }

  /**
   * Build font-property records per line including color and position.
   *
   * Groups DataTm segments by Y-coordinate, extracts dominant font size,
   * bold/italic flags, color, structural tag, Y-position, and per-column
   * segment data for each line.
   *
   * @param array $data_tm
   *   DataTm entries: [[Tm, text, fontId, fontSize], ...].
   * @param array $fonts
   *   Font objects keyed by ID.
   * @param array $segment_colors
   *   Per-segment color/tag data from extractSegmentColors().
   *
   * @return array
   *   Array of line records with keys: fontSize, isBold, isItalic,
   *   color, tag, yPos, columns.
   */
  protected function buildFontLinesWithColorAndPosition(
    array $data_tm,
    array $fonts,
    array $segment_colors,
  ): array {
    $lines = [];
    $current_y = NULL;
    $current_segments = [];

    foreach ($data_tm as $seg_idx => $item) {
      $tm = $item[0];
      $text = $item[1] ?? '';
      $font_id = $item[2] ?? '';
      $tf_size = (float) ($item[3] ?? 1);

      // Skip completely empty segments.
      if ($text === '') {
        continue;
      }

      $is_space = (trim($text) === '');

      $real_size = round(abs((float) $tm[3]) * $tf_size, 1);
      $x_pos = round((float) $tm[4], 1);
      $y_pos = round((float) $tm[5], 0);

      $font_name = '';
      if ($font_id !== '' && isset($fonts[$font_id])) {
        $font_name = $fonts[$font_id]->getName();
      }

      $seg_color = $segment_colors[$seg_idx] ?? ['color' => NULL, 'tag' => ''];

      $segment = [
        'charCount' => $is_space ? 0 : mb_strlen($text),
        'fontSize' => $real_size,
        'fontName' => $font_name,
        'color' => $seg_color['color'],
        'tag' => $seg_color['tag'],
        'x' => $x_pos,
        'text' => $text,
        'isSpace' => $is_space,
      ];

      // Same Y-coordinate means same line (tolerance of 2pt).
      if ($current_y !== NULL && abs($y_pos - $current_y) <= 2) {
        $current_segments[] = $segment;
      }
      else {
        if (!empty($current_segments)) {
          $merged = $this->mergeSegmentsWithColor($current_segments);
          $merged['yPos'] = $current_y;
          $merged['columns'] = $current_segments;
          $lines[] = $merged;
        }
        $current_segments = [$segment];
        $current_y = $y_pos;
      }
    }

    if (!empty($current_segments)) {
      $merged = $this->mergeSegmentsWithColor($current_segments);
      $merged['yPos'] = $current_y;
      $merged['columns'] = $current_segments;
      $lines[] = $merged;
    }

    return $lines;
  }

  /**
   * Merge segments on the same line into a property summary with color.
   *
   * @param array $segments
   *   Segments with charCount, fontSize, fontName, color, tag.
   *
   * @return array
   *   Array with keys: fontSize, isBold, isItalic, color, tag.
   */
  protected function mergeSegmentsWithColor(array $segments): array {
    $dominant_size = 0;
    $bold_chars = 0;
    $italic_chars = 0;
    $total_chars = 0;
    $color_counts = [];
    $tag_counts = [];

    foreach ($segments as $seg) {
      $chars = max(1, $seg['charCount']);
      $total_chars += $chars;

      if ($seg['fontSize'] > $dominant_size) {
        $dominant_size = $seg['fontSize'];
      }

      $name = strtolower($seg['fontName']);
      if (str_contains($name, 'bold')) {
        $bold_chars += $chars;
      }
      if (str_contains($name, 'italic') || str_contains($name, 'oblique')) {
        $italic_chars += $chars;
      }

      // Track color by character count.
      if ($seg['color'] !== NULL) {
        $color_key = implode(',', array_map(
          fn($v) => round($v, 4),
          $seg['color']
        ));
        $color_counts[$color_key] = ($color_counts[$color_key] ?? 0) + $chars;
      }

      // Track structural tag by character count.
      if (!empty($seg['tag'])) {
        $tag_counts[$seg['tag']] = ($tag_counts[$seg['tag']] ?? 0) + $chars;
      }
    }

    // Find dominant color.
    $dominant_color = NULL;
    if (!empty($color_counts)) {
      arsort($color_counts);
      $key = array_key_first($color_counts);
      $dominant_color = array_map('floatval', explode(',', $key));
    }

    // Find dominant tag.
    $dominant_tag = '';
    if (!empty($tag_counts)) {
      arsort($tag_counts);
      $dominant_tag = array_key_first($tag_counts);
    }

    return [
      'fontSize' => $dominant_size,
      'isBold' => ($total_chars > 0 && $bold_chars > $total_chars / 2),
      'isItalic' => ($total_chars > 0 && $italic_chars > $total_chars / 2),
      'color' => $dominant_color,
      'tag' => $dominant_tag,
    ];
  }

}
