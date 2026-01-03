# SIWE Login Module - Context Information

## Project Overview

This is a Drupal module that provides Ethereum wallet-based authentication using the Sign-In with Ethereum (SIWE) standard. The module allows users to authenticate with their Ethereum wallets instead of traditional username/password combinations.

### Key Features

- Ethereum wallet-based authentication using SIWE standard
- Nonce-based replay attack prevention
- Email verification for new users (optional)
- ENS name validation against Ethereum mainnet (optional)
- Username creation for users without ENS names (optional)
- Configurable session timeouts
- Integration with Drupal's user system

### Technologies Used

- **Drupal 10/11**: Core CMS framework
- **PHP 8.1+**: Server-side language
- **JavaScript**: Client-side implementation
- **Ethereum Libraries**:
  - `kornrunner/keccak`: Keccak-256 hashing
  - `simplito/elliptic-php`: Elliptic curve cryptography
  - `web3p/web3.php`: Ethereum interaction
- **Frontend Libraries**:
  - `ethers.js`: Web3 provider interaction
  - jQuery: Drupal integration

## Module Architecture

### Core Components

1. **Authentication Service** (`SiweAuthService`): Main authentication logic
2. **Message Validator** (`SiweMessageValidator`): Validates SIWE messages and signatures
3. **User Manager** (`EthereumUserManager`): Manages Ethereum-based user accounts
4. **Controllers**:
   - `SiweAuthController`: Handles nonce generation and verification endpoints
   - `EmailVerificationController`: Handles email verification confirmation
5. **Forms**:
   - `EmailVerificationForm`: Collects email for new users
   - `UsernameCreationForm`: Collects username for users without ENS names
   - `SiweSettingsForm`: Admin configuration form
6. **Blocks**: `SiweLoginBlock` - Displays login button
7. **Mail Plugin**: `SiweMail` - Handles email sending

### API Endpoints

- `GET /siwe/nonce` - Generates authentication nonce
- `POST /siwe/verify` - Verifies SIWE message and authenticates user
- `GET /siwe/email-verification` - Email verification form
- `GET /siwe/email-verification/{uid}/{timestamp}/{hash}` - Email verification confirmation
- `GET /siwe/create-username` - Username creation form
- `GET /admin/config/people/siwe` - Admin settings page

## Configuration Options

- **Nonce TTL**: Time-to-live for nonces in seconds (default: 300)
- **Message TTL**: Time-to-live for SIWE messages in seconds (default: 600)
- **Require Email Verification**: Require email verification for new users
- **Require ENS or Username**: Require users to set a username if they don't have an ENS name
- **Session Timeout**: Session timeout in hours (default: 24 hours)
- **Enable ENS Validation**: Enable validation that ENS names resolve to signing addresses
- **Ethereum Provider URL**: URL for the Ethereum RPC provider (Alchemy, Infura, etc.)

## Development Workflow

### Installation

1. Install dependencies:
   ```bash
   composer require kornrunner/keccak:^1.0 simplito/elliptic-php:^1.0 web3p/web3.php:^0.3.2
   ```
2. Enable the module:
   ```bash
   drush en siwe_login -y
   ```

### Building and Running

This is a Drupal module that runs within a Drupal installation. There are no separate build steps required beyond standard Drupal module installation.

### Testing

Drupal's testing framework can be used for testing. Run tests with:
```bash
php ./core/scripts/run-tests.sh --module siwe_login
```

## Key Implementation Details

### Authentication Flow

1. User clicks "Sign in with Ethereum" button
2. Module generates a nonce and stores it in cache
3. User signs a SIWE message with their Ethereum wallet
4. Signature is verified using cryptographic libraries
5. If valid:
   - If email verification is required and user doesn't exist or lacks verified email, redirect to email verification form
   - If username creation is required and user doesn't have ENS name, redirect to username creation form
   - Otherwise, create or update user account and log them in

### Security Features

- Uses EIP-191 message signing standard
- Implements nonce-based replay attack prevention
- Configurable token expiration
- Email verification for new users when enabled
- ENS name validation against Ethereum mainnet when provided

### Field Requirements

The module requires the following fields to be configured for the user entity:
- `field_ethereum_address` - Stores the Ethereum address associated with the user account
- `field_ens_name` (optional) - Stores the ENS name associated with the user account

These fields are automatically created during module installation.

## Directory Structure

```
siwe_login/
├── config/                 # Configuration files
│   ├── install/            # Default configuration
│   └── schema/             # Configuration schema
├── css/                    # Stylesheets
├── js/                     # JavaScript files
├── src/                    # PHP source code
│   ├── Controller/         # API controllers
│   ├── Exception/          # Custom exceptions
│   ├── Form/               # Drupal forms
│   ├── Plugin/             # Drupal plugins (Block, Mail)
│   └── Service/            # Business logic services
├── siwe_login.info.yml     # Module metadata
├── siwe_login.module       # Module hooks
├── siwe_login.install      # Installation hooks
├── siwe_login.libraries.yml # Asset libraries
├── siwe_login.links.menu.yml # Menu links
├── siwe_login.permissions.yml # Permissions
├── siwe_login.routing.yml  # URL routing
└── siwe_login.services.yml # Dependency injection services
```