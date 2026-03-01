/**
 * @file
 * Safe SDK Manager - Async singleton for Safe Protocol Kit initialization.
 *
 * This module provides a centralized manager for initializing and accessing
 * the Safe Protocol Kit SDK. It implements an async singleton pattern to ensure
 * only one SDK instance is active per Safe address.
 *
 * @see https://docs.safe.global/sdk/protocol-kit
 */

(function (Drupal, once) {
  'use strict';

  // Cache of initialized Safe instances by address
  const safeInstances = new Map();

  // Pending initialization promises by address
  const pendingInits = new Map();

  // Sepolia chain configuration
  const SEPOLIA_CHAIN_ID = 11155111n;

  /**
   * Safe SDK Manager namespace.
   */
  Drupal.safeSDK = {

    /**
     * Initialize Safe SDK for an existing deployed Safe.
     *
     * @param {string} safeAddress - The deployed Safe contract address.
     * @param {object} provider - ethers.js BrowserProvider instance.
     * @returns {Promise<object>} Initialized Safe Protocol Kit instance.
     */
    async init(safeAddress, provider) {
      const addressKey = safeAddress.toLowerCase();

      // Return cached instance if available
      if (safeInstances.has(addressKey)) {
        return safeInstances.get(addressKey);
      }

      // Return pending promise if initialization is in progress
      if (pendingInits.has(addressKey)) {
        return pendingInits.get(addressKey);
      }

      // Start new initialization
      const initPromise = this._initializeSafe(safeAddress, provider);
      pendingInits.set(addressKey, initPromise);

      try {
        const safeInstance = await initPromise;
        safeInstances.set(addressKey, safeInstance);
        pendingInits.delete(addressKey);
        return safeInstance;
      } catch (error) {
        pendingInits.delete(addressKey);
        throw error;
      }
    },

    /**
     * Initialize Safe SDK for deployment (predicted Safe).
     *
     * This is used when creating a new Safe that doesn't exist on-chain yet.
     * The SDK will calculate the deterministic address based on the config.
     *
     * @param {object} config - Safe configuration object.
     * @param {string[]} config.signers - Array of owner addresses.
     * @param {number} config.threshold - Required signature threshold.
     * @param {string|number} config.salt_nonce - Salt for deterministic address.
     * @param {object} provider - ethers.js BrowserProvider instance.
     * @returns {Promise<object>} Safe Protocol Kit instance for deployment.
     */
    async initForDeployment(config, provider) {
      const signer = await provider.getSigner();
      const signerAddress = await signer.getAddress();

      console.log('[Safe SDK] Initializing for deployment:', {
        owners: config.signers,
        threshold: config.threshold,
        saltNonce: config.salt_nonce
      });

      try {
        const protocolKit = await Safe.init({
          provider: window.ethereum,
          signer: signerAddress,
          predictedSafe: {
            safeAccountConfig: {
              owners: config.signers,
              threshold: config.threshold
            },
            safeDeploymentConfig: {
              saltNonce: config.salt_nonce?.toString() || '0'
            }
          }
        });

        console.log('[Safe SDK] Deployment instance initialized');
        return protocolKit;
      } catch (error) {
        console.error('[Safe SDK] Failed to initialize for deployment:', error);
        throw error;
      }
    },

    /**
     * Get a cached Safe instance without re-initializing.
     *
     * @param {string} safeAddress - The Safe address to look up.
     * @returns {object|null} Cached Safe instance or null if not found.
     */
    getCached(safeAddress) {
      return safeInstances.get(safeAddress.toLowerCase()) || null;
    },

    /**
     * Clear a specific Safe instance from cache.
     *
     * @param {string} safeAddress - The Safe address to clear.
     */
    clearCache(safeAddress) {
      const addressKey = safeAddress.toLowerCase();
      safeInstances.delete(addressKey);
      pendingInits.delete(addressKey);
      console.log('[Safe SDK] Cleared cache for:', safeAddress);
    },

    /**
     * Clear all cached Safe instances.
     */
    clearAllCache() {
      safeInstances.clear();
      pendingInits.clear();
      console.log('[Safe SDK] Cleared all cached instances');
    },

    /**
     * Check if the user is connected to the correct network (Sepolia).
     *
     * @param {object} provider - ethers.js BrowserProvider instance.
     * @returns {Promise<boolean>} True if on Sepolia, false otherwise.
     */
    async isCorrectNetwork(provider) {
      const network = await provider.getNetwork();
      return network.chainId === SEPOLIA_CHAIN_ID;
    },

    /**
     * Get the current signer address from the provider.
     *
     * @param {object} provider - ethers.js BrowserProvider instance.
     * @returns {Promise<string>} The connected wallet address.
     */
    async getSignerAddress(provider) {
      const signer = await provider.getSigner();
      return signer.getAddress();
    },

    /**
     * Internal initialization method.
     *
     * @param {string} safeAddress - The Safe address.
     * @param {object} provider - ethers.js BrowserProvider.
     * @returns {Promise<object>} Initialized Safe instance.
     * @private
     */
    async _initializeSafe(safeAddress, provider) {
      const signer = await provider.getSigner();
      const signerAddress = await signer.getAddress();

      console.log('[Safe SDK] Initializing Safe:', {
        safeAddress,
        signer: signerAddress
      });

      try {
        const protocolKit = await Safe.init({
          provider: window.ethereum,
          signer: signerAddress,
          safeAddress: safeAddress
        });

        // Log Safe info for debugging
        const threshold = await protocolKit.getThreshold();
        const owners = await protocolKit.getOwners();
        console.log('[Safe SDK] Safe initialized:', {
          address: safeAddress,
          threshold,
          owners
        });

        return protocolKit;
      } catch (error) {
        console.error('[Safe SDK] Failed to initialize Safe:', error);
        throw error;
      }
    }
  };

  /**
   * Drupal behavior for global SDK initialization.
   */
  Drupal.behaviors.safeSDKManager = {
    attach: function (context, settings) {
      once('safe-sdk-manager', 'html', context).forEach(function () {
        // Verify Safe global is available
        if (typeof Safe === 'undefined') {
          console.error('[Safe SDK] Protocol Kit not loaded. Run "npm install" in module directory.');
          return;
        }

        console.log('[Safe SDK] Manager initialized, Protocol Kit available');

        // Verify EthSafeSignature is available
        if (typeof EthSafeSignature === 'undefined') {
          console.warn('[Safe SDK] EthSafeSignature not available, signature reconstruction may fail');
        }
      });
    }
  };

})(Drupal, once);
