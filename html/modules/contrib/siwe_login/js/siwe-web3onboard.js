/**
 * @file
 * SIWE Login with Web3Onboard integration.
 */

/**
 * Initialize Web3Onboard with configured options.
 */
async function initOnboard(config) {
  // Dynamic imports from esm.sh CDN
  const { default: Onboard } = await import('https://esm.sh/@web3-onboard/core@2');

  const wallets = [];

  // Add injected wallets if enabled
  if (config.injectedWalletsEnabled) {
    const { default: injectedModule } = await import('https://esm.sh/@web3-onboard/injected-wallets@2');
    wallets.push(injectedModule());
  }

  // Add WalletConnect if enabled
  if (config.walletConnectEnabled && config.walletConnectProjectId) {
    const { default: walletConnectModule } = await import('https://esm.sh/@web3-onboard/walletconnect@2');
    wallets.push(walletConnectModule({
      projectId: config.walletConnectProjectId,
      requiredChains: [1],
      dappUrl: window.location.origin,
    }));
  }

  // Default to Ethereum mainnet
  // Note: We intentionally omit ensAddress to prevent Web3Onboard from
  // attempting automatic ENS resolution, which can fail for addresses
  // without reverse records. We handle ENS lookup manually in authenticate().
  const chains = [
    {
      id: '0x1',
      token: 'ETH',
      label: 'Ethereum Mainnet',
      rpcUrl: 'https://rpc.ankr.com/eth',
      // Prevent automatic ENS lookups by Web3Onboard
      blockExplorerUrl: 'https://etherscan.io',
    },
  ];

  return Onboard({
    wallets,
    chains,
    appMetadata: {
      name: config.appName || 'Drupal SIWE',
      icon: config.appIcon || '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="#627EEA"/></svg>',
      description: 'Sign in with your Ethereum wallet',
    },
    theme: config.theme || 'system',
    accountCenter: {
      desktop: { enabled: false },
      mobile: { enabled: false },
    },
    connect: {
      autoConnectLastWallet: false,
      disableUDResolution: true,
    },
  });
}

/**
 * Generate SIWE message (EIP-4361 format).
 */
function createSiweMessage(params) {
  const { domain, address, statement, uri, version, chainId, nonce, issuedAt, resources } = params;

  let message = `${domain} wants you to sign in with your Ethereum account:\n`;
  message += `${address}\n\n`;

  if (statement) {
    message += `${statement}\n\n`;
  }

  message += `URI: ${uri}\n`;
  message += `Version: ${version}\n`;
  message += `Chain ID: ${chainId}\n`;
  message += `Nonce: ${nonce}\n`;
  message += `Issued At: ${issuedAt}`;

  if (resources && resources.length > 0) {
    message += `\nResources:`;
    resources.forEach(resource => {
      message += `\n- ${resource}`;
    });
  }

  return message;
}

/**
 * Main SIWE authentication flow.
 */
async function authenticate(onboard) {
  // Import ethers for provider handling
  const { BrowserProvider, getAddress } = await import('https://esm.sh/ethers@6');

  // Connect wallet via Web3Onboard modal
  const wallets = await onboard.connectWallet();

  if (!wallets || wallets.length === 0) {
    throw new Error('No wallet connected');
  }

  const wallet = wallets[0];
  const address = wallet.accounts[0].address;
  const chainId = parseInt(wallet.chains[0].id, 16);

  // Get checksum address
  const checksumAddress = getAddress(address);

  // Get ethers provider from wallet
  const provider = new BrowserProvider(wallet.provider);
  const signer = await provider.getSigner();

  // Perform ENS lookup (with timeout and better error handling)
  let ensName = null;
  try {
    // Add timeout to prevent hanging on slow RPC calls
    const lookupPromise = provider.lookupAddress(address);
    const timeoutPromise = new Promise((_, reject) =>
      setTimeout(() => reject(new Error('ENS lookup timeout')), 5000)
    );

    ensName = await Promise.race([lookupPromise, timeoutPromise]);

    if (ensName) {
      // Verify the reverse resolution matches
      const resolvedAddress = await provider.resolveName(ensName);
      if (resolvedAddress?.toLowerCase() !== address.toLowerCase()) {
        console.warn('ENS reverse resolution mismatch, ignoring');
        ensName = null;
      }
    }
  } catch (e) {
    // ENS lookup failures are non-critical - the address doesn't have a reverse record,
    // the RPC doesn't support ENS, or there's a network issue
    console.debug('ENS lookup skipped (no reverse record or network issue):', e.message || e);
  }

  // Get nonce from server
  const nonceResponse = await fetch('/siwe/nonce');
  const { nonce } = await nonceResponse.json();

  // Create SIWE message
  const messageParams = {
    domain: window.location.host,
    address: checksumAddress,
    statement: 'Sign in with Ethereum to Drupal',
    uri: window.location.origin,
    version: '1',
    chainId: chainId,
    nonce: nonce,
    issuedAt: new Date().toISOString(),
  };

  if (ensName) {
    messageParams.resources = [`ens:${ensName}`];
  }

  const message = createSiweMessage(messageParams);

  // Sign the message
  const signature = await signer.signMessage(message);

  // Verify with server
  const verifyResponse = await fetch('/siwe/verify', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      message: message,
      signature: signature,
      address: address,
      nonce: nonce,
    }),
  });

  const result = await verifyResponse.json();

  // Disconnect wallet from Web3Onboard
  await onboard.disconnectWallet({ label: wallet.label });

  return result;
}

/**
 * Drupal behavior for SIWE login with Web3Onboard.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.siweLoginWeb3Onboard = {
    attach: async function (context, settings) {
      const config = settings.siweLogin?.web3onboard;

      if (!config?.enabled) {
        return;
      }

      once('siwe-login-web3onboard', '#siwe-login-button', context).forEach(async (button) => {
        let onboard;

        try {
          onboard = await initOnboard(config);
        } catch (error) {
          console.error('Failed to initialize Web3Onboard:', error);
          return;
        }

        button.addEventListener('click', async function (e) {
          e.preventDefault();

          const originalText = button.textContent;
          button.disabled = true;
          button.textContent = 'Connecting...';

          try {
            const result = await authenticate(onboard);

            if (result.success) {
              if (result.redirect) {
                window.location.href = result.redirect;
              } else {
                window.location.reload();
              }
            } else {
              throw new Error(result.message || 'Authentication failed');
            }
          } catch (error) {
            console.error('SIWE authentication failed:', error);
            // Show user-friendly error
            if (error.message !== 'No wallet connected') {
              alert('Authentication failed: ' + error.message);
            }
          } finally {
            button.disabled = false;
            button.textContent = originalText;
          }
        });
      });
    },
  };

})(Drupal, drupalSettings, once);
