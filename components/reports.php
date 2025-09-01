<?php
// components/reports.php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDate($ts): string {
    if (!$ts) return '-';
    try { return (new DateTime($ts))->format('Y-m-d H:i'); } catch (Throwable $e) { return h((string)$ts); }
}
function yesNo(?bool $b): string { return $b ? 'Yes' : 'No'; }

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    $stmt = db()->query("
        SELECT
            id,
            merchant_account,
            event_code,
            event_date,
            success,
            psp_reference,
            reason,
            download_url,
            received_at
        FROM report_notifications
        ORDER BY id DESC
        LIMIT 500
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="card"><h3>DB Error</h3><pre>'.h($e->getMessage()).'</pre></div>';
    exit;
}
?>
<section class="section reports-section">
  <div class="section-header">
    <h2>Report Webhooks</h2>
    <p class="muted">Process a <code>REPORT_AVAILABLE</code> row to fetch its CSV and update orders/transactions.</p>
  </div>

  <div class="card">
    <div class="card-body" style="overflow:auto;">
      <table class="table table-striped" style="width:100%; min-width:1080px;">
        <thead>
          <tr>
            <th>ID</th>
            <th>Merchant</th>
            <th>Event</th>
            <th>Event date</th>
            <th>Success</th>
            <th>PSP ref</th>
            <th>Download</th>
            <th>Reason</th>
            <th>Received</th>
            <th style="white-space:nowrap;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="muted">No report webhooks yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr data-row-id="<?= (int)$r['id'] ?>">
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['merchant_account'] ?? '') ?></td>
            <td><span class="badge"><?= h($r['event_code'] ?? '') ?></span></td>
            <td><?= fmtDate($r['event_date'] ?? null) ?></td>
            <td><?= yesNo(!empty($r['success'])) ?></td>
            <td><code><?= h($r['psp_reference'] ?? '') ?></code></td>
            <td>
              <?php if (!empty($r['download_url'])): ?>
                <a href="<?= h($r['download_url']) ?>" target="_blank" rel="noopener">Open link</a>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td style="max-width:380px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($r['reason'] ?? '') ?>">
              <?= h($r['reason'] ?? '') ?>
            </td>
            <td><?= fmtDate($r['received_at'] ?? null) ?></td>
            <td>
              <button type="button" class="btn btn-primary btn-process-report" data-id="<?= (int)$r['id'] ?>">Process</button>
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
  // Inject a toast UI if it doesn't exist, and expose window.showToast
  function ensureToast(){
    if (document.getElementById('toast')) return;
    const el = document.createElement('div');
    el.id = 'toast';
    el.className = 'toast';
    el.hidden = true;
    el.innerHTML = `
      <style>
        .toast{position:fixed;right:16px;bottom:16px;z-index:9999;display:flex;align-items:center;gap:12px;
               background:#0f172a;color:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(2,6,23,.3);}
        .toast[hidden]{display:none;}
        .toast-accent{width:6px;height:100%;background:#22c55e;border-top-left-radius:12px;border-bottom-left-radius:12px;}
        .toast-content{padding:12px 6px 12px 8px;max-width:64ch;}
        .toast-title{font-weight:700;margin-bottom:2px}
        .toast-msg{opacity:.9}
        .toast-close{border:0;background:transparent;color:#fff;opacity:.8;font-size:14px;padding:8px;cursor:pointer;margin-right:6px}
        .toast-close:hover{opacity:1}
      </style>
      <div class="toast-accent"></div>
      <div class="toast-content">
        <div class="toast-title">Action</div>
        <div class="toast-msg">Message</div>
      </div>
      <button class="toast-close" id="toast-close" aria-label="Close">✕</button>
    `;
    document.body.appendChild(el);
    document.addEventListener('click', function(ev){
      if (ev.target && ev.target.id === 'toast-close'){
        const t = document.getElementById('toast');
        if (t) t.hidden = true;
      }
    });
  }

  function showToast(message, title='Action needed', accent='ok'){
    // accent: 'ok' | 'warn' | 'err'
    ensureToast();
    const t = document.getElementById('toast');
    const titleEl = t.querySelector('.toast-title');
    const msgEl = t.querySelector('.toast-msg');
    const accentEl = t.querySelector('.toast-accent');
    titleEl.textContent = title;
    msgEl.textContent = message;
    // color accent
    const map = { ok:'#22c55e', warn:'#eab308', err:'#ef4444' };
    accentEl.style.background = map[accent] || map.ok;

    t.hidden = false;
    clearTimeout(t._hideTimer);
    t._hideTimer = setTimeout(()=>{ t.hidden = true; }, 3200);
  }
  window.showToast = showToast; // make available globally for other components

  document.addEventListener('click', async function(e){
    const btn = e.target.closest('.btn-process-report');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    if (!id) return;

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Processing…';

    try {
      const res = await fetch('/apis/process_report.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: Number(id) })
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok || data.ok !== true) {
        const msg = (data && data.error) ? data.error : `Failed (HTTP ${res.status})`;
        showToast(msg, 'Report processing failed', 'err');
        btn.textContent = 'Retry';
      } else {
        showToast(
          `Parsed ${data.rowsParsed} rows • Upserted ${data.detailsUpserted} lines • Txns ${data.txnsInserted} • Orders ${data.ordersUpdated}`,
          'Report processed',
          'ok'
        );
        btn.textContent = 'Processed';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn');
        btn.disabled = true; // prevent double processing
      }
    } catch (err) {
      console.error(err);
      showToast('Unexpected error while processing report', 'Error', 'err');
      btn.textContent = 'Retry';
      btn.disabled = false;
    } finally {
      // If you prefer re-enabling on failure only, keep as-is.
      if (btn.textContent === 'Retry') btn.disabled = false;
      if (btn.textContent === originalText) btn.textContent = originalText;
    }
  });
})();
</script>
