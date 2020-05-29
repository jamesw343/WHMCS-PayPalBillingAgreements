<?php

if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    die();
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/PayPalNVP.php';

$whmcs = DI::make('app');

$dateCutoff = date('Y-m-d', strtotime('+' . $whmcs->get_config('CCProcessDaysBefore') . ' days'));

$invoices = Capsule::table('tblinvoices')
    ->where('status', '=', 'Unpaid')
    ->where('duedate', '<=', $dateCutoff)
    ->where('paymentmethod', '=', 'paypalbilling');

if ($whmcs->get_config('CCAttemptOnlyOnce')) {
    $invoices->where('last_capture_attempt', '=', '0000-00-00 00:00:00');
}

$invoices = $invoices->get();

$retryWeeks = intval($whmcs->get_config('CCRetryEveryWeekFor'));

foreach ($invoices as $invoice) {
    if ($retryWeeks > 0 && time() > strtotime("+{$retryWeeks} weeks", strtotime($invoice->duedate)) && $invoice->last_capture_attempt != '0000-00-00 00:00:00') {
        logActivity("PayPal Billing Agreements: Invoice ID: {$invoice->id} due over {$retryWeeks} week(s) ago, skipping");
        continue;
    }

    // Don't automatically charge Mass Pay invoices

    $massPayment = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', '=', $invoice->id)
        ->where('type', '=', 'Invoice')
        ->count();

    if ($massPayment > 0) {
        logActivity("PayPal Billing Agreements: Invoice ID: {$invoice->id} is a Mass Pay invoice, skipping");
        continue;
    }

    // Don't automatically charge Add Funds invoices

    $addFunds = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', '=', $invoice->id)
        ->where('type', '=', 'AddFunds')
        ->count();

    if ($addFunds > 0) {
        logActivity("PayPal Billing Agreements: Invoice ID: {$invoice->id} is an Add Funds invoice, skipping");
        continue;
    }

    $clientId = intval($invoice->userid);

    $billingAgreement = Capsule::table('paypal_billingagreement')
        ->where('client_id', '=', $clientId)
        ->where('status', '=', 'Active')
        ->first();

    if (!$billingAgreement) {
        logActivity("PayPal Billing Agreements: User ID: {$clientId} does not have an active PayPal Billing Agreement to pay Invoice ID: {$invoice->id}, skipping");
        continue;
    }

    $disableAutoCCProcessing = @Capsule::table('tblclients')
        ->where('id', '=', $clientId)
        ->first()
        ->disableautocc;

    if ($disableAutoCCProcessing) {
        logActivity("PayPal Billing Agreements: User ID: {$clientId} has auto-cc processing disabled for Invoice ID: {$invoice->id}, skipping");
        continue;
    }

    $balance = $invoice->total;

    $transactions = Capsule::table('tblaccounts')
        ->where('invoiceid', '=', $invoice->id)
        ->get();

    foreach ($transactions as $transaction) {
        $balance -= $transaction->amountin;
        $balance += $transaction->amountout;
    }

    if ($balance <= 0) {
        logActivity("PayPal Billing Agreements: Invoice ID: {$invoice->id} has a zero-balance, skipping");
        continue;
    }

    $response = (new PayPalNVP())
        ->addPair('AMT', $balance)
        ->addPair('CURRENCYCODE', 'USD')
        ->addPair('PAYMENTACTION', 'Sale')
        ->addPair('DESC', $whmcs->get_config('CompanyName') . ' Invoice #' . $invoice->id)
        ->addPair('REFERENCEID', $billingAgreement->id)
        ->addPair('NOTIFYURL', $whmcs->get_config('SystemURL') . '/modules/gateways/callback/paypalbilling.php')
        ->execute('DoReferenceTransaction');

    Capsule::table('tblinvoices')
        ->where('id', '=', $invoice->id)
        ->update(['last_capture_attempt' => date('Y-m-d H:i:s')]);

    if (!$response || !$response['success'] || $response['response']['ACK'] !== 'Success' || !$response['response']['TRANSACTIONID']) {
        logTransaction('paypalbilling', $response, 'error');
        logActivity("PayPal Billing Agreements: Unable to charge Invoice ID: {$invoice->id}");
        continue;
    }

    logTransaction('paypalbilling', $response, 'success');
    logActivity("PayPal Billing Agreements: Successfully charged Invoice ID: {$invoice->id}");
    addInvoicePayment(
        $invoice->id,
        $response['response']['TRANSACTIONID'],
        $response['response']['AMT'],
        $response['response']['FEEAMT'],
        'paypalbilling'
    );
}

$statusCheck = @Capsule::table('tblpaymentgateways')
    ->where('gateway', '=', 'paypalbilling')
    ->where('setting', '=', 'enableCronStatusCheck')
    ->first()
    ->value;

if ($statusCheck === 'on') {
    $activeAgreements = Capsule::table('paypal_billingagreement')
        ->where('status', '=', 'Active')
        ->get();

    foreach ($activeAgreements as $activeAgreement) {
        $response = (new PayPalNVP())
            ->addPair('REFERENCEID', $activeAgreement->id)
            ->execute('BillAgreementUpdate');

        if (!$response['success']) {
            continue;
        }

        // PayPal error code 10201: Billing Agreement was cancelled
        if (isset($response['response']['L_ERRORCODE0']) && $response['response']['L_ERRORCODE0'] === '10201') {
            Capsule::table('paypal_billingagreement')
                ->where('id', '=', $activeAgreement->id)
                ->update([
                    'status' => 'Cancelled',
                    'updated_at' => time(),
                ]);

            logActivity("Marked billing agreement #{$activeAgreement->id} as Cancelled due to cron check");
        } else {
            Capsule::table('paypal_billingagreement')
                ->where('id', '=', $activeAgreement->id)
                ->update([
                    'acc_email' => @$response['response']['EMAIL'],
                    'acc_payer_id' => @$response['response']['PAYERID'],
                    'acc_payer_status' => @$response['response']['PAYERSTATUS'],
                    'acc_first_name' => @$response['response']['FIRSTNAME'],
                    'acc_last_name' => @$response['response']['LASTNAME'],
                    'acc_business' => @$response['response']['BUSINESS'],
                    'acc_country_code' => @$response['response']['COUNTRYCODE'],
                    'updated_at' => time(),
                ]);
        }
    }
}