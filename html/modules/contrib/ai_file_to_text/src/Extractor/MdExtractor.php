<?php

namespace Drupal\ai_file_to_text\Extractor;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Extracts text and HTML from Markdown (.md) files.
 *
 * Also provides a utility to convert HTML output from any extractor
 * into Markdown using league/html-to-markdown.
 */
class MdExtractor implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['md'];
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

    $content = file_get_contents($file_path);
    if ($content === FALSE) {
      return '';
    }

    // Strip common Markdown syntax to produce plain text.
    $text = $content;

    // Remove images: ![alt](url)
    $text = preg_replace('/!\[([^\]]*)\]\([^\)]+\)/', '$1', $text);
    // Convert links: [text](url) → text
    $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
    // Remove heading markers.
    $text = preg_replace('/^#{1,6}\s+/m', '', $text);
    // Remove bold/italic markers.
    $text = preg_replace('/(\*{1,3}|_{1,3})(.+?)\1/', '$2', $text);
    // Remove inline code.
    $text = preg_replace('/`([^`]+)`/', '$1', $text);
    // Remove code fence markers.
    $text = preg_replace('/^```[a-z]*$/m', '', $text);
    // Remove horizontal rules.
    $text = preg_replace('/^[-*_]{3,}$/m', '', $text);
    // Remove blockquote markers.
    $text = preg_replace('/^>\s?/m', '', $text);

    return trim($text);
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    if (!file_exists($file_path)) {
      return '';
    }

    $content = file_get_contents($file_path);
    if ($content === FALSE) {
      return '';
    }

    // Use league/commonmark with table extension if available.
    if (class_exists('\League\CommonMark\MarkdownConverter')) {
      // Pre-process: remove image references and definitions from
      // markdown before conversion to prevent base64 data URIs from
      // leaking into the HTML output.
      // Remove reference-style image definitions: [imageN]: data:image/...
      $content = preg_replace(
        '/^\[image\d+\]:\s*data:image\/[^\n]*/m',
        '',
        $content
      );
      // Remove image usages: ![alt][ref] and ![alt](url)
      $content = preg_replace('/!\[[^\]]*\]\[[^\]]*\]/', '', $content);
      $content = preg_replace('/!\[[^\]]*\]\([^\)]+\)/', '', $content);

      $environment = new Environment([
        'html_input' => 'strip',
        'allow_unsafe_links' => FALSE,
      ]);
      $environment->addExtension(new CommonMarkCoreExtension());
      $environment->addExtension(new TableExtension());

      $converter = new MarkdownConverter($environment);
      $html = trim($converter->convert($content)->getContent());

      // Post-process: strip image tags (we extract text, not media).
      // 1. Strip standalone image paragraphs: <p><img ...></p>
      $html = preg_replace('/<p>\s*<img[^>]*>\s*<\/p>/', '', $html);
      // 2. Strip remaining inline <img> tags.
      $html = preg_replace('/<img[^>]*>/', '', $html);
      // 3. Strip paragraphs containing image reference definitions
      //    (e.g. <p>[image1]: <a href="data:image/...">...</a></p>).
      $html = preg_replace(
        '/<p>\s*\[image\d+\]:\s*<a[^>]*>.*?<\/a>\s*<\/p>/s',
        '',
        $html
      );
      // 4. Strip <a> tags with data:image hrefs (any remaining).
      $html = preg_replace(
        '/<a\s+href="data:image[^"]*"[^>]*>.*?<\/a>/s',
        '',
        $html
      );
      // 5. Remove empty wrapper elements left after stripping.
      $html = preg_replace(
        '/<(p|strong|em|b|i|h[1-6])>\s*<\/\1>/',
        '',
        $html
      );

      // Post-process: convert <h1> to <p class="h1"> to match the
      // project convention (level 1 rendered as <p> with class),
      // unless native headings are enabled.
      if (empty($options['native_headings'])) {
        $html = preg_replace(
          '/<h1>(.*?)<\/h1>/s',
          '<p class="h1">$1</p>',
          $html
        );
      }

      return trim($html);
    }

    // Fallback: basic Markdown to HTML conversion.
    return trim($this->basicMarkdownToHtml($content, $options));
  }

  /**
   * Convert HTML content to Markdown.
   *
   * This is the output format conversion utility. Any extractor that
   * produces HTML can have its output converted to Markdown via this method.
   *
   * @param string $html
   *   The HTML content to convert.
   *
   * @return string
   *   The Markdown output.
   */
  public function htmlToMarkdown(string $html): string {
    if (trim($html) === '') {
      return '';
    }

    $converter = new HtmlConverter([
      'header_style' => 'atx',
      'strip_tags' => TRUE,
      'bold_style' => '**',
      'italic_style' => '*',
      'use_autolinks' => FALSE,
    ]);
    $converter->getEnvironment()->addConverter(new TableConverter());

    return trim($converter->convert($html));
  }

  /**
   * Basic Markdown to HTML conversion fallback.
   *
   * Handles headings, bold, italic, links, code blocks, and paragraphs.
   *
   * @param string $markdown
   *   The Markdown content.
   * @param array $options
   *   Extraction options:
   *   - native_headings: bool Use native <h1>…<h6> tags (default FALSE).
   *
   * @return string
   *   Basic HTML output.
   */
  protected function basicMarkdownToHtml(string $markdown, array $options = []): string {
    $native_headings = !empty($options['native_headings']);
    $html = '';
    $lines = explode("\n", $markdown);
    $in_code_block = FALSE;
    $in_list = FALSE;
    $paragraph = [];

    $flush_paragraph = function () use (&$html, &$paragraph) {
      if (!empty($paragraph)) {
        $text = implode('<br>', $paragraph);
        $html .= '<p>' . $text . '</p>' . "\n";
        $paragraph = [];
      }
    };

    foreach ($lines as $line) {
      // Code fences.
      if (preg_match('/^```/', $line)) {
        if ($in_code_block) {
          $html .= '</code></pre>' . "\n";
          $in_code_block = FALSE;
        }
        else {
          $flush_paragraph();
          $html .= '<pre><code>';
          $in_code_block = TRUE;
        }
        continue;
      }
      if ($in_code_block) {
        $html .= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n";
        continue;
      }

      $trimmed = trim($line);

      // Blank lines flush paragraphs.
      if ($trimmed === '') {
        $flush_paragraph();
        if ($in_list) {
          $html .= '</ul>' . "\n";
          $in_list = FALSE;
        }
        continue;
      }

      // Headings.
      if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
        $flush_paragraph();
        $level = strlen($m[1]);
        $text = $this->inlineMarkdown($m[2]);
        if ($native_headings) {
          $tag = 'h' . $level;
        }
        else {
          $tag = $level === 1 ? 'p' : ('h' . $level);
        }
        $html .= "<$tag class=\"h{$level}\">" . $text . "</$tag>" . "\n";
        continue;
      }

      // Unordered list items.
      if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $m)) {
        $flush_paragraph();
        if (!$in_list) {
          $html .= '<ul>' . "\n";
          $in_list = TRUE;
        }
        $html .= '<li>' . $this->inlineMarkdown($m[1]) . '</li>' . "\n";
        continue;
      }

      // Horizontal rules.
      if (preg_match('/^[-*_]{3,}$/', $trimmed)) {
        $flush_paragraph();
        $html .= '<hr>' . "\n";
        continue;
      }

      // Normal text — accumulate into paragraph.
      $paragraph[] = $this->inlineMarkdown($trimmed);
    }

    $flush_paragraph();
    if ($in_list) {
      $html .= '</ul>' . "\n";
    }

    return $html;
  }

  /**
   * Convert inline Markdown (bold, italic, links, code) to HTML.
   *
   * @param string $text
   *   The text with inline Markdown.
   *
   * @return string
   *   The text with inline HTML.
   */
  protected function inlineMarkdown(string $text): string {
    // Images: ![alt](url) — skip images (text extraction).
    $text = preg_replace('/!\[([^\]]*)\]\([^\)]+\)/', '$1', $text);
    // Links: [text](url)
    $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2">$1</a>', $text);
    // Bold: **text** or __text__
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
    // Italic: *text* or _text_
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);
    // Inline code: `code`
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    return $text;
  }

}
