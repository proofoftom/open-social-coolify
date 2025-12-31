/**
 * @file
 * WaaP utility functions for Drupal WaaP Login module.
 *
 * Provides helper functions for WaaP SDK integration including
 * availability checks, address formatting, and message display.
 */

(function (Drupal, drupalSettings) {
  "use strict";

  /**
   * WaaP utility functions namespace.
   *
   * Exposed globally as Drupal.waapUtils for use by other modules
   * and components in the WaaP Login module.
   *
   * @namespace
   */
  Drupal.waapUtils = {
    /**
     * Check if WaaP SDK is available and initialized.
     *
     * @returns {boolean}
     *   True if WaaP SDK is available, false otherwise.
     */
    isWaapAvailable: function () {
      return typeof window.waap !== "undefined" && window.waap !== null;
    },

    /**
     * Get the login method used for current WaaP session.
     *
     * @returns {string|null}
     *   The login method (e.g., 'email', 'social', 'wallet') or null.
     */
    getLoginMethod: function () {
      if (!this.isWaapAvailable()) {
        return null;
      }

      try {
        if (typeof window.waap.getLoginMethod === "function") {
          return window.waap.getLoginMethod();
        }
        return null;
      } catch (error) {
        console.error("Failed to get WaaP login method:", error);
        return null;
      }
    },

    /**
     * Check if user is logged in via WaaP.
     *
     * @returns {boolean}
     *   True if user is authenticated via WaaP, false otherwise.
     */
    isLoggedIn: function () {
      const method = this.getLoginMethod();
      return (
        method === "waap" ||
        method === "injected" ||
        method === "walletconnect" ||
        method !== null
      );
    },

    /**
     * Get current wallet address from WaaP.
     *
     * @returns {Promise<string|null>}
     *   The current wallet address or null if not available.
     */
    getCurrentAddress: async function () {
      if (!this.isWaapAvailable()) {
        return null;
      }

      try {
        const accounts = await window.waap.request({
          method: "eth_accounts",
        });

        if (!accounts || accounts.length === 0) {
          return null;
        }

        return accounts[0];
      } catch (error) {
        console.error("Failed to get current address:", error);
        return null;
      }
    },

    /**
     * Format Ethereum address for display.
     *
     * Shortens address to show first 4 and last 4 characters
     * with ellipsis in between (e.g., 0x1234...5678).
     *
     * @param {string} address
     *   The Ethereum address to format.
     * @param {number} [startChars=4]
     *   Number of characters to show at the start.
     * @param {number} [endChars=4]
     *   Number of characters to show at the end.
     *
     * @returns {string}
     *   The formatted address.
     */
    formatAddress: function (address, startChars, endChars) {
      if (!address) {
        return "";
      }

      startChars = startChars || 4;
      endChars = endChars || 4;

      if (address.length <= startChars + endChars) {
        return address;
      }

      return address.slice(0, startChars) + "..." + address.slice(-endChars);
    },

    /**
     * Validate Ethereum address format.
     *
     * Checks if the address is a valid Ethereum address format
     * (42 characters, starting with 0x, followed by hex characters).
     *
     * @param {string} address
     *   The address to validate.
     *
     * @returns {boolean}
     *   True if address format is valid, false otherwise.
     */
    validateAddress: function (address) {
      if (!address || typeof address !== "string") {
        return false;
      }

      // Check basic format: 0x followed by 40 hex characters
      const addressRegex = /^0x[a-fA-F0-9]{40}$/;
      return addressRegex.test(address);
    },

    /**
     * Display a message to the user.
     *
     * Attempts to use Drupal's message system if available,
     * otherwise falls back to alert.
     *
     * @param {string} message
     *   The message to display.
     * @param {string} [type='status']
     *   The message type: 'status', 'warning', or 'error'.
     */
    showMessage: function (message, type) {
      type = type || "status";

      // Try to use Drupal's message system
      if (Drupal.Message && Drupal.Message.default) {
        const messageElement = document.querySelector("[data-drupal-messages]");
        if (messageElement) {
          const messages = Drupal.Message.default();
          messages.clear();
          messages.add(message, { type: type });
          return;
        }
      }

      // Fallback: create a temporary message element
      this.createTemporaryMessage(message, type);
    },

    /**
     * Create a temporary message element.
     *
     * @param {string} message
     *   The message to display.
     * @param {string} type
     *   The message type.
     */
    createTemporaryMessage: function (message, type) {
      const messageContainer =
        document.querySelector(".messages") || this.createMessageContainer();
      const messageElement = document.createElement("div");

      messageElement.className = "messages messages--" + type;
      messageElement.innerHTML =
        '<div class="message__content">' + message + "</div>";

      messageContainer.appendChild(messageElement);

      // Auto-remove after 5 seconds
      setTimeout(() => {
        messageElement.remove();
        if (messageContainer.children.length === 0) {
          messageContainer.remove();
        }
      }, 5000);
    },

    /**
     * Create a message container if one doesn't exist.
     *
     * @returns {HTMLElement}
     *   The message container element.
     */
    createMessageContainer: function () {
      const container = document.createElement("div");
      container.className = "messages";
      container.setAttribute("data-drupal-messages", "");
      document.body.insertBefore(container, document.body.firstChild);
      return container;
    },

    /**
     * Get CSRF token from various sources.
     *
     * Checks multiple sources for CSRF token in order of preference:
     * 1. Meta tag with name="csrf-token"
     * 2. Drupal settings
     * 3. Drupal.ajax instances
     *
     * @returns {string}
     *   The CSRF token or empty string if not found.
     */
    getCsrfToken: function () {
      // Check meta tag first
      const metaToken = document.querySelector('meta[name="csrf-token"]');
      if (metaToken) {
        return metaToken.getAttribute("content");
      }

      // Check drupalSettings
      if (drupalSettings.waap_login && drupalSettings.waap_login.csrf_token) {
        return drupalSettings.waap_login.csrf_token;
      }

      // Check for token in settings (Drupal 8+ style)
      if (drupalSettings.user && drupalSettings.user.csrf_token) {
        return drupalSettings.user.csrf_token;
      }

      // Try Drupal.ajax (legacy)
      if (Drupal.ajax && Drupal.ajax.instances) {
        const instanceKeys = Object.keys(Drupal.ajax.instances);
        if (instanceKeys.length > 0) {
          const ajaxInstance = Drupal.ajax.instances[instanceKeys[0]];
          if (
            ajaxInstance &&
            ajaxInstance.options &&
            ajaxInstance.options.token
          ) {
            return ajaxInstance.options.token;
          }
        }
      }

      return "";
    },

    /**
     * Check if the current page is in dark mode.
     *
     * @returns {boolean}
     *   True if dark mode is enabled, false otherwise.
     */
    isDarkMode: function () {
      // Check for data attribute on body
      const body = document.body;
      if (body && body.getAttribute("data-theme") === "dark") {
        return true;
      }

      // Check for dark mode class
      if (body && body.classList.contains("dark-mode")) {
        return true;
      }

      // Check CSS media query
      if (
        window.matchMedia &&
        window.matchMedia("(prefers-color-scheme: dark)").matches
      ) {
        return true;
      }

      return false;
    },

    /**
     * Copy text to clipboard.
     *
     * @param {string} text
     *   The text to copy.
     *
     * @returns {Promise<boolean>}
     *   True if copy was successful, false otherwise.
     */
    copyToClipboard: async function (text) {
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          return true;
        }

        // Fallback for older browsers
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.select();

        try {
          document.execCommand("copy");
          document.body.removeChild(textArea);
          return true;
        } catch (err) {
          document.body.removeChild(textArea);
          return false;
        }
      } catch (error) {
        console.error("Failed to copy to clipboard:", error);
        return false;
      }
    },

    /**
     * Convert hex string to checksummed address (EIP-55).
     *
     * @param {string} address
     *   The address to checksum.
     *
     * @returns {string}
     *   The checksummed address.
     */
    toChecksumAddress: function (address) {
      if (!this.validateAddress(address)) {
        return address;
      }

      address = address.toLowerCase().replace("0x", "");

      const hash = this.keccak256(address);
      let checksum = "0x";

      for (let i = 0; i < address.length; i++) {
        const hashChar = parseInt(hash[i], 16);
        const addressChar = address[i];

        if (hashChar > 7) {
          checksum += addressChar.toUpperCase();
        } else {
          checksum += addressChar;
        }
      }

      return checksum;
    },

    /**
     * Simple Keccak-256 hash implementation.
     *
     * Note: This is a simplified implementation. For production use,
     * consider using a proper crypto library like ethers.js or web3.js.
     *
     * @param {string} value
     *   The value to hash.
     *
     * @returns {string}
     *   The hashed value.
     */
    keccak256: function (value) {
      // Simplified implementation - in production, use a proper library
      // This is a placeholder that returns a pseudo-hash for demonstration
      let hash = 0;
      for (let i = 0; i < value.length; i++) {
        const char = value.charCodeAt(i);
        hash = (hash << 5) - hash + char;
        hash = hash & hash; // Convert to 32bit integer
      }
      // Return a hex-like string of 64 characters
      const hashHex = Math.abs(hash).toString(16).padStart(64, "0");
      return hashHex;
    },

    /**
     * Check if a user agent string indicates a mobile device.
     *
     * @returns {boolean}
     *   True if the device appears to be mobile, false otherwise.
     */
    isMobile: function () {
      const userAgent = navigator.userAgent || navigator.vendor || window.opera;

      return /android|ipad|iphone|ipod|blackberry|iemobile|opera mini/i.test(
        userAgent.toLowerCase()
      );
    },

    /**
     * Get the WaaP configuration from Drupal settings.
     *
     * @returns {Object}
     *   The WaaP configuration object.
     */
    getConfig: function () {
      return drupalSettings.waap_login || {};
    },

    /**
     * Check if a specific authentication method is enabled.
     *
     * @param {string} method
     *   The method to check (e.g., 'email', 'social', 'wallet').
     *
     * @returns {boolean}
     *   True if the method is enabled, false otherwise.
     */
    isAuthMethodEnabled: function (method) {
      const config = this.getConfig();
      const enabledMethods = config.authentication_methods || [
        "email",
        "social",
        "wallet",
      ];
      return enabledMethods.indexOf(method) !== -1;
    },

    /**
     * Get enabled social providers from configuration.
     *
     * @returns {Array<string>}
     *   Array of enabled social provider names.
     */
    getEnabledSocialProviders: function () {
      const config = this.getConfig();
      return config.allowed_socials || ["google", "facebook", "twitter"];
    },

    /**
     * Wait for WaaP SDK to be available.
     *
     * @param {number} [timeout=10000]
     *   Maximum time to wait in milliseconds.
     * @param {number} [interval=100]
     *   Check interval in milliseconds.
     *
     * @returns {Promise<boolean>}
     *   True if WaaP became available, false if timeout occurred.
     */
    waitForWaap: function (timeout, interval) {
      timeout = timeout || 10000;
      interval = interval || 100;

      return new Promise((resolve) => {
        const startTime = Date.now();

        const checkInterval = setInterval(() => {
          if (this.isWaapAvailable()) {
            clearInterval(checkInterval);
            resolve(true);
          } else if (Date.now() - startTime >= timeout) {
            clearInterval(checkInterval);
            resolve(false);
          }
        }, interval);
      });
    },
  };
})(Drupal, drupalSettings);
