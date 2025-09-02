<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function pick(array $src, array $keys): array {
    $out = [];
    foreach ($keys as $k) { if (array_key_exists($k, $src)) $out[$k] = $src[$k]; }
    return $out;
}

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    // Accept JSON or querystring
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in)) $in = $_GET;

    $id   = isset($in['id']) ? (int)$in['id'] : 0;
    $sref = isset($in['shopperReference']) ? trim((string)$in['shopperReference']) : '';

    if ($id <= 0 && $sref === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id or shopperReference']);
        exit;
    }

    // Find customer
    if ($id > 0) {
        $cs = db()->prepare("SELECT id, shopper_reference, email, phone, created_at FROM customers WHERE id = :id LIMIT 1");
        $cs->execute([':id' => $id]);
    } else {
        $cs = db()->prepare("SELECT id, shopper_reference, email, phone, created_at FROM customers WHERE shopper_reference = :sr LIMIT 1");
        $cs->execute([':sr' => $sref]);
    }
    $cust = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$cust) {
        http_response_code(404);
        echo json_encode(['error' => 'Customer not found']);
        exit;
    }
    $cid  = (int)$cust['id'];
    $sref = (string)$cust['shopper_reference'];

    // Orders for this customer (try by customer_id; also include shopper_reference if present in schema)
    $orders = [];
    try {
        $q = db()->prepare("
            SELECT id, order_number, amount_minor, currency, status, channel, created_at
            FROM orders
            WHERE (customer_id = :cid) OR (shopper_reference = :sr)
            ORDER BY id DESC
            LIMIT 500
        ");
        $q->execute([':cid' => $cid, ':sr' => $sref]);
        $orders = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback if shopper_reference column doesn't exist
        $q = db()->prepare("
            SELECT id, order_number, amount_minor, currency, status, channel, created_at
            FROM orders
            WHERE customer_id = :cid
            ORDER BY id DESC
            LIMIT 500
        ");
        $q->execute([':cid' => $cid]);
        $orders = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    // Call Adyen to retrieve stored payment methods for this shopper
    $apiKey = env('ADYEN_CHECKOUT_API_KEY', '');
    $merchantAccount = env('ADYEN_PBL_MERCHANT_ID', '') ?: env('ADYEN_POS_MERCHANT_ID', '');
    $base = rtrim(env('ADYEN_PBL_CHECKOUT_BASE_URL', 'https://checkout-test.adyen.com'), '/');

    $stored = [];
    if ($apiKey !== '' && $merchantAccount !== '') {
        $url = $base . '/v71/paymentMethods';
        $payload = [
            'merchantAccount' => $merchantAccount,
            'shopperReference' => $sref,
            // Optionally add:
            // 'channel' => 'Web',
            // 'countryCode' => env('ADYEN_DEFAULT_COUNTRY', 'AE'),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body !== false && !$err && $http >= 200 && $http < 300) {
            $rsp = json_decode($body, true);
            if (is_array($rsp) && !empty($rsp['storedPaymentMethods'])) {
                foreach ($rsp['storedPaymentMethods'] as $pm) {
                    // Filter out fields the UI should not show
                    $m = pick($pm, ['id','type','brand','name','lastFour','expiryMonth','expiryYear','holderName']);
                    $stored[] = $m;
                }
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'customer' => $cust,
        'orders' => $orders,
        'storedPaymentMethods' => $stored,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
