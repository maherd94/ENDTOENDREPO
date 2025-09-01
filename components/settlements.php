<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h($v): string { // accept mixed, safe under strict_types
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '0';
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function fmtMoney($n){ return $n === null ? '-' : number_format((float)$n, 2, '.', ','); }
function fmtDate($ts): string {
    if ($ts === null || $ts === '' ) return '-';
    $t = strtotime((string)$ts);
    return $t && $t > 0 ? date('Y-m-d H:i', $t) : '-';
}

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    // Master list with useful rollups
    $stmt = db()->query("
      SELECT
        s.id,
        s.batch_number,
        s.report_filename,
        s.report_date,
        s.gross_currency, s.net_currency,
        s.gross_debit, s.gross_credit,
        s.net_debit,   s.net_credit,
        s.commission, s.markup, s.scheme_fees, s.interchange,
        COALESCE(x.rows_total, 0)         AS rows_total,
        COALESCE(x.rows_settled, 0)       AS rows_settled,
        COALESCE(x.net_move, 0)           AS net_movement
      FROM settlements s
      LEFT JOIN (
        SELECT
          settlement_id,
          COUNT(*)                                            AS rows_total,
          COUNT(*) FILTER (WHERE lower(type)='settled')       AS rows_settled,
          SUM( (COALESCE(net_credit,0)::numeric - COALESCE(net_debit,0)::numeric) )
            FILTER (WHERE lower(type)='settled')              AS net_move
        FROM settlement_details
        GROUP BY settlement_id
      ) x ON x.settlement_id = s.id
      ORDER BY s.report_date DESC, s.id DESC
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
    <h2>Settlements</h2>
    <p class="muted">Click a row to view its settlement details (line items & fees).</p>
  </div>

  <div class="card">
    <div class="card-body" style="overflow:auto;">
      <table class="table" id="settlements-table" style="min-width:1100px;">
        <thead>
          <tr>
            <th>#</th>
            <th>Batch</th>
            <th>Report file</th>
            <th>Date</th>
            <th class="right">Rows</th>
            <th class="right">Settled rows</th>
            <th class="right">Net movement (NC)</th>
            <th class="right">Commission</th>
            <th class="right">Markup</th>
            <th class="right">Scheme</th>
            <th class="right">Interchange</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="11" class="muted">No settlements yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr class="settlement-row" data-id="<?= (int)$r['id'] ?>" style="cursor:pointer;">
            <td><?= (int)$r['id'] ?></td>
            <td><span class="badge"><?= h($r['batch_number']) ?></span></td>
            <td><code><?= h($r['report_filename']) ?></code></td>
            <td><?= h(fmtDate($r['report_date'] ?? null)) ?></td>
            <td class="right"><?= (int)$r['rows_total'] ?></td>
            <td class="right"><?= (int)$r['rows_settled'] ?></td>
            <td class="right"><?= fmtMoney($r['net_movement']).' '.h($r['net_currency'] ?: '') ?></td>
            <td class="right"><?= fmtMoney($r['commission']) ?></td>
            <td class="right"><?= fmtMoney($r['markup']) ?></td>
            <td class="right"><?= fmtMoney($r['scheme_fees']) ?></td>
            <td class="right"><?= fmtMoney($r['interchange']) ?></td>
          </tr>
          <tr class="settlement-details" id="settle-details-<?= (int)$r['id'] ?>" hidden>
            <td colspan="11">
              <div class="card" style="margin:8px 0;">
                <div class="card-body">
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

<style>
  .right { text-align: right; }
  .sdet-table td, .sdet-table th { padding:6px 8px; }
</style>

<script>
(function(){
  function fmt(n){ if(n===null||n===undefined) return '-'; const x=Number(n); return isNaN(x)?'-':x.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }

  async function fetchDetails(settlementId){
    const url = '/apis/settlement_details_list.php?id=' + encodeURIComponent(settlementId);
    const res = await fetch(url, { headers: {'Accept':'application/json'}});
    if (!res.ok) throw new Error('HTTP '+res.status);
    return res.json();
  }

  function renderDetails(container, data){
    const rows = data.data || [];
    if (!rows.length) {
      container.html('<div class="muted">No details for this settlement.</div>');
      return;
    }
    const $t = $(`
      <table class="table sdet-table" style="min-width:1100px;">
        <thead>
          <tr>
            <th>PSP Ref</th>
            <th>Order (Merchant Ref)</th>
            <th>Type</th>
            <th>Date</th>
            <th class="right">Net Debit</th>
            <th class="right">Net Credit</th>
            <th class="right">Proc.</th>
            <th class="right">Markup</th>
            <th class="right">Scheme</th>
            <th class="right">Interch.</th>
            <th>PM</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    `);
    const $tb = $t.find('tbody');

    rows.forEach(r => {
      const orderCell = (r.order_id && r.merchant_reference)
        ? `<a href="/htmls/index.html?tab=orders&order=${encodeURIComponent(r.merchant_reference)}" title="Open order">${r.merchant_reference}</a>`
        : (r.merchant_reference ? `<span class="muted">${r.merchant_reference}</span>` : '-');

      const dt = r.creation_date ? new Date(r.creation_date) : null;
      const dtStr = dt ? dt.toISOString().slice(0,16).replace('T',' ') : '-';
      $tb.append(`
        <tr>
          <td>${r.psp_reference ? `<code>${r.psp_reference}</code>` : '-'}</td>
          <td>${orderCell}</td>
          <td>${r.type || '-'}</td>
          <td>${dtStr}</td>
          <td class="right">${fmt(r.net_debit)} ${r.net_currency || ''}</td>
          <td class="right">${fmt(r.net_credit)} ${r.net_currency || ''}</td>
          <td class="right">${fmt(r.commission)}</td>
          <td class="right">${fmt(r.markup)}</td>
          <td class="right">${fmt(r.scheme_fees)}</td>
          <td class="right">${fmt(r.interchange)}</td>
          <td>${r.payment_method_variant || r.payment_method || '-'}</td>
        </tr>
      `);
    });

    container.empty().append($t);
  }

  // Click to expand/collapse + lazy load
  $('#settlements-table').on('click', 'tr.settlement-row', async function(){
    const id = this.dataset.id;
    const $detailRow = $('#settle-details-' + id);
    const $box = $detailRow.find('.card-body');

    const isHidden = $detailRow.prop('hidden');

    if (isHidden) {
      $detailRow.prop('hidden', false);
      $box.html('<div class="muted">Loading details…</div>');
      try {
        const json = await fetchDetails(id);
        renderDetails($box, json);
      } catch (e) {
        console.error(e);
        $box.html('<div class="muted">Failed to load details.</div>');
      }
    } else {
      $detailRow.prop('hidden', true);
    }
  });
})();
</script>
