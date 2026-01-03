/**
 * @file
 * Like and dislike icons behavior.
 */
(function (Drupal, $, once) {

  'use strict';

  Drupal.behaviors.likeAndDislike = {
    attach: function(context, settings) {
      const $elements = $(once('like-and-dislike', '.vote-widget--like-and-dislike', context));
      function updateWidget(likeOrDislike, $elm) {
        let entity_id, entity_type;
        if (!$elm.hasClass('disable-status')) {
          entity_id = $elm.data('entity-id');
          entity_type = $elm.data('entity-type');
          likeAndDislikeService.vote(entity_id, entity_type, likeOrDislike);
        }
      }
      $elements.each(function () {
        var $widget = $(this);
        $widget.find('.vote-like, .vote-dislike').on({
          keyup: function(event) {
            if (event.which !== 13 && event.which !==32) return;
            updateWidget('like', $(this).find('a'));
          }
        });
        $widget.find('.vote-like a, .vote-dislike a').on({
          click: function() {
            updateWidget('like', $(this));
          },
        });
      });
    }
  };

})(Drupal, jQuery, once);
