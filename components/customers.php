<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
    $stmt = db()->prepare("
        SELECT id, shopper_reference, email, phone, created_at
        FROM customers
        ORDER BY created_at DESC
        LIMIT 200
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="card"><h3>DB Error</h3><pre>'.h($e->getMessage()).'</pre></div>';
    exit;
}
?>
<div class="card">
  <h3 style="margin:0 0 8px 0;">Customers</h3>
  <p class="badge"><?= count($rows) ?> shown</p>
</div>

<table class="table" id="customers-table">
  <thead>
    <tr>
      <th>ID</th>
      <th>Shopper Ref</th>
      <th>Email</th>
      <th>Phone</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="5">No customers yet.</td></tr>
  <?php else: foreach ($rows as $r): ?>
    <tr class="cust-row" data-id="<?= (int)$r['id'] ?>"
        data-ref="<?= h($r['shopper_reference']) ?>"
        style="cursor:pointer;">
      <td><?= (int)$r['id'] ?></td>
      <td><?= h($r['shopper_reference']) ?></td>
      <td><?= h($r['email']) ?></td>
      <td><?= h($r['phone']) ?></td>
      <td><?= h($r['created_at']) ?></td>
    </tr>
    <tr class="cust-details" id="cust-details-<?= (int)$r['id'] ?>" hidden>
      <td colspan="5">
        <div class="card" style="margin:8px 0;">
          <div class="card-body" style="padding:12px;">
            <div class="muted">Loading customer details…</div>
          </div>
        </div>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<!-- Order Details Modal (inline component) -->
<div class="modal-overlay" id="order-modal" hidden>
  <div class="modal" style="max-width:920px;width:92%;">
    <div class="modal-header">
      <div class="modal-title">Order details</div>
      <button class="modal-close" id="order-modal-close">✕</button>
    </div>
    <div class="modal-body" id="order-modal-body">
      <div class="muted">Loading…</div>
    </div>
  </div>
</div>

<script>
(function(){
  function toast(msg, title){
    if (window.showToast) return window.showToast(msg, title || 'Info');
    alert((title? (title + ': ') : '') + msg);
  }
  function fmtMinor(minor, ccy){ if(minor==null) return '-'; return (Number(minor)/100).toFixed(2)+' '+(ccy||''); }
  function escapeHtml(s){ const d=document.createElement('div'); d.innerText=String(s??''); return d.innerHTML; }

  // Modal controls
  const $orderModal = document.getElementById('order-modal');
  const $orderModalBody = document.getElementById('order-modal-body');
  function openOrderModal(){ $orderModal.hidden = false; $orderModal.setAttribute('aria-hidden','false'); }
  function closeOrderModal(){ $orderModal.hidden = true; $orderModal.setAttribute('aria-hidden','true'); $orderModalBody.innerHTML = '<div class="muted">Loading…</div>'; }
  document.getElementById('order-modal-close').addEventListener('click', closeOrderModal);
  $orderModal.addEventListener('click', e => { if (e.target === e.currentTarget) closeOrderModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeOrderModal(); });

  async function fetchCustomerDetails({ id, shopperReference }){
    const res = await fetch('/apis/customer_details.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify({ id, shopperReference })
    });
    if (!res.ok) throw new Error('HTTP '+res.status);
    return res.json();
  }

  async function deleteStoredPm({ storedPaymentMethodId, shopperReference }){
    const res = await fetch('/apis/stored_payment_method_delete.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify({ storedPaymentMethodId, shopperReference })
    });
    const json = await res.json().catch(()=> ({}));
    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Delete failed');
    }
    return json;
  }

  function renderStoredPM($wrap, stored){
    const sref = $wrap.dataset.sref || '';
    const list = Array.isArray(stored) ? stored : [];
    if (!list.length) {
      $wrap.innerHTML = '<div class="muted">No stored payment methods.</div>';
      return;
    }
    const rows = list.map(pm => `
      <tr>
        <td>${escapeHtml(pm.name || pm.brand || pm.type || '-')}</td>
        <td>${escapeHtml(pm.lastFour || '')}</td>
        <td>${escapeHtml((pm.expiryMonth||'') + '/' + (pm.expiryYear||''))}</td>
        <td>${escapeHtml(pm.holderName || '')}</td>
        <td><code>${escapeHtml(pm.id || '')}</code></td>
        <td><span class="badge">${escapeHtml(pm.type || '')}</span></td>
        <td style="white-space:nowrap;">
          <button class="btn btn-danger btn-spm-delete"
                  data-id="${escapeHtml(pm.id || '')}"
                  data-sref="${escapeHtml(sref)}"
                  title="Delete stored payment method">Delete</button>
        </td>
      </tr>
    `).join('');
    $wrap.innerHTML = `
      <table class="table" style="min-width:900px;">
        <thead>
          <tr>
            <th>Brand</th><th>Last 4</th><th>Expiry</th><th>Name</th><th>Ref</th><th>Type</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  function renderOrders($wrap, orders){
    const list = Array.isArray(orders) ? orders : [];
    if (!list.length) {
      $wrap.innerHTML = '<div class="muted">No orders yet.</div>';
      return;
    }
    const rows = list.map(o => `
      <tr>
        <td>${o.id}</td>
        <td><a href="#" class="link-order" data-id="${o.id}" title="Open order"><code>${escapeHtml(o.order_number)}</code></a></td>
        <td><span class="badge">${escapeHtml(o.channel||'')}</span></td>
        <td>${escapeHtml(o.created_at||'')}</td>
        <td class="right">${fmtMinor(o.amount_minor, o.currency)}</td>
        <td><span class="badge">${escapeHtml(o.status||'')}</span></td>
      </tr>
    `).join('');
    $wrap.innerHTML = `
      <table class="table" style="min-width:860px;">
        <thead>
          <tr>
            <th>#</th><th>Order</th><th>Channel</th><th>Created</th><th class="right">Amount</th><th>Status</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  // Expand/collapse + load details
  document.getElementById('customers-table').addEventListener('click', async (e) => {
    const tr = e.target.closest('tr.cust-row');
    if (!tr) return;

    const id = Number(tr.dataset.id);
    const sref = tr.dataset.ref || '';
    const detailsRow = document.getElementById('cust-details-' + id);
    const body = detailsRow.querySelector('.card-body');

    const isHidden = detailsRow.hidden;
    if (isHidden) {
      detailsRow.hidden = false;
      body.innerHTML = '<div class="muted">Loading customer details…</div>';

      try {
        const json = await fetchCustomerDetails({ id, shopperReference: sref });

        const custHeader = `
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
            <div><strong>Shopper Ref:</strong> ${escapeHtml(json.customer?.shopper_reference || sref)}</div>
            <div><strong>Email:</strong> ${escapeHtml(json.customer?.email || '')}</div>
            <div><strong>Phone:</strong> ${escapeHtml(json.customer?.phone || '')}</div>
            <div class="badge">ID: ${escapeHtml(json.customer?.id || String(id))}</div>
          </div>
        `;

        body.innerHTML = `
          ${custHeader}
          <div class="grid" style="display:grid;grid-template-columns: 1fr; gap:16px;">
            <div class="card">
              <div class="card-header"><h3 style="margin:0;">Stored payment methods</h3></div>
              <div class="card-body" id="pm-wrap-${id}" data-sref="${escapeHtml(json.customer?.shopper_reference || sref)}"><div class="muted">—</div></div>
            </div>
            <div class="card">
              <div class="card-header"><h3 style="margin:0;">Orders</h3></div>
              <div class="card-body" id="ord-wrap-${id}"><div class="muted">—</div></div>
            </div>
          </div>
        `;

        renderStoredPM(document.getElementById('pm-wrap-' + id), json.storedPaymentMethods || []);
        renderOrders(document.getElementById('ord-wrap-' + id), json.orders || []);
      } catch (err) {
        console.error(err);
        body.innerHTML = '<div class="muted">Failed to load customer details.</div>';
      }
    } else {
      detailsRow.hidden = true;
    }
  });

  // Delete SPM (event delegation)
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-spm-delete');
    if (!btn) return;

    const spmId = btn.dataset.id || '';
    const sref  = btn.dataset.sref || '';
    if (!spmId || !sref) { toast('Missing card reference or shopper reference','Error'); return; }

    if (!confirm('Delete this stored payment method?')) return;

    const detailsRow = btn.closest('tr.cust-details');
    if (!detailsRow) { toast('Context missing','Error'); return; }
    const custId = Number((detailsRow.id || '').replace('cust-details-','')) || 0;

    const old = btn.textContent;
    btn.disabled = true; btn.textContent = 'Deleting…';

    try {
      await deleteStoredPm({ storedPaymentMethodId: spmId, shopperReference: sref });
      toast('Stored payment method deleted','Success');

      // Refresh just the details content
      const pmWrap = document.getElementById('pm-wrap-' + custId);
      const ordWrap = document.getElementById('ord-wrap-' + custId);
      if (pmWrap) pmWrap.innerHTML = '<div class="muted">Refreshing…</div>';

      const json = await fetchCustomerDetails({ id: custId, shopperReference: sref });
      if (pmWrap) renderStoredPM(pmWrap, json.storedPaymentMethods || []);
      if (ordWrap) renderOrders(ordWrap, json.orders || []);
    } catch (err) {
      console.error(err);
      toast(err.message || 'Delete failed','Error');
    } finally {
      btn.disabled = false; btn.textContent = old;
    }
  });

  // Open order detail component in a modal
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('a.link-order');
    if (!a) return;
    e.preventDefault();

    const orderId = a.dataset.id;
    if (!orderId) return;

    try {
      $orderModalBody.innerHTML = '<div class="muted">Loading…</div>';
      openOrderModal();
      const res = await fetch('/components/order_detail.php?id=' + encodeURIComponent(orderId), { headers: {'Accept':'text/html'}});
      const html = await res.text();
      $orderModalBody.innerHTML = html;
    } catch (err) {
      console.error(err);
      $orderModalBody.innerHTML = '<div class="muted">Failed to load order details.</div>';
    }
  });
})();
</script>

<style>
  .right { text-align: right; }
</style>
