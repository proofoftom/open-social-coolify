# AGENTS.md

This file provides an overview for AI agents working with this codebase.

## Project Overview

This is a **production-ready Docker deployment** of **Open Social 13.0.0-beta2** on Coolify, extended with custom Web3 modules for decentralized community governance and AI capabilities.

**Core Platform**: Open Social (Drupal 10.x distribution for online communities)
**Deployment Target**: Coolify (self-hosted PaaS)
**Infrastructure**: Docker Compose (Apache/PHP 8.3, MariaDB 10.11, Solr 8.11)

## Web3 Module Stack

The project integrates three custom Drupal modules that form a cohesive Web3 authentication and treasury management system:

```text
┌─────────────────────────────────────────────────────────────────┐
│                         User Layer                               │
│   (Ethereum Wallet via MetaMask / WalletConnect)                │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│                      siwe_login                                  │
│   EIP-4361 Sign-In with Ethereum authentication                 │
│   - Nonce-based SIWE message signing                            │
│   - ENS validation & reverse lookup                             │
│   - Optional email verification & username creation             │
│   - Hook: hook_siwe_login_response_alter()                      │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│                   safe_smart_accounts                            │
│   Safe (formerly Gnosis Safe) multi-sig wallet integration      │
│   - SafeAccount, SafeTransaction, SafeConfiguration entities   │
│   - MetaMask deployment & transaction signing                   │
│   - Multi-signature threshold management                        │
│   - Post-login redirect via SIWE hooks                          │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│                    group_treasury                                │
│   Group-level treasury management                                │
│   - Links Safe accounts to Drupal Groups                        │
│   - Automatic signer sync on role changes                       │
│   - Transaction proposals by group members                      │
│   - social_group_treasury: Open Social theming                  │
└─────────────────────────────────────────────────────────────────┘
```

### Module Details

#### siwe_login (drupal/siwe_login 1.0.x-dev)
**Purpose**: Ethereum wallet authentication using Sign-In with Ethereum standard

**Key Features**:
- EIP-4361 SIWE message validation with EIP-191 signature verification
- ENS name validation and reverse lookup (enabled by default as of latest update)
- Multi-step authentication: optional email verification, username creation
- Domain validation with comma-separated multiple domain support
- RPC failover through multiple public endpoints (LlamaRPC, PublicNode, Ankr, Cloudflare)

**Configuration Path**: `/admin/config/people/siwe`

**Recent Changes**:
- ENS validation now enabled by default (commit dbef8b1)

#### safe_smart_accounts (drupal/safe_smart_accounts 1.0.x-dev)
**Purpose**: Safe Smart Account deployment and management

**Key Features**:
- Three-tier entity structure: SafeAccount, SafeTransaction, SafeConfiguration
- CREATE2 deterministic Safe deployment via SafeProxyFactory
- Multi-signature transaction workflow via Safe Protocol Kit SDK
- Owner/threshold management with linked list traversal
- SIWE integration: post-login redirect to Safe management

**Entity Workflow**:
```text
SafeAccount: pending → deploying → active → error
SafeTransaction: draft → pending → executed | failed | cancelled
```

**Recent Changes**:
- Auto-configure profile block visibility on install (commit 19d87bc → c1ce65f)

#### group_treasury (drupal/group_treasury 1.0.x-dev)
**Purpose**: Multi-signature treasury management for Drupal Groups

**Key Features**:
- GroupSafeAccount plugin linking Groups to Safe accounts
- Automatic signer synchronization: role changes → propose addOwner/removeOwner
- Group-level permissions: view, propose, sign, execute, manage
- Treasury tab at `/group/{id}/treasury`
- Wizard integration for treasury creation during group setup

**Submodule**: `social_group_treasury` provides Open Social-specific theming and block configuration

**Permissions** (defined in group_treasury.group.permissions.yml):
- `view group_treasury` - View treasury tab
- `propose group_treasury transactions` - Create transaction proposals
- `sign group_treasury transactions` - Add signatures
- `execute group_treasury transactions` - Submit to blockchain
- `manage group_treasury` - Add/remove/reconnect treasury

### Module Development Workflow

**Important**: The Web3 modules (`siwe_login`, `safe_smart_accounts`, `group_treasury`) are managed as **git subtrees**. Module files are committed directly to this repository, but can be pushed/pulled to their upstream Drupal.org repositories.

#### Making Changes to Modules

1. **Edit module files directly** in `html/modules/contrib/<module_name>/`
2. **Commit to open-social-coolify** as normal:
   ```bash
   git add html/modules/contrib/siwe_login/
   git commit -m "fix(siwe_login): description of change"
   git push
   ```

3. **Push changes to module's Drupal.org repository** (when ready to release):
   ```bash
   git subtree push --prefix=html/modules/contrib/siwe_login siwe_login 1.0.x
   git subtree push --prefix=html/modules/contrib/safe_smart_accounts safe_smart_accounts 1.0.x
   git subtree push --prefix=html/modules/contrib/group_treasury group_treasury 1.0.x
   ```

#### Pulling Updates from Upstream

If the module was updated on Drupal.org independently:
```bash
git subtree pull --prefix=html/modules/contrib/siwe_login siwe_login 1.0.x --squash
```

#### Subtree Remotes

The following remotes are configured for subtree operations:
- `siwe_login` → `git@git.drupal.org:project/siwe_login.git`
- `safe_smart_accounts` → `git@git.drupal.org:project/safe_smart_accounts.git`
- `group_treasury` → `git@git.drupal.org:project/group_treasury.git`

**For agents working with git worktrees**: Module files are already in the repository. No `composer install` needed for the Web3 modules - they are committed directly.

## AI Modules

The project includes AI capabilities via:

```yaml
drupal/ai: 1.2.x-dev           # Core AI module
drupal/ai_agents: 1.2.x-dev    # AI agent functionality
drupal/ai_provider_deepseek: ^1.0  # DeepSeek LLM provider
```

These modules provide:
- AI-powered content generation and analysis
- Agent-based automation capabilities
- DeepSeek API integration for LLM operations

**Configuration Path**: `/admin/config/ai`

## Dependency Chain

The entrypoint.sh enables modules in this order during deployment:

```bash
# Core Drupal modules
graphql, search_api, search_api_solr

# Web3 stack (via social_group_treasury which depends on the full chain)
siwe_login → safe_smart_accounts → group_treasury → social_group_treasury
```

## Key Technical Patterns

### Cache Invalidation
All Web3 modules use proper entity API methods to ensure cache tags are invalidated:

```php
// Correct - triggers cache invalidation
$safe = $storage->load($id);
$safe->setStatus('active');
$safe->save();

// Wrong - bypasses cache system
drush sqlq "UPDATE safe_account SET status = 'active'";
```

### Safe Transaction Signing
Transaction signing uses the official Safe Protocol Kit SDK (`@safe-global/protocol-kit`):
- `protocolKit.signTransaction()` handles signature formatting automatically
- `protocolKit.executeTransaction()` packs signatures and submits to chain
- SDK manages v-value adjustment internally (no manual 31/32 handling needed)

### Automatic Signer Sync
When group roles change, group_treasury automatically proposes Safe transactions:
- Admin role assigned → proposes `addOwnerWithThreshold(address, threshold)`
- Admin role removed → proposes `removeOwner(prevOwner, address, threshold)`

## Development Commands

### Container Operations
```bash
docker compose up -d --build       # Build and start
docker compose logs -f opensocial  # View app logs
docker compose restart solr        # Restart service
```

### Drush Commands (from container)
```bash
cd /var/www/html/html
../../vendor/bin/drush status
../../vendor/bin/drush cache:rebuild
../../vendor/bin/drush pm:list --filter=siwe
../../vendor/bin/drush watchdog:show --type=siwe_login --count=20
../../vendor/bin/drush config:get siwe_login.settings
```

### Module-Specific Debugging
```bash
# SIWE Login
drush config:set siwe_login.settings expected_domain "yourdomain.com"
drush watchdog:show --type=siwe_login --count=20

# Safe Smart Accounts
drush sql-query "SELECT id, safe_address, status FROM safe_account WHERE user_id = X"
drush sql-query "SELECT id, nonce, status FROM safe_transaction WHERE safe_account = X"

# Group Treasury
drush entity:query group_relationship --filter="gid=X,plugin_id=group_safe_account:safe_account"
drush watchdog:show --type=group_treasury --count=20
```

## Environment Variables

### Required for Production
```bash
DB_PASSWORD           # Database password
DB_ROOT_PASSWORD      # MySQL root password
DRUPAL_HASH_SALT      # Generate: openssl rand -hex 32
DRUPAL_TRUSTED_HOST_PATTERNS  # Regex: ^yourdomain\.com$
DRUPAL_ADMIN_PASS     # Admin password
```

### Coolify Auto-Detection
```bash
SERVICE_FQDN_OPENSOCIAL  # Used for SIWE domain config
COOLIFY_URL              # Fallback domain detection
```

## File Structure

```text
/open-social-coolify/
├── composer.json         # Web3 & AI module dependencies
├── entrypoint.sh        # Container init, module enablement
├── html/
│   └── modules/
│       └── contrib/
│           ├── siwe_login/
│           │   ├── CLAUDE.md          # AI agent guidance
│           │   └── src/Service/       # Auth, ENS, RPC services
│           ├── safe_smart_accounts/
│           │   ├── CLAUDE.md          # AI agent guidance
│           │   ├── src/Entity/        # SafeAccount, SafeTransaction, SafeConfiguration
│           │   └── js/                # MetaMask integration
│           └── group_treasury/
│               ├── CLAUDE.md          # AI agent guidance
│               ├── src/               # Controllers, forms, services
│               └── modules/
│                   └── social_group_treasury/  # Open Social integration
├── solr-config/          # Search API Solr configuration
└── docs/                 # Additional documentation
```

## Common Troubleshooting

### SIWE Authentication Issues
- Check `expected_domain` matches your actual domain
- Verify GMP PHP extension is installed
- Check ENS RPC endpoints aren't rate-limited

### Safe Deployment Failures
- Ensure MetaMask is on Sepolia testnet
- Check browser console for JavaScript errors
- Verify SafeProxyFactory contract is accessible

### Treasury Tab Missing
- Confirm `group_safe_account` plugin enabled on Group Type
- Check user has `view group_treasury` permission
- Query group_relationship for treasury link

### Module Logs
```bash
drush watchdog:show --type=siwe_login
drush watchdog:show --type=safe_smart_accounts
drush watchdog:show --type=group_treasury
```

## Version Information

| Component | Version |
|-----------|---------|
| Open Social | 13.0.0-beta2 |
| Drupal | 10.x |
| PHP | 8.3 |
| siwe_login | 1.0.x-dev |
| safe_smart_accounts | 1.0.x-dev |
| group_treasury | 1.0.x-dev |
| drupal/ai | 1.2.x-dev |
| drupal/ai_agents | 1.2.x-dev |
| drupal/ai_provider_deepseek | 1.0.0 |
