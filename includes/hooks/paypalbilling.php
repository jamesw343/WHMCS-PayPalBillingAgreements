<?php

use WHMCS\View\Menu\Item as MenuItem;
use WHMCS\Database\Capsule;

add_hook('ClientAreaPrimaryNavbar', 10, function(MenuItem $primaryNavbar) {
    if (!is_null($primaryNavbar->getChild('Billing'))) {
        $primaryNavbar->getChild('Billing')
            ->addChild('Manage PayPal Billing', [
                'label' => 'Manage PayPal Billing',
                'uri' => 'paypalbilling.php?action=manage',
                'order' => 1000
            ]);
    }
});

add_hook('ClientAreaSecondarySidebar', 10, function(MenuItem $secondarySidebar) {
    if (!is_null($secondarySidebar->getChild('Billing'))) {
        $secondarySidebar->getChild('Billing')
            ->addChild('Manage PayPal Billing', [
                'label' => 'Manage PayPal Billing',
                'uri' => 'paypalbilling.php?action=manage',
                'order' => 1000
            ]);

        $filename = basename($_SERVER['REQUEST_URI'], ".php");
        $parseFile = explode('.', $filename);

        $secondarySidebar
            ->getChild('Billing')
            ->getChild('Manage PayPal Billing')
            ->setCurrent($parseFile['0'] === 'paypalbilling');

    }
});

add_hook('AdminAreaClientSummaryPage', 10, function($vars) {
    $clientId = $vars['userid'];

    $output = '<div class="clientsummaryactions">';

    $billingAgreement = Capsule::table('paypal_billingagreement')
        ->where('client_id', '=', $clientId)
        ->where('status', '=', 'Active')
        ->first();

    if ($billingAgreement) {
        $output .= '<strong class="textgreen">Valid PayPal Billing Agreement</strong> : ' . $billingAgreement->id;
    } else {
        $output .= '<strong class="textred">No active PayPal Billing Agreements found.</strong>';
    }

    $output .= '</div>';

    return $output;
});