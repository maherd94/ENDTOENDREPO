<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/* Normalize any incoming reference to ord_* like e-com */
function normalize_order_ref(string $r): string {
    $r = trim($r);
    // keep only [A-Za-z0-9_-], lower-case
    $r = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $r));
    if (stripos($r, 'ord_') !== 0) {
        $r = 'ord_' . $r;
    }
    // ensure we have something after ord_
    if ($r === 'ord_' || strlen($r) < 6) {
        $r = 'ord_' . (string)round(microtime(true) * 1000);
    }
    return $r;
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

    // Required
    $reference   = trim((string)($in['reference'] ?? ''));          // order_number (will normalize)
    $shopperRef  = trim((string)($in['shopperReference'] ?? ''));
    $amount      = $in['amount'] ?? null;                            // {value, currency}
    $items       = $in['items'] ?? [];                               // [{productId, qty}]

    // Optional (from terminal response)
    $terminalId    = isset($in['terminalId']) ? (string)$in['terminalId'] : null;
    $storeIdIn     = isset($in['storeId']) ? (int)$in['storeId'] : null;
    $storeCodeIn   = isset($in['storeCode']) ? (string)$in['storeCode'] : null;

    $pspReference  = isset($in['pspReference']) ? (string)$in['pspReference'] : null;
    $paymentBrand  = isset($in['paymentBrand']) ? (string)$in['paymentBrand'] : null;
    $maskedPan     = isset($in['maskedPan']) ? (string)$in['maskedPan'] : null;
    $authCode      = isset($in['authCode']) ? (string)$in['authCode'] : null;
    $terminalTxRef = isset($in['terminalTxRef']) ? (string)$in['terminalTxRef'] : null;
    $deviceTime    = isset($in['deviceTime']) ? (string)$in['deviceTime'] : null;

    if ($reference === '' || $shopperRef === '' || !$amount || !isset($amount['value'], $amount['currency'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields (reference, shopperReference, amount)']);
        exit;
    }
    if (!is_array($items) || count($items) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No items provided']);
        exit;
    }

    // Force ord_* format
    $reference = normalize_order_ref($reference);

    // Resolve customer
    $st = db()->prepare('SELECT id FROM customers WHERE shopper_reference = :sr LIMIT 1');
    $st->execute([':sr' => $shopperRef]);
    $customerId = $st->fetchColumn();
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown shopperReference']);
        exit;
    }
    $customerId = (int)$customerId;

    // Resolve store (prefer storeCode from Device API AdditionalResponse, case-insensitive)
    $storeId = null;
    if (!empty($storeCodeIn)) {
        $s = db()->prepare('SELECT id FROM stores WHERE UPPER(code) = UPPER(:code) LIMIT 1');
        $s->execute([':code' => $storeCodeIn]);
        $sid = $s->fetchColumn();
        if ($sid) $storeId = (int)$sid;
    }
    if (!$storeId && $storeIdIn) {
        $storeId = (int)$storeIdIn;
    }
    if (!$storeId) {
        // fallback (adjust as you like)
        $s = db()->prepare("SELECT id FROM stores WHERE code IN ('POS','STORE_POS','MAIN_POS','ONLINE') ORDER BY id LIMIT 1");
        $s->execute();
        $sid = $s->fetchColumn();
        if ($sid) $storeId = (int)$sid;
    }

    // Build order items using server-side prices
    $currency   = strtoupper((string)$amount['currency']);
    $calcTotal  = 0;
    $orderItems = [];

    $qProd = db()->prepare('SELECT id, sku, name, price_minor, currency FROM products WHERE id = :id');
    foreach ($items as $it) {
        $pid = (int)($it['productId'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $qProd->execute([':id' => $pid]);
        $p = $qProd->fetch();
        if (!$p) continue;

        $prodCur = strtoupper((string)$p['currency']);
        if ($prodCur !== $currency) {
            http_response_code(400);
            echo json_encode(['error' => "Currency mismatch on product {$pid}"]);
            exit;
        }

        $unit = (int)$p['price_minor'];
        $line = $unit * $qty;
        $calcTotal += $line;

        $orderItems[] = [
            'product_id'       => (int)$p['id'],
            'sku'              => (string)$p['sku'],
            'name'             => (string)$p['name'],
            'qty'              => $qty,
            'unit_price_minor' => $unit,
            'line_total_minor' => $line,
            'currency'         => $currency
        ];
    }

    if ($calcTotal <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Calculated total is zero or items invalid']);
        exit;
    }

    db()->beginTransaction();

    // Notes
    $notes = json_encode([
        'terminalId'  => $terminalId,
        'maskedPan'   => $maskedPan,
        'authCode'    => $authCode,
        'deviceTime'  => $deviceTime,
        'terminalTx'  => $terminalTxRef,
        'storeCode'   => $storeCodeIn
    ]);

    // Insert order (channel POS). We mark as CAPTURED (approved sale).
    $insOrder = db()->prepare("
        INSERT INTO orders (order_number, channel, store_id, customer_id, amount_minor, currency, status, psp_ref, notes)
        VALUES (:order_number, 'POS', :store_id, :customer_id, :amount_minor, :currency, 'CAPTURED', :psp_ref, :notes)
        RETURNING id
    ");
    $insOrder->execute([
        ':order_number' => $reference,
        ':store_id'     => $storeId,
        ':customer_id'  => $customerId,
        ':amount_minor' => $calcTotal,
        ':currency'     => $currency,
        ':psp_ref'      => $pspReference,
        ':notes'        => $notes
    ]);
    $orderId = (int)$insOrder->fetchColumn();

    // Insert items
    $insItem = db()->prepare("
        INSERT INTO order_items (order_id, product_id, sku, name, qty, unit_price_minor, currency, line_total_minor)
        VALUES (:order_id, :product_id, :sku, :name, :qty, :unit_price_minor, :currency, :line_total_minor)
    ");
    foreach ($orderItems as $oi) {
        $insItem->execute([
            ':order_id'         => $orderId,
            ':product_id'       => $oi['product_id'],
            ':sku'              => $oi['sku'],
            ':name'             => $oi['name'],
            ':qty'              => $oi['qty'],
            ':unit_price_minor' => $oi['unit_price_minor'],
            ':currency'         => $oi['currency'],
            ':line_total_minor' => $oi['line_total_minor'],
        ]);
    }

    // Record POS payment transaction as RECEIVED
    $insTxn = db()->prepare("
        INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
        VALUES (:order_id, 'RECEIVED', 'SUCCESS', :amount_minor, :currency, :psp_ref, :raw_method)
    ");
    $insTxn->execute([
        ':order_id'     => $orderId,
        ':amount_minor' => $calcTotal,
        ':currency'     => $currency,
        ':psp_ref'      => $pspReference,
        ':raw_method'   => $paymentBrand
    ]);

    db()->commit();

    echo json_encode([
        'ok'            => true,
        'order_id'      => $orderId,
        'order_number'  => $reference,
        'amount_minor'  => $calcTotal,
        'currency'      => $currency
    ]);
} catch (Throwable $e) {
    if (db()) { try { db()->rollBack(); } catch (\Throwable $ignore) {} }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
