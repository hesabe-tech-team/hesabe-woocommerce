# Hesabe for WooCommerce

Hesabe payment gateway integration for WooCommerce with two checkout styles:

- Hosted mode: customer is redirected to Hesabe payment page.
- Direct mode: customer selects brand at checkout (KNET, Visa/Mastercard, Apple Pay).

## Plugin Version

- Current plugin version: `11.2`

## Compatibility / Supported Versions

Based on plugin metadata and code:

- WordPress: `5.8+`
- PHP: `7.4+`
- WooCommerce: `6.0+`
- WooCommerce HPOS: supported (compatibility declared)
- WooCommerce Checkout Blocks: supported (compatibility declared)

Tested against the Hesabe sandbox in November 2026, with KNET and Visa / Mastercard in
Hosted and Direct mode, on the Checkout block and the classic checkout:

- WordPress `7.1.4`, WooCommerce `11.2.2`, PHP `8.5`
- WordPress `6.9.4`, WooCommerce `10.7.0`, PHP `8.3`

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
- Order status from Hesabe's signed webhook (Checkout API `3.0`), for customers who never
  come back to the shop
- `Check Hesabe payment status` order action, from Hesabe's Transaction Enquiry

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

## Payment Status Updates

Three things update a Hesabe order:

1. The customer's return. When the customer comes back from Hesabe, the order is marked
   paid, or failed when the payment did not go through.
2. Hesabe's webhook. Every payment asks Hesabe to post the result to:
   - `https://your-shop.example.com/wc-api/wc_hesabe_webhook/`
   - `https://your-shop.example.com/?wc-api=wc_hesabe_webhook` with plain permalinks

   The webhook updates the order when the customer never comes back, for example after
   closing the browser on Hesabe's page. Without it, WooCommerce cancels the unpaid order
   when its `Hold stock` time runs out (60 minutes by default). Hesabe signs the webhook
   with your access code, and the plugin ignores a webhook whose signature does not match.
   Nothing needs to be configured, but the shop must be reachable from the internet over
   HTTPS, and a firewall must let Hesabe's POST through.
3. `Check Hesabe payment status`. On the order page, choose it under `Order actions` and
   press `Update`. The plugin asks Hesabe's Transaction Enquiry and applies its answer.

The return comes first. When the webhook has already updated the order, the return checks
it again with the Transaction Enquiry. A paid order is never marked failed. When Hesabe
confirms a payment, a pending, failed or cancelled order is marked paid, as WooCommerce
does for any gateway. Each update adds an order note naming what reported it and Hesabe's
payment token. When Hesabe and the order disagree, such as a different amount, the order
is not changed and its note says why. For an order in another currency with `Currency
converter` on, Hesabe reports the amount it charged in KWD, which the note shows next to
the order total.

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
  - The message and the order note give Hesabe's reason. For example, Hesabe refuses a
    customer email at a domain that receives no mail, such as `example.com`.
- Order paid at Hesabe but still pending or cancelled in WooCommerce:
  - Run `Check Hesabe payment status` from the order's `Order actions`
  - Check that `wc-api/wc_hesabe_webhook` is reachable from the internet
- Apple Pay button shown but payment fails:
  - Validate domain registration and Apple Pay merchant setup

## Support

- Hesabe website: [https://hesabe.com/](https://hesabe.com/)
- Hesabe docs: [https://developer.hesabe.com/docs](https://developer.hesabe.com/docs)
