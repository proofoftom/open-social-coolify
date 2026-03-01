<?php

namespace Drupal\ai_file_to_text\Pdf;

use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;

/**
 * Extracts and matches link annotations from PDF pages.
 *
 * Reads /Annots arrays from pages for URI link annotations, matches
 * link-colored text segments to annotations by text-content similarity
 * and positional overlap.
 */
class LinkExtractor {

  /**
   * The style analyzer for color conversion.
   *
   * @var \Drupal\ai_file_to_text\Pdf\StyleAnalyzer
   */
  protected StyleAnalyzer $styleAnalyzer;

  /**
   * Constructs a LinkExtractor.
   *
   * @param \Drupal\ai_file_to_text\Pdf\StyleAnalyzer $style_analyzer
   *   The style analyzer instance.
   */
  public function __construct(StyleAnalyzer $style_analyzer) {
    $this->styleAnalyzer = $style_analyzer;
  }

  /**
   * Extract link annotations from a PDF page.
   *
   * Reads the /Annots array from the page and returns URI link annotations
   * with their bounding box (Rect) for matching to text by position and
   * color. Links are sorted by Y descending then X ascending (matching
   * visual top-to-bottom, left-to-right page order).
   *
   * @param \Smalot\PdfParser\Page $page
   *   The page object.
   *
   * @return array
   *   Array of link records with keys: uri, xMin, xMax, yMin, yMax.
   */
  public function extractPageLinks(Page $page): array {
    $links = [];

    try {
      $header = $page->getHeader();
      $annots = $header->get('Annots');
    }
    catch (\Exception $e) {
      return [];
    }

    if (!$annots instanceof ElementArray) {
      return [];
    }

    $items = $annots->getContent();
    if (!is_array($items)) {
      return [];
    }

    foreach ($items as $item) {
      try {
        if ($item instanceof ElementXRef) {
          $obj = $item->getObject();
          if (!$obj instanceof PDFObject) {
            continue;
          }
          $details = $obj->getDetails();
        }
        elseif (method_exists($item, 'getDetails')) {
          $details = $item->getDetails();
        }
        else {
          continue;
        }

        // Only process Link annotations with URI actions.
        $subtype = $details['Subtype'] ?? '';
        if ($subtype !== 'Link') {
          continue;
        }

        $action = $details['A'] ?? [];
        $uri = $action['URI'] ?? '';
        if ($uri === '') {
          continue;
        }

        // Rect is [x1, y1, x2, y2].
        $rect = $details['Rect'] ?? [];
        if (count($rect) < 4) {
          continue;
        }

        $x_min = min((float) $rect[0], (float) $rect[2]);
        $x_max = max((float) $rect[0], (float) $rect[2]);
        $y_min = min((float) $rect[1], (float) $rect[3]);
        $y_max = max((float) $rect[1], (float) $rect[3]);

        $links[] = [
          'uri' => $uri,
          'xMin' => $x_min,
          'xMax' => $x_max,
          'yMin' => $y_min,
          'yMax' => $y_max,
        ];
      }
      catch (\Exception $e) {
        continue;
      }
    }

    // Sort by Y descending (top of page first in PDF coords), then X.
    usort($links, function ($a, $b) {
      $y_cmp = $b['yMax'] <=> $a['yMax'];
      return $y_cmp !== 0 ? $y_cmp : ($a['xMin'] <=> $b['xMin']);
    });

    return $links;
  }

  /**
   * Find link annotations whose Y range overlaps a text line's Y-position.
   *
   * @param float $y_pos
   *   The Y-coordinate of the text line from DataTm.
   * @param array $page_links
   *   Link annotations from extractPageLinks().
   *
   * @return array
   *   Array of matching link records with 'uri' key.
   */
  public function findLinksForYPosition(float $y_pos, array $page_links): array {
    $matches = [];
    foreach ($page_links as $link) {
      // DataTm Y can differ from Annot Rect Y by a few points.
      if ($y_pos >= ($link['yMin'] - 5) && $y_pos <= ($link['yMax'] + 5)) {
        $matches[] = $link;
      }
    }
    return $matches;
  }

  /**
   * Match link-colored text segments to link annotations.
   *
   * Identifies link text by its distinctive color (the global link color),
   * groups consecutive link-colored segments on the same line, and matches
   * each group to link annotations using text-content similarity. This is
   * more robust than sequential matching because it handles cases where
   * colored text runs and annotations don't have a 1:1 correspondence.
   *
   * @param array $font_lines
   *   Font line records from buildFontLinesWithColorAndPosition().
   * @param array $page_links
   *   Sorted link annotations from extractPageLinks().
   * @param string|null $link_color_hex
   *   The global link color as hex string (e.g. "#1155cc"), or NULL.
   * @param array $segment_colors
   *   Per-segment color data from extractSegmentColors().
   * @param array $data_tm
   *   Raw DataTm entries.
   *
   * @return array
   *   The font_lines array with 'linkSpans' added to each line.
   *   Each linkSpan is ['text' => string, 'uri' => string].
   */
  public function matchLinksToText(
    array $font_lines,
    array $page_links,
    ?string $link_color_hex,
    array $segment_colors,
    array $data_tm,
  ): array {
    if (empty($page_links) || $link_color_hex === NULL) {
      foreach ($font_lines as &$line) {
        $line['linkSpans'] = [];
      }
      return $font_lines;
    }

    // Step 1: Walk through font lines, find segments with link color,
    // and group them into text runs. Track which font_line each run
    // belongs to and the run's X range.
    $all_runs = [];
    foreach ($font_lines as $line_idx => &$line) {
      $line['linkSpans'] = [];
      $current_run_text = '';
      $current_run_x_min = PHP_FLOAT_MAX;
      $current_run_x_max = 0;
      $in_link = FALSE;

      foreach ($line['columns'] as $seg) {
        $seg_hex = '#000000';
        if ($seg['color'] !== NULL) {
          $seg_hex = $this->styleAnalyzer->colorToHex($seg['color']);
        }

        if ($seg_hex === $link_color_hex) {
          $seg_text = trim($seg['text'] ?? '');
          if ($seg_text !== '') {
            $seg_x = (float) ($seg['x'] ?? 0);
            if ($in_link) {
              $current_run_text .= $seg_text;
            }
            else {
              $current_run_text = $seg_text;
              $current_run_x_min = $seg_x;
              $in_link = TRUE;
            }
            $current_run_x_max = $seg_x;
          }
        }
        else {
          if ($in_link && $current_run_text !== '') {
            $all_runs[] = [
              'lineIdx' => $line_idx,
              'text' => $current_run_text,
              'xMin' => $current_run_x_min,
              'xMax' => $current_run_x_max,
            ];
            $current_run_text = '';
            $in_link = FALSE;
          }
        }
      }
      if ($in_link && $current_run_text !== '') {
        $all_runs[] = [
          'lineIdx' => $line_idx,
          'text' => $current_run_text,
          'xMin' => $current_run_x_min,
          'xMax' => $current_run_x_max,
        ];
      }
    }
    unset($line);

    if (empty($all_runs)) {
      return $font_lines;
    }

    // Step 2: Match each colored text run to the best annotation using
    // text-content similarity. For each run, find the annotation whose
    // URI most closely matches the run's display text.
    $used_annots = [];
    foreach ($all_runs as &$run) {
      $run_text = $run['text'];
      $run_text_lower = mb_strtolower(preg_replace('/[\s\x{200b}]+/u', '', $run_text));
      $best_idx = NULL;
      $best_score = 0;

      foreach ($page_links as $ai => $annot) {
        if (isset($used_annots[$ai])) {
          continue;
        }

        $uri = $annot['uri'];
        $score = $this->linkMatchScore($run_text_lower, $uri);

        if ($score > $best_score) {
          $best_score = $score;
          $best_idx = $ai;
        }
      }

      if ($best_idx !== NULL && $best_score > 0) {
        $run['uri'] = $page_links[$best_idx]['uri'];
        $used_annots[$best_idx] = TRUE;
      }
      else {
        $run['uri'] = '';
      }
    }
    unset($run);

    // Step 3: For unmatched runs, find a matching unmatched annotation
    // using sequential page order with X-coordinate overlap validation.
    // Annotations are sorted Y-descending (top of page first in PDF
    // coords). DataTm runs are in Y-ascending order (which is also
    // top-to-bottom visually). To align these two orderings for
    // sequential matching, we reverse the unmatched annotations so
    // they go bottom-to-top, matching runs from bottom-to-top too.
    $unmatched_annot_indices = [];
    foreach ($page_links as $ai => $annot) {
      if (!isset($used_annots[$ai])) {
        $unmatched_annot_indices[] = $ai;
      }
    }
    // Reverse so bottom-of-page annotations come first.
    $unmatched_annot_indices = array_reverse($unmatched_annot_indices);

    // Match unmatched runs (from last to first = bottom to top) to
    // unmatched annotations (bottom to top) by X-overlap.
    $unmatched_runs = [];
    foreach ($all_runs as $ri => &$run) {
      if ($run['uri'] === '') {
        $unmatched_runs[] = $ri;
      }
    }
    unset($run);
    // Reverse runs too so we match bottom-up.
    $unmatched_runs = array_reverse($unmatched_runs);

    $annot_ptr = 0;
    foreach ($unmatched_runs as $ri) {
      $run_x_min = $all_runs[$ri]['xMin'];
      $run_x_max = $all_runs[$ri]['xMax'];

      // Find the first unmatched annotation with X-overlap.
      while ($annot_ptr < count($unmatched_annot_indices)) {
        $ai = $unmatched_annot_indices[$annot_ptr];
        $annot = $page_links[$ai];
        $overlap_min = max($run_x_min, $annot['xMin']);
        $overlap_max = min($run_x_max, $annot['xMax']);
        if ($overlap_max > $overlap_min) {
          $all_runs[$ri]['uri'] = $annot['uri'];
          $annot_ptr++;
          break;
        }
        $annot_ptr++;
      }
    }

    // Final fallback: sequential assignment for any remaining
    // unmatched runs with the remaining unmatched annotations.
    $still_unmatched = [];
    foreach ($page_links as $ai => $annot) {
      if (!isset($used_annots[$ai])) {
        // Check if this annotation was used in Step 3.
        $was_used = FALSE;
        foreach ($all_runs as $run) {
          if (($run['uri'] ?? '') === $annot['uri']) {
            $was_used = TRUE;
            break;
          }
        }
        if (!$was_used) {
          $still_unmatched[] = $ai;
        }
      }
    }
    $remaining_idx = 0;
    foreach ($all_runs as &$run) {
      if ($run['uri'] === '' && $remaining_idx < count($still_unmatched)) {
        $ai = $still_unmatched[$remaining_idx];
        $run['uri'] = $page_links[$ai]['uri'];
        $remaining_idx++;
      }
    }
    unset($run);

    // Step 4: Assign runs to their respective font lines as linkSpans.
    foreach ($all_runs as $run) {
      $line_idx = $run['lineIdx'];
      if ($run['uri'] !== '') {
        $font_lines[$line_idx]['linkSpans'][] = [
          'text' => $run['text'],
          'uri' => $run['uri'],
        ];
      }
    }

    return $font_lines;
  }

  /**
   * Compute a match score between link display text and an annotation URI.
   *
   * Scores how well the display text corresponds to the URI by checking
   * for common substrings, path segments, and text overlap. Higher scores
   * indicate a better match.
   *
   * @param string $display_lower
   *   Lowercased, space-stripped display text.
   * @param string $uri
   *   The annotation URI.
   *
   * @return float
   *   A match score (0.0 = no match, higher = better).
   */
  public function linkMatchScore(string $display_lower, string $uri): float {
    if ($display_lower === '' || $uri === '') {
      return 0.0;
    }

    $score = 0.0;
    $uri_lower = strtolower($uri);

    // Check if the display text IS the URL (or a shortened version).
    $uri_no_protocol = preg_replace('/^https?:\/\//', '', $uri_lower);
    $uri_clean = rtrim($uri_no_protocol, '/');
    $display_clean = preg_replace('/^https?:\/\//', '', $display_lower);
    $display_clean = rtrim($display_clean, '/');

    // Exact match or prefix match of URL.
    if ($display_clean === $uri_clean || str_starts_with($uri_clean, $display_clean)) {
      return 100.0;
    }
    if (str_starts_with($display_clean, $uri_clean)) {
      return 95.0;
    }

    // Check if display text appears in the URI path/hostname.
    if (str_contains($uri_lower, $display_lower)) {
      $score = max($score, 80.0);
    }

    // Check URI path segments against display text.
    $parsed = parse_url($uri);
    $path = $parsed['path'] ?? '';
    $path_segments = array_filter(explode('/', $path));
    foreach ($path_segments as $segment) {
      $seg_lower = strtolower(urldecode($segment));
      $seg_clean = str_replace(['-', '_', '%20'], '', $seg_lower);
      if ($seg_clean !== '' && str_contains($display_lower, $seg_clean)) {
        $overlap = mb_strlen($seg_clean) / max(mb_strlen($display_lower), 1);
        $score = max($score, 30.0 + $overlap * 50.0);
      }
    }

    // Check host against display text.
    $host = $parsed['host'] ?? '';
    $host_clean = str_replace(['.', 'www.'], '', strtolower($host));
    if ($host_clean !== '' && str_contains($display_lower, $host_clean)) {
      $score = max($score, 20.0);
    }

    // Check if any word from display text (3+ chars) is in the URI.
    $words = preg_split('/[^a-z0-9]+/', $display_lower);
    $matches = 0;
    $total = 0;
    foreach ($words as $word) {
      if (mb_strlen($word) < 3) {
        continue;
      }
      $total++;
      if (str_contains($uri_lower, $word)) {
        $matches++;
      }
    }
    if ($total > 0) {
      $word_ratio = $matches / $total;
      $score = max($score, $word_ratio * 60.0);
    }

    return $score;
  }

}
