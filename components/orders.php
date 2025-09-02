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
        <?php else: foreach ($rows as $r): $oid = (int)$r['id']; ?>
          <tr class="order-row" data-id="<?= $oid ?>">
            <td><?= $oid ?></td>
            <td>
              <a href="#"
                 class="order-link"
                 data-id="<?= $oid ?>"
                 title="View details"
                 style="text-decoration:none">
                <code><?= h($r['order_number']) ?></code>
              </a>
            </td>
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
                data-id="<?= $oid ?>"
                data-mref="<?= h($r['order_number']) ?>"
                data-psp="<?= h($r['psp_ref'] ?? '') ?>"
                data-amount="<?= (int)$r['amount_minor'] ?>"
                data-cur="<?= h($r['currency']) ?>"
              >Refund</button>
            </td>
          </tr>
          <tr class="order-details" id="order-details-<?= $oid ?>" hidden>
            <td colspan="9">
              <div class="card" style="margin:8px 0;">
                <div class="card-body" id="order-box-<?= $oid ?>">
                  <div class="muted">Loading details…</div>
                </div>
              </div>
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
  function toMinorFromString(inputStr){
    const clean = String(inputStr).trim();
    if (!clean) return NaN;
    const n = Number(clean);
    if (isNaN(n)) return NaN;
    return Math.round(n * 100);
  }

  // Inline order details loader/toggler
  document.addEventListener('click', async function(e){
    const link = e.target.closest('.order-link');
    if (!link) return;

    e.preventDefault();
    const id = link.dataset.id;
    if (!id) return;

    const row = document.getElementById('order-details-' + id);
    const box = document.getElementById('order-box-' + id);
    const isHidden = row.hasAttribute('hidden');

    // Optional: collapse others for single-open behavior
    // document.querySelectorAll('tr.order-details:not([hidden])').forEach(tr => tr.hidden = true);

    if (isHidden) {
      row.hidden = false;
      box.innerHTML = '<div class="muted">Loading details…</div>';
      try {
        const res = await fetch('/components/order_detail.php?id=' + encodeURIComponent(id), { headers: { 'X-Requested-With':'fetch' }});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        box.innerHTML = html;
      } catch (err) {
        console.error(err);
        box.innerHTML = '<div class="muted">Failed to load order details.</div>';
      }
    } else {
      row.hidden = true;
    }
  });

  // Refund handler (unchanged)
  document.addEventListener('click', async function(e){
    const btn = e.target.closest('.btn-refund');
    if (!btn) return;

    const orderId   = Number(btn.dataset.id);
    const currency  = btn.dataset.cur || 'AED';
    const orderMinor= Number(btn.dataset.amount) || 0;

    if (!orderId) { toast('Missing order id','Error'); return; }

    const def = (orderMinor/100).toFixed(2);
    const amtStr = prompt(`Refund amount in ${currency}:`, def);
    if (amtStr === null) return;

    const amountMinor = toMinorFromString(amtStr);
    if (!amountMinor || amountMinor <= 0) { toast('Invalid amount','Error'); return; }
    if (amountMinor > orderMinor) { toast('Refund exceeds order amount','Error'); return; }

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = 'Requesting…';

    try {
      const res = await fetch('/apis/refund_payment.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ orderId, amount: { value: amountMinor, currency } })
      });
      const json = await res.json().catch(()=> ({}));
      if (!res.ok || !json.ok) {
        toast(json.error || 'Refund request failed','Error');
        btn.textContent = oldText;
        btn.disabled = false;
        return;
      }
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
