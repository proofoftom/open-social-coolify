<?php

namespace Drupal\ai_file_to_text\Pdf;

/**
 * Detects table regions in PDF pages from DataTm positioning data.
 *
 * Uses X-coordinate column clustering and row alignment heuristics to
 * identify structured tabular content. Supports both primary detection
 * (large column gaps, 100+ pt) and secondary detection (smaller gaps,
 * 50+ pt with stricter requirements).
 */
class TableDetector {

  /**
   * Detect table regions from DataTm by X-coordinate column clustering.
   *
   * Uses strict heuristics to avoid false positives from wrapped text
   * or bullet lists. Real tables have large consistent column gaps
   * (typically 100+ points) and multiple rows with matching column
   * positions.
   *
   * @param array $data_tm
   *   DataTm entries from the page.
   *
   * @return array
   *   Array of table region definitions.
   */
  public function detectTableRegions(array $data_tm): array {
    // Minimum X-gap between adjacent column starts to consider separate
    // columns. Real tables typically have 100+ pt gaps between columns.
    // Normal word spacing is 3-10pt, indented text is 20-40pt.
    $gap_threshold = 100;
    // Maximum difference in column start position to consider consistent.
    $col_start_tolerance = 30;
    // Minimum number of rows to qualify as a table.
    $min_table_rows = 2;

    // Group segments by Y-coordinate (combining wrapped text).
    $y_lines = [];
    $current_y = NULL;
    $current_segs = [];

    foreach ($data_tm as $item) {
      $tm = $item[0];
      $text = $item[1] ?? '';
      if (trim($text) === '') {
        continue;
      }

      $x = round((float) $tm[4], 1);
      $y = round((float) $tm[5], 0);

      if ($current_y !== NULL && abs($y - $current_y) <= 2) {
        $current_segs[] = ['x' => $x, 'text' => $text];
      }
      else {
        if (!empty($current_segs)) {
          $y_lines[] = ['y' => $current_y, 'segments' => $current_segs];
        }
        $current_segs = [['x' => $x, 'text' => $text]];
        $current_y = $y;
      }
    }
    if (!empty($current_segs)) {
      $y_lines[] = ['y' => $current_y, 'segments' => $current_segs];
    }

    // Bullet characters that indicate list items, not table rows.
    $bullet_chars_table = ['●', '•', '◦', '▪', '▸', '►', '○', '■', '◆', '➤', '–', '—'];

    // Classify each line: detect columns by X-gaps between column starts.
    // A column is a cluster of segments. The gap is measured between the
    // first segment X of adjacent clusters, not between the last segment
    // of one group and the first of the next (which would catch wrapping).
    $line_infos = [];
    foreach ($y_lines as $y_line) {
      $segments = $y_line['segments'];
      usort($segments, fn($a, $b) => $a['x'] <=> $b['x']);

      // Find column groups: cluster by X-gap between consecutive segments.
      $columns = [[]];
      $last_x = NULL;
      foreach ($segments as $seg) {
        if ($last_x !== NULL && ($seg['x'] - $last_x) > $gap_threshold) {
          $columns[] = [];
        }
        $columns[count($columns) - 1][] = $seg;
        $last_x = $seg['x'];
      }

      // Get column start positions (first X of each column cluster).
      $col_starts = array_map(fn($col) => round($col[0]['x']), $columns);

      // Lines starting with a bullet character are list items, not table
      // rows. The gap between bullet and text shouldn't create columns.
      $first_text = trim($segments[0]['text'] ?? '');
      $is_bullet_line = FALSE;
      foreach ($bullet_chars_table as $bc) {
        if (str_starts_with($first_text, $bc)) {
          $is_bullet_line = TRUE;
          break;
        }
      }

      $line_infos[] = [
        'y' => $y_line['y'],
        'numCols' => count($columns),
        'colStarts' => $col_starts,
        'isMultiCol' => (count($columns) >= 2 && !$is_bullet_line),
      ];
    }

    // Find table regions: runs of adjacent multi-column lines with
    // same column count and consistent column start positions.
    $tables = [];
    $current_table = NULL;
    $table_id = 0;

    foreach ($line_infos as $info) {
      if (!$info['isMultiCol']) {
        // Single-column line breaks any table run.
        if ($current_table !== NULL && count($current_table) >= $min_table_rows) {
          $tables[] = $this->finalizeTableRegion($current_table, $table_id++);
        }
        $current_table = NULL;
        continue;
      }

      if ($current_table === NULL) {
        $current_table = [$info];
        continue;
      }

      $prev = end($current_table);
      $compatible = ($info['numCols'] === $prev['numCols']);

      if ($compatible) {
        for ($c = 0; $c < $info['numCols']; $c++) {
          if (abs($info['colStarts'][$c] - $prev['colStarts'][$c]) > $col_start_tolerance) {
            $compatible = FALSE;
            break;
          }
        }
      }

      if ($compatible) {
        $current_table[] = $info;
      }
      else {
        if (count($current_table) >= $min_table_rows) {
          $tables[] = $this->finalizeTableRegion($current_table, $table_id++);
        }
        $current_table = [$info];
      }
    }
    if ($current_table !== NULL && count($current_table) >= $min_table_rows) {
      $tables[] = $this->finalizeTableRegion($current_table, $table_id++);
    }

    // Secondary pass: detect tables with smaller column gaps (50-100pt).
    // Uses much stricter requirements to avoid false positives:
    // - Minimum 3 columns per row (2-col splits are too common in text)
    // - At least 3 rows required
    // - Very tight column alignment (10pt tolerance)
    // - Column-start-to-column-start gaps must be >= 50pt
    // - Allows varying column counts if shared columns align (e.g., header
    //   has 3 cols but data rows have 4, with first 3 aligned)
    // - Excludes bullet lines and numbered list items
    if (empty($tables)) {
      $secondary_gap = 50;
      $secondary_tolerance = 10;
      $secondary_min_rows = 3;
      $secondary_min_cols = 3;

      // Collect Y values already in tables.
      $table_y_values = [];
      foreach ($tables as $tbl) {
        foreach ($tbl['yValues'] as $ty) {
          $table_y_values[$ty] = TRUE;
        }
      }

      // Re-classify lines with lower gap threshold.
      $secondary_infos = [];
      foreach ($y_lines as $y_line) {
        // Skip lines already in a table.
        if (isset($table_y_values[$y_line['y']])) {
          continue;
        }

        $segments = $y_line['segments'];
        usort($segments, fn($a, $b) => $a['x'] <=> $b['x']);

        // Find column groups with lower gap threshold.
        $columns = [[]];
        $last_x = NULL;
        foreach ($segments as $seg) {
          if ($last_x !== NULL && ($seg['x'] - $last_x) > $secondary_gap) {
            $columns[] = [];
          }
          $columns[count($columns) - 1][] = $seg;
          $last_x = $seg['x'];
        }

        $col_starts = array_map(fn($col) => round($col[0]['x']), $columns);

        // Check bullet exclusion.
        $first_text = trim($segments[0]['text'] ?? '');
        $is_bullet_line = FALSE;
        foreach ($bullet_chars_table as $bc) {
          if (str_starts_with($first_text, $bc)) {
            $is_bullet_line = TRUE;
            break;
          }
        }

        // Also exclude numbered list items (e.g., "1. ​ Start with...").
        $is_numbered = (bool) preg_match('/^\d+\./', $first_text);

        // Require at least secondary_min_cols columns and no bullet/number.
        $is_multi_col = (count($columns) >= $secondary_min_cols
          && !$is_bullet_line && !$is_numbered);

        $secondary_infos[] = [
          'y' => $y_line['y'],
          'numCols' => count($columns),
          'colStarts' => $col_starts,
          'isMultiCol' => $is_multi_col,
        ];
      }

      // Find table regions with flexible column count matching.
      $current_table = NULL;

      foreach ($secondary_infos as $info) {
        if (!$info['isMultiCol']) {
          if ($current_table !== NULL && count($current_table) >= $secondary_min_rows) {
            $tables[] = $this->finalizeTableRegionFlexible($current_table, $table_id++);
          }
          $current_table = NULL;
          continue;
        }

        if ($current_table === NULL) {
          $current_table = [$info];
          continue;
        }

        // Flexible compatibility: check if the shared (minimum) columns
        // align within tolerance.
        $prev = end($current_table);
        $min_cols = min($info['numCols'], $prev['numCols']);
        $compatible = ($min_cols >= $secondary_min_cols);

        if ($compatible) {
          for ($c = 0; $c < $min_cols; $c++) {
            if (abs($info['colStarts'][$c] - $prev['colStarts'][$c]) > $secondary_tolerance) {
              $compatible = FALSE;
              break;
            }
          }
        }

        if ($compatible) {
          $current_table[] = $info;
        }
        else {
          if (count($current_table) >= $secondary_min_rows) {
            $tables[] = $this->finalizeTableRegionFlexible($current_table, $table_id++);
          }
          $current_table = [$info];
        }
      }
      if ($current_table !== NULL && count($current_table) >= $secondary_min_rows) {
        $tables[] = $this->finalizeTableRegionFlexible($current_table, $table_id++);
      }
    }

    return $tables;
  }

  /**
   * Check if a line's Y-position falls within a table region.
   *
   * @param int $y_pos
   *   The Y-coordinate of the line.
   * @param array $table_regions
   *   Table regions from detectTableRegions().
   *
   * @return array|null
   *   The matching table region info, or NULL if not in a table.
   */
  public function findTableForLine(int $y_pos, array $table_regions): ?array {
    foreach ($table_regions as $region) {
      foreach ($region['yValues'] as $ty) {
        if (abs($ty - $y_pos) <= 2) {
          return $region;
        }
      }
    }
    return NULL;
  }

  /**
   * Build table cell texts from DataTm segments and column boundaries.
   *
   * Segments within each cell are joined with spaces to produce readable
   * text (individual DataTm segments often lack inter-word spacing).
   *
   * @param array $segments
   *   Segments from the line with 'x' and 'text' keys.
   * @param array $col_starts
   *   Column start X-positions.
   * @param int $num_cols
   *   Number of columns.
   *
   * @return array
   *   Array of cell text strings, one per column.
   */
  public function buildTableCellTexts(
    array $segments,
    array $col_starts,
    int $num_cols,
  ): array {
    // Sort segments by X position.
    usort($segments, fn($a, $b) => ($a['x'] ?? 0) <=> ($b['x'] ?? 0));

    // Group segments into clusters by X-gap. Use a gap threshold that
    // is half the minimum gap between column starts, to avoid splitting
    // within a column while still separating adjacent columns.
    $min_col_gap = PHP_FLOAT_MAX;
    for ($c = 1; $c < $num_cols; $c++) {
      $gap = $col_starts[$c] - $col_starts[$c - 1];
      if ($gap < $min_col_gap) {
        $min_col_gap = $gap;
      }
    }
    $cluster_gap = max(30, $min_col_gap * 0.4);

    $clusters = [[]];
    $last_x = NULL;
    foreach ($segments as $seg) {
      $x = $seg['x'] ?? 0;
      if ($last_x !== NULL && ($x - $last_x) > $cluster_gap) {
        $clusters[] = [];
      }
      $clusters[count($clusters) - 1][] = $seg;
      $last_x = $x;
    }

    // Assign each cluster to the nearest column based on the cluster's
    // first segment X position.
    $cell_segments = array_fill(0, $num_cols, []);

    foreach ($clusters as $cluster) {
      if (empty($cluster)) {
        continue;
      }
      $cluster_x = $cluster[0]['x'] ?? 0;

      // Find nearest column start.
      $best_col = 0;
      $best_dist = PHP_FLOAT_MAX;
      for ($c = 0; $c < $num_cols; $c++) {
        $dist = abs($cluster_x - $col_starts[$c]);
        if ($dist < $best_dist) {
          $best_dist = $dist;
          $best_col = $c;
        }
      }

      foreach ($cluster as $seg) {
        $cell_segments[$best_col][] = $seg;
      }
    }

    // Join segments using the isSpace flag from the DataTm whitespace
    // segments that buildFontLinesWithColorAndPosition() now preserves.
    // Whitespace-only segments represent actual word boundaries in the
    // PDF, so we simply insert a space when we encounter one and
    // concatenate non-space segments directly.
    $cells = [];
    foreach ($cell_segments as $seg_list) {
      if (empty($seg_list)) {
        $cells[] = '';
        continue;
      }

      $result = '';
      $pending_space = FALSE;
      foreach ($seg_list as $seg) {
        if (!empty($seg['isSpace'])) {
          // Mark that a space should be inserted before the next
          // non-space segment.
          $pending_space = TRUE;
          continue;
        }
        $text = $seg['text'] ?? '';
        if ($text === '') {
          continue;
        }
        if ($pending_space && $result !== '') {
          $result .= ' ';
        }
        $pending_space = FALSE;
        $result .= $text;
      }
      $cells[] = trim($result);
    }

    return $cells;
  }

  /**
   * Finalize a table region from a list of consistent multi-column lines.
   *
   * @param array $lines
   *   Array of line info records from the table detection loop.
   * @param int $table_id
   *   Unique table identifier.
   *
   * @return array
   *   Table region definition with tableId, yValues, numCols, colStarts.
   */
  protected function finalizeTableRegion(array $lines, int $table_id): array {
    // Average column start positions across all rows for best accuracy.
    $num_cols = $lines[0]['numCols'];
    $col_start_sums = array_fill(0, $num_cols, 0);
    foreach ($lines as $line) {
      for ($c = 0; $c < $num_cols; $c++) {
        $col_start_sums[$c] += $line['colStarts'][$c];
      }
    }
    $avg_starts = array_map(fn($sum) => round($sum / count($lines)), $col_start_sums);

    return [
      'tableId' => $table_id,
      'yValues' => array_map(fn($l) => $l['y'], $lines),
      'numCols' => $num_cols,
      'colStarts' => $avg_starts,
    ];
  }

  /**
   * Finalize a table region with flexible column counts.
   *
   * Unlike finalizeTableRegion(), this handles rows with varying column
   * counts (e.g., header with 3 columns but data rows with 4). Uses the
   * maximum column count across all rows and averages column positions
   * only from rows that have that column.
   *
   * @param array $lines
   *   Array of line info records from the table detection loop.
   * @param int $table_id
   *   Unique table identifier.
   *
   * @return array
   *   Table region definition with tableId, yValues, numCols, colStarts.
   */
  protected function finalizeTableRegionFlexible(array $lines, int $table_id): array {
    // Use the maximum column count across all rows.
    $max_cols = max(array_map(fn($l) => $l['numCols'], $lines));

    // Average column starts only from rows that have each column.
    $col_start_sums = array_fill(0, $max_cols, 0);
    $col_start_counts = array_fill(0, $max_cols, 0);
    foreach ($lines as $line) {
      for ($c = 0; $c < $line['numCols']; $c++) {
        $col_start_sums[$c] += $line['colStarts'][$c];
        $col_start_counts[$c]++;
      }
    }
    $avg_starts = [];
    for ($c = 0; $c < $max_cols; $c++) {
      $avg_starts[] = ($col_start_counts[$c] > 0)
        ? round($col_start_sums[$c] / $col_start_counts[$c])
        : 0;
    }

    return [
      'tableId' => $table_id,
      'yValues' => array_map(fn($l) => $l['y'], $lines),
      'numCols' => $max_cols,
      'colStarts' => $avg_starts,
    ];
  }

}
