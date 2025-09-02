<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// DB
try {
    $pdo = db();
    $pdo->exec('SET search_path TO "ENDTOEND", public');
} catch (Throwable $e) {
    echo '<div class="card"><h3>DB Error</h3><p>' . h($e->getMessage()) . '</p></div>';
    exit;
}

/* ---------- Filters & paging ---------- */
$perPage = 50;
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

$evFilter = isset($_GET['event']) ? trim((string)$_GET['event']) : '';
$refSearch = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$where = [];
$params = [];

if ($evFilter !== '') {
    $where[] = 'we.event_code ILIKE :ev';
    $params[':ev'] = $evFilter;
}
if ($refSearch !== '') {
    // search merchant/psp/original refs within payload
    $where[] = "(we.payload_json->>'merchantReference' ILIKE :q
              OR we.payload_json->>'pspReference' ILIKE :q
              OR we.payload_json->>'originalReference' ILIKE :q)";
    $params[':q'] = '%' . $refSearch . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Count for pagination */
$countSql = "SELECT COUNT(*) FROM webhook_events we $whereSql";
$stc = $pdo->prepare($countSql);
$stc->execute($params);
$totalRows = (int)$stc->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* Query rows */
$sql = "
  SELECT
    we.id,
    we.received_at,
    we.event_code,
    we.order_id,
    we.transaction_id,
    we.payload_json->>'success' AS success_str,
    we.payload_json->>'merchantReference' AS merchant_reference,
    we.payload_json->>'pspReference' AS psp_reference,
    we.payload_json->>'originalReference' AS original_reference,
    we.payload_json
  FROM webhook_events we
  $whereSql
  ORDER BY we.id DESC
  LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) { $st->bindValue($k, $v); }
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Safe JSON for embedding inside <script type="application/json"> */
function json_for_script($data): string {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // prevent </script> breakouts inside JSON
    return str_replace('</script>', '<\/script>', (string)$json);
}
?>
<div class="card" style="margin-bottom:1rem;">
  <form id="webhookFilters" class="form-inline" style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end;">
    <div>
      <label for="event" class="label">Event</label>
      <input type="text" id="event" name="event" placeholder="AUTHORISATION" value="<?= h($evFilter) ?>" />
    </div>
    <div>
      <label for="q" class="label">Ref search</label>
      <input type="text" id="q" name="q" placeholder="merchant/psp/original ref" value="<?= h($refSearch) ?>" />
    </div>
    <div>
      <button type="submit" class="btn">Apply</button>
      <?php if ($evFilter || $refSearch): ?>
        <a href="#webhooks" class="btn btn-secondary" id="resetFilters">Reset</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <h3>Webhook Events</h3>
    <div class="muted">Showing page <?= h($page) ?> of <?= h($totalPages) ?> (<?= h($totalRows) ?> total)</div>
  </div>
  <div class="table-wrap">
    <table class="table" id="webhookTable">
      <thead>
        <tr>
          <th style="width:72px;">ID</th>
          <th style="width:170px;">Received</th>
          <th style="width:140px;">Event</th>
          <th style="width:90px;">Success</th>
          <th>Merchant Ref</th>
          <th>PSP Ref</th>
          <th style="width:110px;">Order</th>
          <th style="width:110px;">Txn</th>
          <th style="width:140px;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9"><em>No webhook events yet.</em></td></tr>
      <?php else:
        foreach ($rows as $r):
          $success = strtolower((string)$r['success_str']) === 'true' ? 'true' : ( (string)$r['success_str'] === '' ? '' : 'false');
          $payloadText = $r['payload_json']; // JSON string (from PostgreSQL)
          // Decode to array (for pretty print in <pre> with JS we don't need PHP pretty, but keep it available)
          $payloadArr = json_decode((string)$payloadText, true);
          // Fallback if decode fails
          if ($payloadArr === null) { $payloadArr = []; }
          $payloadScript = json_for_script($payloadArr);
      ?>
        <tr class="wh-row" data-id="<?= (int)$r['id'] ?>" style="cursor:pointer;">
          <td><?= (int)$r['id'] ?></td>
          <td><?= h($r['received_at']) ?></td>
          <td><?= h($r['event_code']) ?></td>
          <td><?= h($success) ?></td>
          <td><?= h($r['merchant_reference']) ?></td>
          <td><?= h($r['psp_reference']) ?></td>
          <td>
            <?php if ($r['order_id']): ?>
              <a href="#orders/<?= (int)$r['order_id'] ?>" class="order-link" data-id="<?= (int)$r['order_id'] ?>">#<?= (int)$r['order_id'] ?></a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['transaction_id']): ?>
              <span>#<?= (int)$r['transaction_id'] ?></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn btn-small resend-btn" data-id="<?= (int)$r['id'] ?>">Resend</button>
          </td>
        </tr>
        <tr class="wh-payload" data-for="<?= (int)$r['id'] ?>" style="display:none;">
          <td colspan="9">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <strong>NotificationRequestItem payload</strong>
              <div>
                <button class="btn btn-small copy-json" data-id="<?= (int)$r['id'] ?>">Copy JSON</button>
              </div>
            </div>
            <pre class="json-view" id="jsonview-<?= (int)$r['id'] ?>" style="white-space:pre-wrap; overflow:auto; max-height:320px; margin-top:.5rem;"></pre>
            <!-- embed raw payload safely -->
            <script type="application/json" id="payload-<?= (int)$r['id'] ?>"><?= $payloadScript ?></script>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:.75rem;">
    <?php if ($page > 1): ?>
      <a class="btn btn-small" href="#webhooks?page=<?= $page-1 ?>" data-nav="page">« Prev</a>
    <?php else: ?>
      <button class="btn btn-small" disabled>« Prev</button>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
      <a class="btn btn-small" href="#webhooks?page=<?= $page+1 ?>" data-nav="page">Next »</a>
    <?php else: ?>
      <button class="btn btn-small" disabled>Next »</button>
    <?php endif; ?>
  </div>

  <p class="muted" style="margin-top:.5rem;">
    Tip: Click any row to expand/collapse the payload. Resend posts the same item back to <code>/webhook.php</code>.
  </p>
</div>

<script>
(function(){
  // Filters submit => reload component with params
  $('#webhookFilters').on('submit', function(e){
    e.preventDefault();
    const params = $(this).serialize();
    $('#app').html('Loading…').load('/components/webhooks.php?' + params, function(res, status, xhr){
      if (status === 'error') {
        $('#app').html('<div class="card"><h3>Error</h3><p>' + xhr.status + ' ' + xhr.statusText + '</p></div>');
      }
    });
  });
  // Reset just navigates to #webhooks (index.js will load component)
  $('#resetFilters').on('click', function(e){
    e.preventDefault();
    window.location.hash = '#webhooks';
    // index/app.js boot() will handle
  });

  // Row expand/collapse + lazy pretty-print
  $(document).on('click', 'tr.wh-row', function(e){
    // Ignore clicks on buttons inside the row (resend)
    if ($(e.target).closest('button, .btn').length) return;

    const id = $(this).data('id');
    const $payloadRow = $('tr.wh-payload[data-for="'+id+'"]');
    const $pre = $('#jsonview-' + id);
    const $script = $('#payload-' + id);

    $payloadRow.toggle();

    if ($payloadRow.is(':visible') && $pre.text().trim() === '') {
      try {
        const raw = $script.text();
        const obj = JSON.parse(raw || '{}');
        $pre.text(JSON.stringify(obj, null, 2));
      } catch (err) {
        $pre.text('Failed to parse payload JSON: ' + (err?.message || err));
      }
    }
  });

  // Copy JSON button
  $(document).on('click', '.copy-json', async function(e){
    e.preventDefault();
    const id = $(this).data('id');
    const raw = $('#payload-' + id).text();
    try {
      await navigator.clipboard.writeText(raw);
      $(this).text('Copied!'); const btn = this;
      setTimeout(()=> { $(btn).text('Copy JSON'); }, 1200);
    } catch (err) {
      alert('Copy failed: ' + (err?.message || err));
    }
  });

  // Resend button
  $(document).on('click', '.resend-btn', function(e){
    e.preventDefault();
    const $btn = $(this);
    const id = $btn.data('id');
    const payloadScript = $('#payload-' + id).text();

    let nri;
    try {
      nri = JSON.parse(payloadScript || '{}');
    } catch (err) {
      alert('Invalid JSON payload on this row: ' + (err?.message || err));
      return;
    }

    const envelope = {
      notificationItems: [
        { NotificationRequestItem: nri }
      ]
    };

    $btn.prop('disabled', true).text('Sending…');

    $.ajax({
      url: '/webhook.php',
      method: 'POST',
      data: JSON.stringify(envelope),
      contentType: 'application/json; charset=utf-8',
      dataType: 'text' // webhook.php returns plain text "[accepted]" or JSON error
    }).done(function(resp){
      $btn.text('Sent ✓');
      setTimeout(()=> $btn.text('Resend'), 1500);
    }).fail(function(xhr){
      let msg = 'Failed: ' + xhr.status + ' ' + xhr.statusText;
      try {
        const j = JSON.parse(xhr.responseText);
        if (j?.error) msg += ' — ' + j.error;
      } catch(e){}
      alert(msg);
    }).always(function(){
      $btn.prop('disabled', false);
    });
  });

  // Pagination links (hash-based so index/app.js will re-load)
  $(document).on('click', 'a[data-nav="page"]', function(e){
    e.preventDefault();
    const href = $(this).attr('href'); // like #webhooks?page=2
    window.location.hash = href;
    // Your app.js boot() monitors hash and loads accordingly
    // But just in case, trigger:
    $('#app').html('Loading…').load('/components/webhooks.php' + href.replace('#webhooks',''), function(res, status, xhr){
      if (status === 'error') $('#app').html('<div class="card"><h3>Error</h3><p>'+xhr.status+' '+xhr.statusText+'</p></div>');
    });
  });
})();
</script>
