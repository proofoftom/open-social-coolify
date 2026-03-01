# SIWE Authentication for Drupal

## Overview

This module provides Ethereum wallet-based authentication for Drupal using the Sign-In with Ethereum (SIWE) standard. It supports multiple authentication flows, email verification, ENS validation, and extensible integration with other modules.

## Features

### 🔐 **Core Authentication**
- **SIWE Standard Compliance**: Full EIP-4361 implementation
- **Multiple Wallet Support**: MetaMask, WalletConnect, and other Web3 wallets
- **Nonce-based Security**: Replay attack prevention with configurable TTL
- **Domain Validation**: Prevents cross-domain authentication attacks

### 📧 **Optional Verification Flows**
- **Email Verification**: Optional email verification for new users
- **Username Creation**: Custom username creation for users without ENS names
- **ENS Validation**: Optional ENS name validation against Ethereum mainnet
- **Multi-step Authentication**: Seamless flow through verification steps

### 🔧 **Extensibility & Integration**
- **Hook System**: `hook_siwe_login_response_alter()` for other modules to customize responses
- **Clean Architecture**: No hard dependencies, loose coupling with other modules
- **Session Management**: Configurable session timeouts and security settings
- **Field Management**: Automatic user field creation and management

## Requirements

- Drupal 10.0 or higher
- PHP 8.1 or higher  
- GMP extension for cryptographic operations
- Composer

## Installation

### **DDEV Setup**

If using DDEV, ensure the GMP extension is installed by adding to `.ddev/config.yaml`:

```yaml
webimage_extra_packages: [php8.3-gmp]
```

Then restart DDEV:

```shell
ddev restart
```

### **Method 1: Using Composer (Recommended)**

1. Add the module to your project:

```shell
composer require drupal/siwe_login
```

2. Enable the module:

```shell
drush en siwe_login -y
```

### **Method 2: Manual Installation (Development)**

1. Clone into custom modules directory:

```shell
cd web/modules/custom
git clone https://github.com/proofoftom/drupal_siwe_login siwe_login
```

2. Install dependencies:

```shell
composer require kornrunner/keccak:^1.0 simplito/elliptic-php:^1.0 web3p/web3.php:^0.3.2
```

3. Enable the module:

```shell
drush en siwe_login -y
```

### **Optional Configuration Import**

```shell
drush config-import --partial --source=modules/custom/siwe_login/config/install
```

## Configuration

Configure at `/admin/config/people/siwe` or through admin menu: **Configuration → People → SIWE Login**

### **Core Settings**
- **Nonce TTL**: Time-to-live for authentication nonces (default: 300 seconds)
- **Message TTL**: Time-to-live for SIWE messages (default: 600 seconds)  
- **Session Timeout**: User session duration (default: 24 hours)
- **Expected Domain**: Domain validation for SIWE messages

### **Optional Features**
- **Require Email Verification**: Force email verification for new users
- **Require ENS or Username**: Require username if no ENS name available
- **Ethereum Provider URL**: RPC provider for ENS validation (Alchemy, Infura, etc.)

## Authentication Flows

### **1. Direct SIWE Flow**
```
User Signs Message → Signature Verified → User Authenticated → JSON Response with Redirect
```

### **2. Email Verification Flow**
```
User Signs Message → Email Required → Verification Email Sent → User Clicks Link → Authenticated → Redirect
```

### **3. Username Creation Flow**  
```
User Signs Message → No ENS Name → Username Form → Username Created → Authenticated → Redirect
```

### **4. Combined Flow**
```
User Signs Message → Email Verification → Username Creation → Authenticated → Redirect
```

## API Endpoints

- `GET /siwe/nonce` - Generate authentication nonce
- `POST /siwe/verify` - Verify SIWE message and authenticate user
- `GET /siwe/email-verification` - Email verification form
- `POST /siwe/email-verification` - Process email verification
- `GET /siwe/email-verify/{uid}/{timestamp}/{hash}` - Email verification confirmation
- `GET /siwe/create-username` - Username creation form
- `POST /siwe/create-username` - Process username creation

## Extensibility

### **Hook System for Other Modules**

The module provides `hook_siwe_login_response_alter()` for other modules to customize authentication responses:

```php
/**
 * Implements hook_siwe_login_response_alter().
 */
function my_module_siwe_login_response_alter(array &$response_data, UserInterface $user) {
  // Add custom redirect URL
  $response_data['redirect'] = '/custom/dashboard';
  
  // Add additional user data
  $response_data['custom_data'] = [
    'role' => $user->getRoles(),
    'last_login' => $user->getLastLoginTime(),
  ];
}
```

### **Integration Example: Safe Smart Accounts**

The Safe Smart Accounts module uses this hook to redirect users to their Safe account management interface after SIWE authentication:

```php
function safe_smart_accounts_siwe_login_response_alter(array &$response_data, UserInterface $account) {
  $redirect_url = safe_smart_accounts_get_user_redirect_url($account);
  if ($redirect_url) {
    $response_data['redirect'] = $redirect_url->toString();
  }
}
```

## Security Features

### **Message Validation**
- **EIP-191 Standard**: Structured message signing
- **Nonce Verification**: Prevents replay attacks
- **Domain Binding**: Validates expected domain  
- **Timestamp Validation**: Prevents expired message usage
- **Address Recovery**: Cryptographic signature verification

### **Session Security**
- **Configurable Timeouts**: Customizable session duration
- **Secure Storage**: Proper session token management
- **Access Control**: Integration with Drupal permissions system
- **CSRF Protection**: Built-in cross-site request forgery protection

### **ENS Validation** (Optional)
When ENS validation is enabled:
- **Forward Resolution**: ENS name → Ethereum address
- **Reverse Resolution**: Ethereum address → ENS name  
- **Consistency Check**: Ensures bidirectional resolution matches
- **Mainnet Validation**: Uses Ethereum mainnet ENS contracts

## User Fields

The module automatically creates and manages these user fields:

### **Required Fields**
- `field_ethereum_address` (string): User's Ethereum address
  - Stores checksummed address with 0x prefix
  - Unique constraint prevents duplicate addresses
  - Updated on each successful authentication

### **Optional Fields**  
- `field_ens_name` (string): User's ENS name
  - Stores validated ENS name (e.g., "vitalik.eth")
  - Only populated when ENS validation is enabled
  - Updated when ENS name changes

## JavaScript Integration

### **Frontend Requirements**
The module requires these JavaScript libraries:
- **ethers.js v6+**: Ethereum wallet interaction
- **@spruceid/siwe-parser**: SIWE message parsing

### **Browser Integration**
```javascript
// SIWE authentication is handled automatically
// Custom integration can listen for authentication events
document.addEventListener('siweAuthenticated', function(event) {
  const userData = event.detail;
  console.log('User authenticated:', userData);
});
```

## Development & Testing

### **Local Development**
```shell
# Start DDEV environment
ddev start

# Enable module with dependencies
ddev drush en siwe_login -y

# Configure for local development
ddev drush config:set siwe_login.settings expected_domain "drupal-project.ddev.site"

# Clear caches
ddev drush cache:rebuild
```

### **Testing Checklist**
- ✅ Direct SIWE authentication flow
- ✅ Email verification flow (when enabled)
- ✅ Username creation flow (when enabled)  
- ✅ ENS validation (when configured)
- ✅ Session timeout behavior
- ✅ Permission integration
- ✅ Multi-device authentication
- ✅ Error handling and edge cases

### **Debug Mode**
Enable verbose logging in `settings.php`:
```php
$config['system.logging']['error_level'] = 'verbose';
```

View authentication logs:
```shell
ddev drush watchdog:show --type=siwe_login
```

## Performance Considerations

### **Caching Strategy**
- **Nonce Caching**: Short-term cache for authentication nonces
- **ENS Caching**: Long-term cache for ENS resolution results
- **Session Optimization**: Efficient session storage and retrieval

### **Optimization Settings**
```yaml
# Recommended production settings
nonce_ttl: 300          # 5 minutes
message_ttl: 600         # 10 minutes  
session_timeout: 86400   # 24 hours
cache_ttl: 3600         # 1 hour ENS cache
```

## Troubleshooting

### **Common Issues**

**"GMP extension required" Error**
```shell
# Install GMP extension
sudo apt-get install php-gmp
# or for DDEV
echo "webimage_extra_packages: [php8.3-gmp]" >> .ddev/config.yaml && ddev restart
```

**MetaMask Connection Issues**
- Ensure website is served over HTTPS in production
- Check browser console for Web3 provider errors
- Verify MetaMask is unlocked and connected to correct network

**ENS Validation Failing**
- Verify Ethereum provider URL is correctly configured
- Check API rate limits with your provider (Alchemy/Infura)
- Ensure ENS name resolves correctly on Ethereum mainnet

**Authentication Not Redirecting**
- Check if other modules implement `hook_siwe_login_response_alter()`
- Verify JavaScript is loading correctly
- Check browser network tab for API response errors

### **Debug Commands**
```shell
# Check module status
ddev drush pm:list --filter=siwe

# View configuration
ddev drush config:get siwe_login.settings

# Check user fields
ddev drush field:info user

# View authentication logs
ddev drush watchdog:show --type=siwe_login --count=20
```

## Contributing

### **Development Guidelines**
1. **Follow Drupal coding standards**
2. **Test all authentication flows** 
3. **Maintain backward compatibility**
4. **Document API changes**
5. **Update validation checklists**

### **Architecture Principles**
- **Modular Design**: Clean separation of concerns
- **Extensible Hooks**: Allow other modules to integrate
- **Security First**: Comprehensive validation and sanitization
- **Performance Aware**: Efficient caching and optimization

## Support & Issues

- **Issue Queue**: [GitHub Issues](https://github.com/proofoftom/drupal_siwe_login/issues)
- **Documentation**: This README and inline code documentation
- **Community**: Drupal SIWE authentication community discussions

## License

This project is licensed under the GPL v2 or later - see the LICENSE file for details.

## Related Modules

- **Safe Smart Accounts**: Integrates with this module for Safe Smart Account management
- **Web3 Integration**: Other Ethereum-based Drupal modules
- **Decentralized Identity**: DID and verifiable credentials modules
