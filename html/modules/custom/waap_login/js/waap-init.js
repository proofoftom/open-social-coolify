/**
 * @file
 * WaaP SDK initialization for Drupal WaaP Login module.
 *
 * Initializes the Human.tech WaaP SDK with configuration from Drupal settings.
 * Handles dynamic loading of the SDK from CDN and global export.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * WaaP SDK initialization behavior.
   *
   * Loads the WaaP SDK from CDN and initializes it with Drupal settings.
   * The initialized WaaP instance is exported to window.waap for use by other
   * modules and components.
   *
   * @namespace
   * @property {function} attach
   *   Drupal behavior attach function.
   */
  Drupal.behaviors.waapInit = {
    /**
     * Attach behavior to the context.
     *
     * @param {HTMLElement} context
     *   The context element.
     * @param {Object} settings
     *   The Drupal settings object.
     */
    attach: function (context, settings) {
      // Only initialize once per page load
      if (window.waap) {
        return;
      }

      // Check if WaaP is enabled in configuration
      const config = drupalSettings.waap_login || {};
      if (!config.enabled) {
        console.log('WaaP Login is disabled in configuration.');
        return;
      }

      // Load and initialize the WaaP SDK
      this.loadAndInitSDK(config);
    },

    /**
     * Load WaaP SDK from CDN and initialize it.
     *
     * @param {Object} config
     *   Configuration object from drupalSettings.waap_login.
     */
    loadAndInitSDK: async function (config) {
      try {
        // Load WaaP SDK from CDN
        await this.loadSDKScript();

        // Check if SDK is available
        if (typeof window.WaaP === 'undefined' || typeof window.WaaP.init === 'undefined') {
          throw new Error('WaaP SDK loaded but init function not available');
        }

        // Build initialization configuration
        const initConfig = this.buildInitConfig(config);

        // Initialize WaaP SDK
        window.waap = await window.WaaP.init(initConfig);

        console.log('WaaP SDK initialized successfully', {
          environment: initConfig.environment,
          authMethods: initConfig.authenticationMethods,
          darkMode: initConfig.theme === 'dark'
        });

        // Trigger custom event for other modules
        this.triggerWaapReadyEvent();

      } catch (error) {
        console.error('Failed to initialize WaaP SDK:', error);
        this.triggerWaapErrorEvent(error);
      }
    },

    /**
     * Load WaaP SDK script from CDN.
     *
     * @returns {Promise<void>}
     *   Promise that resolves when script is loaded.
     */
    loadSDKScript: function () {
      return new Promise((resolve, reject) => {
        // Check if script is already loaded
        if (document.querySelector('script[src*="waap-sdk"]')) {
          resolve();
          return;
        }

        const script = document.createElement('script');
        script.src = 'https://cdn.wallet.human.tech/sdk/v1/waap-sdk.min.js';
        script.async = true;
        script.crossOrigin = 'anonymous';

        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load WaaP SDK from CDN'));

        document.head.appendChild(script);
      });
    },

    /**
     * Build WaaP SDK initialization configuration from Drupal settings.
     *
     * @param {Object} config
     *   Configuration object from drupalSettings.waap_login.
     *
     * @returns {Object}
     *   WaaP SDK initialization configuration.
     */
    buildInitConfig: function (config) {
      return {
        environment: config.use_staging ? 'sandbox' : 'production',
        authenticationMethods: config.authentication_methods || ['email', 'social', 'wallet'],
        socialProviders: config.allowed_socials || ['google', 'facebook', 'twitter'],
        walletConnect: {
          projectId: config.walletconnect_project_id || ''
        },
        theme: config.enable_dark_mode ? 'dark' : 'light',
        showSecuredBadge: config.show_secured_badge !== false,
        referralCode: config.referral_code || '',
        gasTankEnabled: config.gas_tank_enabled || false
      };
    },

    /**
     * Trigger custom event when WaaP is ready.
     */
    triggerWaapReadyEvent: function () {
      const event = new CustomEvent('waapReady', {
        detail: {
          waap: window.waap
        }
      });
      window.dispatchEvent(event);
    },

    /**
     * Trigger custom event on WaaP initialization error.
     *
     * @param {Error} error
     *   The error that occurred.
     */
    triggerWaapErrorEvent: function (error) {
      const event = new CustomEvent('waapError', {
        detail: {
          error: error
        }
      });
      window.dispatchEvent(event);
    }
  };

})(Drupal, drupalSettings);
