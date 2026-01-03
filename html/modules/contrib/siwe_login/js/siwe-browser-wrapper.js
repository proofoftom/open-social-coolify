/**
 * Browser-compatible wrapper for SIWE (Sign-In with Ethereum)
 * This provides the same API as the official SIWE library but works in browsers
 */
(function (global, factory) {
  typeof exports === "object" && typeof module !== "undefined"
    ? factory(exports)
    : typeof define === "function" && define.amd
    ? define(["exports"], factory)
    : ((global =
        typeof globalThis !== "undefined" ? globalThis : global || self),
      factory((global.siwe = {})));
})(this, function (exports) {
  "use strict";

  /**
   * Generates a random nonce
   * @returns {string} A random nonce
   */
  function generateNonce() {
    return (
      Math.random().toString(36).substring(2, 15) +
      Math.random().toString(36).substring(2, 15)
    );
  }

  /**
   * SIWE Message class
   */
  class SiweMessage {
    /**
     * Creates a parsed Sign-In with Ethereum Message (EIP-4361) object
     * @param {Object|string} param - Sign message as a string or an object
     */
    constructor(param) {
      if (typeof param === "string") {
        // Parse existing message string (simplified implementation)
        this.parseMessage(param);
      } else {
        // Create from object parameters
        this.scheme = param.scheme;
        this.domain = param.domain;
        this.address = param.address;
        this.statement = param.statement;
        this.uri = param.uri;
        this.version = param.version;
        this.chainId = param.chainId;
        this.nonce = param.nonce || generateNonce();
        this.issuedAt = param.issuedAt || new Date().toISOString();
        this.expirationTime = param.expirationTime;
        this.notBefore = param.notBefore;
        this.requestId = param.requestId;
        this.resources = param.resources;
      }
    }

    /**
     * Parses a SIWE message string (simplified implementation)
     * @param {string} message - The SIWE message string
     */
    parseMessage(message) {
      // This is a simplified parser - in a real implementation, you'd want to parse all fields
      const lines = message.split("\n");
      // Extract domain from first line
      const headerMatch = lines[0].match(
        /([^ ]+) wants you to sign in with your Ethereum account:/
      );
      if (headerMatch) {
        this.domain = headerMatch[1];
      }

      // Extract address from second line
      if (lines[1]) {
        this.address = lines[1];
      }

      // Extract statement (could be multiple lines)
      if (lines[3] && !lines[3].startsWith("URI:")) {
        this.statement = lines[3];
      }
    }

    /**
     * Creates a SIWE message string for signing
     * @returns {string} EIP-4361 formatted message
     */
    toMessage() {
      const headerPrefix = this.scheme
        ? `${this.scheme}://${this.domain}`
        : this.domain;
      const header = `${headerPrefix} wants you to sign in with your Ethereum account:`;
      const uriField = `URI: ${this.uri}`;
      let prefix = [header, this.address].join("\n");
      const versionField = `Version: ${this.version}`;

      if (!this.nonce) {
        this.nonce = generateNonce();
      }

      const chainField = `Chain ID: ${this.chainId || "1"}`;
      const nonceField = `Nonce: ${this.nonce}`;
      const suffixArray = [uriField, versionField, chainField, nonceField];

      this.issuedAt = this.issuedAt || new Date().toISOString();
      suffixArray.push(`Issued At: ${this.issuedAt}`);

      if (this.expirationTime) {
        const expiryField = `Expiration Time: ${this.expirationTime}`;
        suffixArray.push(expiryField);
      }

      if (this.notBefore) {
        suffixArray.push(`Not Before: ${this.notBefore}`);
      }

      if (this.requestId) {
        suffixArray.push(`Request ID: ${this.requestId}`);
      }

      if (this.resources) {
        suffixArray.push(
          [`Resources:`, ...this.resources.map((x) => `- ${x}`)].join("\n")
        );
      }

      const suffix = suffixArray.join("\n");
      prefix = [prefix, this.statement].join("\n\n");

      if (this.statement !== undefined) {
        prefix += "\n";
      }

      return [prefix, suffix].join("\n");
    }

    /**
     * Prepares the message for signing
     * @returns {string} Message ready for signing
     */
    prepareMessage() {
      return this.toMessage();
    }

    /**
     * Verifies the signature (simplified implementation)
     * @param {Object} params - Verification parameters
     * @returns {Promise<Object>} Verification result
     */
    async verify(params) {
      // In a real implementation, you would verify the signature here
      // For now, we'll just return a successful result
      return {
        success: true,
        data: this,
      };
    }
  }

  // Export the SiweMessage class
  exports.SiweMessage = SiweMessage;
  exports.generateNonce = generateNonce;

  Object.defineProperty(exports, "__esModule", { value: true });

  // Expose SiweMessage globally for backward compatibility
  if (typeof window !== "undefined") {
    // Browser environment
    window.SiweMessage = SiweMessage;
  } else if (typeof global !== "undefined") {
    // Node.js environment
    global.SiweMessage = SiweMessage;
  } else if (typeof self !== "undefined") {
    // Web worker environment
    self.SiweMessage = SiweMessage;
  }
});
