<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = basename(__FILE__, '.php');

$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$input = file_get_contents('php://input');
$input = explode('&', $input);

$pairs = [];

foreach ($input as $pair) {
    $pair = explode('=', $pair);

    if (count($pair) == 2) {
        $pairs[$pair[0]] = urldecode($pair[1]);
    }
}

$pairs['cmd'] = '_notify-validate';

$ch = curl_init('https://ipnpb.paypal.com/cgi-bin/webscr');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_VERIFYPEER => 1,
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => http_build_query($pairs),
    CURLOPT_FORBID_REUSE => 1,
    CURLOPT_HTTPHEADER => [
        'Connection: Close'
    ]
]);

$result = curl_exec($ch);

if (strcmp($result, 'VERIFIED') !== 0) {
    logTransaction($gatewayParams['name'], [
        'input' => $pairs,
        'ipnresult' => $result,
        'ip' => $_SERVER['REMOTE_ADDR']
    ], 'not-verified');

    header('HTTP/1.1 403 Forbidden');
    die();
}

logTransaction($gatewayParams['name'], [
    'input' => $pairs,
    'ipnresult' => $result,
    'ip' => $_SERVER['REMOTE_ADDR']
], 'verified');

if (isset($pairs['txn_type'], $pairs['mp_id']) && $pairs['txn_type'] == 'mp_cancel') {
    Capsule::table('paypal_billingagreement')
        ->where('id', '=', $pairs['mp_id'])
        ->update(['status' => 'Cancelled']);

    logActivity("Marked billing agreement #{$pairs['mp_id']} as Cancelled due to IPN notification");
}

if (isset($pairs['payment_status']) && $pairs['payment_status'] == 'Reversed') {
    $originalTxnId = $pairs['parent_txn_id'];

    paymentReversed($pairs['txn_id'], $originalTxnId, 0, 'paypalbilling');
    logTransaction('paypalbilling', $pairs, 'Payment Reversed');
}