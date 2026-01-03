(function (Drupal, drupalSettings) {
  "use strict";

  Drupal.behaviors.siweLogin = {
    attach: function (context, settings) {
      const button = context.querySelector("#siwe-login-button");

      if (!button) return;

      button.addEventListener("click", async function () {
        try {
          // Check for Web3 provider
          if (typeof window.ethereum === "undefined") {
            throw new Error("Please install MetaMask or another Web3 wallet");
          }

          // Request account access
          const accounts = await window.ethereum.request({
            method: "eth_requestAccounts",
          });
          const address = accounts[0];
          const checksumAddress = ethers.getAddress(address);

          // Create ethers provider from window.ethereum
          const provider = new ethers.BrowserProvider(window.ethereum);

          // Lookup ENS name
          let ensName = null;

          try {
            // Lookup ENS name for the address
            ensName = await provider.lookupAddress(address);

            // Verify forward resolution if we got an ENS name
            if (ensName) {
              const resolvedAddress = await provider.resolveName(ensName);
              // Convert both addresses to checksum format for comparison
              const checksumAddress = ethers.getAddress(address);
              const checksumResolvedAddress =
                ethers.getAddress(resolvedAddress);

              // If verification fails, reset to null
              if (checksumResolvedAddress !== checksumAddress) {
                ensName = null;
              }
            }
          } catch (ensError) {
            // ENS lookup failed, continue without ENS name
            console.warn("ENS lookup failed:", ensError);
          }

          // Get nonce from server
          const nonceResponse = await fetch("/siwe/nonce");
          const { nonce } = await nonceResponse.json();
          const chainId = await window.ethereum.request({
            method: "eth_chainId",
          });

          // Prepare SIWE message parameters
          const messageParams = {
            domain: window.location.host,
            address: checksumAddress,
            statement: "Sign in with Ethereum to Drupal",
            uri: window.location.origin,
            version: "1",
            chainId: parseInt(chainId, 16),
            nonce: nonce,
            issuedAt: new Date().toISOString(),
          };

          // Add ENS name as resource if available
          if (ensName) {
            messageParams.resources = [`ens:${ensName}`];
          }

          // Create SIWE message
          const message = new SiweMessage(messageParams);
          const preparedMessage = message.prepareMessage();

          // Sign message using ethers.js (similar to Next.js implementation)
          const signer = await provider.getSigner();
          const signature = await signer.signMessage(preparedMessage);

          // Verify with server
          const verifyResponse = await fetch("/siwe/verify", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
            body: JSON.stringify({
              message: preparedMessage,
              signature: signature,
              address: address,
              nonce: nonce,
            }),
          });

          const result = await verifyResponse.json();

          if (result.success) {
            // Handle redirect if provided (for email verification)
            if (result.redirect) {
              window.location.href = result.redirect;
            } else {
              // Redirect or update UI
              window.location.reload();
            }
          }
        } catch (error) {
          console.error("SIWE authentication failed:", error);
        }
      });
    },
  };
})(Drupal, drupalSettings);
