# WaaP Login

Wallet as a Protocol (WaaP) authentication for Drupal, powered by Human.tech.

## Overview

The WaaP Login module provides seamless Web3 authentication for Open Social communities using Human.tech's Wallet as a Protocol SDK. It enables users to authenticate using email, social accounts, or wallet connections through a unified interface, offering a user-friendly alternative to traditional Web3 authentication methods.

WaaP Login is designed to coexist with the existing SIWE Login module, sharing the same Ethereum address field while providing a different authentication approach that's more accessible to users who may not already have a Web3 wallet.

## Features

- **Multi-method Authentication**: Support for email, social providers (Google, Facebook, Twitter, Discord, Coinbase, LinkedIn, Apple, GitHub), and wallet connections (MetaMask, WalletConnect)
- **Email Verification Workflow**: Optional email verification for new users to ensure account security
- **Username Creation Wizard**: Custom username creation for users without ENS names
- **Session Management**: Configurable session time-to-live (TTL) for secure authentication
- **Integration with SIWE Login**: Shares the `field_ethereum_address` field, allowing both authentication methods to coexist
- **Configurable Authentication Methods**: Enable/disable specific authentication methods as needed
- **Dark Mode Support**: Built-in dark mode for seamless integration with dark-themed sites
- **Mobile-Responsive Design**: Fully responsive authentication interface
- **WalletConnect Integration**: Support for WalletConnect protocol
- **Referral Code Support**: Optional referral code functionality for user acquisition
- **Gas Tank Functionality**: Optional gas sponsorship for sponsored transactions

## Requirements

- **Drupal**: 10.x
- **PHP**: 8.1 or higher
- **siwe_login module**: Required for the `field_ethereum_address` field
- **Human.tech WaaP SDK**: Loaded via CDN (no local installation required)

## Installation

### Composer Installation

```bash
composer require drupal/waap_login:^1.0
drush en waap_login -y
drush cr
```

### Manual Installation

1. Download the module and place it in `html/modules/contrib/waap_login/`
2. Enable the module via the admin interface or Drush:
   ```bash
   drush en waap_login -y
   ```
3. Clear all caches:
   ```bash
   drush cr
   ```

## Configuration

### Accessing Settings

Navigate to `/admin/config/people/waap` to access the WaaP Login configuration page.

### WaaP SDK Settings

1. **Environment**: Choose between Production and Sandbox (staging) environments
2. **Authentication Methods**: Select which authentication methods to enable:
   - Email
   - Phone
   - Social
   - Wallet
3. **Social Providers**: Configure which social providers are available:
   - Google
   - Facebook
   - Twitter
   - Discord
   - Coinbase
   - LinkedIn
   - Apple
   - GitHub
4. **WalletConnect Project ID**: Enter your WalletConnect project ID for WalletConnect integration
5. **Dark Mode**: Enable or disable dark mode for the WaaP modal
6. **Show Secured Badge**: Display the "Secured by Human.tech" badge

### User Management Settings

1. **Require Email Verification**: Enable to require new users to verify their email address
2. **Require Username**: Enable to require new users to create a custom username
3. **Auto-create Users**: Enable to automatically create user accounts when a new wallet address is detected

### Session Management

1. **Session TTL**: Set the session time-to-live in seconds (default: 86400 = 24 hours)

### Integration Settings

1. **Referral Code**: Enter an optional referral code for user acquisition tracking
2. **Enable Gas Tank**: Enable gas sponsorship for sponsored transactions

## Usage

### For End Users

#### Login Flow

1. Click the "Login with WaaP" button on the login page or in the WaaP Login block
2. Choose your preferred authentication method from the WaaP modal:
   - **Email**: Enter your email address and complete the verification process
   - **Social**: Select a social provider (Google, Facebook, Twitter, etc.) and complete the OAuth flow
   - **Wallet**: Connect your wallet via MetaMask or WalletConnect
3. Complete the authentication flow as prompted by the WaaP SDK
4. If email verification is required, enter your email address and click the verification link sent to your inbox
5. If username creation is required, choose a unique username for your account
6. Access your account with full functionality

#### Logout Flow

1. Click the "Disconnect" button in the WaaP Login block
2. The WaaP session will be terminated and you will be logged out of Drupal

### For Developers

#### API Endpoints

##### POST /waap/verify

Authenticates a user after WaaP SDK login.

**Request Body**:
```json
{
  "address": "0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb",
  "loginType": "waap",
  "sessionData": {
    "loginMethod": "email",
    "provider": "google"
  }
}
```

**Success Response** (200):
```json
{
  "success": true,
  "user": {
    "uid": 123,
    "name": "alice.eth",
    "address": "0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb"
  }
}
```

**Redirect Response** (200):
```json
{
  "success": true,
  "redirect": "/waap/email-verification"
}
```

**Error Response** (400/401):
```json
{
  "error": "Invalid wallet address format",
  "code": "WAAP_INVALID_ADDRESS",
  "details": {
    "field": "address"
  },
  "timestamp": "2025-12-31T09:00:00Z"
}
```

##### GET /waap/status

Checks the current WaaP authentication status.

**Success Response** (200):
```json
{
  "authenticated": true,
  "user": {
    "uid": 123,
    "name": "alice.eth",
    "address": "0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb"
  },
  "waapMethod": "waap"
}
```

##### POST /waap/logout

Logs out the current WaaP session.

**Success Response** (200):
```json
{
  "success": true
}
```

##### GET /waap/email-verification/{uid}/{timestamp}/{hash}

Confirms email verification for a user account.

##### GET /waap/create-username

Displays the username creation form for users who need to set a custom username.

#### Placing the Login Block

1. Navigate to `/admin/structure/block`
2. Find the "WaaP Login" block
3. Click "Place block"
4. Select the desired region (e.g., "Sidebar First" or "Content")
5. Configure visibility settings as needed
6. Save the block placement

#### Customizing the Login Button

The login button can be customized by overriding the Twig templates:

- `templates/waap-login-button.html.twig` - Login button template
- `templates/waap-logout-button.html.twig` - Logout button template

Copy these templates to your theme and modify as needed.

## Multi-Step Authentication Flow

The WaaP Login module supports a multi-step authentication flow to improve security and user experience:

### Step 1: WaaP Authentication

The user authenticates through the WaaP SDK using their preferred method (email, social, or wallet).

### Step 2: Email Verification (Optional)

If `require_email_verification` is enabled, the user is redirected to `/waap/email-verification` to:
1. Enter their email address
2. Receive a verification email
3. Click the verification link to confirm

### Step 3: Username Creation (Optional)

If `require_username` is enabled and the user doesn't have an ENS name or custom username, they are redirected to `/waap/create-username` to:
1. Enter a unique username
2. Validate username availability
3. Save their username

### Step 4: Finalization

Once all required steps are complete, the user is logged in and can access their account.

## Troubleshooting

### Common Issues

#### WaaP SDK Not Loading

**Symptoms**: The WaaP login button appears but clicking it does nothing, or browser console shows "WaaP SDK not loaded" error.

**Solutions**:
1. Check browser console for JavaScript errors
2. Verify internet connection (the SDK is loaded from CDN)
3. Clear Drupal caches: `drush cr`
4. Check that the `waap_login` library is properly attached to the page
5. Verify CDN accessibility from your network

#### Authentication Fails

**Symptoms**: User completes WaaP authentication but is not logged in.

**Solutions**:
1. Check watchdog logs: `drush watchdog:show --type=waap_login --count=20`
2. Verify module configuration at `/admin/config/people/waap`
3. Check CSRF token validity in browser network tab
4. Ensure the `field_ethereum_address` field exists on user entities
5. Verify the siwe_login module is enabled (provides the shared field)

#### Email Verification Not Working

**Symptoms**: Users don't receive verification emails, or verification links don't work.

**Solutions**:
1. Check Drupal mail configuration at `/admin/config/system/mail`
2. Verify mail server is accessible and properly configured
3. Check spam folder in user's email client
4. Test mail functionality: `drush php-eval "\Drupal::service('plugin.manager.mail')->mail('waap_login', 'test', 'test@example.com', 'en');"`
5. Check watchdog logs for mail-related errors

#### Username Creation Issues

**Symptoms**: Users cannot create usernames, or usernames are rejected.

**Solutions**:
1. Check that username requirements are configured correctly
2. Verify username availability check is working
3. Check watchdog logs for validation errors
4. Ensure the username doesn't conflict with existing users

#### Block Not Displaying

**Symptoms**: The WaaP Login block doesn't appear on the page.

**Solutions**:
1. Verify the block is placed in a region: `/admin/structure/block`
2. Check block visibility settings (pages, content types, roles)
3. Clear Drupal caches: `drush cr`
4. Check that the module is enabled: `drush pm:list --filter=waap_login`
5. Verify the user has permission to view the block

### Debug Mode

Enable debug logging to troubleshoot issues:

```bash
# Enable debug logging in settings.php
$settings['waap_login_debug'] = TRUE;

# View debug logs
drush watchdog:show --type=waap_login --severity=Debug
```

### Checking Configuration

```bash
# View current configuration
drush config:get waap_login.settings

# View email configuration
drush config:get waap_login.mail
```

### Clearing Session Data

```bash
# Clear all WaaP sessions
drush php-eval "\Drupal::keyValue('waap_login.sessions')->deleteAll();"

# Clear specific user session
drush php-eval "\Drupal::keyValue('waap_login.sessions')->delete(123);"
```

## FAQ

**Q: Can WaaP Login coexist with SIWE Login?**

A: Yes, both modules share the same `field_ethereum_address` field and can be enabled simultaneously. Users can choose their preferred authentication method.

**Q: Which authentication methods are supported?**

A: The module supports email, phone, social providers (Google, Facebook, Twitter, Discord, Coinbase, LinkedIn, Apple, GitHub), and wallet connections (MetaMask, WalletConnect).

**Q: Is email verification required?**

A: Email verification is optional and configurable. You can enable or disable it in the module settings at `/admin/config/people/waap`.

**Q: Is username creation required?**

A: Username creation is optional and configurable. If enabled, users without ENS names will be prompted to create a username.

**Q: What happens if a user already has an account with the same wallet address?**

A: The module will find the existing user account and log them in. No duplicate accounts are created.

**Q: Can I customize the WaaP modal appearance?**

A: Yes, you can enable/disable dark mode and the "Secured by Human.tech" badge in the module settings. For more advanced customization, you can override the CSS in your theme.

**Q: How do I get a WalletConnect Project ID?**

A: Visit [WalletConnect Cloud](https://cloud.walletconnect.com/) to create a project and obtain your Project ID.

**Q: What is the Gas Tank feature?**

A: Gas Tank allows you to sponsor transactions for your users, covering gas fees on their behalf. This feature is optional and requires configuration with Human.tech.

**Q: How do I integrate WaaP Login with other modules?**

A: Use the `hook_waap_login_response_alter()` hook to modify the authentication response and add custom redirects or actions. See the API documentation for details.

**Q: Is WaaP Login secure?**

A: Yes, WaaP Login uses Human.tech's secure authentication infrastructure with 2PC (Two-Party Computation) and 2PC-MPC security models. The module also implements CSRF protection, rate limiting, and proper session management.

**Q: Can I use WaaP Login without WalletConnect?**

A: Yes, WalletConnect is optional. You can disable it by leaving the WalletConnect Project ID field empty.

## Contributing

Contributions are welcome! Please follow these guidelines:

1. **Code Standards**: Follow [Drupal coding standards](https://www.drupal.org/docs/develop/standards)
2. **Testing**: Include tests for new features (unit, functional, and integration tests)
3. **Documentation**: Update documentation for any new features or changes
4. **Pull Requests**: Submit pull requests with clear descriptions of changes
5. **Issue Reporting**: Use the issue tracker to report bugs or request features

### Development Setup

```bash
# Clone the repository
git clone https://github.com/your-org/waap_login.git

# Install dependencies
composer install

# Enable development modules
drush en -y waap_login_test

# Run tests
phpunit modules/contrib/waap_login/tests

# Run code sniffer
phpcs --standard=Drupal modules/contrib/waap_login
```

## License

This module is licensed under GPL-2.0-or-later.

## Support

- **Documentation**: https://docs.wallet.human.tech/
- **Issue Tracker**: [module issue queue]
- **Community**: Open Social Slack
- **Drupal.org**: https://www.drupal.org/project/waap_login

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## Credits

- **Developed by**: Your Organization
- **Powered by**: Human.tech WaaP SDK
- **Maintainers**: List of maintainers

## Related Modules

- [SIWE Login](https://www.drupal.org/project/siwe_login) - Sign-In with Ethereum authentication
- [Safe Smart Accounts](https://www.drupal.org/project/safe_smart_accounts) - Multi-sig wallet integration
- [Group Treasury](https://www.drupal.org/project/group_treasury) - Group-level treasury management
