# Zyntrodata Payments for Magento

Version 0.3.0-rc2 — staging acceptance-test package, 27 October 2026.
For Magento 2.4.6 / 2.4.6-p8, PHP 8.1 or 8.2, classic checkout.

## Install

Have your Magento developer back up and install on a staging store first.
Extract the ZIP directly into `app/code/Zyntrodata/Payments/`.
`registration.php` must be directly inside that folder.

Run from the Magento root as its filesystem owner:

```sh
php bin/magento module:enable Zyntrodata_Payments
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy en_US
php bin/magento cache:clean
```

Use your actual store locales and normal static-asset deployment process.
Keep Magento cron running. Upgrade the existing Zyntrodata module in place;
do not enable a duplicate payment module or resubmit existing orders.

## Configure

Open **Stores → Configuration → Sales → Payment Methods → Zyntrodata — Classic Checkout**.
Select the correct store/website scope, enable the method and enter the API Origin,
Partner Code, Secure Key and Secure IV supplied separately by Zyntrodata.
The API origin must be HTTPS, without `/api`; use matching DEV/UAT credentials for testing.

**Hosted Payment Return URL** is optional. Leave it blank to use your normal checkout
success page, or enter an HTTPS destination. The hosted form URL comes from the API.
You do not need to edit Magento core or change its global Secure Base URL for this setting.
HTTP loopback returns are supported only for local development with the matching API.

Zyntrodata must enable Magento and the selected methods for your merchant. Checkout uses USD.
Before testing, have Zyntrodata confirm the matching API/hosted UI, database prerequisites
and webhook service are deployed. Your store needs a publicly reachable HTTPS callback
at `/zyntrodata/webhook/status`; permit it through your firewall and do not cache it.
Credentials and signing secrets must never be shared with customers or included in support screenshots.

## Verify before going live

- Confirm only your enabled payment methods appear. QR/Bank Transfer show the correct instructions.
- E-Check opens Zyntrodata's hosted form, reuses checkout details and returns to the correct store page.
- Orders stay **On hold** until the API confirms payment. E-Check advances on deposit;
  physical checks on clearance; NMI requires provider payment confirmation. Internal approval alone is insufficient.
- Verify a signed notification records a paid invoice without another charge, and repeated notifications do not duplicate it.
- Test eligible unpaid cancellation and the portal refund process with Zyntrodata.

For refunds, use **Request Refund** in the Zyntrodata portal. Approval adds a note only.
Once backoffice records the refund check as mailed, Magento records the actual amount
as an offline credit memo. It does not send another refund, restock inventory or confirm check clearance.
Paid or in-progress payments require portal review instead of unpaid cancellation.

If an order says **RECONCILIATION_REQUIRED**, keep it held and contact Zyntrodata with its
order/invoice number, amount, time and saved reference. Do not clear the submission marker,
place another order or ask the customer to pay again until the original outcome is resolved.

## Acceptance-test limitations

Local simulated-payment checks passed; live merchant end-to-end acceptance is still required.
Reads/authentication use a five-second budget; payment creation can take up to 45 seconds.
Options cache for 30 seconds; tokens cache for up to 15 minutes within expiry.
This ZIP excludes tests, internal documentation and credentials. Share credentials separately.
Contact your Zyntrodata onboarding contact before production activation.

## Release 0.3.0-rc2

Sends the order storefront hostname so the API can describe the payment as Payment for order #123 — shop.example.com. Requires the corresponding API update. No new settings or database script. Composer installation is also available; see Magento-Composer-Installation-Guide.md supplied with the release.
