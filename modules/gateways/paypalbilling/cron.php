<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/PayPalNVP.php';

if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    die();
}

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
        logActivity("PayPal Billing Agreements: Invoice #{$invoice->id} due over {$retryWeeks} week(s) ago, skipping.");
        continue;
    }

    // Don't automatically charge Mass Pay invoices

    $massPayment = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', '=', $invoice->id)
        ->where('type', '=', 'Invoice')
        ->count();

    if ($massPayment > 0) {
        logActivity("PayPal Billing Agreements: Invoice #{$invoice->id} is a Mass Pay invoice, skipping.");
        continue;
    }

    $clientId = intval($invoice->userid);

    $billingAgreement = Capsule::table('paypal_billingagreement')
        ->where('client_id', '=', $clientId)
        ->where('status', '=', 'Active')
        ->first();

    if (!$billingAgreement) {
        logActivity("PayPal Billing Agreements: Client #{$clientId} does not have an active PayPal Billing Agreement to pay Invoice #{$invoice->id}, skipping.");
        continue;
    }

    $disableAutoCCProcessing = @Capsule::table('tblclients')
        ->where('id', '=', $clientId)
        ->first()
        ->disableautocc;

    if ($disableAutoCCProcessing) {
        logActivity("PayPal Billing Agreements: Client #{$clientId} has auto-cc processing disabled for Invoice #{$invoice->id}, skipping.");
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
        logActivity("PayPal Billing Agreements: Invoice #{$invoice->id} has a zero-balance, skipping.");
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
        logActivity("PayPal Billing Agreements: Unable to charge Invoice #{$invoice->id}");
        continue;
    }

    logTransaction('paypalbilling', $response, 'success');
    addInvoicePayment(
        $invoice->id,
        $response['response']['TRANSACTIONID'],
        $response['response']['AMT'],
        $response['response']['FEEAMT'],
        'paypalbilling'
    );
}