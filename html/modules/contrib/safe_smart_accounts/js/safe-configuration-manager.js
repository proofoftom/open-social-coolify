/**
 * @file
 * Safe Smart Accounts - Configuration Manager using Protocol Kit.
 *
 * Handles Safe configuration changes (owner management, threshold) using
 * the official Safe Protocol Kit SDK.
 *
 * @see https://docs.safe.global/sdk/protocol-kit
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Behavior for Safe configuration management.
   */
  Drupal.behaviors.safeConfigurationManager = {
    attach: function (context) {
      // Attach to configuration update forms
      const configForms = context.querySelectorAll(
        '#safe-account-manage-form:not(.safe-config-processed)'
      );

      configForms.forEach(function (form) {
        form.classList.add('safe-config-processed');

        const saveButton = form.querySelector('input[name="op"][value="Save Configuration"]');
        if (saveButton) {
          saveButton.addEventListener('click', handleConfigurationSave);
        }
      });
    }
  };

  /**
   * Handle configuration save (placeholder for future preview feature).
   */
  async function handleConfigurationSave() {
    // For now, let the form submit normally.
    // Future: intercept to show preview of on-chain transactions
  }

  /**
   * Create an add owner transaction using the Protocol Kit.
   *
   * @param {string} safeAddress - The Safe contract address.
   * @param {string} newOwner - Address of owner to add.
   * @param {number} newThreshold - New threshold after adding.
   * @returns {Promise<object>} The Safe transaction object.
   */
  async function createAddOwnerTransaction(safeAddress, newOwner, newThreshold) {
    const provider = new ethers.BrowserProvider(window.ethereum);

    if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
      throw new Error('Please switch to Sepolia testnet in your wallet');
    }

    const protocolKit = await Drupal.safeSDK.init(safeAddress, provider);

    // SDK handles linked list navigation internally
    const safeTx = await protocolKit.createAddOwnerTx({
      ownerAddress: newOwner,
      threshold: newThreshold
    });

    console.log('[Config Manager] Created addOwner transaction:', {
      newOwner,
      newThreshold
    });

    return safeTx;
  }

  /**
   * Create a remove owner transaction using the Protocol Kit.
   *
   * @param {string} safeAddress - The Safe contract address.
   * @param {string} ownerToRemove - Address of owner to remove.
   * @param {number} newThreshold - New threshold after removal.
   * @returns {Promise<object>} The Safe transaction object.
   */
  async function createRemoveOwnerTransaction(safeAddress, ownerToRemove, newThreshold) {
    const provider = new ethers.BrowserProvider(window.ethereum);

    if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
      throw new Error('Please switch to Sepolia testnet in your wallet');
    }

    const protocolKit = await Drupal.safeSDK.init(safeAddress, provider);

    // SDK handles prevOwner calculation internally
    const safeTx = await protocolKit.createRemoveOwnerTx({
      ownerAddress: ownerToRemove,
      threshold: newThreshold
    });

    console.log('[Config Manager] Created removeOwner transaction:', {
      ownerToRemove,
      newThreshold
    });

    return safeTx;
  }

  /**
   * Create a swap owner transaction using the Protocol Kit.
   *
   * @param {string} safeAddress - The Safe contract address.
   * @param {string} oldOwner - Address of owner to replace.
   * @param {string} newOwner - Address of new owner.
   * @returns {Promise<object>} The Safe transaction object.
   */
  async function createSwapOwnerTransaction(safeAddress, oldOwner, newOwner) {
    const provider = new ethers.BrowserProvider(window.ethereum);

    if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
      throw new Error('Please switch to Sepolia testnet in your wallet');
    }

    const protocolKit = await Drupal.safeSDK.init(safeAddress, provider);

    // SDK handles prevOwner calculation internally
    const safeTx = await protocolKit.createSwapOwnerTx({
      oldOwnerAddress: oldOwner,
      newOwnerAddress: newOwner
    });

    console.log('[Config Manager] Created swapOwner transaction:', {
      oldOwner,
      newOwner
    });

    return safeTx;
  }

  /**
   * Create a change threshold transaction using the Protocol Kit.
   *
   * @param {string} safeAddress - The Safe contract address.
   * @param {number} newThreshold - The new threshold value.
   * @returns {Promise<object>} The Safe transaction object.
   */
  async function createChangeThresholdTransaction(safeAddress, newThreshold) {
    const provider = new ethers.BrowserProvider(window.ethereum);

    if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
      throw new Error('Please switch to Sepolia testnet in your wallet');
    }

    const protocolKit = await Drupal.safeSDK.init(safeAddress, provider);

    const safeTx = await protocolKit.createChangeThresholdTx(newThreshold);

    console.log('[Config Manager] Created changeThreshold transaction:', {
      newThreshold
    });

    return safeTx;
  }

  /**
   * Create a configuration change transaction via Drupal API.
   *
   * @param {string} safeAccountId - The Safe account entity ID.
   * @param {string} changeType - Type: add_owner, remove_owner, swap_owner, change_threshold.
   * @param {object} changeData - Data for the change.
   * @returns {Promise<object>} The created transaction data.
   */
  async function createConfigurationTransaction(safeAccountId, changeType, changeData) {
    try {
      const tokenResponse = await fetch('/session/token?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-cache'
      });

      if (!tokenResponse.ok) {
        throw new Error('Failed to retrieve CSRF token');
      }

      const csrfToken = await tokenResponse.text();

      const response = await fetch(
        `/safe-accounts/${safeAccountId}/configuration/create-transaction`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            change_type: changeType,
            change_data: changeData
          })
        }
      );

      const responseData = await response.json();

      if (!response.ok) {
        throw new Error(responseData.error || `HTTP error! status: ${response.status}`);
      }

      return responseData;
    } catch (error) {
      console.error('[Config Manager] Create transaction error:', error);
      throw error;
    }
  }

  /**
   * Execute a configuration change transaction on-chain.
   *
   * @param {string} safeAccountId - The Safe account entity ID.
   * @param {string} transactionId - The transaction entity ID.
   */
  async function executeConfigurationChange(safeAccountId, transactionId) {
    try {
      if (typeof window.ethereum === 'undefined') {
        throw new Error('Please install MetaMask or another Web3 wallet');
      }

      await window.ethereum.request({ method: 'eth_requestAccounts' });
      const provider = new ethers.BrowserProvider(window.ethereum);

      if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
        throw new Error('Please switch to Sepolia testnet in your wallet');
      }

      showLoadingMessage('Fetching transaction data...');
      const txData = await getTransactionData(safeAccountId, transactionId);

      if (!txData) {
        throw new Error('Could not retrieve transaction data');
      }

      const signatures = txData.signatures || [];
      if (signatures.length < txData.threshold) {
        throw new Error(`Not enough signatures: ${signatures.length}/${txData.threshold}`);
      }

      showLoadingMessage('Initializing Safe SDK...');
      const protocolKit = await Drupal.safeSDK.init(txData.safe_address, provider);

      // Recreate the transaction
      const safeTx = await protocolKit.createTransaction({
        transactions: [{
          to: txData.to,
          value: txData.value.toString(),
          data: txData.data || '0x',
          operation: txData.operation || 0
        }],
        options: {
          nonce: txData.nonce
        }
      });

      // Add all signatures
      for (const sig of signatures) {
        if (typeof EthSafeSignature !== 'undefined') {
          safeTx.addSignature(new EthSafeSignature(sig.signer, sig.signature));
        } else {
          safeTx.signatures.set(sig.signer.toLowerCase(), {
            signer: sig.signer,
            data: sig.signature,
            isContractSignature: false
          });
        }
      }

      showLoadingMessage('Executing configuration change on-chain...');
      const executionResult = await protocolKit.executeTransaction(safeTx);

      showLoadingMessage('Waiting for blockchain confirmation...');
      if (executionResult.transactionResponse) {
        await executionResult.transactionResponse.wait();
      }

      const txHash = executionResult.hash;
      console.log('[Config Manager] Configuration change executed:', txHash);

      // Update transaction status in Drupal
      await updateTransactionStatus(safeAccountId, transactionId, 'executed', txHash);

      // Clear SDK cache to force re-fetch of updated Safe state
      Drupal.safeSDK.clearCache(txData.safe_address);

      showSuccessMessage('Configuration change executed successfully!');

      setTimeout(() => window.location.reload(), 2000);
    } catch (error) {
      console.error('[Config Manager] Execution failed:', error);
      showErrorMessage(`Execution failed: ${error.message}`);
      throw error;
    }
  }

  /**
   * Fetch transaction data from Drupal.
   *
   * @param {string} safeAccountId - Safe account entity ID.
   * @param {string} transactionId - Transaction entity ID.
   * @returns {Promise<object|null>} Transaction data or null.
   */
  async function getTransactionData(safeAccountId, transactionId) {
    try {
      const response = await fetch(
        `/safe-accounts/${safeAccountId}/transactions/${transactionId}/data`,
        {
          method: 'GET',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin'
        }
      );

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
      }

      return response.json();
    } catch (error) {
      console.error('[Config Manager] Fetch error:', error);
      return null;
    }
  }

  /**
   * Update transaction status in Drupal.
   *
   * @param {string} safeAccountId - Safe account entity ID.
   * @param {string} transactionId - Transaction entity ID.
   * @param {string} status - New status.
   * @param {string} txHash - Execution transaction hash.
   * @returns {Promise<object>} Update result.
   */
  async function updateTransactionStatus(safeAccountId, transactionId, status, txHash) {
    try {
      const tokenResponse = await fetch('/session/token?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-cache'
      });

      if (!tokenResponse.ok) {
        throw new Error('Failed to retrieve CSRF token');
      }

      const csrfToken = await tokenResponse.text();

      const response = await fetch(
        `/safe-accounts/${safeAccountId}/transactions/${transactionId}/status`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            status: status,
            execution_tx_hash: txHash
          })
        }
      );

      const responseData = await response.json();

      if (!response.ok) {
        throw new Error(responseData.error || `HTTP error! status: ${response.status}`);
      }

      return responseData;
    } catch (error) {
      console.error('[Config Manager] Status update error:', error);
      throw error;
    }
  }

  // UI Helper Functions

  function showLoadingMessage(message) {
    if (typeof Drupal !== 'undefined' && Drupal.message) {
      removeLoadingMessage();
      Drupal.message({ text: message, type: 'warning', id: 'safe-config-loading' });
    } else {
      console.log(message);
    }
  }

  function showSuccessMessage(message) {
    if (typeof Drupal !== 'undefined' && Drupal.message) {
      Drupal.message({ text: message, type: 'status' });
    } else {
      alert(message);
    }
  }

  function showErrorMessage(message) {
    if (typeof Drupal !== 'undefined' && Drupal.message) {
      Drupal.message({ text: message, type: 'error' });
    } else {
      alert(message);
    }
  }

  function removeLoadingMessage() {
    if (typeof Drupal !== 'undefined' && Drupal.message) {
      const messages = document.querySelectorAll(
        '.messages[data-drupal-message-id="safe-config-loading"]'
      );
      messages.forEach((msg) => msg.remove());
    }
  }

  // Export functions for external use
  window.SafeConfigurationManager = {
    createAddOwnerTransaction,
    createRemoveOwnerTransaction,
    createSwapOwnerTransaction,
    createChangeThresholdTransaction,
    createConfigurationTransaction,
    executeConfigurationChange
  };

})(Drupal, drupalSettings);
