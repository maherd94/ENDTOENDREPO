<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_amount(?int $minor, string $currency): string {
    if ($minor === null) return '';
    return number_format($minor / 100, 2) . ' ' . $currency;
}
function fmt_dec($n, ?string $ccy = null): string {
    if ($n === null || $n === '') return '-';
    $val = (float)$n;
    return number_format($val, 2, '.', ',') . ($ccy ? ' ' . $ccy : '');
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
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

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
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Payment operations timeline (transactions)
    $txStmt = db()->prepare("
      SELECT type, status, amount_minor, currency, psp_ref, created_at, raw_method
      FROM transactions
      WHERE order_id = :id
      ORDER BY created_at ASC, id ASC
    ");
    $txStmt->execute([':id' => $id]);
    $txs = $txStmt->fetchAll(PDO::FETCH_ASSOC);

    // Latest payment (for header chip)
    $lastStmt = db()->prepare("
      SELECT type, status
      FROM transactions
      WHERE order_id = :id
      ORDER BY created_at DESC, id DESC
      LIMIT 1
    ");
    $lastStmt->execute([':id' => $id]);
    $last = $lastStmt->fetch(PDO::FETCH_ASSOC);

    // Pull PSP refs used by this order (to cross-link settlement details even if merchant_reference is missing)
    $pspStmt = db()->prepare("SELECT DISTINCT psp_ref FROM transactions WHERE order_id = :id AND psp_ref IS NOT NULL");
    $pspStmt->execute([':id' => $id]);
    $pspRefs = array_values(array_filter(array_map(static fn($r) => $r['psp_ref'] ?? null, $pspStmt->fetchAll(PDO::FETCH_ASSOC))));

    // Settlement details joined via merchant_reference OR PSP ref
    $params = [':mref' => (string)$order['order_number']];
    $inPlaceholders = [];
    foreach ($pspRefs as $i => $ref) {
        $ph = ':psp' . $i;
        $inPlaceholders[] = $ph;
        $params[$ph] = $ref;
    }
    $wherePsp = $inPlaceholders ? (' OR sd.psp_reference IN (' . implode(',', $inPlaceholders) . ')') : '';

    $sdetSql = "
      SELECT
        sd.id,
        sd.psp_reference,
        sd.merchant_reference,
        sd.type,
        sd.creation_date,
        sd.net_currency,
        sd.net_debit,
        sd.net_credit,
        sd.processing_fee,
        sd.markup,
        sd.scheme_fees,
        sd.interchange,
        sd.payment_method,
        sd.payment_method_variant,
        sd.batch_number,
        s.id AS settlement_id,
        s.report_filename,
        s.report_date
      FROM settlement_details sd
      LEFT JOIN settlements s ON s.id = sd.settlement_id
      WHERE (sd.merchant_reference = :mref{$wherePsp})
      ORDER BY sd.creation_date NULLS LAST, sd.id
    ";
    $sdetStmt = db()->prepare($sdetSql);
    $sdetStmt->execute($params);
    $srows = $sdetStmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate fee + net figures
    $sum = [
        'settled_nc' => 0.0, // sum of (net_credit - net_debit) for rows where type='Settled'
        'net_all'    => 0.0, // sum across ALL rows (authoritative net movement)
        'processing_fee' => 0.0,
        'markup'     => 0.0,
        'scheme'     => 0.0,
        'inter'      => 0.0,
    ];
    // Try to resolve currency
    $settleCurrency = $order['currency'] ?? null;
    foreach ($srows as $r) {
        $nc = (float)($r['net_credit'] ?? 0) - (float)($r['net_debit'] ?? 0);
        $sum['net_all'] += $nc;
        if (strcasecmp((string)$r['type'], 'Settled') === 0) {
            $sum['settled_nc'] += $nc;
        }
        $sum['processing_fee'] += (float)($r['processing_fee'] ?? 0);
        $sum['markup']     += (float)($r['markup'] ?? 0);
        $sum['scheme']     += (float)($r['scheme_fees'] ?? 0);
        $sum['inter']      += (float)($r['interchange'] ?? 0);
        if (!$settleCurrency) {
            $settleCurrency = $r['net_currency'] ?: $settleCurrency;
        }
    }
    $totalFees = $sum['processing_fee'] + $sum['markup'] + $sum['scheme'] + $sum['inter'];
    $netToMerchant_calc = $sum['settled_nc'] - $totalFees;   // settled minus fees
    $netToMerchant_netAll = $sum['net_all'];                 // authoritative net movement from the report
    $ccy = $settleCurrency ?: ($order['currency'] ?? 'AED');

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

<div class="card">
  <h3 style="margin:0 0 8px 0;">Settlement & Fees</h3>

  <?php if (!$srows): ?>
    <p class="muted" style="margin:0;">No settlement lines yet for this order.</p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns: repeat(4, minmax(180px, 1fr)); gap:12px; margin-bottom:12px;">
      <div class="stat">
        <div class="muted">Settled amount (NC)</div>
        <div style="font-weight:700; font-size:18px;"><?= fmt_dec($sum['settled_nc'], $ccy) ?></div>
      </div>
      <div class="stat">
        <div class="muted">Fees (processing + markup + scheme + interchange)</div>
        <div style="font-weight:700; font-size:18px;"><?= fmt_dec($totalFees, $ccy) ?></div>
      </div>
      <div class="stat">
        <div class="muted">Net to merchant (Settled − Fees)</div>
        <div style="font-weight:700; font-size:18px;"><?= fmt_dec($netToMerchant_calc, $ccy) ?></div>
      </div>
      <div class="stat">
        <div class="muted">Net movement (all lines)</div>
        <div style="font-weight:700; font-size:18px;"><?= fmt_dec($netToMerchant_netAll, $ccy) ?></div>
      </div>
    </div>

    <table class="table" style="min-width:1100px;">
      <thead>
        <tr>
          <th>Batch</th>
          <th>Type</th>
          <th>When</th>
          <th>PSP Ref</th>
          <th class="right">Net Debit</th>
          <th class="right">Net Credit</th>
          <th class="right">Processing</th>
          <th class="right">Markup</th>
          <th class="right">Scheme</th>
          <th class="right">Interchange</th>
          <th>PM</th>
          <th>Report File</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($srows as $r): ?>
          <?php
            $dt = $r['creation_date'] ? date('Y-m-d H:i', strtotime((string)$r['creation_date'])) : '-';
            $pm = $r['payment_method_variant'] ?: $r['payment_method'];
          ?>
          <tr>
            <td><span class="badge"><?= h((string)$r['batch_number']) ?></span></td>
            <td><?= h((string)$r['type']) ?></td>
            <td><?= h($dt) ?></td>
            <td><?= $r['psp_reference'] ? '<code>'.h($r['psp_reference']).'</code>' : '—' ?></td>
            <td class="right"><?= fmt_dec($r['net_debit'], $r['net_currency'] ?: $ccy) ?></td>
            <td class="right"><?= fmt_dec($r['net_credit'], $r['net_currency'] ?: $ccy) ?></td>
            <td class="right"><?= fmt_dec($r['processing_fee'], $ccy) ?></td>
            <td class="right"><?= fmt_dec($r['markup'], $ccy) ?></td>
            <td class="right"><?= fmt_dec($r['scheme_fees'], $ccy) ?></td>
            <td class="right"><?= fmt_dec($r['interchange'], $ccy) ?></td>
            <td><?= h($pm ?: '-') ?></td>
            <td><?= $r['report_filename'] ? '<code>'.h($r['report_filename']).'</code>' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="4" class="right">Totals:</th>
          <th class="right"><?= fmt_dec(array_sum(array_map(static fn($x)=>(float)($x['net_debit']??0), $srows)), $ccy) ?></th>
          <th class="right"><?= fmt_dec(array_sum(array_map(static fn($x)=>(float)($x['net_credit']??0), $srows)), $ccy) ?></th>
          <th class="right"><?= fmt_dec($sum['processing_fee'], $ccy) ?></th>
          <th class="right"><?= fmt_dec($sum['markup'], $ccy) ?></th>
          <th class="right"><?= fmt_dec($sum['scheme'], $ccy) ?></th>
          <th class="right"><?= fmt_dec($sum['inter'], $ccy) ?></th>
          <th colspan="2"></th>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>
</div>

<style>
  .right { text-align: right; }
  .stat .muted { color:#64748b; font-size:12px; }
</style>
