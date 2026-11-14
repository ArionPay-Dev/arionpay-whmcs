# ArionPay for WHMCS

Lets your clients pay WHMCS invoices with cryptocurrency through [ArionPay](https://arionpay.com). The money goes straight to your own wallets: ArionPay never holds it.

Version 2.1.0. **Not yet tested on a real WHMCS install.** It passes 29 checks against a stand-in of the WHMCS functions a gateway uses and a real ArionPay server. Please try **Test Mode** first (see below and `TESTING.md`).

## What you need

- WHMCS 8.x, PHP 7.4 or newer, with the PHP cURL extension
- An ArionPay account with one store, and a wallet connected to it for real payments (Test Mode works without a wallet)
- Your WHMCS site on a public `https://` address on port 443 or 8443, so ArionPay can reach it

## Install

1. Open the zip file.
2. Copy its contents into your WHMCS main folder (the folder that has `configuration.php` in it). These files go in place:
   - `modules/gateways/arionpay.php`
   - `modules/gateways/callback/arionpay.php`
   - `modules/gateways/arionpay/` (folder)
3. In WHMCS go to **System Settings > Payment Gateways > All Payment Gateways** and activate **ArionPay Crypto**.

## Settings: where each one comes from

| WHMCS setting | Where to get it |
| --- | --- |
| **Public Key** | In your ArionPay dashboard, open your store and copy the **API key**. It starts with `pk_`. |
| **Secret Key** | In your ArionPay dashboard, open your store and copy the **API secret**. It starts with `sk_`. ArionPay asks for your authenticator code first. |
| **Webhook Secret** | On the **Webhooks** page in your ArionPay dashboard, copy the **signing secret**. It starts with `whsec_`. |
| **API Base URL** | Leave it as `https://merchant.arionpay.com` unless ArionPay tells you to change it. |
| **Test Mode** | Tick it to try the module without real money. **Untick it before you take real payments.** |

## Payment updates

Nothing to paste: every invoice tells ArionPay to send its payment updates to

```
https://YOUR-WHMCS-ADDRESS/modules/gateways/callback/arionpay.php
```

(built from the **System URL** in WHMCS General Settings, so that must be your real public https address). If you also want to use **Send test webhook** in the ArionPay dashboard, paste the same address on its **Webhooks** page.

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off. This module needs the current webhook format.

## Try it first

1. Tick **Test Mode** and save.
2. Open an unpaid invoice in the client area, choose ArionPay and press the pay button.
3. On the ArionPay page, choose a coin and use the test payment buttons. No real money moves.
4. The invoice should now be **Paid**. Look under **Billing > Gateway Log** to see what happened.
5. You can also press **Send test webhook** on the ArionPay Webhooks page. It should show as delivered and it does not change any invoice.

## How payment works

- The customer presses the pay button on the invoice. The module creates an ArionPay invoice, or reuses the one that is still open, and sends the customer to the ArionPay payment page.
- When ArionPay confirms the payment, it tells WHMCS through the callback URL, and the payment is added to the invoice.
- The exact amount is confirmed automatically, and so is a small difference inside your store's payment tolerance (default 1%). If a customer sends a different amount, the invoice stays unpaid: ArionPay's Payment Radar shows that payment in your dashboard and you decide whether to accept it. If you accept it, the invoice is paid here too.
- Coming back from the payment page does not mark an invoice paid.
- The module adds a payment only when the ArionPay invoice amount and currency exactly match the WHMCS invoice balance and currency. If they do not match, it writes that in the Gateway Log and does not pay the invoice. You can then add the payment by hand.
- A late payment or a payment accepted by hand in ArionPay is noted in the Gateway Log.

## Good to know

- Leave **Convert To For Processing** off for this gateway. The module always charges in the WHMCS invoice's own currency.
- Your WHMCS invoice currency must be one ArionPay accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR.
- Refunds are made from your own wallet. The module does not send refunds.
- The module sends ArionPay the invoice number, amount, currency and the link back to the invoice. It does not send your client's name or email.
- The module keeps one small table, `mod_arionpay_invoices`, to remember which ArionPay invoice belongs to which WHMCS invoice.
- Keys and secrets are never written to the Gateway Log.

## Upgrading from the old ArionPay module

Replace the old files with these ones. The file names are the same, so WHMCS treats it as the same gateway and your saved Public Key, Secret Key and API Base URL are kept. You still need to add the **Webhook Secret** and turn off the old v1 compatibility in ArionPay, as described above. Invoices that were created with the old module are not paid through this new one.

## Removing the module

Deactivate the gateway in WHMCS and delete the three items listed under Install. You can also drop the `mod_arionpay_invoices` table.
