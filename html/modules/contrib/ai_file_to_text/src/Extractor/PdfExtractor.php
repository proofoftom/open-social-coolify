<?php

namespace Drupal\ai_file_to_text\Extractor;

use Drupal\ai_file_to_text\Pdf\PdfToHtml;

/**
 * Extracts text and HTML from PDF files.
 *
 * Thin wrapper around the PdfToHtml engine that implements the
 * ExtractorInterface for integration with the file extractor manager.
 *
 * The actual conversion logic lives in the Pdf\ namespace classes:
 * - PdfToHtml: Core orchestration pipeline.
 * - TableDetector: Table region detection from DataTm positioning.
 * - LinkExtractor: Link annotation extraction and matching.
 * - StyleAnalyzer: Font size, color, heading, and style detection.
 * - HtmlRenderer: Semantic HTML rendering (headings, lists, tables).
 *
 * @see \Drupal\ai_file_to_text\Pdf\PdfToHtml
 */
class PdfExtractor implements ExtractorInterface {

  /**
   * The PDF-to-HTML conversion parser.
   *
   * @var \Drupal\ai_file_to_text\Pdf\PdfToHtml
   */
  protected PdfToHtml $parser;

  /**
   * Constructs a PdfExtractor.
   */
  public function __construct() {
    $this->parser = new PdfToHtml();
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedExtensions(): array {
    return ['pdf'];
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
    return $this->parser->extractText($file_path);
  }

  /**
   * {@inheritdoc}
   */
  public function extractHtml(string $file_path, string $extension = '', array $options = []): string {
    return $this->parser->extractHtml($file_path, $options);
  }

}
