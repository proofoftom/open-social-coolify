/**
 * @file
 * Adds JavaScript functionality to the private message inbox block.
 */

Drupal.PrivateMessageInbox = {};
Drupal.PrivateMessageInbox.updateInbox = {};

(($, Drupal, drupalSettings, window, once) => {
  let container;
  let updateInterval;
  let loadingPrev;
  let loadingNew;

  /**
   * Used to manually trigger Drupal's JavaScript commands.
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

  /**
   * Updates the inbox after an Ajax call.
   */
  function updateInbox() {
    if (!loadingNew) {
      loadingNew = true;

      const ids = {};

      if (container.length > 0) {
        container[0]
          .querySelectorAll('.private-message-thread-inbox')
          .forEach((el) => {
            ids[el.dataset.threadId] = el.dataset.lastUpdate;
          });
      }

      $.ajax({
        url: drupalSettings.privateMessageInboxBlock.loadNewUrl,
        method: 'POST',
        data: { ids },
        success(data) {
          loadingNew = false;
          triggerCommands(data);
          if (updateInterval) {
            window.setTimeout(updateInbox, updateInterval);
          }
        },
      });
    }
  }

  /**
   * Reorders the inbox after an Ajax Load, to show newest threads first.
   * @param {Array} threadIds The threads IDs.
   * @param {Array} newThreads The new Threads.
   */
  function reorderInbox(threadIds, newThreads) {
    const map = {};

    container[0]
      .querySelectorAll(':scope > .private-message-thread-inbox')
      .forEach((el) => {
        map[el.dataset.threadId] = $(el);
      });

    threadIds.forEach((threadId) => {
      if (newThreads[threadId]) {
        if (map[threadId]) {
          map[threadId].remove();
        }

        $('<div/>').html(newThreads[threadId]).contents().appendTo(container);
      } else if (map[threadId]) {
        container.append(map[threadId]);
      }
    });

    Drupal.attachBehaviors(container[0]);
  }

  /**
   * Inserts older threads into the inbox after an Ajax load.
   * @param {string} threads The threads HTML.
   */
  function insertPreviousThreads(threads) {
    const contents = $('<div/>').html(threads).contents();

    contents.css('display', 'none').appendTo(container).slideDown(300);
    Drupal.attachBehaviors(contents[0]);
  }

  /**
   * Adds CSS classes to the currently selected thread.
   * @param {string} threadId The thread id.
   */
  function setActiveThread(threadId) {
    container.find('.active-thread:first').removeClass('active-thread');
    container
      .find(`.private-message-thread[data-thread-id="${threadId}"]:first`)
      .removeClass('unread-thread')
      .addClass('active-thread');
  }

  /**
   * Click handler for the button that loads older threads into the inbox.
   * @param {Object} e The event.
   */
  function loadOldThreadWatcherHandler(e) {
    e.preventDefault();

    if (!loadingPrev) {
      loadingPrev = true;

      let oldestTimestamp;
      container[0].querySelectorAll('.private-message-thread').forEach((el) => {
        const timestamp = parseInt(el.dataset.lastUpdate, 10);
        oldestTimestamp = !oldestTimestamp
          ? timestamp
          : Math.min(timestamp, oldestTimestamp);
      });

      $.ajax({
        url: drupalSettings.privateMessageInboxBlock.loadPrevUrl,
        data: {
          timestamp: oldestTimestamp,
          count: drupalSettings.privateMessageInboxBlock.threadCount,
        },
        success(data) {
          loadingPrev = false;
          triggerCommands(data);
        },
      });
    }
  }

  /**
   * Watches the button that loads previous threads into the inbox.
   * @param {Object} context The context.
   */
  function loadOlderThreadWatcher(context) {
    once(
      'load-older-threads-watcher',
      '#load-previous-threads-button',
      context,
    ).forEach((el) => {
      el.addEventListener('click', loadOldThreadWatcherHandler);
    });
  }

  /**
   * Click Handler executed when private message threads are clicked.
   *
   * Loads the thread into the private message window.
   * @param {Event} e The event.
   */
  const inboxThreadLinkListenerHandler = (e) => {
    if (Drupal.PrivateMessages) {
      e.preventDefault();
      const threadId = e.currentTarget.dataset.threadId;
      Drupal.PrivateMessages.loadThread(threadId);
      setActiveThread(threadId);
    }
  };

  /**
   * Watches private message threads for clicks, so new threads can be loaded.
   * @param {Object} context The context.
   */
  function inboxThreadLinkListener(context) {
    once(
      'inbox-thread-link-listener',
      '.private-message-inbox-thread-link',
      context,
    ).forEach((el) => {
      el.addEventListener('click', inboxThreadLinkListenerHandler);
    });
  }

  /**
   * Initializes the private message inbox JavaScript.
   */
  function init(context) {
    $(
      once(
        'init-inbox-block',
        '.block-private-message-inbox-block .private-message-thread--full-container',
        context,
      ),
    ).each(function () {
      container = $(this);

      const threadId = container
        .children('.private-message-thread:first')
        .attr('data-thread-id');
      setActiveThread(threadId);

      if (
        drupalSettings.privateMessageInboxBlock.totalThreads >
        drupalSettings.privateMessageInboxBlock.itemsToShow
      ) {
        $('<div/>', { id: 'load-previous-threads-button-wrapper' })
          .append(
            $('<a/>', { href: '#', id: 'load-previous-threads-button' }).text(
              Drupal.t('Load Previous'),
            ),
          )
          .insertAfter(container);
        loadOlderThreadWatcher(document);
      }
      updateInterval =
        drupalSettings.privateMessageInboxBlock.ajaxRefreshRate * 1000;
      if (updateInterval) {
        window.setTimeout(updateInbox, updateInterval);
      }
    });
  }

  Drupal.behaviors.privateMessageInboxBlock = {
    attach(context) {
      init(context);
      inboxThreadLinkListener(context);

      Drupal.AjaxCommands.prototype.insertInboxOldPrivateMessageThreads = (
        ajax,
        response,
      ) => {
        if (response.threads) {
          insertPreviousThreads(response.threads);
        }
        if (!response.threads || !response.hasNext) {
          $('#load-previous-threads-button')
            .parent()
            .slideUp(300, function () {
              $(this).remove();
            });
        }
      };

      Drupal.AjaxCommands.prototype.privateMessageInboxUpdate = (
        ajax,
        response,
      ) => reorderInbox(response.threadIds, response.newThreads);

      Drupal.AjaxCommands.prototype.privateMessageTriggerInboxUpdate = () =>
        updateInbox();

      if (Drupal.PrivateMessages) {
        Drupal.PrivateMessages.setActiveThread = (id) => setActiveThread(id);
      }

      Drupal.PrivateMessageInbox.updateInbox = () => updateInbox();
    },
    detach(context) {
      $(context)
        .find('#load-previous-threads-button')
        .unbind('click', loadOldThreadWatcherHandler);
      $(context)
        .find('.private-message-inbox-thread-link')
        .unbind('click', inboxThreadLinkListenerHandler);
    },
  };
})(jQuery, Drupal, drupalSettings, window, once);
