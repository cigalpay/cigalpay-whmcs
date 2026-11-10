# CigalPay for WHMCS

Official WHMCS payment gateway module for [CigalPay](https://cigalpay.com): your clients pay their invoices in crypto (Bitcoin, USDT, Ethereum, Solana, Litecoin, Dogecoin and more). Invoices stay in USD or EUR, and the client picks the coin on the CigalPay checkout.

- No webhook to set up: the module sends its notification address with every payment.
- An invoice is marked paid only from CigalPay's signed notification, for a checkout this WHMCS created for that invoice, at that invoice's price.
- Refunds in crypto from the WHMCS refund screen.

## Requirements

- WHMCS 8.0+
- PHP 7.4+ with `curl`
- Invoices in USD or EUR, up to 5,000 per invoice
- A CigalPay account ([sign up](https://cigalpay.com))

## Install

1. Download `cigalpay-whmcs.zip` from the [latest release](https://github.com/cigalpay/cigalpay-whmcs/releases/latest).
2. Unzip it at the root of your WHMCS installation. It adds:
   - `modules/gateways/cigalpay.php`
   - `modules/gateways/cigalpay/`
   - `modules/gateways/callback/cigalpay.php`

## Set up

1. In your CigalPay dashboard, open **Settings → API credentials** and copy your **Account ID**, your **notification signing secret** and, to refund in crypto, an **API key**.
2. In WHMCS, go to **System Settings → Payment Gateways → All Payment Gateways** and click **CigalPay (crypto)**.
3. Paste the values and save.

Clients then see **Crypto (Bitcoin, USDT, Ethereum…)** as a payment method on invoices in USD or EUR.

## How it works

1. On the invoice, the module creates a CigalPay checkout priced in the invoice currency and shows a **Pay** button. A checkout created in the last 25 minutes at the same price is reused (prices are locked for 30 minutes).
2. The client picks a coin and pays on the CigalPay checkout.
3. CigalPay sends signed notifications to `modules/gateways/callback/cigalpay.php`. The module checks the signature, that the checkout is one it created for that invoice (table `mod_cigalpay_checkouts`), the amount and the currency, then adds the payment to the invoice, once.
4. Every notification shows in **Billing → Gateway Log**, including partial payments and expired checkouts.

Recurring billing: each renewal invoice is paid like any other, with its own checkout. Crypto wallets don't allow automatic charges.

## Refunds

With the API key set, **Refund** on an invoice payment refunds through CigalPay: the client gets an email with a link to enter their address, and CigalPay sends the coin they paid with, once the refund is reviewed. The amount is in the invoice currency.

## Testing

Define `CIGALPAY_CHECKOUT_URL` and `CIGALPAY_MERCHANT_API_URL` in WHMCS's `configuration.php` to point the module at test servers.

## License

MIT
