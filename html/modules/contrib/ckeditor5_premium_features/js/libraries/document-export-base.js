/*
 * Copyright (c) 2003-2025, CKSource Holding sp. z o.o. All rights reserved.
 * For licensing, see https://ckeditor.com/legal/ckeditor-oss-license
 */

(function ($, Drupal) {
  Drupal.CKEditor5PremiumFeatures = {

    editorContentExportProcessor: async function(editor, config = { enableHighlighting : true }) {
      this.editor = editor;
      let editorContent = this.getEditorContent(config.enableHighlighting);
      editorContent = await Drupal.CKEditor5PremiumFeatures.mediaTagsConverter.convertMediaTags(
        editorContent,
        editor.sourceElement.dataset.editorActiveTextFormat
      );

      // Apply caption filter to process image captions
      if (config.pluginConfig.captionsFilterEnabled) {
        editorContent = await Drupal.CKEditor5PremiumFeatures.captionFilterConverter.applyCaptionFilter(
          editorContent
        );
      }

      if (config.pluginConfig.convertImagesToBase64.enabled) {
        editorContent = await Drupal.CKEditor5PremiumFeatures.base64ImageConverter.convert(
          editorContent,
          config.pluginConfig.convertImagesToBase64.filesType
        );
      }
      editorContent = Drupal.CKEditor5PremiumFeatures.relativePathsProcessor(editorContent);

      return editorContent;
    },

    getEditorContent(enableHighlighting = true) {
      return this.editor.getData( {
        showSuggestionHighlights: enableHighlighting,
      });
    },
  }
})(jQuery, Drupal);
