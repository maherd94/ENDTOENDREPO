<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!$in || !is_array($in)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    // Validate input
    $reference        = trim((string)($in['reference'] ?? ''));
    $shopperRef       = trim((string)($in['shopperReference'] ?? ''));
    $amount           = $in['amount'] ?? null;   // {value, currency}
    $items            = $in['items'] ?? [];
    $pspReference     = isset($in['pspReference']) ? (string)$in['pspReference'] : null;
    $paymentMethod    = isset($in['paymentMethodType']) ? (string)$in['paymentMethodType'] : null;

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

    // Resolve customer & store
    $customerId = null;
    $st = db()->prepare('SELECT id FROM customers WHERE shopper_reference = :sr LIMIT 1');
    $st->execute([':sr' => $shopperRef]);
    $row = $st->fetch();
    if ($row) $customerId = (int)$row['id'];

    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown shopperReference']);
        exit;
    }

    $storeId = null;
    $st = db()->query("SELECT id FROM stores WHERE code = 'ONLINE' LIMIT 1");
    if ($r = $st->fetch()) $storeId = (int)$r['id'];

    // Build order items from DB (trust server prices/currency)
    $orderItems = [];
    $calcTotal = 0;
    $currency = strtoupper((string)$amount['currency']);
    foreach ($items as $it) {
        $pid = (int)($it['productId'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $p = db()->prepare('SELECT id, sku, name, price_minor, currency FROM products WHERE id = :id');
        $p->execute([':id' => $pid]);
        $prod = $p->fetch();
        if (!$prod) continue;

        $prodCurrency = strtoupper((string)$prod['currency']);
        if ($prodCurrency !== $currency) {
            http_response_code(400);
            echo json_encode(['error' => "Currency mismatch on product {$pid}"]);
            exit;
        }

        $unit = (int)$prod['price_minor'];
        $line = $unit * $qty;
        $calcTotal += $line;

        $orderItems[] = [
            'product_id'       => (int)$prod['id'],
            'sku'              => (string)$prod['sku'],
            'name'             => (string)$prod['name'],
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

    // (Optional) validate cart total vs session amount
    if ((int)$amount['value'] !== $calcTotal) {
        // You can decide to reject or accept. Here, we accept but log a warning.
        // return error instead if you want strict:
        // http_response_code(400); echo json_encode(['error'=>'Amount mismatch']); exit;
    }

    db()->beginTransaction();

    // Insert order (map "received" -> CAPTURED to use existing enum)
    $insOrder = db()->prepare("
        INSERT INTO orders (order_number, channel, store_id, customer_id, amount_minor, currency, status, psp_ref)
        VALUES (:order_number, 'ECOM', :store_id, :customer_id, :amount_minor, :currency, 'RECEIVED', :psp_ref)
        RETURNING id
    ");
    $insOrder->execute([
        ':order_number' => $reference,
        ':store_id'     => $storeId,
        ':customer_id'  => $customerId,
        ':amount_minor' => $calcTotal,
        ':currency'     => $currency,
        ':psp_ref'      => $pspReference
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

    // Record payment operation as a successful CAPTURE
    $insTxn = db()->prepare("
        INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
        VALUES (:order_id, 'RECEIVED', 'SUCCESS', :amount_minor, :currency, :psp_ref, :raw_method)
    ");
    $insTxn->execute([
        ':order_id'     => $orderId,
        ':amount_minor' => $calcTotal,
        ':currency'     => $currency,
        ':psp_ref'      => $pspReference,
        ':raw_method'   => $paymentMethod
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
    if (db()) {
        try { db()->rollBack(); } catch (\Throwable $ignore) {}
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
