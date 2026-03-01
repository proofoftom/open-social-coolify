<?php

namespace Drupal\ai_file_to_text\Extractor;

/**
 * Extracts text and HTML from ODT (OpenDocument Text) files.
 *
 * PHPWord's ODText reader does not preserve heading structure or font/style
 * information, so this class parses the ODT ZIP archive directly using
 * ZipArchive, DOMDocument, and DOMXPath to extract styled content.
 */
class OdtExtractor implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['odt'];
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
    $parsed = $this->parseOdt($file_path);
    if (!$parsed) {
      return '';
    }

    $xpath = $parsed['xpath'];
    $paragraph_styles = $parsed['paragraphStyles'];

    $body_nodes = $xpath->query('//office:body/office:text');
    if ($body_nodes->length === 0) {
      return '';
    }

    $body = $body_nodes->item(0);
    $text = $this->extractOdtTextFromNode($body, $xpath, $paragraph_styles);

    return trim($text);
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    $parsed = $this->parseOdt($file_path);
    if (!$parsed) {
      return '';
    }

    $xpath = $parsed['xpath'];
    $paragraph_styles = $parsed['paragraphStyles'];
    $text_styles = $parsed['textStyles'];

    $body_nodes = $xpath->query('//office:body/office:text');
    if ($body_nodes->length === 0) {
      return '';
    }

    $html = '';
    $body = $body_nodes->item(0);
    foreach ($body->childNodes as $child) {
      $html .= $this->buildOdtHtmlFromNode($child, $xpath, $paragraph_styles, $text_styles, $options);
    }

    return trim($html);
  }

  /**
   * Parse an ODT file and return its style maps and DOM document.
   *
   * @param string $file_path
   *   The real filesystem path to the ODT file.
   *
   * @return array|null
   *   An array with keys 'dom', 'xpath', 'paragraphStyles', 'textStyles',
   *   or NULL on failure.
   */
  protected function parseOdt(string $file_path): ?array {
    $zip = new \ZipArchive();
    if ($zip->open($file_path) !== TRUE) {
      return NULL;
    }

    // Build style maps from both styles.xml and content.xml.
    $paragraph_styles = [];
    $text_styles = [];

    // Parse styles.xml for named (document-level) styles.
    $styles_xml = $zip->getFromName('styles.xml');
    if ($styles_xml !== FALSE) {
      $styles_dom = new \DOMDocument();
      $styles_dom->loadXML($styles_xml);
      $styles_xpath = new \DOMXPath($styles_dom);
      $this->registerOdtNamespaces($styles_xpath);
      $this->collectOdtStyles($styles_xpath, $paragraph_styles, $text_styles);
    }

    // Parse content.xml.
    $content_xml = $zip->getFromName('content.xml');
    $zip->close();
    if ($content_xml === FALSE) {
      return NULL;
    }

    $dom = new \DOMDocument();
    $dom->loadXML($content_xml);
    $xpath = new \DOMXPath($dom);
    $this->registerOdtNamespaces($xpath);

    // Collect automatic styles from content.xml (these override/extend
    // the named styles from styles.xml).
    $this->collectOdtStyles($xpath, $paragraph_styles, $text_styles);

    return [
      'dom' => $dom,
      'xpath' => $xpath,
      'paragraphStyles' => $paragraph_styles,
      'textStyles' => $text_styles,
    ];
  }

  /**
   * Register ODT XML namespaces on an XPath object.
   *
   * @param \DOMXPath $xpath
   *   The XPath object to register namespaces on.
   */
  protected function registerOdtNamespaces(\DOMXPath $xpath): void {
    $namespaces = [
      'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
      'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
      'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
      'fo' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
      'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
      'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
      'svg' => 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0',
      'xlink' => 'http://www.w3.org/1999/xlink',
      'loext' => 'urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0',
    ];
    foreach ($namespaces as $prefix => $uri) {
      $xpath->registerNamespace($prefix, $uri);
    }
  }

  /**
   * Collect paragraph and text style definitions from an XPath context.
   *
   * @param \DOMXPath $xpath
   *   The XPath object with registered namespaces.
   * @param array &$paragraph_styles
   *   Paragraph styles map (keyed by style name).
   * @param array &$text_styles
   *   Text styles map (keyed by style name).
   */
  protected function collectOdtStyles(\DOMXPath $xpath, array &$paragraph_styles, array &$text_styles): void {
    // Collect paragraph styles.
    $style_nodes = $xpath->query('//style:style[@style:family="paragraph"]');
    foreach ($style_nodes as $node) {
      $name = $node->getAttribute('style:name');
      $parent = $node->getAttribute('style:parent-style-name');
      $info = ['parent' => $parent];

      // Get text properties from this style.
      $text_props = $xpath->query('style:text-properties', $node);
      if ($text_props->length > 0) {
        $tp = $text_props->item(0);
        $info['color'] = $tp->getAttribute('fo:color') ?: NULL;
        $info['fontSize'] = $tp->getAttribute('fo:font-size') ?: NULL;
        $info['fontWeight'] = $tp->getAttribute('fo:font-weight') ?: NULL;
        $info['fontStyle'] = $tp->getAttribute('fo:font-style') ?: NULL;
      }

      $paragraph_styles[$name] = $info;
    }

    // Collect text styles.
    $text_style_nodes = $xpath->query('//style:style[@style:family="text"]');
    foreach ($text_style_nodes as $node) {
      $name = $node->getAttribute('style:name');
      $info = [];
      $text_props = $xpath->query('style:text-properties', $node);
      if ($text_props->length > 0) {
        $tp = $text_props->item(0);
        $info['color'] = $tp->getAttribute('fo:color') ?: NULL;
        $info['fontSize'] = $tp->getAttribute('fo:font-size') ?: NULL;
        $info['fontWeight'] = $tp->getAttribute('fo:font-weight') ?: NULL;
        $info['fontStyle'] = $tp->getAttribute('fo:font-style') ?: NULL;
        $info['textPosition'] = $tp->getAttribute('style:text-position') ?: NULL;
        $info['underline'] = $tp->getAttribute('style:text-underline-style') ?: NULL;
      }
      $text_styles[$name] = $info;
    }
  }

  /**
   * Resolve the heading level for an ODT paragraph style.
   *
   * Walks the paragraph style parent chain to detect heading and title styles.
   *
   * @param string $style_name
   *   The paragraph style name (e.g. 'P8').
   * @param array $paragraph_styles
   *   The paragraph styles map.
   *
   * @return int
   *   The heading level (1-6), or 0 for non-heading paragraphs.
   */
  protected function resolveOdtHeadingLevel(string $style_name, array $paragraph_styles): int {
    $visited = [];
    $current = $style_name;
    while ($current && !isset($visited[$current])) {
      $visited[$current] = TRUE;
      // Check for heading pattern: "Heading_20_N" or "Heading N".
      if (preg_match('/Heading[_ ](?:20[_ ])?(\d+)/i', $current, $m)) {
        return min(6, max(1, (int) $m[1]));
      }
      // "Title" maps to heading level 1.
      if (strcasecmp($current, 'Title') === 0) {
        return 1;
      }
      // "Subtitle" maps to heading level 2.
      if (strcasecmp($current, 'Subtitle') === 0) {
        return 2;
      }
      // Walk up the parent chain.
      if (isset($paragraph_styles[$current]['parent'])) {
        $current = $paragraph_styles[$current]['parent'];
      }
      else {
        break;
      }
    }
    return 0;
  }

  /**
   * Resolve inherited text properties for an ODT paragraph style.
   *
   * Walks the parent chain and merges text properties.
   *
   * @param string $style_name
   *   The paragraph style name.
   * @param array $paragraph_styles
   *   The paragraph styles map.
   *
   * @return array
   *   Merged text properties.
   */
  protected function resolveOdtParagraphTextProps(string $style_name, array $paragraph_styles): array {
    $chain = [];
    $visited = [];
    $current = $style_name;
    while ($current && !isset($visited[$current])) {
      $visited[$current] = TRUE;
      if (isset($paragraph_styles[$current])) {
        $chain[] = $paragraph_styles[$current];
        $current = $paragraph_styles[$current]['parent'] ?? NULL;
      }
      else {
        break;
      }
    }

    // Merge from outermost (last) to innermost (first) so that more
    // specific styles override inherited ones.
    $merged = [];
    foreach (array_reverse($chain) as $props) {
      foreach (['color', 'fontSize', 'fontWeight', 'fontStyle'] as $key) {
        if (!empty($props[$key])) {
          $merged[$key] = $props[$key];
        }
      }
    }

    return $merged;
  }

  /**
   * Build a CSS inline style string from ODT text properties.
   *
   * @param array $props
   *   Text properties with keys: color, fontSize, fontWeight, fontStyle.
   *
   * @return string
   *   CSS inline style string.
   */
  protected function buildOdtInlineStyle(array $props): string {
    $styles = [];

    if (!empty($props['color']) && $props['color'] !== '#000000') {
      $styles[] = 'color: ' . $props['color'];
    }
    if (!empty($props['fontSize']) && $props['fontSize'] !== '16pt') {
      $styles[] = 'font-size: ' . $props['fontSize'];
    }

    return implode('; ', $styles);
  }

  /**
   * Recursively extract plain text from ODT DOM nodes.
   *
   * @param \DOMNode $node
   *   The DOM node to extract text from.
   * @param \DOMXPath $xpath
   *   The XPath object.
   * @param array $paragraph_styles
   *   The paragraph styles map.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractOdtTextFromNode(\DOMNode $node, \DOMXPath $xpath, array $paragraph_styles): string {
    $text = '';
    foreach ($node->childNodes as $child) {
      switch ($child->nodeName) {
        case 'text:p':
        case 'text:h':
          $text .= $child->textContent . "\n";
          break;

        case 'text:list':
          $items = $xpath->query('text:list-item/text:p', $child);
          foreach ($items as $item) {
            $text .= $item->textContent . "\n";
          }
          break;

        case 'table:table':
          $rows = $xpath->query('.//table:table-row', $child);
          foreach ($rows as $row) {
            $cells = $xpath->query('table:table-cell', $row);
            $cell_texts = [];
            foreach ($cells as $cell) {
              $cell_texts[] = trim($cell->textContent);
            }
            $text .= implode("\t", $cell_texts) . "\n";
          }
          break;
      }
    }
    return $text;
  }

  /**
   * Build HTML from an ODT DOM node.
   *
   * @param \DOMNode $node
   *   The DOM node.
   * @param \DOMXPath $xpath
   *   The XPath object.
   * @param array $paragraph_styles
   *   The paragraph styles map.
   * @param array $text_styles
   *   The text styles map.
   * @param array $options
   *   Extraction options:
   *   - native_headings: bool Use native <h1>…<h6> tags (default FALSE).
   *
   * @return string
   *   The generated HTML.
   */
  protected function buildOdtHtmlFromNode(\DOMNode $node, \DOMXPath $xpath, array $paragraph_styles, array $text_styles, array $options = []): string {
    $native_headings = !empty($options['native_headings']);
    $html = '';

    switch ($node->nodeName) {
      case 'text:h':
        // Explicit heading element with outline level.
        $depth = (int) ($node->getAttribute('text:outline-level') ?: 1);
        $level = max(1, min(6, $depth));
        if ($native_headings) {
          $tag = 'h' . $level;
        }
        else {
          $tag = $level === 1 ? 'p' : ('h' . $level);
        }
        $inner_html = $this->buildOdtInlineHtml($node, $text_styles);
        $html .= "<$tag class=\"h{$level}\">" . $inner_html . "</$tag>" . "\n";
        break;

      case 'text:p':
        $style_name = $node->getAttribute('text:style-name') ?: '';
        $heading_level = $style_name ? $this->resolveOdtHeadingLevel($style_name, $paragraph_styles) : 0;

        if ($heading_level > 0) {
          // This paragraph is styled as a heading.
          if ($native_headings) {
            $tag = 'h' . $heading_level;
          }
          else {
            $tag = $heading_level === 1 ? 'p' : ('h' . $heading_level);
          }
          $text_props = $this->resolveOdtParagraphTextProps($style_name, $paragraph_styles);
          $style = $this->buildOdtInlineStyle($text_props);
          $inner_html = $this->buildOdtInlineHtml($node, $text_styles);
          if ($inner_html === '') {
            break;
          }
          if ($style !== '') {
            $html .= "<$tag class=\"h{$heading_level}\" style=\"$style\">" . $inner_html . "</$tag>" . "\n";
          }
          else {
            $html .= "<$tag class=\"h{$heading_level}\">" . $inner_html . "</$tag>" . "\n";
          }
        }
        else {
          // Normal paragraph.
          $inner_html = $this->buildOdtInlineHtml($node, $text_styles);
          if ($inner_html === '') {
            break;
          }
          // Try to get a shared style for the paragraph.
          $style = $this->resolveOdtParagraphInlineStyle($node, $style_name, $paragraph_styles, $text_styles);
          if ($style !== '') {
            $html .= '<p style="' . $style . '">' . $inner_html . '</p>' . "\n";
          }
          else {
            $html .= '<p>' . $inner_html . '</p>' . "\n";
          }
        }
        break;

      case 'text:list':
        $html .= '<ul>' . "\n";
        $items = $xpath->query('text:list-item', $node);
        foreach ($items as $item) {
          $paragraphs = $xpath->query('text:p', $item);
          foreach ($paragraphs as $p) {
            $inner_html = $this->buildOdtInlineHtml($p, $text_styles);
            $html .= '<li>' . $inner_html . '</li>' . "\n";
          }
        }
        $html .= '</ul>' . "\n";
        break;

      case 'table:table':
        $html .= '<table>' . "\n";
        $rows = $xpath->query('.//table:table-row', $node);
        $is_first = TRUE;
        foreach ($rows as $row) {
          $html .= '<tr>';
          $cell_tag = $is_first ? 'th' : 'td';
          $cells = $xpath->query('table:table-cell', $row);
          foreach ($cells as $cell) {
            $cell_html = '';
            foreach ($cell->childNodes as $cell_child) {
              if ($cell_child->nodeName === 'text:p') {
                $cell_html .= $this->buildOdtInlineHtml($cell_child, $text_styles);
              }
            }
            $html .= "<$cell_tag>" . $cell_html . "</$cell_tag>";
          }
          $html .= '</tr>' . "\n";
          $is_first = FALSE;
        }
        $html .= '</table>' . "\n";
        break;
    }

    return $html;
  }

  /**
   * Build inline HTML from the children of an ODT text:p or text:h node.
   *
   * @param \DOMNode $node
   *   A text:p or text:h element.
   * @param array $text_styles
   *   The text styles map.
   *
   * @return string
   *   The inline HTML content.
   */
  protected function buildOdtInlineHtml(\DOMNode $node, array $text_styles): string {
    $html = '';
    foreach ($node->childNodes as $child) {
      switch ($child->nodeName) {
        case '#text':
          $html .= htmlspecialchars($child->textContent, ENT_QUOTES, 'UTF-8');
          break;

        case 'text:span':
          $style_name = $child->getAttribute('text:style-name') ?: '';
          $props = $text_styles[$style_name] ?? [];
          $text = htmlspecialchars($child->textContent, ENT_QUOTES, 'UTF-8');

          // Apply semantic inline formatting.
          if (!empty($props['fontWeight']) && $props['fontWeight'] === 'bold') {
            $text = '<strong>' . $text . '</strong>';
          }
          if (!empty($props['fontStyle']) && $props['fontStyle'] === 'italic') {
            $text = '<em>' . $text . '</em>';
          }
          if (!empty($props['underline']) && $props['underline'] !== 'none') {
            $text = '<u>' . $text . '</u>';
          }
          if (!empty($props['textPosition']) && str_contains($props['textPosition'], 'super')) {
            $text = '<sup>' . $text . '</sup>';
          }
          if (!empty($props['textPosition']) && str_contains($props['textPosition'], 'sub')) {
            $text = '<sub>' . $text . '</sub>';
          }

          $html .= $text;
          break;

        case 'text:line-break':
          $html .= '<br>';
          break;

        case 'text:tab':
          $html .= "\t";
          break;

        case 'text:s':
          // Multiple spaces.
          $count = (int) ($child->getAttribute('text:c') ?: 1);
          $html .= str_repeat(' ', $count);
          break;

        case 'text:a':
          // Hyperlink.
          $href = $child->getAttribute('xlink:href') ?: '';
          $link_text = htmlspecialchars($child->textContent, ENT_QUOTES, 'UTF-8');
          $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $link_text . '</a>';
          break;

        case 'text:bookmark':
        case 'text:bookmark-start':
        case 'text:bookmark-end':
        case 'text:soft-page-break':
        case 'draw:frame':
          // Skip these elements.
          break;

        default:
          // For unknown nodes, include their text content.
          if ($child->hasChildNodes()) {
            $html .= $this->buildOdtInlineHtml($child, $text_styles);
          }
          break;
      }
    }
    return $html;
  }

  /**
   * Determine an inline style for a normal ODT paragraph.
   *
   * If all text:span children use the same style, or the paragraph itself has
   * text properties, return that as a CSS style string.
   *
   * @param \DOMNode $node
   *   The text:p element.
   * @param string $style_name
   *   The paragraph style name.
   * @param array $paragraph_styles
   *   The paragraph styles map.
   * @param array $text_styles
   *   The text styles map.
   *
   * @return string
   *   CSS inline style string.
   */
  protected function resolveOdtParagraphInlineStyle(\DOMNode $node, string $style_name, array $paragraph_styles, array $text_styles): string {
    // First check if the paragraph style itself has text properties.
    if ($style_name) {
      $text_props = $this->resolveOdtParagraphTextProps($style_name, $paragraph_styles);
      $p_style = $this->buildOdtInlineStyle($text_props);
      if ($p_style !== '') {
        return $p_style;
      }
    }

    // Otherwise check if all text:span children share the same style.
    $shared_style = NULL;
    foreach ($node->childNodes as $child) {
      if ($child->nodeName === 'text:span') {
        $span_style_name = $child->getAttribute('text:style-name') ?: '';
        $props = $text_styles[$span_style_name] ?? [];
        $span_style = $this->buildOdtInlineStyle($props);
        if ($shared_style === NULL) {
          $shared_style = $span_style;
        }
        elseif ($shared_style !== $span_style) {
          return '';
        }
      }
      elseif ($child->nodeName === '#text' && trim($child->textContent) !== '') {
        // Bare text without a span — no shared style.
        if ($shared_style !== NULL && $shared_style !== '') {
          return '';
        }
      }
    }

    return $shared_style ?? '';
  }

}
