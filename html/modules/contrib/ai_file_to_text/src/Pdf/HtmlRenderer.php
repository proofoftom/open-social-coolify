<?php

namespace Drupal\ai_file_to_text\Pdf;

/**
 * Renders classified PDF content lines into semantic HTML.
 *
 * Handles headings, paragraphs, ordered/unordered lists, tables, links
 * (both annotation-based and auto-detected URLs), and inline styles.
 */
class HtmlRenderer {

  /**
   * The style analyzer for heading detection and inline styles.
   *
   * @var \Drupal\ai_file_to_text\Pdf\StyleAnalyzer
   */
  protected StyleAnalyzer $styleAnalyzer;

  /**
   * Constructs an HtmlRenderer.
   *
   * @param \Drupal\ai_file_to_text\Pdf\StyleAnalyzer $style_analyzer
   *   The style analyzer instance.
   */
  public function __construct(StyleAnalyzer $style_analyzer) {
    $this->styleAnalyzer = $style_analyzer;
  }

  /**
   * Build HTML from lines, detecting headings, lists, links and tables.
   *
   * @param array $lines
   *   All lines with font metadata (may contain NULLs as page breaks).
   * @param float $body_size
   *   The detected body font size.
   * @param array|null $body_color
   *   The detected body color as [r, g, b] (0.0-1.0), or NULL.
   * @param array $options
   *   Extraction options:
   *   - native_headings: bool Use native <h1>…<h6> tags (default FALSE).
   *
   * @return string
   *   The generated HTML.
   */
  public function buildHtml(array $lines, float $body_size, ?array $body_color = NULL, array $options = []): string {
    $html = '';

    // Bullet characters that indicate an unordered list item.
    $bullet_chars = ['●', '•', '◦', '▪', '▸', '►', '○', '■', '◆', '➤', '–', '—'];
    // Regex for numbered list items: "1.", "2)", "a.", "i.", etc.
    // Supports zero-width space (U+200B) after the delimiter, which
    // Google Docs and similar tools insert in PDFs.
    $numbered_pattern = '/^(\d+|[a-z]|[ivxlc]+)[.)][\s\x{200b}]/iu';
    // Regex for sub-list items: "○", preceded by deeper indentation.
    $sub_bullet_chars = ['○', '◦', '▸', '–', '—'];

    // Classify each line. NULL entries are page-break markers.
    $classified = [];
    foreach ($lines as $line) {
      if ($line === NULL) {
        $classified[] = NULL;
        continue;
      }

      // Table rows pass through directly.
      if (isset($line['type']) && $line['type'] === 'table_row') {
        $classified[] = $line;
        continue;
      }

      $text = trim($line['text'] ?? '');
      if ($text === '') {
        continue;
      }

      // Skip likely page numbers: standalone short numeric text.
      $font_size = $line['fontSize'] ?? 0;
      if (preg_match('/^\d{1,4}$/', $text) && $font_size <= $body_size) {
        continue;
      }

      // Skip likely footnotes: short text with font size significantly
      // smaller than body text (below 85% of body size).
      if ($font_size > 0 && $body_size > 0
        && ($font_size / $body_size) < 0.85
        && mb_strlen($text) < 60) {
        continue;
      }

      $heading_level = $this->styleAnalyzer->detectHeadingLevel($font_size, $body_size);
      $color = $line['color'] ?? NULL;
      $link_spans = $line['linkSpans'] ?? [];

      // Validate heading: apply heuristic checks to reduce false
      // positives from font-line/text-line matching drift.
      if ($heading_level > 0) {
        // Headings should be short (under ~65 chars). Long text is
        // likely a paragraph that got mis-matched to a larger font line.
        if (mb_strlen($text) > 65) {
          $heading_level = 0;
        }
        // Headings should not start with bullet characters.
        $starts_with_bullet = FALSE;
        foreach ($bullet_chars as $bullet) {
          if (str_starts_with($text, $bullet)) {
            $starts_with_bullet = TRUE;
            break;
          }
        }
        if ($starts_with_bullet) {
          $heading_level = 0;
        }
        // Headings should not start with lowercase (except after a
        // number prefix like "5. Getting Started").
        if ($heading_level > 0 && preg_match('/^[a-z]/', $text)) {
          $heading_level = 0;
        }
      }

      // Detect bold-only headings: short bold text at body font size
      // that acts as a sub-section heading (e.g., "Development issue
      // queues", "Marketing / Organisation issue queue"). These appear
      // in PDFs as bold body-size text but serve as headings.
      $is_bold = $line['isBold'] ?? FALSE;
      if ($heading_level === 0 && $is_bold
        && mb_strlen($text) <= 45
        && preg_match('/^[A-Z\d]/', $text)) {
        // Ensure it's not a bullet item.
        $is_bullet_text = FALSE;
        foreach ($bullet_chars as $bullet) {
          if (str_starts_with($text, $bullet)) {
            $is_bullet_text = TRUE;
            break;
          }
        }
        if (!$is_bullet_text) {
          $heading_level = 3;
        }
      }

      // Detect list items by bullet character or numbered prefix.
      $list_type = NULL;
      $list_text = $text;
      $is_sub_item = FALSE;

      // Don't treat headings as list items (e.g., "4. Initial Onboarding").
      if ($heading_level === 0) {
        // Check for bullet character at start.
        foreach ($bullet_chars as $bullet) {
          if (str_starts_with($text, $bullet)) {
            $list_type = 'ul';
            // Remove the bullet and any trailing whitespace/zero-width chars.
            $list_text = ltrim(mb_substr($text, mb_strlen($bullet)), " \t\u{200b}");
            // Check if it's a sub-bullet.
            if (in_array($bullet, $sub_bullet_chars, TRUE)) {
              $is_sub_item = TRUE;
            }
            break;
          }
        }

        // Check for numbered list prefix (only if no bullet found).
        // Only treat as a list if the text after the number is short
        // (continuation text, not a heading with a section number).
        if ($list_type === NULL && preg_match($numbered_pattern, $text, $nm)) {
          $list_type = 'ol';
          $list_text = ltrim(mb_substr($text, mb_strlen($nm[0])));
        }
      }

      $classified[] = [
        'text' => $text,
        'listText' => $list_text,
        'headingLevel' => $heading_level,
        'isBold' => $line['isBold'] ?? FALSE,
        'isItalic' => $line['isItalic'] ?? FALSE,
        'fontSize' => $font_size,
        'color' => $color,
        'listType' => $list_type,
        'isSubItem' => $is_sub_item,
        'linkSpans' => $link_spans,
      ];
    }

    // Build output blocks: merge consecutive body lines into paragraphs,
    // group consecutive table rows into tables, group list items.
    $blocks = [];
    $current = NULL;

    foreach ($classified as $item) {
      if ($item === NULL) {
        if ($current !== NULL) {
          $blocks[] = $current;
          $current = NULL;
        }
        continue;
      }

      // Handle table rows.
      if (isset($item['type']) && $item['type'] === 'table_row') {
        $table_id = $item['tableId'];
        if ($current !== NULL && ($current['blockType'] ?? '') === 'table' && $current['tableId'] === $table_id) {
          $current['rows'][] = $item;
        }
        else {
          if ($current !== NULL) {
            $blocks[] = $current;
          }
          $current = [
            'blockType' => 'table',
            'tableId' => $table_id,
            'rows' => [$item],
          ];
        }
        continue;
      }

      // Handle list items.
      if ($item['listType'] !== NULL) {
        if ($current !== NULL && ($current['blockType'] ?? '') === 'list' && $current['listType'] === $item['listType']) {
          $current['items'][] = $item;
        }
        else {
          if ($current !== NULL) {
            $blocks[] = $current;
          }
          $current = [
            'blockType' => 'list',
            'listType' => $item['listType'],
            'items' => [$item],
          ];
        }
        continue;
      }

      // Handle regular text lines.
      // If the previous block is a list, check if this plain body text
      // is a wrapped continuation of the last list item. Criteria:
      // - Same font size as the last list item (within tolerance).
      // - Not a heading.
      // - Same color or body color (not a different styled section).
      // - The continuation is limited: only allow a few continuation lines
      //   before breaking to prevent runaway absorption.
      if ($current !== NULL && ($current['blockType'] ?? '') === 'list'
        && $item['headingLevel'] === 0
        && !empty($current['items'])) {
        $last_item = end($current['items']);
        $last_idx = array_key_last($current['items']);
        $same_size = abs(($last_item['fontSize'] ?? 0) - ($item['fontSize'] ?? 0)) < 1.0;

        // Count how many continuation lines we've already appended.
        $cont_count = $current['items'][$last_idx]['_continuations'] ?? 0;

        if ($same_size && $cont_count < 4) {
          $current['items'][$last_idx]['listText'] .= ' ' . $item['text'];
          $current['items'][$last_idx]['_continuations'] = $cont_count + 1;
          // Merge linkSpans from continuation line.
          if (!empty($item['linkSpans'])) {
            $current['items'][$last_idx]['linkSpans'] = array_merge(
              $current['items'][$last_idx]['linkSpans'] ?? [],
              $item['linkSpans']
            );
          }
          continue;
        }
      }
      $color_key = $item['color'] !== NULL
        ? implode(',', array_map(fn($v) => round($v, 4), $item['color']))
        : 'none';

      $key = $item['headingLevel'] . '|'
        . ($item['isBold'] ? '1' : '0') . '|'
        . ($item['isItalic'] ? '1' : '0') . '|'
        . $item['fontSize'] . '|'
        . $color_key;

      if ($current !== NULL && ($current['blockType'] ?? '') === 'text' && $current['key'] === $key && $item['headingLevel'] === 0) {
        $current['lines'][] = $item['text'];
        // Merge linkSpans from continuation lines.
        $current['linkSpans'] = array_merge($current['linkSpans'] ?? [], $item['linkSpans'] ?? []);
      }
      else {
        if ($current !== NULL) {
          $blocks[] = $current;
        }
        $current = [
          'blockType' => 'text',
          'key' => $key,
          'lines' => [$item['text']],
          'headingLevel' => $item['headingLevel'],
          'isBold' => $item['isBold'],
          'isItalic' => $item['isItalic'],
          'fontSize' => $item['fontSize'],
          'color' => $item['color'],
          'linkSpans' => $item['linkSpans'] ?? [],
        ];
      }
    }
    if ($current !== NULL) {
      $blocks[] = $current;
    }

    // Post-process: merge split numbered lists. When an <ol> block is
    // followed by a <ul> block (sub-items like URLs) and then another
    // <ol> block continuing the sequence, merge them into one <ol>.
    $blocks = $this->mergeConsecutiveLists($blocks);

    // Render each block.
    foreach ($blocks as $block) {
      if (($block['blockType'] ?? '') === 'table') {
        $html .= $this->renderTable($block['rows'], $body_size, $body_color);
        continue;
      }

      if (($block['blockType'] ?? '') === 'list') {
        $html .= $this->renderList($block, $body_size, $body_color);
        continue;
      }

      // Regular text/heading block.
      $inner_html = $this->renderTextLines(
        $block['lines'],
        $block['linkSpans'] ?? []
      );

      // Apply inline bold/italic.
      if ($block['isBold']) {
        $inner_html = '<strong>' . $inner_html . '</strong>';
      }
      if ($block['isItalic']) {
        $inner_html = '<em>' . $inner_html . '</em>';
      }

      $level = $block['headingLevel'];
      $styles = $this->styleAnalyzer->buildInlineStyles(
        $block['fontSize'],
        $body_size,
        $block['color'],
        $body_color,
        $level > 0
      );

      if ($level > 0) {
        $native_headings = !empty($options['native_headings']);
        if ($native_headings) {
          $tag = 'h' . $level;
        }
        else {
          $tag = $level === 1 ? 'p' : ('h' . $level);
        }
        $style_attr = $styles !== '' ? ' style="' . $styles . '"' : '';
        $html .= '<' . $tag . ' class="h' . $level . '"' . $style_attr . '>' . $inner_html . '</' . $tag . '>' . "\n";
      }
      else {
        if ($styles !== '') {
          $html .= '<p style="' . $styles . '">' . $inner_html . '</p>' . "\n";
        }
        else {
          $html .= '<p>' . $inner_html . '</p>' . "\n";
        }
      }
    }

    return trim($html);
  }

  /**
   * Merge consecutive related list blocks into unified lists.
   *
   * Handles two patterns:
   * 1. ol → ul → ol: When a numbered list is interrupted by a sub-list
   *    (e.g., URL sub-items), merge the ul items as sub-items of the
   *    last ol item, and append the continuation ol items.
   * 2. ol → ol: When two consecutive ol blocks have sequential numbers,
   *    merge them into one.
   *
   * @param array $blocks
   *   Array of block records.
   *
   * @return array
   *   Merged block array.
   */
  protected function mergeConsecutiveLists(array $blocks): array {
    if (count($blocks) < 2) {
      return $blocks;
    }

    $merged = [];
    $i = 0;
    while ($i < count($blocks)) {
      $block = $blocks[$i];

      // Check if this is an ol block that can merge with following blocks.
      if (($block['blockType'] ?? '') === 'list'
        && $block['listType'] === 'ol') {

        // Absorb following ul blocks (as sub-items) and ol blocks
        // (as continuation items).
        while ($i + 1 < count($blocks)) {
          $next = $blocks[$i + 1];
          $next_type = $next['blockType'] ?? '';

          if ($next_type === 'list' && $next['listType'] === 'ul') {
            // Merge ul items as sub-items of the last ol item.
            $last_idx = array_key_last($block['items']);
            if ($last_idx !== NULL) {
              foreach ($next['items'] as $sub_item) {
                $sub_item['isSubItem'] = TRUE;
                $block['items'][] = $sub_item;
              }
            }
            $i++;
          }
          elseif ($next_type === 'list' && $next['listType'] === 'ol') {
            // Merge continuation ol items.
            foreach ($next['items'] as $item) {
              $block['items'][] = $item;
            }
            $i++;
          }
          else {
            break;
          }
        }
      }

      $merged[] = $block;
      $i++;
    }

    return $merged;
  }

  /**
   * Render text lines with URL auto-detection and annotation-based links.
   *
   * Applies link spans from PDF annotations by matching their display
   * text to the rendered content. Also auto-detects bare URLs.
   *
   * @param array $lines
   *   Array of text line strings.
   * @param array $link_spans
   *   Link span records with 'text' and 'uri' keys.
   *
   * @return string
   *   HTML with <br> between lines, linked text wrapped in <a> tags.
   */
  protected function renderTextLines(array $lines, array $link_spans = []): string {
    $full_text = implode("\n", $lines);

    // Apply annotation-based link spans first.
    $full_text = $this->applyLinkSpans($full_text, $link_spans);

    // Split back to lines and process each.
    $parts = explode("\n", $full_text);
    $rendered = [];
    foreach ($parts as $line_text) {
      $escaped = htmlspecialchars($line_text, ENT_QUOTES, 'UTF-8');
      // Auto-detect bare URLs and wrap in <a href> tags.
      $escaped = $this->autoLinkUrls($escaped);
      $rendered[] = $escaped;
    }

    // Restore <a> tags that were entity-encoded by htmlspecialchars.
    $result = implode('<br>', $rendered);
    $result = $this->restoreAnchorTags($result);
    return $result;
  }

  /**
   * Apply link spans to text by matching display text to content.
   *
   * Link span text comes from DataTm segments (often without spaces).
   * The content text comes from getText() (with spaces). This method
   * matches by stripping spaces from both and finding the position.
   *
   * @param string $text
   *   The raw text content.
   * @param array $link_spans
   *   Link span records with 'text' and 'uri' keys.
   *
   * @return string
   *   Text with link markers inserted (pre-HTML-escaping).
   */
  protected function applyLinkSpans(string $text, array $link_spans): string {
    if (empty($link_spans)) {
      return $text;
    }

    // For each link span, find its display text in the content and wrap
    // it with temporary markers that survive htmlspecialchars.
    foreach ($link_spans as $span) {
      $link_text = $span['text'] ?? '';
      $uri = $span['uri'] ?? '';
      if ($link_text === '' || $uri === '') {
        continue;
      }

      // The link text from DataTm has no spaces. Find the matching text
      // in the content by comparing space-stripped versions.
      $link_text_no_space = preg_replace('/\s+/u', '', $link_text);
      if ($link_text_no_space === '') {
        continue;
      }

      // Build a regex that matches the link text with optional spaces.
      $chars = preg_split('//u', $link_text_no_space, -1, PREG_SPLIT_NO_EMPTY);
      $pattern = implode('\s*', array_map(fn($c) => preg_quote($c, '/'), $chars));
      $pattern = '/(' . $pattern . ')/u';

      if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
        $matched_text = $m[1][0];
        $offset = $m[1][1];

        // Use temporary markers that won't be affected by htmlspecialchars.
        $replacement = '{{LINK_START:' . base64_encode($uri) . '}}' . $matched_text . '{{LINK_END}}';
        $text = substr_replace($text, $replacement, $offset, strlen($matched_text));
      }
    }

    return $text;
  }

  /**
   * Restore anchor tags from temporary markers after HTML escaping.
   *
   * @param string $html
   *   HTML-escaped text with encoded link markers.
   *
   * @return string
   *   HTML with proper <a href> tags.
   */
  protected function restoreAnchorTags(string $html): string {
    // The markers were HTML-escaped, so match the escaped versions.
    return preg_replace_callback(
      '/\{\{LINK_START:([A-Za-z0-9+\/=]+)\}\}(.*?)\{\{LINK_END\}\}/s',
      function ($m) {
        $uri = base64_decode($m[1]);
        $display = $m[2];
        $href = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '">' . $display . '</a>';
      },
      $html
    );
  }

  /**
   * Render a list block as HTML <ul> or <ol>.
   *
   * Groups sub-items into nested lists when detected.
   *
   * @param array $block
   *   List block with 'listType' and 'items'.
   * @param float $body_size
   *   The detected body font size.
   * @param array|null $body_color
   *   The detected body color.
   *
   * @return string
   *   HTML list markup.
   */
  protected function renderList(array $block, float $body_size, ?array $body_color): string {
    $tag = $block['listType'] === 'ol' ? 'ol' : 'ul';
    $html = '<' . $tag . '>' . "\n";

    $items = $block['items'];
    $i = 0;
    while ($i < count($items)) {
      $item = $items[$i];
      $text = $item['listText'] ?? $item['text'];
      // Apply annotation-based link spans before escaping.
      $text = $this->applyLinkSpans($text, $item['linkSpans'] ?? []);
      $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
      $escaped = $this->autoLinkUrls($escaped);
      $escaped = $this->restoreAnchorTags($escaped);

      // Check if next items are sub-items.
      $sub_items = [];
      while ($i + 1 < count($items) && !empty($items[$i + 1]['isSubItem'])) {
        $i++;
        $sub_items[] = $items[$i];
      }

      if (!empty($sub_items)) {
        $html .= '<li>' . $escaped . "\n";
        $html .= '<ul>' . "\n";
        foreach ($sub_items as $sub) {
          $sub_text = $sub['listText'] ?? $sub['text'];
          $sub_text = $this->applyLinkSpans($sub_text, $sub['linkSpans'] ?? []);
          $sub_escaped = htmlspecialchars($sub_text, ENT_QUOTES, 'UTF-8');
          $sub_escaped = $this->autoLinkUrls($sub_escaped);
          $sub_escaped = $this->restoreAnchorTags($sub_escaped);
          $html .= '<li>' . $sub_escaped . '</li>' . "\n";
        }
        $html .= '</ul>' . "\n";
        $html .= '</li>' . "\n";
      }
      else {
        $html .= '<li>' . $escaped . '</li>' . "\n";
      }

      $i++;
    }

    $html .= '</' . $tag . '>' . "\n";
    return $html;
  }

  /**
   * Auto-detect URLs in text and wrap them in <a href> tags.
   *
   * Handles already-escaped HTML text. Detects http://, https:// URLs
   * and wraps them with anchor tags.
   *
   * @param string $escaped_text
   *   HTML-escaped text.
   *
   * @return string
   *   Text with URLs wrapped in <a href> tags.
   */
  protected function autoLinkUrls(string $escaped_text): string {
    // Match URLs (already HTML-escaped, so &amp; may appear).
    // But skip URLs that are already inside {{LINK_START}}...{{LINK_END}}
    // markers, to avoid double-wrapping with <a> tags.
    //
    // Split text into segments inside/outside markers, only auto-link
    // URLs in outside segments.
    $parts = preg_split(
      '/(\{\{LINK_START:[A-Za-z0-9+\/=]+\}\}.*?\{\{LINK_END\}\})/s',
      $escaped_text, -1, PREG_SPLIT_DELIM_CAPTURE
    );
    $result = '';
    foreach ($parts as $part) {
      if (preg_match('/^\{\{LINK_START:/', $part)) {
        // This part is a link marker span — pass through unchanged.
        $result .= $part;
      }
      else {
        // Auto-link bare URLs in non-marker text.
        $result .= preg_replace_callback(
          '/(https?:\/\/[^\s<>"\']+)/i',
          function ($matches) {
            $url = $matches[1];
            $href = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
            $href = rtrim($href, '.,;:!?)');
            $display = rtrim($url, '.,;:!?)');
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $display . '</a>';
          },
          $part
        );
      }
    }
    return $result;
  }

  /**
   * Render a table block as an HTML table.
   *
   * The first row is rendered as <thead> with <th> elements, remaining
   * rows as <tbody> with <td> elements.
   *
   * @param array $rows
   *   Table row records with 'cells' arrays.
   * @param float $body_size
   *   The detected body font size.
   * @param array|null $body_color
   *   The detected body color.
   *
   * @return string
   *   The HTML table markup.
   */
  protected function renderTable(array $rows, float $body_size, ?array $body_color): string {
    if (empty($rows)) {
      return '';
    }

    $html = "<table>\n";

    foreach ($rows as $ri => $row) {
      $cells = $row['cells'] ?? [];
      $cell_tag = ($ri === 0) ? 'th' : 'td';

      if ($ri === 0) {
        $html .= "<thead>\n";
      }
      elseif ($ri === 1) {
        $html .= "<tbody>\n";
      }

      $html .= '<tr>';
      foreach ($cells as $cell_text) {
        $escaped = htmlspecialchars(trim($cell_text), ENT_QUOTES, 'UTF-8');
        $html .= '<' . $cell_tag . '>' . $escaped . '</' . $cell_tag . '>';
      }
      $html .= "</tr>\n";

      if ($ri === 0) {
        $html .= "</thead>\n";
      }
    }

    if (count($rows) > 1) {
      $html .= "</tbody>\n";
    }

    $html .= "</table>\n";

    return $html;
  }

}
