/**
 * @file
 * Safe Smart Accounts - Safe Deployment using Protocol Kit.
 *
 * Handles deploying new Safe Smart Accounts to the blockchain using
 * the official Safe Protocol Kit SDK.
 *
 * @see https://docs.safe.global/sdk/protocol-kit/guides/safe-deployment
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Behavior for Safe deployment functionality.
   */
  Drupal.behaviors.safeDeployment = {
    attach: function (context, settings) {
      // Find and attach to deploy safe buttons
      const deployButtons = context.querySelectorAll(
        "input[value='Deploy Safe'], button[value='Deploy Safe']"
      );

      deployButtons.forEach(function (button) {
        if (button.hasAttribute('data-safe-deploy-processed')) {
          return;
        }

        button.setAttribute('data-safe-deploy-processed', 'true');
        button.classList.add('safe-deploy-button');

        const safeAccountIdInput = document.querySelector('input[name="safe_account_id"]');
        const userIdInput = document.querySelector('input[name="user_id"]');

        if (safeAccountIdInput && userIdInput) {
          button.setAttribute('data-safe-account-id', safeAccountIdInput.value);
          button.setAttribute('data-user-id', userIdInput.value);
        }

        button.addEventListener('click', handleDeploySafe);
      });
    }
  };

  /**
   * Handle Safe deployment button click.
   *
   * @param {Event} event - Click event.
   */
  async function handleDeploySafe(event) {
    event.preventDefault();
    event.stopPropagation();

    const button = event.currentTarget;
    const safeAccountId = button.getAttribute('data-safe-account-id');
    const userId = button.getAttribute('data-user-id');

    if (!safeAccountId || !userId) {
      showErrorMessage('Missing required information for Safe deployment');
      return;
    }

    const originalText = button.value || button.textContent;
    button.disabled = true;
    button.textContent = 'Deploying...';
    if (button.value) {
      button.value = 'Deploying...';
    }
    button.classList.add('safe-deploy-loading');

    try {
      showLoadingMessage('Deploying Safe Smart Account to Sepolia testnet...');

      // Verify Web3 provider
      if (typeof window.ethereum === 'undefined') {
        throw new Error('Please install MetaMask or another Web3 wallet');
      }

      // Request account access
      await window.ethereum.request({ method: 'eth_requestAccounts' });

      // Create ethers provider
      const provider = new ethers.BrowserProvider(window.ethereum);

      // Verify network
      if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
        throw new Error('Please switch to Sepolia testnet in your wallet');
      }

      // Get Safe configuration from Drupal
      const safeConfig = await getSafeConfiguration(safeAccountId);
      if (!safeConfig) {
        throw new Error('Could not retrieve Safe configuration');
      }

      showLoadingMessage('Please confirm the deployment transaction in your wallet...');

      // Deploy using SDK
      const result = await deploySafeWithSDK(safeConfig, provider);

      showLoadingMessage('Waiting for blockchain confirmation...');

      // Update Drupal entity
      const updateResult = await updateSafeAccountEntity(
        safeAccountId,
        result.safeAddress,
        'deployed',
        result.txHash
      );

      if (updateResult.success) {
        showSuccessMessage(`Safe deployed successfully at address: ${result.safeAddress}`);
        updatePageStatus(result.safeAddress, 'active');
        setTimeout(() => window.location.reload(), 20);
      } else {
        throw new Error(updateResult.error || 'Failed to update Safe account in Drupal');
      }
    } catch (error) {
      console.error('[Safe Deployment] Failed:', error);
      showErrorMessage(`Deployment failed: ${error.message}`);
    } finally {
      button.disabled = false;
      button.textContent = originalText;
      if (button.value) {
        button.value = originalText;
      }
      button.classList.remove('safe-deploy-loading');
      removeLoadingMessage();
    }
  }

  /**
   * Deploy a Safe using the Protocol Kit SDK.
   *
   * @param {object} config - Safe configuration.
   * @param {object} provider - ethers.js BrowserProvider.
   * @returns {Promise<{safeAddress: string, txHash: string}>} Deployment result.
   */
  async function deploySafeWithSDK(config, provider) {
    const signer = await provider.getSigner();
    const signerAddress = await signer.getAddress();

    // Ensure signer is included in owners
    const owners = config.signers || [signerAddress];
    const threshold = config.threshold || 1;
    const saltNonce = config.salt_nonce || '0';

    console.log('[Safe Deployment] Preparing deployment:', {
      owners,
      threshold,
      saltNonce
    });

    // Initialize SDK for deployment (predicted Safe)
    const protocolKit = await Drupal.safeSDK.initForDeployment({
      signers: owners,
      threshold: threshold,
      salt_nonce: saltNonce
    }, provider);

    // Get the predicted Safe address
    const safeAddress = await protocolKit.getAddress();
    console.log('[Safe Deployment] Predicted address:', safeAddress);

    // Check if Safe already exists at this address
    const existingCode = await provider.getCode(safeAddress);
    if (existingCode !== '0x') {
      console.log('[Safe Deployment] Safe already exists at predicted address');
      return { safeAddress, txHash: null };
    }

    // Create deployment transaction
    const deploymentTx = await protocolKit.createSafeDeploymentTransaction();

    console.log('[Safe Deployment] Deployment transaction:', {
      to: deploymentTx.to,
      value: deploymentTx.value,
      dataLength: deploymentTx.data?.length
    });

    // Send transaction via signer
    const tx = await signer.sendTransaction({
      to: deploymentTx.to,
      data: deploymentTx.data,
      value: deploymentTx.value || '0'
    });

    console.log('[Safe Deployment] Transaction sent:', tx.hash);

    // Wait for confirmation
    const receipt = await tx.wait();
    console.log('[Safe Deployment] Transaction confirmed:', {
      hash: receipt.hash,
      blockNumber: receipt.blockNumber,
      gasUsed: receipt.gasUsed.toString()
    });

    return { safeAddress, txHash: tx.hash };
  }

  /**
   * Deploy a newly created Safe account (called from create form).
   *
   * @param {string} safeAccountId - The Safe account entity ID.
   * @param {string} userId - The Drupal user ID.
   */
  async function deployCreatedSafe(safeAccountId, userId) {
    try {
      updateDeploymentStep(2, 'in-progress', 'Waiting for MetaMask signature...', 'Please check your wallet');

      if (typeof window.ethereum === 'undefined') {
        throw new Error('Please install MetaMask or another Web3 wallet');
      }

      await window.ethereum.request({ method: 'eth_requestAccounts' });
      const provider = new ethers.BrowserProvider(window.ethereum);

      if (!await Drupal.safeSDK.isCorrectNetwork(provider)) {
        throw new Error('Please switch to Sepolia testnet in your wallet');
      }

      const safeConfig = await getSafeConfiguration(safeAccountId);
      if (!safeConfig) {
        throw new Error('Could not retrieve Safe configuration');
      }

      // Initialize SDK and get predicted address
      const protocolKit = await Drupal.safeSDK.initForDeployment(safeConfig, provider);
      const predictedAddress = await protocolKit.getAddress();

      console.log('[Safe Deployment] Predicted address:', predictedAddress);

      // Create and send deployment transaction
      const deploymentTx = await protocolKit.createSafeDeploymentTransaction();
      const signer = await provider.getSigner();

      const tx = await signer.sendTransaction({
        to: deploymentTx.to,
        data: deploymentTx.data,
        value: deploymentTx.value || '0'
      });

      const txHash = tx.hash;
      console.log('[Safe Deployment] Transaction submitted:', txHash);

      updateDeploymentStep(2, 'completed', 'Transaction submitted');

      const etherscanUrl = `https://sepolia.etherscan.io/tx/${txHash}`;
      updateDeploymentStep(
        3,
        'in-progress',
        'Waiting for blockchain confirmation...',
        `<a href="${etherscanUrl}" target="_blank" rel="noopener noreferrer">View on Etherscan</a> (Est. 30 seconds)`
      );

      // Wait for confirmation
      await tx.wait();
      console.log('[Safe Deployment] Transaction confirmed');

      // Get actual Safe address (should match predicted)
      const safeAddress = predictedAddress;

      // Update Drupal entity
      const deploymentResult = await updateSafeAccountEntity(
        safeAccountId,
        safeAddress,
        'deployed',
        txHash
      );

      if (deploymentResult.success) {
        updateDeploymentStep(3, 'completed', 'Deployment confirmed on blockchain');
        updateDeploymentStep(
          4,
          'completed',
          'Safe deployed successfully!',
          `Address: ${safeAddress}<br><a href="${etherscanUrl}" target="_blank" rel="noopener noreferrer">View on Etherscan</a>`
        );

        setTimeout(() => {
          window.location.href = `/user/${userId}/safe-accounts/${safeAccountId}`;
        }, 2000);
      } else {
        throw new Error(deploymentResult.error || 'Failed to update Safe account in Drupal');
      }
    } catch (error) {
      console.error('[Safe Deployment] Failed:', error);
      const currentStep = getCurrentStep();
      updateDeploymentStep(currentStep, 'error', `Deployment failed: ${error.message}`);
    }
  }

  /**
   * Fetch Safe configuration from Drupal.
   *
   * @param {string} safeAccountId - The Safe account entity ID.
   * @returns {Promise<object|null>} Configuration or null on error.
   */
  async function getSafeConfiguration(safeAccountId) {
    try {
      const response = await fetch(`/safe-accounts/${safeAccountId}/configuration`, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin'
      });

      if (!response.ok) {
        const errorData = await response.json();
        console.error('[Safe Deployment] Configuration fetch failed:', errorData.error || response.status);
        return null;
      }

      return response.json();
    } catch (error) {
      console.error('[Safe Deployment] Configuration fetch error:', error);
      return null;
    }
  }

  /**
   * Update Safe account entity in Drupal after deployment.
   *
   * @param {string} safeAccountId - The Safe account entity ID.
   * @param {string} safeAddress - The deployed Safe address.
   * @param {string} status - The new status.
   * @param {string} txHash - The deployment transaction hash.
   * @returns {Promise<object>} Update result.
   */
  async function updateSafeAccountEntity(safeAccountId, safeAddress, status, txHash) {
    try {
      const tokenResponse = await fetch('/session/token?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-cache'
      });

      if (!tokenResponse.ok) {
        throw new Error('Failed to retrieve CSRF token');
      }

      const csrfToken = await tokenResponse.text();

      const response = await fetch(`/safe-accounts/${safeAccountId}/update-deployment`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          safe_address: safeAddress,
          status: status,
          deployment_tx_hash: txHash
        })
      });

      const responseData = await response.json();

      if (!response.ok) {
        return {
          success: false,
          error: responseData.error || `HTTP error! status: ${response.status}`
        };
      }

      return responseData;
    } catch (error) {
      console.error('[Safe Deployment] Entity update error:', error);
      return { success: false, error: error.message };
    }
  }

  // UI Helper Functions

  function showLoadingMessage(message) {
    if (typeof Drupal !== 'undefined' && Drupal.message) {
      removeLoadingMessage();
      Drupal.message({ text: message, type: 'warning', id: 'safe-deployment-loading' });
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
      const messages = document.querySelectorAll('.messages[data-drupal-message-id="safe-deployment-loading"]');
      messages.forEach((msg) => msg.remove());
    }
  }

  function updatePageStatus(safeAddress, status) {
    const statusElements = document.querySelectorAll('.safe-status');
    statusElements.forEach((element) => {
      element.textContent = status;
      element.className = `safe-status safe-status-${status}`;
    });

    const addressElements = document.querySelectorAll('.safe-address');
    addressElements.forEach((element) => {
      element.textContent = safeAddress;
      element.title = safeAddress;
    });

    const deployButtons = document.querySelectorAll('.safe-deploy-button');
    deployButtons.forEach((button) => {
      button.style.display = 'none';
    });
  }

  function updateDeploymentStep(stepNumber, status, message, details = '') {
    const step = document.getElementById(`step-${stepNumber}`);
    if (!step) return;

    step.setAttribute('data-status', status);

    const icon = step.querySelector('.step-icon');
    const text = step.querySelector('.step-text');
    const detailsEl = step.querySelector('.step-details');

    if (status === 'completed') {
      icon.textContent = '\u2705';
    } else if (status === 'in-progress') {
      icon.textContent = '\u23F3';
    } else if (status === 'error') {
      icon.textContent = '\u274C';
    }

    if (message) {
      text.textContent = message;
    }

    if (details && detailsEl) {
      detailsEl.innerHTML = details;
    }
  }

  function getCurrentStep() {
    for (let i = 1; i <= 4; i++) {
      const step = document.getElementById(`step-${i}`);
      if (step && step.getAttribute('data-status') === 'in-progress') {
        return i;
      }
    }
    return 1;
  }

  /**
   * Behavior for deployment trigger from AJAX form submission.
   */
  Drupal.behaviors.safeDeploymentTrigger = {
    attach: function (context, settings) {
      if (settings.safeSmartAccounts?.triggerDeployment) {
        console.log('[Safe Deployment] Trigger detected');

        delete settings.safeSmartAccounts.triggerDeployment;

        const step1 = document.getElementById('step-1');
        if (step1) {
          step1.setAttribute('data-status', 'completed');
          const icon = step1.querySelector('.step-icon');
          const text = step1.querySelector('.step-text');
          if (icon) icon.textContent = '\u2705';
          if (text) text.textContent = 'Safe account configuration saved';
        }

        document.dispatchEvent(
          new CustomEvent('safeAccountCreated', {
            detail: {
              safeAccountId: settings.safeSmartAccounts.safeAccountId,
              userId: settings.safeSmartAccounts.userId
            }
          })
        );
      }
    }
  };

  /**
   * Listen for Safe account creation event.
   */
  document.addEventListener('safeAccountCreated', async function (event) {
    console.log('[Safe Deployment] Safe account created, starting deployment...', event.detail);

    const safeAccountId = event.detail.safeAccountId;
    const userId = event.detail.userId;

    if (!safeAccountId || !userId) {
      console.error('[Safe Deployment] Missing required data');
      updateDeploymentStep(2, 'error', 'Missing required information');
      return;
    }

    await deployCreatedSafe(safeAccountId, userId);
  });

  // Export helper functions for other modules
  Drupal.safeDeployment = Drupal.safeDeployment || {};
  Drupal.safeDeployment.updateDeploymentStep = updateDeploymentStep;
  Drupal.safeDeployment.getCurrentStep = getCurrentStep;
  Drupal.safeDeployment.getSafeConfiguration = getSafeConfiguration;
  Drupal.safeDeployment.updateSafeAccountEntity = updateSafeAccountEntity;

})(Drupal, drupalSettings);
