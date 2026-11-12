=== Hesabe for WooCommerce ===
Tags: payment gateway, knet, kuwait, apple pay, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 11.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept KNET, Visa, Mastercard and Apple Pay in WooCommerce with Hesabe, on Hesabe's payment page or as brands in your checkout.

== Description ==

Hesabe for WooCommerce adds Hesabe, a payment gateway in Kuwait, to your shop as one payment method. Customers pay with KNET, Visa, Mastercard or Apple Pay, and the order is updated when Hesabe confirms the payment.

The plugin needs WooCommerce and a Hesabe merchant account. It comes set up for Hesabe's public sandbox, so you can place test orders before you have live keys.

= Two ways to show Hesabe at checkout =

* **Hosted.** The customer chooses Hesabe and continues to Hesabe's payment page, which lists the payment methods of your account.
* **Direct.** The customer chooses KNET, Visa / Mastercard or Apple Pay in your checkout, then continues to that method's page.

= What it supports =

* The Checkout block and the classic checkout.
* High-Performance Order Storage (HPOS).
* Sandbox and Production environments, each with its own keys.
* Apple Pay with the payment types Hesabe enables on your account (9 to 14).
* Orders in another currency than KWD, with the `Currency converter` setting.

= How an order gets its status =

Three things update a Hesabe order:

1. **The customer's return.** When the customer comes back from Hesabe, the order is marked paid, or failed when the payment did not go through.
2. **Hesabe's webhook.** Hesabe posts the result of every payment to the shop. It updates the order when the customer never comes back, for example after closing the browser on Hesabe's page. Hesabe signs the webhook with your access code, and the plugin ignores a webhook whose signature does not match.
3. **Check Hesabe payment status.** An order action that asks Hesabe's Transaction Enquiry about the order and applies the answer.

A paid order is never marked failed. When Hesabe and the order disagree, such as a different amount, the order is not changed and an order note says why.

== External services ==

This plugin connects your shop to Hesabe, the payment gateway that takes the payment. It cannot take a payment without it. With Apple Pay on, it also loads Apple's Apple Pay script.

= Hesabe =

The plugin talks to `sandbox.hesabe.com` when `Environment` is Sandbox and to `api.hesabe.com` when it is Production. The service is provided by Hesabe: [https://hesabe.com/](https://hesabe.com/).

* When a customer places an order with Hesabe as the payment method, your server sends Hesabe a checkout request, encrypted with your keys. It holds your merchant code, the order number and amount, the customer's name, phone number and email address from the billing details, the WooCommerce version, and the addresses Hesabe returns the customer and posts the webhook to. For Apple Pay, and for orders in another currency with `Currency converter` on, it also holds the order's currency. For Apple Pay it holds your shop's domain.
* The customer's browser then goes to Hesabe's payment page. Card details are entered there and never reach your shop.
* Hesabe sends the customer back to your shop with the result, and posts the result to your shop's webhook address.
* When a customer comes back to an order the webhook has already updated, and when you run `Check Hesabe payment status`, your server asks Hesabe about the order. It sends the order number and your access code.
* With Apple Pay, your server and the customer's browser also load Hesabe's Apple Pay script for the payment, and the browser asks Hesabe to validate your shop's domain with Apple.

Hesabe's [terms and conditions](https://hesabe.com/terms.html) and [privacy policy](https://hesabe.com/privacy.html).

= Apple Pay =

When an Apple Pay type is enabled in Direct mode, the customer's browser loads Apple's Apple Pay JS SDK from `applepay.cdn-apple.com` on the checkout page and on the plugin's Apple Pay page. The plugin sends no order or customer data with that request. The payment itself is authorised on the customer's device and handed to Hesabe.

Apple's [Apple Pay on the Web acceptable use guidelines](https://developer.apple.com/support/terms/apple-pay-acceptable-use-guidelines-for-websites) and [privacy policy](https://www.apple.com/legal/privacy/).

== Installation ==

1. Install and activate WooCommerce.
2. In `Plugins`, choose `Add New Plugin`, search for "Hesabe for WooCommerce", then install and activate it. Or upload the plugin's ZIP with `Upload Plugin`.
3. Open `WooCommerce` > `Settings` > `Payments` > `Hesabe`.
4. Tick `Enable Hesabe Payments`.
5. Choose the `Environment`. Sandbox is filled in with Hesabe's public sandbox keys. For Production, enter the merchant code, access code, secret key and IV key of your Hesabe account.
6. Choose the `Integration mode`. In Direct mode, tick the brands to show: KNET, Visa / Mastercard, and the Apple Pay types enabled on your Hesabe account.
7. Save, then place a test order.

== Frequently Asked Questions ==

= Do I need a Hesabe account? =

For live payments, yes. Hesabe gives you a merchant code, an access code, a secret key and an IV key. For testing, the Sandbox environment works with the public sandbox keys the plugin is filled in with. Hesabe lists the test cards in its [sandbox details](https://developer.hesabe.com/docs/support/sandbox-details).

= Which payment methods can my customers use? =

KNET, Visa and Mastercard, and Apple Pay. Hesabe decides which of them are active on your account.

= Does it work with the Checkout block? =

Yes, and with the classic checkout shortcode. It also declares compatibility with High-Performance Order Storage.

= A customer paid, but the order is still pending or cancelled. What now? =

Open the order, choose `Check Hesabe payment status` under `Order actions` and press `Update`. The plugin asks Hesabe and marks the order paid when Hesabe confirms the payment.

This happens when the customer does not come back to the shop and Hesabe's webhook cannot reach it. The webhook address is `https://your-shop.example.com/wc-api/wc_hesabe_webhook/`, or `https://your-shop.example.com/?wc-api=wc_hesabe_webhook` with plain permalinks. The shop must be reachable from the internet over HTTPS, and a firewall must let Hesabe's POST through.

= What does Apple Pay need? =

* Apple Pay enabled on your Hesabe merchant account. Ask support@hesabe.com.
* Your shop's domain registered with Hesabe for Apple Pay, for the environment you use. Ask itsupport@hesabe.com.
* Apple's verification file reachable at `/.well-known/apple-developer-merchantid-domain-association` on your shop.
* `Integration mode` set to Direct, with the Apple Pay types of your account ticked.

Apple Pay is offered only where the customer's device and browser support it.

= My shop does not sell in KWD. Does it work? =

Turn on `Currency converter`. The plugin then sends the order's currency, and Hesabe charges the converted amount in KWD. The order note shows the amount charged next to the order total. With the setting off, the plugin sends the order's amount without a currency and Hesabe charges that number in KWD.

= Hesabe does not appear at checkout. Why? =

Check that the gateway is enabled, that WooCommerce is active, and that all four keys of the chosen environment are filled in.

= The checkout shows an error from Hesabe. Where do I look? =

The message, and the order note, give Hesabe's reason. For example, Hesabe refuses a customer email at a domain that receives no mail. Check that the keys match the environment.

= Which versions was it tested with? =

Against Hesabe's sandbox in November 2026, with KNET and Visa / Mastercard in Hosted and Direct mode, on the Checkout block and the classic checkout: WordPress 7.1.4 with WooCommerce 11.2.2 on PHP 8.5, and WordPress 6.9.4 with WooCommerce 10.7.0 on PHP 8.3.

== Changelog ==

= 11.2 =
* Declares compatibility with WooCommerce 11.2, WordPress 7.1 and the Cart and Checkout blocks.
* Orders are updated from Hesabe's signed webhook (Checkout API 3.0), for customers who never come back to the shop.
* New order action: `Check Hesabe payment status`.
* A failed return no longer changes a paid order.
* The checkout shows Hesabe's reason when Hesabe refuses a payment.
* The return works with plain permalinks.
