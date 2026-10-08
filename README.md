# Zyntrodata Payments — Installation Guide

For Magento Open Source / Adobe Commerce 2.4.6 (including 2.4.6-p8), PHP 8.1 or 8.2, and classic checkout.

This working source contains an unreleased risk-review update. Existing ZIPs have not been rebuilt. See [payment operations](docs/Payment_Operations.md) for hosted E-Check, reconciliation, cancellation and refund behavior and outstanding release requirements.

## 1. Install

Ask your Magento developer to install and test the module on a staging store first.

Extract the ZIP contents into:

    app/code/Zyntrodata/Payments/

The file `registration.php` must sit directly inside that folder.

From the Magento root, run as the Magento filesystem owner:

    php bin/magento module:enable Zyntrodata_Payments
    php bin/magento setup:upgrade
    php bin/magento setup:di:compile
    php bin/magento setup:static-content:deploy en_US
    php bin/magento cache:clean

Replace `en_US` with your store's locale(s). Ensure Magento cron is running. When updating an existing installation, use your normal deployment process to refresh generated static assets.

## 2. Configure

Open **Stores → Configuration → Sales → Payment Methods → Zyntrodata — Classic Checkout**.

Select the correct website/store scope, then enter:

- **API Origin:** the HTTPS address supplied by Zyntrodata, without an `/api` suffix.
- **Partner Code**, **Secure Key**, and **Secure IV:** supplied separately through a secure channel.
- **Checkout Title:** Zyntrodata Payments (update this value when upgrading an existing installation).
- **Hosted Payment Return URL:** optional. Leave blank for the store's normal checkout success page, or enter your own HTTPS return destination. HTTP loopback addresses such as `http://localhost:8088/` are allowed for development by the matching API update. This setting does not change Magento's Base URLs or choose the hosted payment form; that form URL comes from Zyntrodata.
- **Enabled:** Yes.

Save and clean the configuration cache. Use the API address and credentials issued for the intended environment; test and production credentials are separate.

Configure this plugin under Payment Methods; no edits to Magento core, project code or global Secure Base URL are needed for hosted returns. A live destination still needs working HTTPS. Public HTTPS webhook delivery is a separate hosting requirement and is not enabled by setting a browser return URL.

Zyntrodata must enable Magento and your payment options for your merchant account. Checkout currently requires USD.

## 3. Check checkout

Add an item and proceed through classic checkout to **Review & Payments**.

Select **Zyntrodata Payments** and confirm that your assigned payment options appear. For QR, verify the image and destination details. For Bank Transfer, select a country and verify the bank details. Customer details, order number, and total come from checkout.

Coordinate an approved test payment with Zyntrodata before going live. Orders remain On hold until payment is confirmed. Signed notifications retrieve current API status and record payment without charging again. Your public HTTPS callback must be reachable and Magento cron must run. E-Check redirects to the Zyntrodata hosted form; checkout does not collect bank details.

## Need help?

If an option is missing or a notice appears, contact your Zyntrodata onboarding/support contact with the Magento version, selected environment, and error text or screenshot. Never include credentials or tokens.

## Replacing an earlier test package

Version 0.2.0 uses the module name `Zyntrodata_Payments` and payment code `zyntrodata`. If an earlier package was installed under a different name, ask your developer to disable that module before enabling this one. Do not enable both together. Re-enter the supplied API settings and credentials in the new configuration section, then refresh generated assets and Magento caches. Existing settings and order payment metadata are not migrated automatically. Reconcile any pending orders from the earlier module through the portal/backoffice before retiring it; do not resubmit them.
