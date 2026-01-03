# Changelog

All notable changes to the SIWE Login module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-beta1] - 2025-01-24

### Added

- Full EIP-4361 (Sign-In with Ethereum) implementation
- Multiple wallet support (MetaMask, WalletConnect, etc.)
- Nonce-based replay attack prevention with configurable TTL
- Domain validation to prevent cross-domain authentication attacks
- Optional email verification flow for new users
- Optional username creation flow for users without ENS names
- ENS validation feature to verify that ENS names resolve to the signing Ethereum address
- EnsResolver service for interacting with ENS contracts on Ethereum mainnet
- Configuration option for Ethereum provider URL
- `hook_siwe_login_response_alter()` for other modules to customize authentication responses
- SIWE Login block for flexible placement
- Automatic user field management (`field_ethereum_address`, `field_ens_name`)
- Admin configuration form at `/admin/config/people/siwe`
- API endpoints: `/siwe/nonce`, `/siwe/verify`, `/siwe/email-verification`, `/siwe/create-username`

### Security

- EIP-191 message signing standard
- Cryptographic signature verification using secp256k1
- Timestamp validation with clock skew tolerance
- CSRF protection on forms
- Secure session management
