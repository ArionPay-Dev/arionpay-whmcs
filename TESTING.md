# Testing the ArionPay WHMCS module (about 30 minutes)

Thank you for testing! Everything below uses **test mode**: no real money moves. Please work through the steps in order and note anything that looks different from "Expected".

## What you need

- WHMCS 8 or 9 on a public `https://` address (the **System URL** in *Setup > General Settings* must be that address)
- PHP 7.4 or newer with cURL
- An ArionPay account with one store (no wallet needed for test mode)
- A test client in WHMCS whose currency is one ArionPay accepts (USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR)

## 1. Install (5 minutes)

1. Unzip `arionpay-for-whmcs-2.1.0.zip` into the WHMCS main folder (the one with `configuration.php`). It adds `modules/gateways/arionpay.php`, `modules/gateways/arionpay/` and `modules/gateways/callback/arionpay.php`.
2. *System Settings > Payment Gateways > All Payment Gateways*: activate **ArionPay Crypto**.
3. Fill in:
   - **Public Key**: ArionPay dashboard > your store > API key (`pk_...`)
   - **Secret Key**: ArionPay dashboard > your store > API secret (`sk_...`)
   - **Webhook Secret**: ArionPay dashboard > Webhooks > signing secret (`whsec_...`)
   - **API Base URL**: leave `https://merchant.arionpay.com`
   - **Test Mode**: ticked
4. Save. *Expected:* the gateway saves without errors.
5. In ArionPay, open your store settings and check that **Old ArionPay (v1) integration compatibility** is **off**.

## 2. A normal payment

1. Create an invoice for your test client (for example 10.00), unpaid.
2. Log in as the client (or use *Login as Client*), open the invoice, choose **ArionPay**.
   *Expected:* a **Pay Now** button and the line "Test mode is on: no real money is taken."
3. Press **Pay Now**. *Expected:* the ArionPay payment page opens with the right amount and currency.
4. On the ArionPay page, choose a coin (for example USDT TRC20).
5. In another tab, open the ArionPay dashboard > **Invoices**, open this test invoice, and in **Try a test payment** press **Customer pays exactly**.
6. Go back to WHMCS. Within a minute: *Expected:*
   - the WHMCS invoice is **Paid**
   - one payment, with the ArionPay invoice id as the transaction id
   - *Billing > Gateway Log* shows "Payment added to invoice … TEST invoice, no real money."

## 3. Pressing Pay twice

1. Make another unpaid invoice, press **Pay Now**, close the ArionPay page, press **Pay Now** again.
   *Expected:* the **same** ArionPay page opens (same address).

## 4. Wrong amounts (Payment Radar)

1. New invoice, **Pay Now**, choose a coin, then in the dashboard press **Customer pays 5% less**.
   *Expected:* the WHMCS invoice stays **Unpaid**. In ArionPay the payment shows in **Payment Radar** for you to decide.
2. New invoice, same steps, press **Customer pays 1% less**.
   *Expected:* the invoice is **Paid** (inside the default 1% tolerance).
3. New invoice, same steps, press **Customer pays too late**.
   *Expected:* **Paid**, and the Gateway Log says "Paid late".

## 5. Invoice changed after the client went to pay

1. New invoice for 10.00, **Pay Now**, choose a coin (do not pay yet).
2. In WHMCS, edit the invoice: add an item so the total changes.
3. In the dashboard press **Customer pays exactly** on the old ArionPay invoice.
   *Expected:* the WHMCS invoice stays **Unpaid**; the Gateway Log says "Amount mismatch … NOT paid".
4. As the client, press **Pay Now** again. *Expected:* a new ArionPay page with the new total.

## 6. Webhook checks

1. ArionPay dashboard > **Webhooks**: paste `https://YOUR-WHMCS/modules/gateways/callback/arionpay.php`, then press **Send test webhook**.
   *Expected:* "delivered"; the Gateway Log says "Verified ArionPay test webhook received. Nothing changed."
2. In WHMCS, change the **Webhook Secret** to something wrong, save, and press **Send test webhook** again.
   *Expected:* not delivered (401); the Gateway Log says "Webhook refused". **Put the right secret back.**

## 7. Optional: one real payment

Untick **Test Mode**, connect a real wallet in ArionPay, and pay a small invoice (for example 2.00 USDT on TRON). *Expected:* the same as step 2, without "TEST".

## What to send back

For each step: OK, or what happened instead. If something went wrong, please include:

- WHMCS version, PHP version, and the module version (2.1.0)
- the matching lines from *Billing > Gateway Log* (they never contain keys)
- a screenshot of the page with the problem
- the ArionPay invoice id (shown in the payment page address after `/pay/`)
