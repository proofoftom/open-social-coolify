/**
 * @file
 * JavaScript functionality for the private message notification block.
 */

Drupal.PrivateMessageNotificationBlock = {};

(($, Drupal, drupalSettings, window) => {
  let initialized;
  let notificationWrapper;
  let refreshRate;
  let checkingCount;

  /**
   * Trigger Ajax Commands.
   * @param {Object} data The data.
   */
  function triggerCommands(data) {
    const ajaxObject = Drupal.ajax({
      url: '',
      base: false,
      element: false,
      progress: false,
    });

    // Trigger any ajax commands in the response.
    ajaxObject.success(data, 'success');
  }

  function updateCount(unreadItemsCount) {
    notificationWrapper = document.querySelector(
      '.private-message-notification-wrapper',
    );

    if (unreadItemsCount) {
      notificationWrapper.classList.add('unread-threads');
    } else {
      notificationWrapper.classList.remove('unread-threads');
    }

    notificationWrapper.querySelector(
      '.private-message-page-link',
    ).textContent = unreadItemsCount;

    const pageTitle = document.querySelector('head title');

    // Check if there are any unread threads.
    if (unreadItemsCount) {
      // Check if the unread thread count is already in the page title.
      if (pageTitle.textContent.match(/^\(\d+\)\s/)) {
        // Update the unread thread count in the page title.
        pageTitle.textContent = pageTitle.textContent.replace(
          /^\(\d+\)\s/,
          `(${unreadItemsCount}) `,
        );
      } else {
        // Add the unread thread count to the URL.
        pageTitle.textContent = `(${unreadItemsCount}) ${pageTitle.textContent}`;
      }
    }
    // No unread messages. Check if thread count exists in the page title.
    else if (pageTitle.textContent.match(/^\(\d+\)\s/)) {
      // Remove the unread thread count from the page title.
      pageTitle.textContent = pageTitle.textContent.replace(/^\(\d+\)\s/, '');
    }
  }

  /**
   * Retrieve the new unread thread count from the server using AJAX.
   */
  function getUnreadItemsCount() {
    if (!checkingCount) {
      checkingCount = true;

      $.ajax({
        url: drupalSettings.privateMessageNotificationBlock
          .newMessageCountCallback,
        success(data) {
          triggerCommands(data);

          checkingCount = false;
          if (refreshRate) {
            window.setTimeout(getUnreadItemsCount, refreshRate);
          }
        },
      });
    }
  }

  Drupal.PrivateMessageNotificationBlock.getUnreadItemsCount = () => {
    getUnreadItemsCount();
  };

  /**
   * Initializes the script.
   */
  function init() {
    if (!initialized) {
      initialized = true;

      if (drupalSettings.privateMessageNotificationBlock.ajaxRefreshRate) {
        refreshRate =
          drupalSettings.privateMessageNotificationBlock.ajaxRefreshRate * 1000;
        if (refreshRate) {
          window.setTimeout(getUnreadItemsCount, refreshRate);
        }
      }
    }
  }

  Drupal.behaviors.privateMessageNotificationBlock = {
    attach() {
      init();

      Drupal.AjaxCommands.prototype.privateMessageUpdateUnreadItemsCount = (
        ajax,
        response,
      ) => {
        updateCount(response.unreadItemsCount);
      };
    },
  };
})(jQuery, Drupal, drupalSettings, window);
