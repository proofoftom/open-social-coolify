<?php

namespace Drupal\ai_file_to_text\Extractor;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;

/**
 * Extracts text and HTML from Word documents using PHPWord.
 *
 * Handles .docx (Word2007) and .doc (MsDoc) formats.
 * ODT files are handled separately by OdtExtractor because PHPWord's
 * ODText reader does not preserve heading structure or font/style info.
 */
class WordExtractor implements ExtractorInterface {

  /**
   * Map file extensions to PHPWord reader names.
   */
  public const READER_MAP = [
    'docx' => 'Word2007',
    'doc' => 'MsDoc',
  ];

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['docx', 'doc'];
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
    $reader_name = self::READER_MAP[$extension] ?? 'Word2007';
    $php_word = IOFactory::load($file_path, $reader_name);
    $text = '';

    foreach ($php_word->getSections() as $section) {
      $text .= $this->extractTextFromElements($section->getElements());
    }

    return trim($text);
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    $reader_name = self::READER_MAP[$extension] ?? 'Word2007';
    $php_word = IOFactory::load($file_path, $reader_name);

    $html = '';
    foreach ($php_word->getSections() as $section) {
      $html .= $this->buildHtmlFromElements($section->getElements(), $options);
    }

    return trim($html);
  }

  /**
   * Recursively extract text from PHPWord elements.
   *
   * @param array $elements
   *   An array of PHPWord elements.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractTextFromElements(array $elements): string {
    $text = '';

    foreach ($elements as $element) {

      if ($element instanceof Text) {
        $content = $element->getText();
        $text .= $content;
      }
      elseif ($element instanceof Title) {
        $text_content = $element->getText();
        if (is_string($text_content)) {
          $text .= $text_content . "\n";
        }
        elseif ($text_content instanceof AbstractContainer) {
          $text .= $this->extractTextFromElements($text_content->getElements()) . "\n";
        }
      }
      elseif ($element instanceof Link) {
        $text .= $element->getText();
      }
      elseif ($element instanceof ListItem) {
        $text .= $element->getText() . "\n";
      }
      elseif ($element instanceof Table) {
        foreach ($element->getRows() as $row) {
          $cell_texts = [];
          foreach ($row->getCells() as $cell) {
            $cell_texts[] = trim($this->extractTextFromElements($cell->getElements()));
          }
          $text .= implode("\t", $cell_texts) . "\n";
        }
      }
      elseif ($element instanceof TextBreak) {
        $text .= "\n";
      }
      elseif ($element instanceof AbstractContainer) {
        $text .= $this->extractTextFromElements($element->getElements());
      }

      if ($element instanceof AbstractContainer && !($element instanceof Table)) {
        $text .= "\n";
      }
    }

    return $text;
  }

  /**
   * Recursively build HTML from PHPWord elements.
   *
   * @param array $elements
   *   An array of PHPWord elements.
   * @param array $options
   *   Extraction options:
   *   - native_headings: bool Use native <h1>…<h6> tags (default FALSE).
   *
   * @return string
   *   The generated HTML.
   */
  protected function buildHtmlFromElements(array $elements, array $options = []): string {
    $native_headings = !empty($options['native_headings']);
    $html = '';
    $in_list = FALSE;

    foreach ($elements as $element) {
      $is_list_element = ($element instanceof ListItem)
        || $element instanceof ListItemRun;

      if ($in_list && !$is_list_element) {
        $html .= '</ul>' . "\n";
        $in_list = FALSE;
      }

      if ($element instanceof Title) {
        $depth = $element->getDepth();
        $level = max(1, min(6, ($depth <= 1 ? 1 : $depth)));
        if ($native_headings) {
          $tag = 'h' . $level;
        }
        else {
          $tag = $level === 1 ? 'p' : ('h' . $level);
        }
        $inner_html = $this->buildInlineHtml($element->getText());
        $heading_style = $this->getHeadingInlineStyle($element->getStyle());
        if ($heading_style !== '') {
          $html .= "<$tag class=\"h{$level}\" style=\"$heading_style\">" . $inner_html . "</$tag>" . "\n";
        }
        else {
          $html .= "<$tag class=\"h{$level}\">" . $inner_html . "</$tag>" . "\n";
        }
      }
      elseif ($element instanceof ListItem) {
        if (!$in_list) {
          $html .= '<ul>' . "\n";
          $in_list = TRUE;
        }
        $html .= '<li>' . htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8') . '</li>' . "\n";
      }
      elseif ($element instanceof ListItemRun) {
        if (!$in_list) {
          $html .= '<ul>' . "\n";
          $in_list = TRUE;
        }
        $html .= '<li>' . $this->buildInlineHtml($element) . '</li>' . "\n";
      }
      elseif ($element instanceof Table) {
        $html .= '<table>' . "\n";
        $is_first_row = TRUE;
        foreach ($element->getRows() as $row) {
          $html .= '<tr>';
          $cell_tag = $is_first_row ? 'th' : 'td';
          foreach ($row->getCells() as $cell) {
            $cell_html = $this->buildHtmlFromElements($cell->getElements(), $options);
            $cell_html = preg_replace('/^<p>(.*)<\/p>$/s', '$1', trim($cell_html));
            $html .= "<$cell_tag>" . $cell_html . "</$cell_tag>";
          }
          $html .= '</tr>' . "\n";
          $is_first_row = FALSE;
        }
        $html .= '</table>' . "\n";
      }
      elseif ($element instanceof TextBreak) {
        $html .= '<br>' . "\n";
      }
      elseif ($element instanceof Image) {
        // Skip images - text extraction only.
      }
      elseif ($element instanceof Link) {
        $url = htmlspecialchars($element->getSource(), ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8');
        $html .= '<p><a href="' . $url . '">' . $text . '</a></p>' . "\n";
      }
      elseif ($element instanceof AbstractContainer) {
        $inner_html = $this->buildInlineHtml($element);
        if (trim($inner_html) !== '') {
          $paragraph_style = $this->getContainerInlineStyle($element);
          if ($paragraph_style !== '') {
            $html .= '<p style="' . $paragraph_style . '">' . $inner_html . '</p>' . "\n";
          }
          else {
            $html .= '<p>' . $inner_html . '</p>' . "\n";
          }
        }
      }
      elseif ($element instanceof Text) {
        $raw_text = $element->getText();
        $text_style = $this->buildFontInlineStyle($element->getFontStyle());
        $text_html = htmlspecialchars($raw_text, ENT_QUOTES, 'UTF-8');
        if ($text_style !== '') {
          $html .= '<p style="' . $text_style . '">' . $text_html . '</p>' . "\n";
        }
        else {
          $html .= '<p>' . $text_html . '</p>' . "\n";
        }
      }
    }

    if ($in_list) {
      $html .= '</ul>' . "\n";
    }

    return $html;
  }

  /**
   * Build inline HTML from a Title text or AbstractContainer.
   *
   * @param mixed $content
   *   A string, AbstractContainer (TextRun), or other element.
   *
   * @return string
   *   The inline HTML.
   */
  protected function buildInlineHtml(mixed $content): string {
    if (is_string($content)) {
      return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    }

    if (!$content instanceof AbstractContainer) {
      return '';
    }

    $html = '';
    foreach ($content->getElements() as $child) {
      if ($child instanceof Text) {
        $raw_text = $child->getText();
        $text = htmlspecialchars($raw_text, ENT_QUOTES, 'UTF-8');
        $font = $child->getFontStyle();
        if ($font && is_object($font)) {
          if ($font->isBold()) {
            $text = '<strong>' . $text . '</strong>';
          }
          if ($font->isItalic()) {
            $text = '<em>' . $text . '</em>';
          }
          if ($font->getUnderline() && $font->getUnderline() !== 'none') {
            $text = '<u>' . $text . '</u>';
          }
          if ($font->isSuperScript()) {
            $text = '<sup>' . $text . '</sup>';
          }
          if ($font->isSubScript()) {
            $text = '<sub>' . $text . '</sub>';
          }
        }
        $html .= $text;
      }
      elseif ($child instanceof Link) {
        $url = htmlspecialchars($child->getSource(), ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($child->getText(), ENT_QUOTES, 'UTF-8');
        $html .= '<a href="' . $url . '">' . $text . '</a>';
      }
      elseif ($child instanceof TextBreak) {
        $html .= '<br>';
      }
      elseif ($child instanceof AbstractContainer) {
        $html .= $this->buildInlineHtml($child);
      }
    }

    return $html;
  }

  /**
   * Determine a shared inline style for a paragraph container.
   *
   * @param \PhpOffice\PhpWord\Element\AbstractContainer $container
   *   A container (e.g., TextRun).
   *
   * @return string
   *   Inline style string or empty string if mixed.
   */
  protected function getContainerInlineStyle(AbstractContainer $container): string {
    $style = NULL;

    foreach ($container->getElements() as $child) {
      if ($child instanceof Text) {
        $child_style = $this->buildFontInlineStyle($child->getFontStyle());
      }
      elseif ($child instanceof AbstractContainer) {
        $child_style = $this->getContainerInlineStyle($child);
      }
      else {
        continue;
      }

      if ($child_style === '') {
        if ($style !== NULL) {
          return '';
        }
        continue;
      }

      if ($style === NULL) {
        $style = $child_style;
      }
      elseif ($style !== $child_style) {
        return '';
      }
    }

    return $style ?? '';
  }

  /**
   * Build a CSS inline style string from a PHPWord Font object.
   *
   * @param object|null $font
   *   A PHPWord Font style object.
   *
   * @return string
   *   CSS property declarations.
   */
  public function buildFontInlineStyle(?object $font): string {
    if (!$font) {
      return '';
    }

    $styles = [];

    $color = $font->getColor();
    $color_value = NULL;
    if ($color && is_object($color) && method_exists($color, 'getValue')) {
      $color_value = $color->getValue();
    }
    elseif (is_string($color)) {
      $color_value = $color;
    }
    if ($color_value && $color_value !== '000000') {
      $styles[] = 'color: #' . $color_value;
    }

    $size = $font->getSize();
    if ($size && $size != 16) {
      $styles[] = 'font-size: ' . $size . 'pt';
    }

    return implode('; ', $styles);
  }

  /**
   * Get inline CSS style from a document-level named heading style.
   *
   * @param string|null $style_name
   *   The style name from Title::getStyle().
   *
   * @return string
   *   CSS inline style string, or empty string if no style found.
   */
  protected function getHeadingInlineStyle(?string $style_name): string {
    if (empty($style_name)) {
      return '';
    }

    $lookup_name = preg_replace('/^Heading(\d)$/', 'Heading_$1', $style_name);
    $style = Style::getStyle($lookup_name);
    if (!$style) {
      $style = Style::getStyle($style_name);
    }

    if (!$style || !($style instanceof Font)) {
      return '';
    }

    return $this->buildFontInlineStyle($style);
  }

}
