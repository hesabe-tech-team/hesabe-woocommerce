# Hesabe for WooCommerce

Hesabe payment gateway integration for WooCommerce with two checkout styles:

- Hosted mode: customer is redirected to Hesabe payment page.
- Direct mode: customer selects brand at checkout (KNET, Visa/Mastercard, Apple Pay).

## Plugin Version

- Current plugin version: `1.0.13`

## Compatibility / Supported Versions

Based on plugin metadata and code:

- WordPress: `5.8+`
- PHP: `7.4+`
- WooCommerce: `6.0+`
- WooCommerce HPOS: supported (compatibility declared)
- WooCommerce Checkout Blocks: supported

## Features

- Single gateway in WooCommerce: `Hesabe`
- Sandbox and Production credentials
- Hosted and Direct integration modes
- Direct brand toggles:
  - KNET
  - Visa / Mastercard
  - Apple Pay
- Configurable Apple Pay payment type (`9` to `14`)
- Optional currency conversion behavior for non-KWD orders

## Installation

1. Copy folder `hesabe-for-woocommerce` to:
   - `wp-content/plugins/`
2. Activate plugin from WordPress admin:
   - `Plugins` -> `Installed Plugins` -> `Hesabe for WooCommerce` -> `Activate`
3. Ensure WooCommerce is installed and active.

## Configuration

Open:

- `WooCommerce` -> `Settings` -> `Payments` -> `Hesabe`

Configure:

1. Enable gateway
2. Choose `Environment`:
   - Sandbox
   - Production
3. Choose `Integration mode`:
   - Hosted
   - Direct
4. Enter credential keys for selected environment:
   - Merchant Code
   - Access Code
   - Secret Key
   - IV Key
5. (Direct mode) Enable desired brands and Apple Pay type.
6. Save changes.

## Using Hosted Mode

1. Set `Integration mode` to `Hosted`.
2. Customer chooses Hesabe at checkout.
3. Customer is redirected to Hesabe and returned to WooCommerce after payment.

## Using Direct Mode

1. Set `Integration mode` to `Direct`.
2. Enable one or more brands (KNET / Visa / Apple Pay).
3. Customer selects brand at checkout.
4. Payment flow follows selected payment type.

## Apple Pay Notes

Apple Pay requires proper merchant/domain setup on Hesabe and Apple side.

Checklist:

- Apple Pay enabled on your Hesabe merchant account
- Correct Apple Pay payment type enabled in plugin settings
- Domain is registered/whitelisted with merchant profile
- Apple verification file is available at:
  - `/.well-known/apple-developer-merchantid-domain-association`
- Domain passed to Apple Pay must be FQDN only (no scheme/path)

If you see domain-related errors (`not registered for domain`), confirm the exact checkout hostname is registered in the active environment (sandbox vs production).

## Troubleshooting

- Gateway not visible:
  - Confirm plugin is enabled
  - Confirm WooCommerce is active
  - Confirm required credentials are filled
- Checkout/API errors:
  - Recheck Access Code / Secret Key / IV Key / Merchant Code
  - Verify environment is correct
- Apple Pay button shown but payment fails:
  - Validate domain registration and Apple Pay merchant setup

## Support

- Hesabe website: [https://hesabe.com/](https://hesabe.com/)
- Hesabe docs: [https://developer.hesabe.com/docs](https://developer.hesabe.com/docs)
