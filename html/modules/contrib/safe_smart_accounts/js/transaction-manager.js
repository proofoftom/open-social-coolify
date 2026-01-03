/**
 * @file
 * Safe Smart Accounts - Transaction Manager using Protocol Kit.
 *
 * Handles signing and executing Safe transactions using the official
 * Safe Protocol Kit SDK. Replaces manual signature handling with SDK methods.
 *
 * @see https://docs.safe.global/sdk/protocol-kit/guides/execute-transactions
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Behavior for Safe transaction management.
   */
  Drupal.behaviors.safeTransactionManager = {
    attach: function (context) {
      // Attach to sign transaction buttons
      const signButtons = context.querySelectorAll(
        '.safe-transaction-sign:not(.safe-tx-processed)'
      );
      signButtons.forEach(function (button) {
        button.classList.add('safe-tx-processed');
        button.addEventListener('click', handleSignTransaction);
      });

      // Attach to execute transaction buttons
      const executeButtons = context.querySelectorAll(
        '.safe-transaction-execute:not(.safe-tx-processed)'
      );
      executeButtons.forEach(function (button) {
        button.classList.add('safe-tx-processed');
        button.addEventListener('click', handleExecuteTransaction);
      });
    }
  };

  /**
   * Handle signing a Safe transaction.
   *
   * @param {Event} event - Click event.
   */
  async function handleSignTransaction(event) {
    event.preventDefault();
    const button = event.currentTarget;
    const safeAccountId = button.getAttribute('data-safe-account-id');
    const transactionId = button.getAttribute('data-transaction-id');

    if (!safeAccountId || !transactionId) {
      showErrorMessage('Missing required transaction information');
      return;
    }

    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Signing...';
    button.classList.add('safe-tx-loading');

    try {
      // Verify Web3 provider
      if (typeof window.ethereum === 'undefined') {
        throw new Error('Please install MetaMask or another Web3 wallet');
      }

      // Request account access
      await window.ethereum.request({ method: 'eth_requestAccounts' });
      const provider = new ethers.BrowserProvider(window.ethereum);

      // Verify network
      if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
        throw new Error('Please switch to Sepolia testnet in your wallet');
      }

      const signerAddress = await Drupal.safeSDK.getSignerAddress(provider);

      // Fetch transaction data from Drupal
      showLoadingMessage('Fetching transaction data...');
      const txData = await getTransactionData(safeAccountId, transactionId);

      if (!txData) {
        throw new Error('Could not retrieve transaction data');
      }

      // Verify user is a signer
      const signerLower = signerAddress.toLowerCase();
      const signersLower = txData.signers.map((s) => s.toLowerCase());
      if (!signersLower.includes(signerLower)) {
        throw new Error('You are not authorized to sign this transaction');
      }

      // Check if already signed
      const existingSignatures = txData.signatures || [];
      const alreadySigned = existingSignatures.some(
        (sig) => sig.signer.toLowerCase() === signerLower
      );
      if (alreadySigned) {
        throw new Error('You have already signed this transaction');
      }

      showLoadingMessage('Initializing Safe SDK...');

      // Initialize Safe SDK for this Safe
      const protocolKit = await Drupal.safeSDK.init(txData.safe_address, provider);

      // Create Safe transaction object
      showLoadingMessage('Building transaction...');
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

      // Sign the transaction using SDK (handles v-value adjustment automatically)
      showLoadingMessage('Please sign the transaction in your wallet...');
      const signedTx = await protocolKit.signTransaction(safeTx);

      console.log('[Transaction Manager] Transaction signed');

      // Extract the signature for this signer
      const signature = signedTx.signatures.get(signerLower);
      if (!signature) {
        throw new Error('Failed to extract signature from signed transaction');
      }

      // Submit signature to Drupal
      showLoadingMessage('Submitting signature...');
      const result = await submitSignature(
        safeAccountId,
        transactionId,
        signature.data,
        signerAddress
      );

      if (result.success) {
        showSuccessMessage(
          `Signature added successfully! (${result.signature_count} of ${result.threshold})`
        );

        // Reload page to show updated status
        setTimeout(() => window.location.reload(), 2000);
      } else {
        throw new Error(result.error || 'Failed to submit signature');
      }
    } catch (error) {
      console.error('[Transaction Manager] Signing failed:', error);
      showErrorMessage(`Signing failed: ${error.message}`);
    } finally {
      button.disabled = false;
      button.textContent = originalText;
      button.classList.remove('safe-tx-loading');
      removeLoadingMessage();
    }
  }

  /**
   * Handle executing a Safe transaction.
   *
   * @param {Event} event - Click event.
   */
  async function handleExecuteTransaction(event) {
    event.preventDefault();
    const button = event.currentTarget;
    const safeAccountId = button.getAttribute('data-safe-account-id');
    const transactionId = button.getAttribute('data-transaction-id');

    if (!safeAccountId || !transactionId) {
      showErrorMessage('Missing required transaction information');
      return;
    }

    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Executing...';
    button.classList.add('safe-tx-loading');

    try {
      // Verify Web3 provider
      if (typeof window.ethereum === 'undefined') {
        throw new Error('Please install MetaMask or another Web3 wallet');
      }

      await window.ethereum.request({ method: 'eth_requestAccounts' });
      const provider = new ethers.BrowserProvider(window.ethereum);

      // Verify network
      if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
        throw new Error('Please switch to Sepolia testnet in your wallet');
      }

      // Fetch transaction data
      showLoadingMessage('Fetching transaction data...');
      const txData = await getTransactionData(safeAccountId, transactionId);

      if (!txData) {
        throw new Error('Could not retrieve transaction data');
      }

      // Verify enough signatures
      if (!txData.can_execute) {
        throw new Error(
          `Transaction needs ${txData.threshold} signatures but only has ${txData.signatures.length}`
        );
      }

      showLoadingMessage('Initializing Safe SDK...');

      // Initialize Safe SDK
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

      // Add all collected signatures
      console.log('[Transaction Manager] Adding signatures:', txData.signatures.length);
      for (const sig of txData.signatures) {
        // Use EthSafeSignature if available, otherwise add raw signature
        if (typeof EthSafeSignature !== 'undefined') {
          safeTx.addSignature(new EthSafeSignature(sig.signer, sig.signature));
        } else {
          // Fallback: manually add signature to map
          safeTx.signatures.set(sig.signer.toLowerCase(), {
            signer: sig.signer,
            data: sig.signature,
            isContractSignature: false
          });
        }
      }

      // Execute the transaction using SDK
      showLoadingMessage('Please confirm the execution in your wallet...');
      const executionResult = await protocolKit.executeTransaction(safeTx);

      showLoadingMessage('Waiting for blockchain confirmation...');

      // Wait for transaction confirmation
      if (executionResult.transactionResponse) {
        await executionResult.transactionResponse.wait();
      }

      const txHash = executionResult.hash;
      console.log('[Transaction Manager] Transaction executed:', txHash);

      // Update Drupal with execution result
      const result = await markTransactionExecuted(
        safeAccountId,
        transactionId,
        txHash
      );

      if (result.success) {
        showSuccessMessage(
          `Transaction executed successfully! Tx: ${txHash.substring(0, 10)}...`
        );

        // Reload to show executed status
        setTimeout(() => window.location.reload(), 3000);
      } else {
        throw new Error(result.error || 'Failed to update transaction status');
      }
    } catch (error) {
      console.error('[Transaction Manager] Execution failed:', error);

      // Parse Safe-specific error codes
      const errorMessage = parseSafeError(error);
      showErrorMessage(`Execution failed: ${errorMessage}`);
    } finally {
      button.disabled = false;
      button.textContent = originalText;
      button.classList.remove('safe-tx-loading');
      removeLoadingMessage();
    }
  }

  /**
   * Fetch transaction data from Drupal API.
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
      console.error('[Transaction Manager] Fetch error:', error);
      return null;
    }
  }

  /**
   * Submit signature to Drupal API.
   *
   * @param {string} safeAccountId - Safe account entity ID.
   * @param {string} transactionId - Transaction entity ID.
   * @param {string} signature - The signature data.
   * @param {string} signerAddress - The signer's address.
   * @returns {Promise<object>} Submission result.
   */
  async function submitSignature(safeAccountId, transactionId, signature, signerAddress) {
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
        `/safe-accounts/${safeAccountId}/transactions/${transactionId}/sign`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            signature: signature,
            signer: signerAddress
          })
        }
      );

      const responseData = await response.json();

      if (!response.ok) {
        return {
          success: false,
          error: responseData.error || `HTTP error! status: ${response.status}`
        };
      }

      return responseData;
    } catch (error) {
      console.error('[Transaction Manager] Submit error:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Mark transaction as executed in Drupal.
   *
   * @param {string} safeAccountId - Safe account entity ID.
   * @param {string} transactionId - Transaction entity ID.
   * @param {string} blockchainTxHash - The execution transaction hash.
   * @returns {Promise<object>} Update result.
   */
  async function markTransactionExecuted(safeAccountId, transactionId, blockchainTxHash) {
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
        `/safe-accounts/${safeAccountId}/transactions/${transactionId}/execute`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            blockchain_tx_hash: blockchainTxHash
          })
        }
      );

      const responseData = await response.json();

      if (!response.ok) {
        return {
          success: false,
          error: responseData.error || `HTTP error! status: ${response.status}`
        };
      }

      return responseData;
    } catch (error) {
      console.error('[Transaction Manager] Mark executed error:', error);
      return { success: false, error: error.message };
    }
  }

  /**
   * Parse Safe contract errors and return user-friendly messages.
   *
   * @param {Error} error - The error object.
   * @returns {string} User-friendly error message.
   */
  function parseSafeError(error) {
    const errorString = error.message || error.toString();

    // Common Safe error codes
    const safeErrors = {
      'GS010': 'Not enough gas to execute Safe transaction',
      'GS011': 'Could not pay gas costs with ether',
      'GS012': 'Could not pay gas costs with token',
      'GS013': 'Safe balance too low - insufficient ETH in the Safe',
      'GS020': 'Signatures data too short',
      'GS021': 'Invalid contract signature location: inside static part',
      'GS022': 'Invalid contract signature location: length not present',
      'GS023': 'Invalid contract signature location: data not complete',
      'GS024': 'Invalid contract signature provided',
      'GS025': 'Hash has not been approved',
      'GS026': 'Invalid owner provided',
      'GS030': 'Only owners can approve a hash',
      'GS031': 'Method can only be called from this contract'
    };

    // Check for Safe error codes
    for (const [code, message] of Object.entries(safeErrors)) {
      if (errorString.includes(code)) {
        return `${message} (${code})`;
      }
    }

    // Check for common ethers.js errors
    if (errorString.includes('insufficient funds')) {
      return 'Insufficient funds in your wallet to pay for gas fees';
    }

    if (errorString.includes('user rejected')) {
      return 'Transaction was rejected in your wallet';
    }

    if (errorString.includes('nonce')) {
      return 'Transaction nonce error - another transaction may need to be executed first';
    }

    return error.message || 'Unknown error occurred';
  }

  // UI Helper Functions

  function showLoadingMessage(message) {
    if (typeof Drupal !== 'undefined' && Drupal.message) {
      removeLoadingMessage();
      Drupal.message({ text: message, type: 'warning', id: 'safe-transaction-loading' });
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
        '.messages[data-drupal-message-id="safe-transaction-loading"]'
      );
      messages.forEach((msg) => msg.remove());
    }
  }

})(Drupal, drupalSettings);
