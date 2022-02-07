<?php

use WHMCS\ClientArea;
use WHMCS\Database\Capsule;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';
require __DIR__ . '/modules/gateways/paypalbilling/PayPalNVP.php';
require __DIR__ . '/includes/invoicefunctions.php';
require __DIR__ . '/includes/gatewayfunctions.php';
require __DIR__ . '/includes/clientfunctions.php';

$ca = new ClientArea();

$ca->setPageTitle('PayPal Billing');
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('clientarea.php', Lang::trans("clientareatitle"));
$ca->addToBreadCrumb(routePath("account-index"), Lang::trans("clientareanavdetails"));
$ca->addToBreadCrumb('paypalbilling.php', 'PayPal Billing');

$ca->initPage();

$ca->requireLogin();

Menu::primarySidebar('clientView');
Menu::secondarySidebar('clientView');

$action = strtolower(@$_GET['action']);
$action = preg_replace('/[^a-z]/', '', $action);

$whmcs = DI::make('app');
$systemUrl = trim($whmcs->get_config('SystemURL'), '/');

$ca->assign('systemUrl', $systemUrl);

checkContactPermission('invoices');

switch ($action) {

    case 'create':

        $agreement = Capsule::table('paypal_billingagreement')
            ->where('client_id', '=', $ca->getUserId())
            ->where('status', '=', 'Active')
            ->first();

        if ($agreement || @$_SERVER['REQUEST_METHOD'] != 'POST') {
            header("Location: {$systemUrl}/paypalbilling.php?action=manage");
            die();
        }

        $returnSuccessInvoiceId = '';

        if (isset($_POST['invoiceid']) && is_numeric($_POST['invoiceid'])) {
            $returnSuccessInvoiceId .= '&invoiceid=' . intval($_POST['invoiceid']);
        }

        $result = (new PayPalNVP())
            ->addPair('PAYMENTREQUEST_0_PAYMENTACTION', 'AUTHORIZATION')
            ->addPair('PAYMENTREQUEST_0_AMT', 0)
            ->addPair('PAYMENTREQUEST_0_CURRENCYCODE', 'USD')
            ->addPair('L_BILLINGTYPE0', 'MerchantInitiatedBilling')
            ->addPair('L_BILLINGAGREEMENTDESCRIPTION0', $whmcs->get_config('CompanyName') . ' Billing Agreement')
            ->addPair('cancelUrl', $whmcs->get_config('SystemURL') . '/paypalbilling.php?action=returnfailed')
            ->addPair('returnUrl', $whmcs->get_config('SystemURL') . '/paypalbilling.php?action=returnsuccess' . $returnSuccessInvoiceId)
            ->execute('SetExpressCheckout');

        $noredirect = isset($_POST['noredirect']);

        if ($result['success'] && @$result['response']['ACK'] == 'Success' && @$result['response']['TOKEN']) {
            if ($noredirect) {
                die($result['response']['TOKEN']);
            } else {
                header("Location: https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token={$result['response']['TOKEN']}");

                die("<a href=\"https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token={$result['response']['TOKEN']}\">Click here to continue.</a>");
            }
        } else {
            logActivity('Unable to create PayPal Billing Agreement: ' . json_encode($result), 0);

            if ($noredirect) {
                header('HTTP/1.1 500 Internal Server Error');
                die();
            } else {
                die('Unable to create PayPal billing agreement. Please refresh the page and try again later.');
            }
        }

        break;

    case 'cancel':

        $agreement = Capsule::table('paypal_billingagreement')
            ->where('client_id', '=', $ca->getUserId())
            ->where('status', '=', 'Active')
            ->first();

        if (!$agreement || @$_SERVER['REQUEST_METHOD'] != 'POST') {
            header("Location: {$systemUrl}/paypalbilling.php?action=manage");
            die();
        }

        $result = (new PayPalNVP())
            ->addPair('REFERENCEID', $agreement->id)
            ->addPair('BILLINGAGREEMENTSTATUS', 'Canceled')
            ->execute('BillAgreementUpdate');

        $noredirect = isset($_POST['noredirect']);

        if ($result['success'] && (@$result['response']['ACK'] == 'Success' || (@$result['response']['L_ERRORCODE0'] == '10201'))) {
            Capsule::table('paypal_billingagreement')
                ->where('id', '=', $agreement->id)
                ->update([
                    'status' => 'Cancelled',
                    'updated_at' => time(),
                ]);

            if (!$noredirect) {
                header("Location: {$systemUrl}/paypalbilling.php");
            }

            die();
        } else {
            logActivity('Unable to cancel PayPal Billing Agreement: ' . json_encode($result), 0);

            if ($noredirect) {
                header('HTTP/1.1 500 Internal Server Error');
                die();
            } else {
                die('Unable to cancel PayPal billing agreement. Please refresh the page and try again later or cancel this agreement directly on paypal.com');
            }
        }

        break;

    case 'returnsuccess':

        $token = @$_GET['token'];

        if (!$token) {
            header("Location: {$systemUrl}/paypalbilling.php?action=manage");
            die();
        }

        $result = (new PayPalNVP())
            ->addPair('TOKEN', $token)
            ->execute('CreateBillingAgreement');

        if ($result['success'] && @$result['response']['ACK'] == 'Success' && @$result['response']['BILLINGAGREEMENTID']) {
            $status = (new PayPalNVP())
                ->addPair('REFERENCEID', $result['response']['BILLINGAGREEMENTID'])
                ->execute('BillAgreementUpdate');

            Capsule::table('paypal_billingagreement')
                ->insert([
                    'id' => $result['response']['BILLINGAGREEMENTID'],
                    'client_id' => $ca->getUserId(),
                    'acc_email' => @$status['response']['EMAIL'],
                    'acc_payer_id' => @$status['response']['PAYERID'],
                    'acc_payer_status' => @$status['response']['PAYERSTATUS'],
                    'acc_first_name' => @$status['response']['FIRSTNAME'],
                    'acc_last_name' => @$status['response']['LASTNAME'],
                    'acc_business' => @$status['response']['BUSINESS'],
                    'acc_country_code' => @$status['response']['COUNTRYCODE'],
                    'status' => 'Active',
                    'created_at' => strtotime($result['response']['TIMESTAMP'])
                ]);

            if (isset($_GET['invoiceid']) && is_numeric($_GET['invoiceid'])) {
                header('Location: ' . $systemUrl . '/paypalbilling.php?action=submitpayment&invoiceid=' . intval($_GET['invoiceid']));
            } else {
                header("Location: {$systemUrl}/paypalbilling.php?action=manage");
            }

            die();
        } else {
            logActivity('Unable to create PayPal Billing Agreement (2): ' . json_encode($result), 0);

            header("Location: {$systemUrl}/paypalbilling.php?action=returnfailed");
            die();
        }

        break;

    case 'submitpayment':

        if (!isset($_GET['invoiceid'])) {
            header("Location: {$systemUrl}/paypalbilling.php?action=manage");
            die();
        }

        $invoiceId = intval($_GET['invoiceid']);
        $invoice = Capsule::table('tblinvoices')
            ->where('id', '=', $invoiceId)
            ->where('userid', '=', $ca->getUserId())
            ->first();

        if (!$invoice) {
            header("Location: {$systemUrl}/paypalbilling.php?action=manage");
            die();
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
            header("Location: {$systemUrl}/viewinvoice.php?id={$invoice->id}&paymentsuccess=true");
            die();
        }

        $agreement = Capsule::table('paypal_billingagreement')
            ->where('client_id', '=', $ca->getUserId())
            ->where('status', '=', 'Active')
            ->first();

        if (!$agreement) {
            header("Location: {$systemUrl}/paypalbilling.php?action=manage&invoiceid={$invoice->id}");
            die();
        }

        if (!isset($_POST['confirm']) || $_POST['confirm'] != true) {
            $ca->assign('submitPayment', true);
            $ca->assign('invoice', $invoice);
            $ca->assign('balance', number_format($balance, 2));
            $ca->assign('invoiceid', $invoice->id);
            $ca->assign('agreement', $agreement);
            $ca->setTemplate('paypalbilling');

            break;
        }

        $response = (new PayPalNVP())
            ->addPair('AMT', $balance)
            ->addPair('CURRENCYCODE', 'USD')
            ->addPair('PAYMENTACTION', 'Sale')
            ->addPair('DESC', $whmcs->get_config('CompanyName') . ' Invoice #' . $invoice->id)
            ->addPair('REFERENCEID', $agreement->id)
            ->addPair('NOTIFYURL', $whmcs->get_config('SystemURL') . '/modules/gateways/callback/paypalbilling.php')
            ->addPair('IPADDRESS', $_SERVER['REMOTE_ADDR'])
            ->execute('DoReferenceTransaction');

        Capsule::table('tblinvoices')
            ->where('id', '=', $invoice->id)
            ->update(['last_capture_attempt' => date('Y-m-d H:i:s')]);

        if (!$response || !$response['success'] || $response['response']['ACK'] !== 'Success' || !$response['response']['TRANSACTIONID']) {
            logTransaction('paypalbilling', $response, 'error');

            if (isset($_POST['noredirect'])) {
                header('HTTP/1.1 500 Internal Server Error');
            } else {
                header("Location: {$systemUrl}/viewinvoice.php?id={$invoice->id}&paymentfailed=true");
            }
            die();
        }

        logTransaction('paypalbilling', $response, 'success');
        addInvoicePayment(
            $invoice->id,
            $response['response']['TRANSACTIONID'],
            $response['response']['AMT'],
            $response['response']['FEEAMT'],
            'paypalbilling'
        );

        if (!isset($_POST['noredirect'])) {
            header("Location: {$systemUrl}/viewinvoice.php?id={$invoice->id}&paymentsuccess=true");
        }
        die();

        break;

    case 'manage':
    default:

        $agreement = Capsule::table('paypal_billingagreement')
            ->where('client_id', '=', $ca->getUserId())
            ->where('status', '=', 'Active')
            ->first();

        if (isset($_GET['invoiceid'])) {
            $ca->assign('invoiceid', intval($_GET['invoiceid']));
        }

        $disableAutoCC = @Capsule::table('tblclients')
            ->where('id', '=', $ca->getUserId())
            ->first()->disableautocc;

        $ca->assign('billingAgreement', $agreement);
        $ca->assign('billingAgreementDate', date('m/d/Y', @$agreement->created_at));
        $ca->assign('action', $action);
        $ca->assign('processDays', \WHMCS\Config\Setting::getValue('CCProcessDaysBefore'));
        $ca->assign('disableAutoCC', $disableAutoCC);
        $ca->setTemplate('paypalbilling');

        break;

}

$ca->output();
