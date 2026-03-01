# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Drupal module (siwe_login) that provides Ethereum wallet-based authentication using the Sign-In with Ethereum (SIWE / EIP-4361) standard. It supports multiple authentication flows including optional email verification, username creation, and ENS name validation.

## Development Commands

```shell
# Enable the module
ddev drush en siwe_login -y

# Clear caches after changes
ddev drush cache:rebuild

# View module configuration
ddev drush config:get siwe_login.settings

# Set expected domain for local dev
ddev drush config:set siwe_login.settings expected_domain "your-domain.ddev.site"

# View authentication logs
ddev drush watchdog:show --type=siwe_login --count=20

# Import configuration changes
ddev drush config-import --partial --source=modules/custom/siwe_login/config/install
```

## Architecture

### Service Layer (`src/Service/`)

The module uses a layered service architecture:

- **SiweAuthService** (`siwe_login.auth_service`): Main orchestrator for authentication. Generates nonces, coordinates message validation and user management, checks verification requirements.

- **SiweMessageValidator** (`siwe_login.message_validator`): Handles all SIWE message validation including:
  - EIP-191 signature verification using secp256k1 elliptic curve cryptography
  - Nonce validation (stored in cache with TTL)
  - Domain validation (supports multiple comma-separated domains)
  - Timestamp validation with 30-second clock skew tolerance
  - Optional ENS name validation via Ethereum RPC

- **EthereumUserManager** (`siwe_login.user_manager`): Manages Drupal user accounts linked to Ethereum addresses. Creates users with ENS names or generated usernames (`eth_` + first 8 chars of address).

### Controller Layer (`src/Controller/`)

- **SiweAuthController**: Handles `/siwe/nonce` and `/siwe/verify` endpoints. The verify endpoint orchestrates the multi-step authentication flow, checking if email verification or username creation is required before completing authentication.

- **EmailVerificationController**: Handles email verification confirmation links.

### Authentication Flow

1. Client requests nonce from `GET /siwe/nonce`
2. Client signs SIWE message with wallet
3. Client submits to `POST /siwe/verify` with message, signature, address
4. Server validates message structure, signature, timestamps, nonce, domain
5. Depending on config, may redirect to `/siwe/email-verification` or `/siwe/create-username`
6. On success, creates/finds user and calls `user_login_finalize()`

### Hook System

Other modules can alter authentication responses via `hook_siwe_login_response_alter()`:

```php
function mymodule_siwe_login_response_alter(array &$response_data, UserInterface $user) {
  $response_data['redirect'] = '/custom/path';
}
```

### Key Dependencies

PHP libraries (installed via Composer):
- `kornrunner/keccak` - Keccak-256 hashing for Ethereum address derivation
- `simplito/elliptic-php` - secp256k1 curve for signature verification
- `web3p/web3.php` - Ethereum RPC communication for ENS resolution

System requirement: PHP GMP extension for cryptographic operations.

### Configuration

Settings stored in `siwe_login.settings`:
- `nonce_ttl`: Nonce validity period (default: 300s)
- `message_ttl`: Message validity period (default: 600s)
- `expected_domain`: Domain(s) to validate (comma-separated for multiple)
- `require_email_verification`: Enable email verification flow
- `require_ens_or_username`: Require username if no ENS name
- `ethereum_provider_url`: RPC endpoint for ENS validation

### User Fields

The module manages `field_ethereum_address` on user entities to store the checksummed Ethereum address.