# PassimPay for Drupal Commerce

1. Install and configure Drupal Commerce Payment, then enable the PassimPay module.
2. Create a payment gateway at /admin/commerce/config/payment-gateways with the machine name passimpay.
3. Set Merchant ID to your PassimPay Platform ID, IPN Secret to your API Key, and Default currency to USD.
4. Set the PassimPay platform Notification URL to https://shop.example/passimpay/ipn, using your actual domain and installation subdirectory. Grant the anonymous role the access commerce passimpay ipn permission.
5. Clear the Drupal cache and complete a test order. See UPGRADE.md for payment accounting and updating existing installations.

Account: https://account.passimpay.io/
Support: https://passimpay.io/en/contacts
