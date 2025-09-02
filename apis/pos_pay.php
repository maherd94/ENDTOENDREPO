<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $baseUrl   = rtrim(env('ADYEN_DEVICE_API_BASE_URL', 'https://device-api-test.adyen.com/v1'), '/');
    $apiKey    = env('ADYEN_DEVICE_API_KEY');
    $merchant  = env('ADYEN_POS_MERCHANT_ID', '');
    $saleId    = env('ADYEN_POS_SALE_ID', 'POSSystemID12');

    if (!$apiKey || !$merchant) {
        http_response_code(500);
        echo json_encode(['error' => 'Missing ADYEN_DEVICE_API_KEY or ADYEN_POS_MERCHANT_ID in .env']);
        exit;
    }

    // Parse input
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $terminalId      = trim((string)($in['terminalId'] ?? ''));
    $amount          = $in['amount'] ?? null; // {value (minor), currency}
    $txId            = trim((string)($in['transactionId'] ?? ''));
    $shopperRef      = trim((string)($in['shopperReference'] ?? '')); // tokenization hint
    $currency        = isset($amount['currency']) ? (string)$amount['currency'] : 'AED';
    $minor           = isset($amount['value']) ? (int)$amount['value'] : 0;

    if ($terminalId === '' || $minor <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'terminalId and amount.value are required']);
        exit;
    }

    // Convert minor units -> decimal for RequestedAmount
    $requestedAmount = (float)number_format($minor / 100, 2, '.', '');

    // Generate IDs if not provided
    if ($txId === '') {
        $txId = 'm' . dechex(time()) . substr(strval(mt_rand(100000, 999999)), -4);
    }
    $serviceId = (string)mt_rand(100, 999); // simple numeric service ID
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z'); // e.g., 2025-09-01T11:25:34.528Z

    // Build SaleToAcquirerData as a plain key=value string (NO base64)
    // Example: "recurringProcessingModel=UnscheduledCardOnFile&shopperReference=12345"
    $saleToAcquirerData = null;
    if ($shopperRef !== '') {
        $saleToAcquirerData = 'recurringProcessingModel=UnscheduledCardOnFile'
                            . '&shopperReference=' . $shopperRef;
    }

    $payload = [
        'SaleToPOIRequest' => [
            'MessageHeader' => [
                'ProtocolVersion' => '3.0',
                'MessageClass'    => 'Service',
                'MessageCategory' => 'Payment',
                'MessageType'     => 'Request',
                'SaleID'          => $saleId,
                'ServiceID'       => $serviceId,
                'POIID'           => $terminalId,
            ],
            'PaymentRequest' => [
                'SaleData' => array_filter([
                    'SaleTransactionID' => [
                        'TransactionID' => $txId,
                        'TimeStamp'     => $timestamp,
                    ],
                    'SaleToAcquirerData' => $saleToAcquirerData, // plain KV string
                    'TokenRequestedType'=> 'Customer'
                ]),
                'PaymentTransaction' => [
                    'AmountsReq' => [
                        'Currency'        => $currency,
                        'RequestedAmount' => $requestedAmount,
                    ],
                ],
            ],
        ],
    ];

    $url = $baseUrl . '/merchants/' . rawurlencode($merchant) . '/devices/' . rawurlencode($terminalId) . '/sync';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $respBody = curl_exec($ch);
    $errNo    = curl_errno($ch);
    $err      = curl_error($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        http_response_code(502);
        echo json_encode(['error' => 'Upstream connection error', 'details' => $err]);
        exit;
    }
    if ($http < 200 || $http >= 300) {
        http_response_code($http ?: 502);
        echo json_encode(['error' => 'Upstream error', 'status' => $http, 'raw' => $respBody]);
        exit;
    }

    $json = json_decode($respBody, true);
    if (!is_array($json)) {
        http_response_code(502);
        echo json_encode(['error' => 'Invalid upstream JSON', 'raw' => $respBody]);
        exit;
    }

    echo json_encode([
        'ok'               => true,
        'terminalId'       => $terminalId,
        'merchantId'       => $merchant,
        'transactionId'    => $txId,
        'shopperReference' => $shopperRef ?: null,
        'request'          => $payload,
        'response'         => $json,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
