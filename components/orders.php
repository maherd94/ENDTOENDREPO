<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_amount(?int $minor, string $currency): string {
    if ($minor === null) return '';
    return number_format($minor / 100, 2) . ' ' . $currency;
}

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    // Uses the view v_orders_latest_tx (order_id, last_tx_type, last_tx_status)
    $sql = "
      SELECT
        o.id, o.order_number, o.channel, o.amount_minor, o.currency,
        o.status AS order_status, o.psp_ref, o.created_at,
        s.code AS store_code, s.name AS store_name,
        v.last_tx_type, v.last_tx_status
      FROM orders o
      LEFT JOIN stores s ON s.id = o.store_id
      LEFT JOIN v_orders_latest_tx v ON v.order_id = o.id
      ORDER BY o.created_at DESC
      LIMIT 300
    ";
    $rows = db()->query($sql)->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="card"><h3>DB Error</h3><pre>'.h($e->getMessage()).'</pre></div>';
    exit;
}
?>
<div class="card">
  <h3 style="margin:0 0 8px 0;">Orders</h3>
  <p class="badge"><?= count($rows) ?> shown — click an Order # to view details</p>
</div>

<table class="table">
  <thead>
    <tr>
      <th>Order #</th>
      <th>Channel</th>
      <th>Store</th>
      <th>Amount</th>
      <th>Latest Payment</th>
      <th>Order Status</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7">No orders yet.</td></tr>
  <?php else: foreach ($rows as $r): ?>
    <tr>
      <td>
        <a href="#orders/<?= (int)$r['id'] ?>" class="order-link" data-id="<?= (int)$r['id'] ?>">
          <?= h($r['order_number']) ?: ('#'.(int)$r['id']) ?>
        </a>
      </td>
      <td><?= h($r['channel']) ?></td>
      <td><?= h(trim(($r['store_code'] ?? '').' '.($r['store_name'] ?? ''))) ?></td>
      <td><?= fmt_amount((int)$r['amount_minor'], $r['currency']) ?></td>
      <td>
        <?php
          $lp = ($r['last_tx_type'] ?? null) ? (h($r['last_tx_type']).' '.h($r['last_tx_status'])) : '—';
          echo '<span class="badge">'.$lp.'</span>';
        ?>
      </td>
      <td><span class="badge"><?= h($r['order_status']) ?></span></td>
      <td><?= h($r['created_at']) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
