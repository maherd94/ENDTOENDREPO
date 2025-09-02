<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function ord_ref(string $maybe): string {
    $r = trim($maybe);
    $r = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $r));
    if ($r === '' || strpos($r, 'ord_') !== 0) {
        $r = 'ord_' . (string)round(microtime(true) * 1000);
    }
    return $r;
}
function map_status_enum(string $s): string {
    $u = strtoupper($s);
    // our enum: CREATED, ACTIVE, PAID, EXPIRED, CANCELLED, ERROR
    if (in_array($u, ['ACTIVE','PAID','EXPIRED','CANCELLED','CREATED','ERROR'], true)) return $u;
    return 'CREATED';
}

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    // Required inputs from POS UI
    $shopperRef = trim((string)($in['shopperReference'] ?? ''));
    $amount     = $in['amount'] ?? null; // {value, currency}
    $reference  = ord_ref((string)($in['reference'] ?? ''));
    $description= isset($in['description']) ? (string)$in['description'] : null;

    if ($shopperRef === '' || !$amount || !isset($amount['value'], $amount['currency'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing shopperReference or amount']);
        exit;
    }

    // Resolve customer (for optional email / customer_id)
    $st = db()->prepare('SELECT id, email FROM customers WHERE shopper_reference = :sr LIMIT 1');
    $st->execute([':sr' => $shopperRef]);
    $cust = $st->fetch();
    $customerId   = $cust ? (int)$cust['id'] : null;
    $shopperEmail = $cust && !empty($cust['email']) ? (string)$cust['email'] : null;

    // Env/config
    $base   = rtrim(env('ADYEN_PBL_CHECKOUT_BASE_URL', 'https://checkout-test.adyen.com'), '/');
    $apiKey = env('ADYEN_CHECKOUT_API_KEY', '');
    $mca    = env('ADYEN_PBL_MERCHANT_ID', '') ?: env('ADYEN_POS_MERCHANT_ID', '');
    $retUrl = env('ADYEN_DEFAULT_RETURN_URL', 'http://127.0.0.1:8080/htmls/thanks.html');
    $cc     = env('ADYEN_DEFAULT_COUNTRY', 'AE');
    $loc    = env('ADYEN_DEFAULT_LOCALE', 'en-US');

    if ($apiKey === '' || $mca === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Missing ADYEN_CHECKOUT_API_KEY or merchant id in env']);
        exit;
    }

    // Build Pay by Link payload (request tokenization)
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+24 hours')->format('c');
    $payload = [
        'reference'       => $reference,
        'amount'          => ['value' => (int)$amount['value'], 'currency' => (string)$amount['currency']],
        'merchantAccount' => $mca,
        'shopperReference'=> $shopperRef,
        'countryCode'     => $cc,
        'shopperLocale'   => $loc,
        'returnUrl'       => $retUrl,
        'reusable'        => false,
        'expiresAt'       => $expiresAt,

        // Tokenization signals
        'storePaymentMethodMode'       => 'enabled',
        'recurringProcessingModel' => 'CardOnFile',
        'shopperInteraction'       => 'Ecommerce'
    ];
    if ($description)   $payload['description'] = $description;
    if ($shopperEmail)  $payload['shopperEmail'] = $shopperEmail;

    // Call Adyen
    $url = $base . '/v70/paymentLinks';
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
        CURLOPT_TIMEOUT        => 25,
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

    // Persist locally
    $linkId = (string)($rsp['id'] ?? '');
    $urlOut = (string)($rsp['url'] ?? '');
    $status = map_status_enum((string)($rsp['status'] ?? 'CREATED'));
    $expAt  = (string)($rsp['expiresAt'] ?? $expiresAt);

    $ins = db()->prepare("
      INSERT INTO payment_links (link_id, url, merchant_account, order_number, customer_id, shopper_reference,
                                 amount_minor, currency, status, expires_at, channel, metadata)
      VALUES (:link_id, :url, :mca, :order_number, :customer_id, :shopper_reference,
              :amount_minor, :currency, :status, :expires_at, 'LINK', :metadata)
      ON CONFLICT (link_id) DO UPDATE
      SET url = EXCLUDED.url,
          status = EXCLUDED.status,
          expires_at = EXCLUDED.expires_at,
          updated_at = now()
    ");
    $meta = [
        'description' => $description,
        'tokenizationRequested' => true
    ];
    $ins->execute([
        ':link_id'          => $linkId,
        ':url'              => $urlOut,
        ':mca'              => $mca,
        ':order_number'     => $reference,
        ':customer_id'      => $customerId,
        ':shopper_reference'=> $shopperRef,
        ':amount_minor'     => (int)$amount['value'],
        ':currency'         => (string)$amount['currency'],
        ':status'           => $status,
        ':expires_at'       => $expAt,
        ':metadata'         => json_encode($meta),
    ]);

    echo json_encode([
        'ok'           => true,
        'id'           => $linkId,
        'url'          => $urlOut,
        'status'       => $status,
        'expiresAt'    => $expAt,
        'order_number' => $reference
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
