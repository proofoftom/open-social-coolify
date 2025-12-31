# WaaP Login Module - Deployment Guide

**Module Version**: 1.0.x-dev
**Drupal Version**: 10.x
**PHP Version**: 8.1+

## Overview

This guide covers deploying the WaaP Login module to production environments in the Open Social 13.0.0-beta2 deployment on Coolify. The module provides Wallet as a Protocol (WaaP) authentication, allowing users to sign in via email, social providers, or wallet.

## Prerequisites

Before deploying, ensure:
- [ ] Open Social 13.0.0-beta2 is installed
- [ ] PHP 8.1 or higher is available
- [ ] Composer 2.x is installed
- [ ] siwe_login module is installed (dependency)
- [ ] Database backups are created
- [ ] Site is in maintenance mode (recommended)

## Installation Steps

### 1. Add Module via Composer

```bash
# Navigate to project root
cd /path/to/open-social-coolify

# Require the module
composer require drupal/waap_login:1.0.x-dev

# Update dependencies
composer update
```

**Note**: The module is already configured in composer.json as a path repository pointing to `html/modules/contrib/waap_login`.

### 2. Enable the Module

```bash
# Via Drush
cd /var/www/html/html
../../vendor/bin/drush en waap_login -y

# Clear caches
../../vendor/bin/drush cr

# Verify installation
../../vendor/bin/drush pm:list --filter=waap
```

### 3. Configure the Module

Navigate to `/admin/config/people/waap` and configure:

**WaaP SDK Settings**:
- [ ] Enable module: Yes
- [ ] Environment: Production (or Sandbox for testing)
- [ ] Authentication Methods: Select desired methods (email, social, wallet)
- [ ] Social Providers: Configure as needed (Google, Apple, etc.)
- [ ] WalletConnect Project ID: Enter your project ID
- [ ] Dark Mode: Enable/disable
- [ ] Secured Badge: Enable/disable

**User Management**:
- [ ] Email Verification: Enable/disable
- [ ] Username Required: Enable/disable
- [ ] Auto-create Users: Enable/disable

**Session Management**:
- [ ] Session TTL: Set appropriate timeout (default: 3600 seconds)

**Integration**:
- [ ] Enable SIWE Integration: Yes (if using both)
- [ ] Enable Gas Tank: Configure as needed

### 4. Configure Permissions

Navigate to `/admin/people/permissions` and set:
- [ ] `access waap login` - For all users who should see login
- [ ] `use waap login` - For users allowed to authenticate
- [ ] `administer waap login` - For administrators only

### 5. Place Login Block

Navigate to `/admin/structure/block` and:
- [ ] Place "WaaP Login" block in desired region
- [ ] Configure block visibility (typically: show for anonymous users)
- [ ] Save configuration

## Environment-Specific Configuration

### Development Environment

```bash
# Use sandbox environment
drush config:set waap_login.settings use_staging 1

# Enable verbose logging
drush config:set waap_login.settings debug_mode 1

# Shorter session TTL for testing
drush config:set waap_login.settings session_ttl 600
```

### Staging Environment

```bash
# Use production SDK but with testing settings
drush config:set waap_login.settings use_staging 0
drush config:set waap_login.settings debug_mode 1
```

### Production Environment

```bash
# Use production environment
drush config:set waap_login.settings use_staging 0

# Disable debug mode
drush config:set waap_login.settings debug_mode 0

# Standard session TTL
drush config:set waap_login.settings session_ttl 3600

# Ensure HTTPS
drush config:set waap_login.settings require_https 1
```

## Integration Testing

### Test Authentication Flow

1. **Test WaaP Login**:
   ```bash
   # As anonymous user, navigate to login page
   # Click "Login with WaaP"
   # Authenticate via email/social/wallet
   # Verify successful login
   ```

2. **Test Email Verification** (if enabled):
   ```bash
   # Complete WaaP authentication
   # Check email for verification link
   # Click verification link
   # Verify email confirmed
   ```

3. **Test Username Creation** (if enabled):
   ```bash
   # Complete authentication
   # Enter desired username
   # Verify username assigned
   ```

4. **Test Logout**:
   ```bash
   # Click logout button
   # Verify session cleared
   # Verify redirected appropriately
   ```

### Test SIWE Integration

If both waap_login and siwe_login are enabled:

```bash
# Test users can login with either method
# Test field_ethereum_address is shared correctly
# Verify no conflicts between modules
```

### Test Safe Smart Accounts Integration

If safe_smart_accounts is enabled:

```bash
# Test redirect to Safe creation after WaaP login
# Verify wallet address used for Safe deployment
```

## Performance Verification

After deployment, verify performance:

```bash
# Check authentication timing
drush watchdog:show --type=waap_login --count=50

# Verify cache hit ratios
drush cache:rebuild
# Monitor cache statistics in admin interface

# Check database query counts
# Enable Webprofiler or Devel module for profiling
```

## Security Checklist

Before going live:
- [ ] HTTPS is enforced
- [ ] CSRF protection is enabled
- [ ] Flood control is configured (default: 5 attempts/hour)
- [ ] Session TTL is appropriate for your use case
- [ ] Email verification tokens expire after 24 hours
- [ ] Sensitive configuration is not exposed in logs
- [ ] WaaP SDK loaded from trusted CDN
- [ ] Content Security Policy allows WaaP SDK domain

## Monitoring

Set up monitoring for:

```bash
# Authentication failures
drush watchdog:show --type=waap_login --severity=3 --count=20

# Performance metrics
# Monitor average authentication time (target: < 500ms)

# Error rates
# Track authentication success/failure ratios

# Session storage size
# Monitor key-value store growth
```

## Troubleshooting Deployment

### Module Won't Enable

```bash
# Check dependencies
drush pm:list --filter=siwe

# Verify siwe_login is enabled
drush en siwe_login -y

# Check for errors
drush watchdog:show --type=php --count=20

# Verify requirements
drush core:requirements
```

### Configuration Not Saving

```bash
# Clear caches
drush cr

# Check file permissions
ls -la html/sites/default

# Verify config directory is writable
```

### Block Not Appearing

```bash
# Clear block cache
drush cache:clear render

# Verify block placement
drush block:list

# Check block visibility settings
```

### Authentication Failing

```bash
# Check WaaP SDK loading
# View browser console for errors

# Verify CORS settings
# Check network tab in browser dev tools

# Review flood control
drush sql:query "SELECT * FROM flood WHERE event = 'waap_login.auth_ip'"

# Clear flood entries if needed
drush sql:query "DELETE FROM flood WHERE event = 'waap_login.auth_ip'"
```

### Email Verification Not Working

```bash
# Test email system
drush php-eval "mail('test@example.com', 'Test', 'Test message');"

# Check mail configuration
drush config:get system.mail

# Review email templates
drush config:get waap_login.mail
```

## Rollback Procedures

If deployment issues occur:

### Quick Rollback

```bash
# Disable module
drush pm:uninstall waap_login -y

# Clear caches
drush cr

# Restore from backup if needed
drush sql:drop
drush sql:cli < backup.sql
```

### Data Preservation Rollback

```bash
# Disable but keep data
drush pm:uninstall waap_login --no

# Note: This preserves field_ethereum_address if siwe_login is still installed

# Remove from composer
composer remove drupal/waap_login

# Update entrypoint.sh to remove waap_login from enable command
```

## Coolify-Specific Deployment

For Coolify deployments:

### Environment Variables

Ensure these are set in Coolify:
```bash
SERVICE_FQDN_OPENSOCIAL=your-domain.com
DRUPAL_HASH_SALT=<generated-hash>
DRUPAL_TRUSTED_HOST_PATTERNS=^your-domain\.com$
```

### Build Process

The module will be automatically:
1. Installed via Composer when container builds
2. Enabled via entrypoint.sh on container start
3. Configured via default config on first install

### Dependency Chain

The entrypoint.sh enables modules in this order:
```bash
# Web3 stack
siwe_login → waap_login → safe_smart_accounts → group_treasury → social_group_treasury
```

### Post-Deployment

After Coolify deployment:
```bash
# SSH into container
coolify ssh <app-id>

# Verify module is enabled
cd /var/www/html/html
../../vendor/bin/drush pm:list --filter=waap

# Check logs
../../vendor/bin/drush watchdog:show --type=waap_login --count=20
```

## Maintenance

### Regular Tasks

```bash
# Weekly: Review authentication logs
drush watchdog:show --type=waap_login --count=100

# Monthly: Clean up expired sessions
drush cron

# Quarterly: Review and update WaaP SDK version
# Check https://docs.wallet.human.tech/ for updates
```

### Updates

When updating the module:

```bash
# Backup database
drush sql:dump > backup-$(date +%Y%m%d).sql

# Update via Composer
composer update drupal/waap_login

# Run database updates
drush updb -y

# Clear caches
drush cr

# Test functionality
```

## Support Resources

- **Module Documentation**: [`README.md`](README.md), [`CLAUDE.md`](CLAUDE.md)
- **Security Audit**: [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md)
- **Performance Guide**: [`PERFORMANCE_OPTIMIZATION.md`](PERFORMANCE_OPTIMIZATION.md)
- **WaaP Documentation**: https://docs.wallet.human.tech/
- **Drupal Documentation**: https://www.drupal.org/docs

## Appendix A: Configuration Export

After configuration, export for version control:

```bash
# Export configuration
drush config:export

# Commit to version control
git add config/sync/waap_login.*
git commit -m "Add WaaP Login configuration"
```

## Appendix B: Load Testing

For production readiness:

```bash
# Use Apache Bench
ab -n 1000 -c 10 https://your-domain.com/waap/status

# Monitor during load test
drush watchdog:tail
```

## Appendix C: Backup Strategy

Before deployment:

```bash
# Full database backup
drush sql:dump --gzip > waap-pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz

# Configuration backup
drush config:export --destination=/backup/config-$(date +%Y%m%d)

# Code backup
tar -czf /backup/code-$(date +%Y%m%d).tar.gz html/
```

## Appendix D: Module Integration

### SIWE Login Integration

WaaP Login integrates with SIWE Login through the shared `field_ethereum_address` field:

- Both modules use the same field to store wallet addresses
- Users can authenticate via either method
- The wallet address is consistent across both authentication flows

### Safe Smart Accounts Integration

When Safe Smart Accounts is enabled:

- After WaaP login, users are redirected to create a Safe account
- The wallet address from WaaP authentication is used for Safe deployment
- Multi-signature wallet functionality is available

### Group Treasury Integration

When Group Treasury is enabled:

- Users with Safe accounts can participate in group treasuries
- Wallet addresses are synchronized between authentication and treasury systems
- Multi-signature transactions can be proposed and executed

## Appendix E: Common Issues and Solutions

### Issue: WaaP SDK Not Loading

**Symptoms**: Login button appears but clicking it does nothing.

**Solutions**:
1. Check browser console for JavaScript errors
2. Verify CDN is accessible
3. Check Content Security Policy settings
4. Ensure WalletConnect Project ID is configured

### Issue: Email Not Received

**Symptoms**: User completes authentication but never receives verification email.

**Solutions**:
1. Check Drupal mail logs
2. Verify mail server configuration
3. Check spam folders
4. Test mail system with `drush php-eval`

### Issue: Session Expiring Too Quickly

**Symptoms**: Users are logged out frequently.

**Solutions**:
1. Increase session TTL in configuration
2. Check PHP session settings
3. Verify cookie settings
4. Check for aggressive caching

### Issue: Conflicts with SIWE Login

**Symptoms**: Users experience issues when both modules are enabled.

**Solutions**:
1. Verify `field_ethereum_address` exists
2. Check field configuration
3. Review hook implementations
4. Test each module independently

## Appendix F: API Endpoints

The module provides the following API endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/waap/auth` | POST | Initiate WaaP authentication |
| `/waap/callback` | POST | Handle WaaP callback |
| `/waap/verify` | POST | Verify authentication token |
| `/waap/logout` | POST | Logout current user |
| `/waap/status` | GET | Check authentication status |
| `/waap/verify-email` | GET | Verify email address |
| `/waap/username` | POST | Set username after auth |

## Appendix G: Configuration Reference

### waap_login.settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enabled` | boolean | true | Enable/disable module |
| `use_staging` | boolean | false | Use staging environment |
| `auth_methods` | array | `['email', 'social', 'wallet']` | Available auth methods |
| `social_providers` | array | `[]` | Enabled social providers |
| `walletconnect_project_id` | string | `''` | WalletConnect Project ID |
| `dark_mode` | boolean | false | Enable dark mode |
| `secured_badge` | boolean | true | Show secured badge |
| `email_verification` | boolean | true | Require email verification |
| `username_required` | boolean | false | Require username |
| `auto_create_users` | boolean | true | Auto-create users |
| `session_ttl` | integer | 3600 | Session timeout (seconds) |
| `enable_siwe_integration` | boolean | true | Enable SIWE integration |
| `enable_gas_tank` | boolean | false | Enable Gas Tank |
| `debug_mode` | boolean | false | Enable debug logging |
| `require_https` | boolean | true | Require HTTPS |
| `flood_limit` | integer | 5 | Max auth attempts per hour |

## Appendix H: Database Schema

The module creates the following database tables:

| Table | Purpose |
|-------|---------|
| `waap_auth_session` | Store authentication sessions |
| `waap_email_verification` | Store email verification tokens |
| `waap_auth_log` | Log authentication attempts |

## Appendix I: Permissions

| Permission | Description | Recommended Roles |
|------------|-------------|-------------------|
| `access waap login` | Access WaaP login page | Anonymous, Authenticated |
| `use waap login` | Use WaaP authentication | Anonymous, Authenticated |
| `administer waap login` | Administer WaaP settings | Administrator |
