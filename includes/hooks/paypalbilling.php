<?php

use WHMCS\View\Menu\Item as MenuItem;
use WHMCS\Database\Capsule;

add_hook('ClientAreaPrimaryNavbar', 10, function(MenuItem $primaryNavbar)
{
    if (!is_null($primaryNavbar->getChild('Billing'))) {
        $primaryNavbar->getChild('Billing')
            ->addChild('Manage PayPal Billing', [
                'label' => 'Manage PayPal Billing',
                'uri' => 'paypalbilling.php?action=manage',
                'order' => 55
            ]);
    }
});

add_hook('ClientAreaPrimarySidebar', 10, function(MenuItem $primarySidebar)
{
    if (!is_null($primarySidebar->getChild('My Account'))) {
        $primarySidebar->getChild('My Account')
            ->addChild('Manage PayPal Billing', [
                'label' => 'Manage PayPal Billing',
                'uri' => 'paypalbilling.php?action=manage',
                'order' => 25
            ]);

        $primarySidebar
            ->getChild('My Account')
            ->getChild('Manage PayPal Billing')
            ->setCurrent(APP::getCurrentFileName() === 'paypalbilling');
    }
});

add_hook('AdminAreaClientSummaryPage', 10, function($vars)
{
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