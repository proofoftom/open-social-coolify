# AI File to Text

## Overview

This Drupal module provides AI automators and agent function calls to convert
document files to plain text, HTML, or Markdown. It supports a wide range of
file formats and follows the same pattern as the
[AI Simple PDF to Text](https://www.drupal.org/project/ai_simple_pdf_to_text)
module.

## Supported File Types

- **Word (.docx, .doc)**: Extracts text and styled HTML using the PHPWord
  library, including paragraphs, headings, lists, tables, links, bold, italic,
  underline, font size, and color.
- **ODT (.odt)**: Custom ZipArchive + DOMDocument parser that preserves heading
  structure, bold, italic, underline, font size, and color styling.
- **ODS (.ods)**: Custom ZipArchive + DOMDocument parser for spreadsheet files,
  rendering rows and columns as HTML tables.
- **PDF (.pdf)**: Extracts text and styled HTML using smalot/pdfparser with
  DataTm font analysis, color extraction from raw content streams, and automatic
  table detection via X-coordinate clustering.
- **CSV (.csv)**: Reads CSV files using native PHP and converts rows to
  tab-separated text or HTML tables.
- **TXT (.txt)**: Reads plain text files directly.
- **Markdown (.md)**: Converts Markdown to HTML using league/commonmark with
  table support. Strips embedded images and base64 data URIs.

## Output Formats

The module supports three output formats, configurable via the admin form:

- **text**: Plain text extraction (default).
- **html**: Styled HTML with headings, bold, italic, underline, font size,
  color, and tables.
- **markdown**: Converts the extracted HTML to Markdown using
  league/html-to-markdown.

## Requirements

- Drupal 10 or 11
- [AI module](https://www.drupal.org/project/ai) >= 1.1.0
- [PHPOffice/PHPWord](https://github.com/PHPOffice/PHPWord) >= 1.4
- [smalot/pdfparser](https://github.com/smalot/pdfparser) >= 2.12
- [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) >= 5.1

## Installation

```bash
composer require drupal/ai_file_to_text
drush en ai_file_to_text
```

## Usage

### AI Automators

This module provides automator plugins for `text_long` and `string_long` field
types. When configuring an AI automator:

1. Select a file field as the source field.
2. Choose "File to Text" as the automator type.
3. Select the desired output format (text, HTML, or Markdown).
4. Uploaded files will be automatically converted.

### AI Agents (Function Calls)

The module registers a `file_to_text` function call in the
`information_tools` group. AI agents can call this function with either:

- `file_id`: A Drupal file entity ID
- `file_location`: A file URI or path
- `output_format`: One of `text`, `html`, or `markdown` (optional, defaults to text)

## Related Projects

- [AI Simple PDF to Text](https://www.drupal.org/project/ai_simple_pdf_to_text)
- [AI module](https://www.drupal.org/project/ai)
