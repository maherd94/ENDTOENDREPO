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

// Env
$checkoutBase = rtrim(env('ADYEN_CHECKOUT_REFUND_BASE_URL', 'https://checkout-test.adyen.com'), '/');
$apiKey       = env('ADYEN_CHECKOUT_API_KEY', '');
$merchant     = env('ADYEN_CHECKOUT_MERCHANT_ACCOUNT', env('ADYEN_POS_MERCHANT_ID', ''));

if ($apiKey === '' || $merchant === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Missing ADYEN_CHECKOUT_API_KEY or merchant account in env']);
    exit;
}

// Input
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$orderId = (int)($in['orderId'] ?? 0);
$amount  = $in['amount'] ?? null;
if ($orderId <= 0 || !$amount || !isset($amount['value'], $amount['currency'])) {
    http_response_code(400);
    echo json_encode(['error' => 'orderId and amount {value,currency} are required']);
    exit;
}
$minor    = (int)$amount['value'];
$currency = (string)$amount['currency'];
if ($minor <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'amount.value must be > 0']);
    exit;
}

try {
    // Fetch the order & resolve the original payment PSP reference
    $ord = db()->prepare("SELECT id, order_number, amount_minor, currency, psp_ref FROM orders WHERE id = :id LIMIT 1");
    $ord->execute([':id' => $orderId]);
    $order = $ord->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    $paymentPsp = $order['psp_ref'] ?? null;
    if (!$paymentPsp) {
        // fallback: last AUTH or CAPTURE txn psp_ref
        $q = db()->prepare("
          SELECT psp_ref FROM transactions
           WHERE order_id = :oid AND psp_ref IS NOT NULL AND type IN ('AUTH','CAPTURE')
           ORDER BY id DESC LIMIT 1
        ");
        $q->execute([':oid' => $orderId]);
        $paymentPsp = $q->fetchColumn() ?: null;
    }

    if (!$paymentPsp) {
        http_response_code(400);
        echo json_encode(['error' => 'No payment PSP reference found for this order']);
        exit;
    }

    // Build request
    $payload = [
        'amount'          => ['currency' => $currency, 'value' => $minor],
        'reference'       => 'rf_' . $order['order_number'] . '_' . time(),
        'merchantAccount' => $merchant,
    ];

    $url = $checkoutBase . '/v71/payments/' . rawurlencode($paymentPsp) . '/refunds';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $err) {
        http_response_code(502);
        echo json_encode(['error' => 'Upstream connection error', 'details' => $err]);
        exit;
    }
    $rsp = json_decode($body, true);
    if ($http < 200 || $http >= 300 || !is_array($rsp)) {
        http_response_code($http ?: 502);
        echo json_encode(['error' => 'Upstream error', 'raw' => $body]);
        exit;
    }

    // Optionally insert a "pending" transaction (Received) so the UI reflects it immediately
    try {
        $ins = db()->prepare("
          INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
          VALUES (:oid, 'REFUND', 'RECEIVED', :amt, :cur, :psp_ref, 'API')
        ");
        $ins->execute([
            ':oid'     => $orderId,
            ':amt'     => $minor,
            ':cur'     => $currency,
            ':psp_ref' => (string)($rsp['pspReference'] ?? ''), // the REFUND psp ref
        ]);
    } catch (Throwable $e) {
        // non-fatal
    }

    echo json_encode([
        'ok'          => true,
        'resultCode'  => (string)($rsp['resultCode'] ?? ''),
        'pspReference'=> (string)($rsp['pspReference'] ?? ''),
        'raw'         => $rsp,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
