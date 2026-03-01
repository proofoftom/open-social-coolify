<?php

namespace Drupal\ai_file_to_text\Pdf;

/**
 * Analyzes visual style properties from PDF content.
 *
 * Detects body font size, body/link colors, heading levels, and builds
 * inline CSS styles from font metadata. Operates on line-level records
 * produced by the PDF parsing pipeline.
 */
class StyleAnalyzer {

  /**
   * Detect the body font size (the most common size by character count).
   *
   * @param array $lines
   *   All lines with font metadata (may contain NULLs).
   *
   * @return float
   *   The body font size.
   */
  public function detectBodyFontSize(array $lines): float {
    $size_counts = [];
    foreach ($lines as $line) {
      if ($line === NULL || empty($line['fontSize'])) {
        continue;
      }
      $size = (string) $line['fontSize'];
      // For table rows, count chars from all cells.
      if (isset($line['type']) && $line['type'] === 'table_row') {
        $char_count = array_sum(array_map(fn($c) => mb_strlen(trim($c)), $line['cells'] ?? []));
      }
      else {
        $char_count = mb_strlen(trim($line['text'] ?? ''));
      }
      $size_counts[$size] = ($size_counts[$size] ?? 0) + $char_count;
    }

    if (empty($size_counts)) {
      return 12.0;
    }

    arsort($size_counts);
    $body_size = (float) array_key_first($size_counts);

    return $body_size > 0 ? $body_size : 12.0;
  }

  /**
   * Detect the body text color (the most common color by character count).
   *
   * @param array $lines
   *   All lines with font metadata (may contain NULLs).
   *
   * @return array|null
   *   The body color as [r, g, b] (0.0-1.0), or NULL if no color data.
   */
  public function detectBodyColor(array $lines): ?array {
    $color_counts = [];
    foreach ($lines as $line) {
      if ($line === NULL || empty($line['color'])) {
        continue;
      }
      // For table rows, count chars from all cells.
      if (isset($line['type']) && $line['type'] === 'table_row') {
        $char_count = array_sum(array_map(fn($c) => mb_strlen(trim($c)), $line['cells'] ?? []));
      }
      else {
        $char_count = mb_strlen(trim($line['text'] ?? ''));
      }
      $key = implode(',', array_map(
        fn($v) => round($v, 4),
        $line['color']
      ));
      $color_counts[$key] = ($color_counts[$key] ?? 0) + $char_count;
    }

    if (empty($color_counts)) {
      return NULL;
    }

    arsort($color_counts);
    $parts = explode(',', array_key_first($color_counts));
    return array_map('floatval', $parts);
  }

  /**
   * Detect the body color from raw segment color data.
   *
   * Used for per-page body color detection before the full document
   * body color is computed.
   *
   * @param array $segment_colors
   *   Per-segment color/tag data from extractSegmentColors().
   *
   * @return array|null
   *   The body color as [r, g, b] (0.0-1.0), or NULL.
   */
  public function detectBodyColorFromSegments(array $segment_colors): ?array {
    $color_counts = [];
    foreach ($segment_colors as $sc) {
      if ($sc['color'] === NULL) {
        continue;
      }
      $key = implode(',', array_map(
        fn($v) => round($v, 4),
        $sc['color']
      ));
      $color_counts[$key] = ($color_counts[$key] ?? 0) + 1;
    }

    if (empty($color_counts)) {
      return NULL;
    }

    arsort($color_counts);
    $parts = explode(',', array_key_first($color_counts));
    return array_map('floatval', $parts);
  }

  /**
   * Detect the link color from segment colors across all pages.
   *
   * The link color is the most common non-body, non-black, non-white
   * color that appears across all segments. Using a global color avoids
   * per-page misdetection where a non-link styled color (like grey
   * channel names) might be more frequent on a single page.
   *
   * @param array $segment_colors
   *   Aggregated per-segment color/tag data from all pages.
   * @param array|null $body_color
   *   The global body text color.
   *
   * @return string|null
   *   The link color as hex string (e.g. "#1155cc"), or NULL.
   */
  public function detectLinkColor(array $segment_colors, ?array $body_color): ?string {
    $body_hex = $body_color !== NULL ? $this->colorToHex($body_color) : '#000000';

    $color_counts = [];
    foreach ($segment_colors as $sc) {
      if ($sc['color'] === NULL) {
        continue;
      }
      $hex = $this->colorToHex($sc['color']);
      // Exclude body color, black, white, and near-white.
      if ($hex === $body_hex || $hex === '#000000' || $hex === '#ffffff'
        || $hex === '#fefefe') {
        continue;
      }
      $color_counts[$hex] = ($color_counts[$hex] ?? 0) + 1;
    }

    if (empty($color_counts)) {
      return NULL;
    }

    arsort($color_counts);
    return array_key_first($color_counts);
  }

  /**
   * Detect heading level from font size relative to body size.
   *
   * @param float $font_size
   *   The font size of the line.
   * @param float $body_size
   *   The body font size.
   *
   * @return int
   *   Heading level 1-4, or 0 for body text.
   */
  public function detectHeadingLevel(float $font_size, float $body_size): int {
    if ($body_size <= 0 || $font_size <= 0) {
      return 0;
    }

    $ratio = $font_size / $body_size;

    if ($ratio >= 1.75) {
      return 1;
    }
    if ($ratio >= 1.4) {
      return 2;
    }
    if ($ratio >= 1.15) {
      return 3;
    }

    return 0;
  }

  /**
   * Build inline CSS styles combining font-size and color.
   *
   * Only includes font-size for non-heading text with a non-standard size.
   * Only includes color when different from the body text color.
   * White (#ffffff) and near-white colors are skipped (invisible on white
   * backgrounds).
   *
   * @param float $font_size
   *   The font size.
   * @param float $body_size
   *   The body font size.
   * @param array|null $color
   *   The text color as [r, g, b] (0.0-1.0), or NULL.
   * @param array|null $body_color
   *   The body color as [r, g, b] (0.0-1.0), or NULL.
   * @param bool $is_heading
   *   Whether this is a heading (skip font-size in that case).
   *
   * @return string
   *   CSS inline style string or empty string.
   */
  public function buildInlineStyles(
    float $font_size,
    float $body_size,
    ?array $color,
    ?array $body_color,
    bool $is_heading = FALSE,
  ): string {
    $parts = [];

    // Font size for non-heading, non-standard-size text.
    if (!$is_heading && $font_size > 0 && abs($font_size - $body_size) > 1.0) {
      $parts[] = 'font-size: ' . round($font_size, 1) . 'pt';
    }

    // Color when it differs from body color.
    if ($color !== NULL) {
      $hex = $this->colorToHex($color);
      $body_hex = $body_color !== NULL ? $this->colorToHex($body_color) : '#000000';

      // Skip white/near-white (invisible on white background)
      // and skip if same as body color.
      if ($hex !== $body_hex && $hex !== '#ffffff' && $hex !== '#fefefe') {
        $parts[] = 'color: ' . $hex;
      }
    }

    return implode('; ', $parts);
  }

  /**
   * Convert RGB float array to hex color string.
   *
   * @param array $color
   *   RGB values as [r, g, b] with each 0.0-1.0.
   *
   * @return string
   *   Hex color like "#2e74b5".
   */
  public function colorToHex(array $color): string {
    return sprintf(
      '#%02x%02x%02x',
      (int) round($color[0] * 255),
      (int) round($color[1] * 255),
      (int) round($color[2] * 255)
    );
  }

}
