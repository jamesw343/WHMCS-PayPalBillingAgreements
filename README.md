# WHMCS PayPal Billing Agreements

### Features
- Automatically charge users the correct invoice amounts, eliminating accidental overpayments or missed/late payments
- Only attempts to automatically charge invoices with the payment method as "PayPal Billing Agreements"
- Customers can make one-time payments from the client area without being redirected to PayPal and back (after successfully setting up a billing agreement)
- Honors the "Process Days Before Due" and "Retry Every Week For" automation setting for when to charge a customer's PayPal account
- Honors the "Auto CC Processing" setting for individual customers
- Displays whether a customer has an active billing agreement or not in the admin area

### Prerequisites
For this addon to work correctly, you **must** have PayPal reference transactions enabled on your PayPal account. You will need to contact PayPal and request that this feature be enabled for your account. Usually you'll need to go through an approval process, which may include a personal credit pull.

### Installation
1. Rename the directory `/templates/six` to match the name of your current client area template
2. Upload all files to your WHMCS root directory
3. Run the following SQL statement to create the billing agreement table in your WHMCS database:
```sql
CREATE TABLE `paypal_billingagreement` (
    `id` varchar(32) NOT NULL,
    `client_id` int(10) NOT NULL,
    `acc_email` varchar(127),
    `acc_payer_id` varchar(17),
    `acc_payer_status` varchar(10),
    `acc_first_name` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci,
    `acc_last_name` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci,
    `acc_business` varchar(127) CHARACTER SET utf8 COLLATE utf8_general_ci,
    `acc_country_code` varchar(2),
    `status` varchar(32) NOT NULL,
    `created_at` int(10) NOT NULL,
    `updated_at` int(10),
    PRIMARY KEY (`id`),
    FOREIGN KEY (`client_id`) REFERENCES `tblclients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);
```
4. Activate the module in Setup => Payments => Payment Gateways
5. Enter your PayPal API Username, Password, and Signature in Setup => Payments => Payment Gateways => Manage Existing Gateways => PayPal Billing Agreement
6. *(Optional)* Set your IPN URL to `https://your.site/modules/gateways/callback/paypalbilling.php`. If you don't setup your IPN URL, consider enabling "Enable Cron Status Check" under the payment gateways module options. *(Warning: Running the cron status check with many active billing agreements may significantly extend the runtime of the cron job)*
7. Create a custom email template under Setup => Email Templates. Make sure the `Email Type` is set to `Invoice` and the `Unique Name` is `PayPal Billing Agreement Payment Failed`. You may use the following as a starter template:
```html
<p>Dear {$client_name},</p>
<p>This is a notice that a recent PayPal Billing Agreement payment we attempted on your PayPal account failed.</p>
<p>Invoice Date: {$invoice_date_created}<br />Invoice No: {$invoice_num}<br />Amount: {$invoice_total}<br />Status: {$invoice_status}</p>
<p>You will need to login to our client area to pay the invoice manually at {$invoice_link}</p>
<p>{$signature}</p>
```
8. Run a cron job at 11:00 PM every night:
`0 23 * * php -q /path/to/whmcs/modules/gateways/paypalbilling/cron.php`

### Upgrade
If you installed this addon prior to May 29th, 2020, run the following SQL statements to update the database schema.
```sql
ALTER TABLE `paypal_billingagreement` ADD COLUMN `acc_country_code` varchar(2) AFTER `client_id`;
ALTER TABLE `paypal_billingagreement` ADD COLUMN `acc_business` varchar(127) CHARACTER SET utf8 COLLATE utf8_general_ci AFTER `client_id`;
ALTER TABLE `paypal_billingagreement` ADD COLUMN `acc_last_name` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci AFTER `client_id`;
ALTER TABLE `paypal_billingagreement` ADD COLUMN `acc_first_name` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci AFTER `client_id`;
ALTER TABLE `paypal_billingagreement` ADD COLUMN `acc_payer_status` varchar(10) AFTER `client_id`;
ALTER TABLE `paypal_billingagreement` ADD COLUMN `acc_payer_id` varchar(17) AFTER `client_id`;
ALTER TABLE `paypal_billingagreement` ADD COLUMN `acc_email` varchar(127) AFTER `client_id`;
ALTER TABLE `paypal_billingagreement` ADD COLUMN `updated_at` int(10) AFTER `created_at`;
```

### Limitations
- Currently designed to make auto-payments on a separate cron job. It's best to run this cron job at a separate time from your normal WHMCS daily cron to avoid conflicts.
- Does not support e-checks (or rather, the addon will mark the invoice as PAID instantly regardless of whether the e-check clears). Recommended to disable accepting e-checks on your PayPal account to avoid this.
- No auto-installation
- No ability to manage a customer's billing agreement settings through the admin area (either login as client or go to paypal.com to cancel existing subscriptions)
