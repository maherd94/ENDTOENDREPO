<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/*
Expected JSON:
{
  "reference": "ord_...",                  // required
  "shopperReference": "SR-0006",           // required
  "items": [ {"productId":1,"qty":2}, ... ], // required
  "amount": { "value": 12345, "currency":"AED" }, // optional, server will recompute
  "storeId": 12,                           // optional; fallback tries ONLINE store
  "notes": { "source":"PBL", "desc":"..." } // optional, stored in orders.notes
}
*/

function ord_ref(string $maybe): string {
    $r = trim($maybe);
    $r = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $r));
    if ($r === '' || strpos($r, 'ord_') !== 0) {
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

    $reference  = ord_ref((string)($in['reference'] ?? ''));
    $shopperRef = trim((string)($in['shopperReference'] ?? ''));
    $itemsIn    = $in['items'] ?? [];
    $amountIn   = $in['amount'] ?? null;
    $storeIdIn  = isset($in['storeId']) ? (int)$in['storeId'] : null;
    $notesIn    = isset($in['notes']) ? $in['notes'] : ['source' => 'PBL'];

    if ($reference === '' || $shopperRef === '' || !is_array($itemsIn) || count($itemsIn) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields (reference, shopperReference, items)']);
        exit;
    }

    // Idempotency: return existing order if present
    $chk = db()->prepare('SELECT id FROM orders WHERE order_number = :r LIMIT 1');
    $chk->execute([':r' => $reference]);
    $existingId = $chk->fetchColumn();
    if ($existingId) {
        echo json_encode(['ok' => true, 'order_id' => (int)$existingId, 'order_number' => $reference, 'idempotent' => true]);
        exit;
    }

    // Resolve customer
    $st = db()->prepare('SELECT id, email FROM customers WHERE shopper_reference = :sr LIMIT 1');
    $st->execute([':sr' => $shopperRef]);
    $cRow = $st->fetch();
    if (!$cRow) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown shopperReference']);
        exit;
    }
    $customerId = (int)$cRow['id'];

    // Resolve store (prefer provided id; else ONLINE; else first store)
    $storeId = null;
    if ($storeIdIn) {
        $q = db()->prepare('SELECT id FROM stores WHERE id = :id LIMIT 1');
        $q->execute([':id' => $storeIdIn]);
        $sid = $q->fetchColumn();
        if ($sid) $storeId = (int)$sid;
    }
    if (!$storeId) {
        $q = db()->prepare("SELECT id FROM stores WHERE UPPER(code) = 'ONLINE' LIMIT 1");
        $q->execute();
        $sid = $q->fetchColumn();
        if ($sid) $storeId = (int)$sid;
    }
    if (!$storeId) {
        $q = db()->prepare("SELECT id FROM stores ORDER BY id LIMIT 1");
        $q->execute();
        $sid = $q->fetchColumn();
        if ($sid) $storeId = (int)$sid;
    }

    // Build order items from products (server is source-of-truth)
    $orderItems = [];
    $calcTotal  = 0;
    $currency   = null;

    $qProd = db()->prepare('SELECT id, sku, name, price_minor, currency FROM products WHERE id = :id');
    foreach ($itemsIn as $it) {
        $pid = (int)($it['productId'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $qProd->execute([':id' => $pid]);
        $p = $qProd->fetch();
        if (!$p) {
            http_response_code(400);
            echo json_encode(['error' => "Unknown productId {$pid}"]);
            exit;
        }
        $prodCur = strtoupper((string)$p['currency']);
        if ($currency === null) $currency = $prodCur;
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

    // If client sent amount and currency, ensure currency matches (value differences are ok; server uses calcTotal)
    if (is_array($amountIn) && isset($amountIn['currency'])) {
        $inCur = strtoupper((string)$amountIn['currency']);
        if ($currency !== null && $inCur !== $currency) {
            http_response_code(400);
            echo json_encode(['error' => "Amount currency {$inCur} does not match product currency {$currency}"]);
            exit;
        }
    }
    if ($currency === null) $currency = strtoupper((string)($amountIn['currency'] ?? 'AED'));

    db()->beginTransaction();

    // Ensure link row (if created earlier) points to this customer & (optionally) store
    try {
        $up = db()->prepare("UPDATE payment_links
            SET customer_id = COALESCE(customer_id, :cid),
                shopper_reference = COALESCE(shopper_reference, :sr)
            WHERE order_number = :ord");
        $up->execute([':cid' => $customerId, ':sr' => $shopperRef, ':ord' => $reference]);
    } catch (\Throwable $ignore) {}

    // Insert order with a pre-payment status (use 'PENDING'; ensure your enum has it)
    // If your enum lacks PENDING, change to a value you have (e.g., 'AUTHORISED' after payment)
    $status = 'PENDING';
    $notes  = json_encode((is_array($notesIn) ? $notesIn : ['notes' => (string)$notesIn]) + ['channel' => 'LINK']);

    $insOrder = db()->prepare("
        INSERT INTO orders (order_number, channel, store_id, customer_id, amount_minor, currency, status, notes)
        VALUES (:order_number, 'LINK', :store_id, :customer_id, :amount_minor, :currency, :status, :notes)
        RETURNING id
    ");
    $insOrder->execute([
        ':order_number' => $reference,
        ':store_id'     => $storeId,
        ':customer_id'  => $customerId,
        ':amount_minor' => $calcTotal,
        ':currency'     => $currency,
        ':status'       => $status,
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

    db()->commit();

    echo json_encode([
        'ok'            => true,
        'order_id'      => $orderId,
        'order_number'  => $reference,
        'amount_minor'  => $calcTotal,
        'currency'      => $currency,
        'status'        => $status
    ]);
} catch (Throwable $e) {
    if (db()) { try { db()->rollBack(); } catch (\Throwable $ignore) {} }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
