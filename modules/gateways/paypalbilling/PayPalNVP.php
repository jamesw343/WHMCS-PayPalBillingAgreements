<?php

if (!defined("WHMCS")) {
    header('HTTP/1.1 403 Forbidden');
    die();
}

use WHMCS\Database\Capsule;

class PayPalNVP
{

    private static $apiEndpoint = 'https://api-3t.paypal.com/nvp';

    private static $apiUsername = '';
    private static $apiPassword = '';
    private static $apiSignature = '';

    private $data = [];

    public function __construct()
    {
        if (self::$apiUsername && self::$apiPassword && self::$apiSignature) {
            return;
        }

        $credentials = Capsule::table('tblpaymentgateways')
            ->where('gateway', '=', 'paypalbilling')
            ->whereIn('setting', ['apiUsername', 'apiPassword', 'apiSignature'])
            ->get();

        foreach ($credentials as $credential) {
            $setting = $credential->setting;

            self::$$setting = $credential->value;
        }
    }

    public function addPair($key, $value)
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function execute($method)
    {
        $this->addPair('USER', self::$apiUsername)
            ->addPair('PWD', self::$apiPassword)
            ->addPair('SIGNATURE', self::$apiSignature)
            ->addPair('METHOD', $method)
            ->addPair('VERSION', '95');

        $ch = curl_init(self::$apiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($this->data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode != 200) {
            return [
                'success' => false,
                'error' => "HTTP Code {$httpCode}/CURL: " . curl_error($ch),
                'response' => $response
            ];
        }

        $resultPairs = [];
        $result = explode('&', $response);

        foreach ($result as $resultPair) {
            $resultPair = explode('=', $resultPair);
            $resultPairs[strtoupper($resultPair[0])] = urldecode($resultPair[1]);
        }

        return [
            'success' => true,
            'response' => $resultPairs,
        ];
    }

}