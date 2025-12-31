/**
 * @file
 * WaaP login and logout handlers for Drupal WaaP Login module.
 *
 * Handles WaaP authentication flow including login button clicks,
 * multi-step authentication, and logout functionality.
 */

(function (Drupal, drupalSettings, $) {
  "use strict";

  /**
   * Debounce utility function to prevent rapid repeated clicks.
   *
   * @param {Function} func
   *   The function to debounce.
   * @param {number} wait
   *   The debounce wait time in milliseconds.
   *
   * @returns {Function}
   *   The debounced function.
   */
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  /**
   * WaaP login button behavior.
   *
   * Handles click events on WaaP login buttons, initiates WaaP SDK
   * authentication, and sends authentication data to Drupal backend.
   *
   * @namespace
   * @property {function} attach
   *   Drupal behavior attach function.
   */
  Drupal.behaviors.waapLogin = {
    /**
     * Attach behavior to context.
     *
     * @param {HTMLElement} context
     *   The context element.
     * @param {Object} settings
     *   The Drupal settings object.
     */
    attach: function (context, settings) {
      $(".waap-login-button", context)
        .once("waap-login")
        .on(
          "click",
          debounce(
            async function (e) {
              e.preventDefault();
              await this.handleLogin($(this));
            }.bind(this),
            300
          )
        );
    },

    /**
     * Handle WaaP login flow.
     *
     * @param {jQuery} $button
     *   The login button element.
     */
    handleLogin: async function ($button) {
      const originalText = $button.text();
      const config = drupalSettings.waap_login || {};

      try {
        // Check if WaaP SDK is available
        if (!window.waap) {
          throw new Error(
            Drupal.t("WaaP SDK not initialized. Please refresh the page.")
          );
        }

        // Set loading state
        this.setButtonLoading($button, Drupal.t("Connecting..."));

        // Authenticate with WaaP
        const authResult = await this.authenticateWithWaaP();

        if (!authResult) {
          // User cancelled authentication
          this.resetButton($button, originalText);
          return;
        }

        // Get wallet address
        const address = await this.getWalletAddress();
        if (!address) {
          throw new Error(Drupal.t("No wallet address found."));
        }

        // Send authentication data to backend
        const response = await this.sendToBackend({
          address: address,
          loginType: authResult.loginType,
          loginValue: authResult.loginValue,
          sessionData: authResult.session,
          csrf_token: config.csrf_token || this.getCsrfToken(),
        });

        // Handle response
        await this.handleAuthResponse(response, $button, originalText);
      } catch (error) {
        console.error("WaaP login error:", error);
        this.showError(error.message);
        this.resetButton($button, originalText);
      }
    },

    /**
     * Authenticate with WaaP SDK.
     *
     * @returns {Promise<Object|null>}
     *   Authentication result or null if cancelled.
     */
    authenticateWithWaaP: async function () {
      try {
        // Open WaaP authentication modal
        const loginType = await window.waap.login();

        if (!loginType) {
          // User cancelled or closed modal
          return null;
        }

        // Get login method details
        const loginMethod = window.waap.getLoginMethod
          ? window.waap.getLoginMethod()
          : loginType;

        // Get session data if available
        let sessionData = null;
        if (window.waap.getSession) {
          sessionData = await window.waap.getSession();
        }

        return {
          loginType: loginType,
          loginValue: loginMethod,
          session: sessionData,
        };
      } catch (error) {
        console.error("WaaP authentication error:", error);
        throw new Error(Drupal.t("Authentication failed. Please try again."));
      }
    },

    /**
     * Get current wallet address from WaaP.
     *
     * @returns {Promise<string|null>}
     *   The wallet address or null.
     */
    getWalletAddress: async function () {
      try {
        const accounts = await window.waap.request({
          method: "eth_requestAccounts",
        });

        if (!accounts || accounts.length === 0) {
          return null;
        }

        return accounts[0];
      } catch (error) {
        console.error("Failed to get wallet address:", error);
        throw new Error(Drupal.t("Failed to retrieve wallet address."));
      }
    },

    /**
     * Send authentication data to Drupal backend.
     *
     * @param {Object} data
     *   Authentication data to send.
     *
     * @returns {Promise<Object>}
     *   Backend response.
     */
    sendToBackend: async function (data) {
      const csrfToken = data.csrf_token || this.getCsrfToken();

      const response = await fetch("/waap/verify", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken,
        },
        body: JSON.stringify({
          address: data.address,
          loginType: data.loginType,
          loginValue: data.loginValue,
          sessionData: data.sessionData,
          csrf_token: csrfToken,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.error || Drupal.t("Authentication request failed.")
        );
      }

      return await response.json();
    },

    /**
     * Handle authentication response from backend.
     *
     * @param {Object} response
     *   Backend response object.
     * @param {jQuery} $button
     *   The login button element.
     * @param {string} originalText
     *   Original button text.
     */
    handleAuthResponse: async function (response, $button, originalText) {
      if (response.success) {
        if (response.redirect) {
          // Redirect for multi-step auth (email verification, username creation)
          window.location.href = response.redirect;
        } else {
          // Authentication complete, reload page
          window.location.reload();
        }
      } else {
        throw new Error(response.error || Drupal.t("Authentication failed."));
      }
    },

    /**
     * Set button to loading state.
     *
     * @param {jQuery} $button
     *   The button element.
     * @param {string} text
     *   Loading text.
     */
    setButtonLoading: function ($button, text) {
      $button.prop("disabled", true).addClass("is-loading").text(text);
    },

    /**
     * Reset button to original state.
     *
     * @param {jQuery} $button
     *   The button element.
     * @param {string} text
     *   Original button text.
     */
    resetButton: function ($button, text) {
      $button.prop("disabled", false).removeClass("is-loading").text(text);
    },

    /**
     * Get CSRF token from meta tag or Drupal settings.
     *
     * @returns {string}
     *   CSRF token.
     */
    getCsrfToken: function () {
      // Try meta tag first
      const metaToken = document.querySelector('meta[name="csrf-token"]');
      if (metaToken) {
        return metaToken.getAttribute("content");
      }

      // Try drupalSettings
      if (drupalSettings.waap_login && drupalSettings.waap_login.csrf_token) {
        return drupalSettings.waap_login.csrf_token;
      }

      // Try Drupal.ajax (legacy)
      if (Drupal.ajax && Drupal.ajax.instances) {
        const ajaxInstance =
          Drupal.ajax.instances[Object.keys(Drupal.ajax.instances)[0]];
        if (
          ajaxInstance &&
          ajaxInstance.options &&
          ajaxInstance.options.token
        ) {
          return ajaxInstance.options.token;
        }
      }

      return "";
    },

    /**
     * Display error message to user.
     *
     * @param {string} message
     *   Error message to display.
     */
    showError: function (message) {
      Drupal.waapUtils && Drupal.waapUtils.showMessage
        ? Drupal.waapUtils.showMessage(message, "error")
        : alert(message);
    },
  };

  /**
   * WaaP logout button behavior.
   *
   * Handles click events on WaaP logout buttons, logs out from both
   * WaaP SDK and Drupal backend.
   *
   * @namespace
   * @property {function} attach
   *   Drupal behavior attach function.
   */
  Drupal.behaviors.waapLogout = {
    /**
     * Attach behavior to context.
     *
     * @param {HTMLElement} context
     *   The context element.
     * @param {Object} settings
     *   The Drupal settings object.
     */
    attach: function (context, settings) {
      $(".waap-logout-button", context)
        .once("waap-logout")
        .on(
          "click",
          debounce(
            async function (e) {
              e.preventDefault();
              await this.handleLogout($(this));
            }.bind(this),
            300
          )
        );
    },

    /**
     * Handle WaaP logout flow.
     *
     * @param {jQuery} $button
     *   The logout button element.
     */
    handleLogout: async function ($button) {
      const originalText = $button.text();

      try {
        // Set loading state
        $button
          .prop("disabled", true)
          .addClass("is-loading")
          .text(Drupal.t("Disconnecting..."));

        // Logout from WaaP SDK if available
        if (window.waap && window.waap.logout) {
          await window.waap.logout();
        }

        // Logout from Drupal backend
        await this.logoutFromDrupal();

        // Redirect to home
        window.location.href = "/";
      } catch (error) {
        console.error("WaaP logout error:", error);
        // Even on error, try to redirect to logout page
        window.location.href = "/user/logout";
      }
    },

    /**
     * Logout from Drupal backend.
     *
     * @returns {Promise<void>}
     */
    logoutFromDrupal: async function () {
      const csrfToken = Drupal.behaviors.waapLogin.getCsrfToken();

      const response = await fetch("/waap/logout", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken,
        },
      });

      if (!response.ok) {
        throw new Error(Drupal.t("Logout request failed."));
      }

      return await response.json();
    },
  };

  /**
   * Listen for WaaP ready event.
   *
   * Enables login buttons once WaaP SDK is initialized.
   */
  window.addEventListener("waapReady", function (event) {
    $(".waap-login-button").prop("disabled", false);
  });

  /**
   * Listen for WaaP error event.
   *
   * Disables login buttons and shows error if SDK fails to initialize.
   */
  window.addEventListener("waapError", function (event) {
    console.error("WaaP initialization error:", event.detail.error);
    $(".waap-login-button").prop("disabled", true);
    if (Drupal.waapUtils && Drupal.waapUtils.showMessage) {
      Drupal.waapUtils.showMessage(
        Drupal.t(
          "WaaP authentication is currently unavailable. Please try again later."
        ),
        "error"
      );
    }
  });
})(Drupal, drupalSettings, jQuery);
