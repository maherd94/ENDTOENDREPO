<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection error', 'details' => $e->getMessage()]);
    exit;
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);
    if (!is_array($in)) $in = $_POST;

    $spmId = trim((string)($in['storedPaymentMethodId'] ?? ''));
    $sref  = trim((string)($in['shopperReference'] ?? ''));

    if ($spmId === '' || $sref === '') {
        http_response_code(400);
        echo json_encode(['error' => 'storedPaymentMethodId and shopperReference are required']);
        exit;
    }

    $apiKey = env('ADYEN_CHECKOUT_API_KEY', '');
    $merchantAccount = env('ADYEN_PBL_MERCHANT_ID', '') ?: env('ADYEN_POS_MERCHANT_ID', '');
    $base = rtrim(env('ADYEN_PBL_CHECKOUT_BASE_URL', 'https://checkout-test.adyen.com'), '/');

    if ($apiKey === '' || $merchantAccount === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Missing ADYEN_CHECKOUT_API_KEY or merchant account in env']);
        exit;
    }

    $url = $base . '/v71/storedPaymentMethods/' . rawurlencode($spmId)
         . '?merchantAccount=' . rawurlencode($merchantAccount)
         . '&shopperReference=' . rawurlencode($sref);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        http_response_code(502);
        echo json_encode(['error' => 'Upstream connection error', 'details' => $err]);
        exit;
    }

    if ($http >= 200 && $http < 300) {
        // Adyen returns 204 No Content or 200; treat both as success.
        echo json_encode(['ok' => true, 'status' => $http]);
        exit;
    }

    // Non-2xx: return body for debugging
    http_response_code($http ?: 502);
    echo json_encode(['error' => 'Upstream error', 'status' => $http, 'raw' => $body]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
