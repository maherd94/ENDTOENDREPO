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

    $stmt = db()->query("
      SELECT
        o.id, o.order_number, o.amount_minor, o.currency, o.status, o.channel, o.psp_ref,
        COALESCE(s.code, '') AS store_code, COALESCE(s.name, '') AS store_name,
        o.created_at
      FROM orders o
      LEFT JOIN stores s ON s.id = o.store_id
      ORDER BY o.id DESC
      LIMIT 500
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="card"><h3>DB Error</h3><pre>'.h($e->getMessage()).'</pre></div>';
    exit;
}
?>
<section class="section">
  <div class="section-header">
    <h2>Orders</h2>
    <p class="muted">Issue refunds directly to Adyen. Refunds are async; status finalizes via webhook.</p>
  </div>

  <div class="card">
    <div class="card-body" style="overflow:auto;">
      <table class="table" id="orders-table" style="min-width:1040px;">
        <thead>
          <tr>
            <th>#</th>
            <th>Order</th>
            <th>Channel</th>
            <th>Store</th>
            <th>Created</th>
            <th>Amount</th>
            <th>Status</th>
            <th>PSP ref</th>
            <th style="white-space:nowrap;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="muted">No orders yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><code><?= h($r['order_number']) ?></code></td>
            <td><span class="badge"><?= h($r['channel']) ?></span></td>
            <td><?= h(trim(($r['store_code'] ? $r['store_code'].' ' : '').$r['store_name'])) ?></td>
            <td><?= h($r['created_at']) ?></td>
            <td><?= fmt_amount((int)$r['amount_minor'], $r['currency']) ?></td>
            <td><span class="badge"><?= h($r['status']) ?></span></td>
            <td><code><?= h($r['psp_ref'] ?? '') ?></code></td>
            <td>
              <button
                class="btn btn-danger btn-refund"
                title="Refund this order"
                data-id="<?= (int)$r['id'] ?>"
                data-mref="<?= h($r['order_number']) ?>"
                data-psp="<?= h($r['psp_ref'] ?? '') ?>"
                data-amount="<?= (int)$r['amount_minor'] ?>"
                data-cur="<?= h($r['currency']) ?>"
              >Refund</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script>
(function(){
  function toast(msg, title){
    if (window.showToast) return window.showToast(msg, title || 'Info');
    alert((title? (title + ': ') : '') + msg);
  }
  function toMinorFromString(inputStr, currency){
    // accepts "25.00" -> 2500; avoids locale surprises
    const clean = String(inputStr).trim();
    if (!clean) return NaN;
    const n = Number(clean);
    if (isNaN(n)) return NaN;
    // default 2dp; if you track currency exponents, replace here
    return Math.round(n * 100);
  }

  document.addEventListener('click', async function(e){
    const btn = e.target.closest('.btn-refund');
    if (!btn) return;

    const orderId = Number(btn.dataset.id);
    const orderNum = btn.dataset.mref || '';
    const pspRef = btn.dataset.psp || '';
    const currency = btn.dataset.cur || 'AED';
    const orderMinor = Number(btn.dataset.amount) || 0;

    if (!orderId) { toast('Missing order id','Error'); return; }

    // Ask for an amount (defaults to full amount)
    let def = (orderMinor/100).toFixed(2);
    let amtStr = prompt(`Refund amount in ${currency}:`, def);
    if (amtStr === null) return; // cancelled
    const amountMinor = toMinorFromString(amtStr, currency);
    if (!amountMinor || amountMinor <= 0) { toast('Invalid amount','Error'); return; }
    if (amountMinor > orderMinor) { toast('Refund exceeds order amount','Error'); return; }

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = 'Requesting…';

    try {
      const res = await fetch('/apis/refund_payment.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
          orderId,
          amount: { value: amountMinor, currency }
        })
      });
      const json = await res.json().catch(()=> ({}));
      if (!res.ok || !json.ok) {
        toast(json.error || 'Refund request failed','Error');
        btn.textContent = oldText;
        btn.disabled = false;
        return;
      }
      // Optionally reflect a pending txn in UI; final state comes via webhook
      toast(`Refund submitted (result: ${json.resultCode || 'Received'})`, 'Success');
      btn.textContent = 'Refund requested';
    } catch (err){
      console.error(err);
      toast('Network error requesting refund','Error');
      btn.textContent = oldText;
      btn.disabled = false;
    }
  });
})();
</script>
