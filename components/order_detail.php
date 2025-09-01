<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_amount(?int $minor, string $currency): string {
    if ($minor === null) return '';
    return number_format($minor / 100, 2) . ' ' . $currency;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo '<div class="card"><h3>Invalid order</h3></div>';
    exit;
}

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    // Order header
    $orderStmt = db()->prepare("
      SELECT o.*, s.code AS store_code, s.name AS store_name
      FROM orders o
      LEFT JOIN stores s ON s.id = o.store_id
      WHERE o.id = :id
      LIMIT 1
    ");
    $orderStmt->execute([':id' => $id]);
    $order = $orderStmt->fetch();

    if (!$order) {
        echo '<div class="card"><h3>Order not found</h3></div>';
        exit;
    }

    // Items
    $itemsStmt = db()->prepare("
      SELECT oi.*, p.sku AS product_sku
      FROM order_items oi
      LEFT JOIN products p ON p.id = oi.product_id
      WHERE oi.order_id = :id
      ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([':id' => $id]);
    $items = $itemsStmt->fetchAll();

    // Payment operations timeline (transactions)
    $txStmt = db()->prepare("
      SELECT type, status, amount_minor, currency, psp_ref, created_at, raw_method
      FROM transactions
      WHERE order_id = :id
      ORDER BY created_at ASC, id ASC
    ");
    $txStmt->execute([':id' => $id]);
    $txs = $txStmt->fetchAll();

    // Latest payment (for header chip)
    $lastStmt = db()->prepare("
      SELECT type, status
      FROM transactions
      WHERE order_id = :id
      ORDER BY created_at DESC, id DESC
      LIMIT 1
    ");
    $lastStmt->execute([':id' => $id]);
    $last = $lastStmt->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="card"><h3>DB Error</h3><pre>'.h($e->getMessage()).'</pre></div>';
    exit;
}
?>

<div class="card">
  <h3 style="margin:0;">
    Order <?= h($order['order_number']) ?: ('#'.(int)$order['id']) ?>
  </h3>
  <p style="margin:6px 0 0 0;">
    <span class="badge">Channel: <?= h($order['channel']) ?></span>
    <span class="badge">Store: <?= h(trim(($order['store_code'] ?? '').' '.($order['store_name'] ?? ''))) ?></span>
    <span class="badge">Order status: <?= h($order['status']) ?></span>
    <?php if ($last): ?>
      <span class="badge">Latest payment: <?= h($last['type'].' '.$last['status']) ?></span>
    <?php else: ?>
      <span class="badge">Latest payment: —</span>
    <?php endif; ?>
  </p>
  <p style="margin:6px 0 0 0;">
    <strong>Amount:</strong> <?= fmt_amount((int)$order['amount_minor'], $order['currency']) ?> |
    <strong>PSP Ref:</strong> <?= h($order['psp_ref']) ?> |
    <strong>Created:</strong> <?= h($order['created_at']) ?>
  </p>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Order Items</h3>
  <table class="table">
    <thead>
      <tr>
        <th>SKU</th>
        <th>Name</th>
        <th>Qty</th>
        <th>Unit Price</th>
        <th>Line Total</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$items): ?>
      <tr><td colspan="5">No items.</td></tr>
    <?php else: foreach ($items as $it): ?>
      <tr>
        <td><?= h($it['sku'] ?: $it['product_sku']) ?></td>
        <td><?= h($it['name']) ?></td>
        <td><?= (int)$it['qty'] ?></td>
        <td><?= fmt_amount((int)$it['unit_price_minor'], $it['currency']) ?></td>
        <td><?= fmt_amount((int)$it['line_total_minor'], $it['currency']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3 style="margin:0 0 8px 0;">Payment Operations</h3>
  <table class="table">
    <thead>
      <tr>
        <th>When</th>
        <th>Operation</th>
        <th>Status</th>
        <th>Amount</th>
        <th>Method</th>
        <th>PSP Ref</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$txs): ?>
      <tr><td colspan="6">No payment operations yet.</td></tr>
    <?php else: foreach ($txs as $t): ?>
      <tr>
        <td><?= h($t['created_at']) ?></td>
        <td><span class="badge"><?= h($t['type']) ?></span></td>
        <td><span class="badge"><?= h($t['status']) ?></span></td>
        <td><?= fmt_amount((int)$t['amount_minor'], $t['currency']) ?></td>
        <td><?= h($t['raw_method']) ?></td>
        <td><?= h($t['psp_ref']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
