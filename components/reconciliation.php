<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';

try {
    $pdo = db();
    $pdo->exec('SET search_path TO "ENDTOEND", public');
} catch (Throwable $e) {
    echo '<div class="card"><h3>DB Error</h3><p>'.htmlspecialchars((string)$e->getMessage()).'</p></div>';
    exit;
}

/* ---------- helpers (safe output) ---------- */
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function fmtMoney($n): string {
    $n = is_numeric($n) ? (float)$n : 0.0;
    return number_format($n, 2);
}

/* ---------------- Filters ---------------- */
$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('-13 days')->format('Y-m-d'); // last 14 days
$defaultTo   = $today->format('Y-m-d');

$from     = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from']) ? $_GET['from'] : $defaultFrom;
$to       = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])   ? $_GET['to']   : $defaultTo;
$currency = isset($_GET['ccy'])  && preg_match('/^[A-Z]{3}$/', (string)$_GET['ccy'])           ? strtoupper((string)$_GET['ccy']) : 'AED';

$params = [
    ':from' => $from . ' 00:00:00',
    ':to'   => $to   . ' 23:59:59',
    ':ccy'  => $currency,
];

/* ---------------- KPIs & Fees ---------------- */
$kpiSql = "
  SELECT
    COALESCE(SUM(COALESCE(sd.gross_credit,0) - COALESCE(sd.gross_debit,0)), 0) AS gross_movement,
    COALESCE(SUM(COALESCE(sd.commission,0)), 0)   AS fee_commission,
    COALESCE(SUM(COALESCE(sd.markup,0)), 0)       AS fee_markup,
    COALESCE(SUM(COALESCE(sd.scheme_fees,0)), 0)  AS fee_scheme,
    COALESCE(SUM(COALESCE(sd.interchange,0)), 0)  AS fee_interchange,
    COALESCE(SUM(COALESCE(sd.net_credit,0) - COALESCE(sd.net_debit,0)), 0) AS net_inflow
  FROM settlement_details sd
  WHERE sd.creation_date BETWEEN :from AND :to
    AND (sd.net_currency = :ccy OR sd.gross_currency = :ccy)
";
$kpi = [
  'gross_movement'=>0, 'fee_commission'=>0, 'fee_markup'=>0, 'fee_scheme'=>0, 'fee_interchange'=>0, 'net_inflow'=>0
];
try {
    $st = $pdo->prepare($kpiSql);
    $st->execute($params);
    $kpi = $st->fetch(PDO::FETCH_ASSOC) ?: $kpi;
} catch (Throwable $e) { $kpi_error = $e->getMessage(); }

$totalFees = (float)$kpi['fee_commission'] + (float)$kpi['fee_markup'] + (float)$kpi['fee_scheme'] + (float)$kpi['fee_interchange'];

/* ---------------- Recon rate ---------------- */
$reconSql = "
  WITH settled AS (
    SELECT sd.psp_reference, sd.merchant_reference
    FROM settlement_details sd
    WHERE sd.creation_date BETWEEN :from AND :to
      AND (sd.net_currency = :ccy OR sd.gross_currency = :ccy)
      AND sd.type ILIKE 'Settled'
  )
  SELECT
    COUNT(*) AS total_lines,
    SUM(CASE WHEN o.id IS NOT NULL OR t.order_id IS NOT NULL THEN 1 ELSE 0 END) AS matched_lines
  FROM settled s
  LEFT JOIN orders o       ON o.order_number = s.merchant_reference
  LEFT JOIN transactions t ON t.psp_ref = s.psp_reference
";
$recon = ['total_lines'=>0, 'matched_lines'=>0];
try {
    $st = $pdo->prepare($reconSql);
    $st->execute($params);
    $recon = $st->fetch(PDO::FETCH_ASSOC) ?: $recon;
} catch (Throwable $e) { $recon_error = $e->getMessage(); }
$reconRate = ($recon['total_lines'] > 0) ? round(100 * ((int)$recon['matched_lines'] / (int)$recon['total_lines']), 1) : 100.0;

/* ---------------- Unmatched Settled Lines ---------------- */
$unmatchedReportSql = "
  SELECT
    sd.creation_date,
    sd.psp_reference,
    sd.merchant_reference,
    sd.payment_method,
    sd.payment_method_variant,
    (COALESCE(sd.net_credit,0) - COALESCE(sd.net_debit,0)) AS net_movement,
    sd.net_currency,
    sd.batch_number
  FROM settlement_details sd
  LEFT JOIN orders o       ON o.order_number = sd.merchant_reference
  LEFT JOIN transactions t ON t.psp_ref = sd.psp_reference
  WHERE sd.creation_date BETWEEN :from AND :to
    AND (sd.net_currency = :ccy OR sd.gross_currency = :ccy)
    AND sd.type ILIKE 'Settled'
    AND o.id IS NULL
    AND t.order_id IS NULL
  ORDER BY sd.creation_date DESC
  LIMIT 50
";
$unmatchedReport = [];
try {
    $st = $pdo->prepare($unmatchedReportSql);
    $st->execute($params);
    $unmatchedReport = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $unmatched_report_error = $e->getMessage(); }

/* ---------------- Orders not yet Settled ---------------- */
$ordersNotSettledSql = "
  SELECT o.id, o.order_number, o.status, o.psp_ref, o.settled_at, o.settlement_batch
  FROM orders o
  LEFT JOIN transactions ts
         ON ts.order_id = o.id AND ts.type = 'SETTLED' AND ts.status = 'SUCCESS'
  WHERE ts.order_id IS NULL
  ORDER BY o.id DESC
  LIMIT 50
";
$ordersNotSettled = [];
try {
    $st = $pdo->query($ordersNotSettledSql);
    $ordersNotSettled = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $orders_not_settled_error = $e->getMessage(); }

/* ---------------- Batch summary (parent) ---------------- */
$batchSummarySql = "
  SELECT
    s.batch_number,
    s.report_filename,
    s.report_date,
    s.net_currency,
    (COALESCE(s.net_credit,0) - COALESCE(s.net_debit,0)) AS net_inflow,
    (COALESCE(s.gross_credit,0) - COALESCE(s.gross_debit,0)) AS gross_movement,
    COALESCE(s.commission,0)  AS fee_commission,
    COALESCE(s.markup,0)      AS fee_markup,
    COALESCE(s.scheme_fees,0) AS fee_scheme,
    COALESCE(s.interchange,0) AS fee_interchange
  FROM settlements s
  WHERE (s.net_currency = :ccy OR s.gross_currency = :ccy OR :ccy IS NULL)
    AND s.report_date BETWEEN :from AND :to
  ORDER BY s.report_date DESC, s.batch_number DESC
  LIMIT 20
";
$batchSummary = [];
try {
    $st = $pdo->prepare($batchSummarySql);
    $st->execute($params);
    $batchSummary = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $batch_error = $e->getMessage(); }
?>
<link rel="preconnect" href="https://cdn.jsdelivr.net" />
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net" />
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="card" style="margin-bottom:1rem;">
  <form id="reconFilters" class="form-inline" style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end;">
    <div>
      <label for="from" class="label">From</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>">
    </div>
    <div>
      <label for="to" class="label">To</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>">
    </div>
    <div>
      <label for="ccy" class="label">Currency</label>
      <input type="text" id="ccy" name="ccy" value="<?= h($currency) ?>" maxlength="3" style="text-transform:uppercase;">
    </div>
    <div>
      <button type="submit" class="btn">Apply</button>
    </div>
  </form>
</div>

<div class="grid" style="display:grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap:1rem;">
  <div class="card">
    <h3>Gross Movement (GC)</h3>
    <div class="kpi"><?= h($currency) ?> <?= fmtMoney($kpi['gross_movement']) ?></div>
    <small>Gross credits − debits</small>
  </div>
  <div class="card">
    <h3>Total Fees (NC)</h3>
    <div class="kpi"><?= h($currency) ?> <?= fmtMoney($totalFees) ?></div>
    <small>Commission + Markup + Scheme + Interchange</small>
  </div>
  <div class="card">
    <h3>Net Inflow (NC)</h3>
    <div class="kpi"><?= h($currency) ?> <?= fmtMoney($kpi['net_inflow']) ?></div>
    <small>Net credits − debits</small>
  </div>
  <div class="card">
    <h3>Reconciliation Rate</h3>
    <div class="kpi"><?= (int)$recon['matched_lines'] ?> / <?= (int)$recon['total_lines'] ?> (<?= $reconRate ?>%)</div>
    <small>Settled lines matched to orders/txns</small>
  </div>
</div>

<div class="card" style="margin-top:1rem;">
  <h3>Fees Composition (NC)</h3>
  <canvas id="feeDonut" height="140"></canvas>
</div>

<div class="card" style="margin-top:1rem;">
  <h3>Batch Summary (last 20)</h3>
  <?php if (!empty($batch_error)): ?>
    <p class="text-error">Error: <?= h($batch_error) ?></p>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Report Date</th>
          <th>Batch #</th>
          <th>File</th>
          <th>Gross Move</th>
          <th>Net Inflow</th>
          <th>Fees (C/M/S/I)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$batchSummary): ?>
          <tr><td colspan="6"><em>No batches in the selected window.</em></td></tr>
        <?php else: foreach ($batchSummary as $b): ?>
          <tr>
            <td><?= h($b['report_date']) ?></td>
            <td><?= h($b['batch_number']) ?></td>
            <td><?= h($b['report_filename']) ?></td>
            <td style="text-align:right;"><?= h($currency) ?> <?= fmtMoney($b['gross_movement']) ?></td>
            <td style="text-align:right;"><?= h($currency) ?> <?= fmtMoney($b['net_inflow']) ?></td>
            <td style="text-align:right;">
              <?= fmtMoney($b['fee_commission']) ?> /
              <?= fmtMoney($b['fee_markup']) ?> /
              <?= fmtMoney($b['fee_scheme']) ?> /
              <?= fmtMoney($b['fee_interchange']) ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:1rem;">
  <h3>Unmatched Settled Lines (top 50)</h3>
  <?php if (!empty($unmatched_report_error)): ?>
    <p class="text-error">Error: <?= h($unmatched_report_error) ?></p>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Creation Date</th>
          <th>PSP Ref</th>
          <th>Merchant Ref</th>
          <th>PM / Variant</th>
          <th>Net Move</th>
          <th>CCY</th>
          <th>Batch</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$unmatchedReport): ?>
          <tr><td colspan="7"><em>All settled lines are matched for the selected range/currency 🎉</em></td></tr>
        <?php else: foreach ($unmatchedReport as $r): ?>
          <tr>
            <td><?= h($r['creation_date'] ?? '') ?></td>
            <td><?= h($r['psp_reference'] ?? '') ?></td>
            <td><?= h($r['merchant_reference'] ?? '') ?></td>
            <td><?= h($r['payment_method'] ?? '') ?><?= !empty($r['payment_method_variant']) ? ' / '.h($r['payment_method_variant']) : '' ?></td>
            <td style="text-align:right;"><?= fmtMoney($r['net_movement'] ?? 0) ?></td>
            <td><?= h($r['net_currency'] ?? $currency) ?></td>
            <td><?= h($r['batch_number'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:1rem;">
  <h3>Orders Not Yet Settled (top 50)</h3>
  <?php if (!empty($orders_not_settled_error)): ?>
    <p class="text-error">Error: <?= h($orders_not_settled_error) ?></p>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Order #</th>
          <th>Status</th>
          <th>PSP Ref</th>
          <th>Settled At</th>
          <th>Batch</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$ordersNotSettled): ?>
          <tr><td colspan="6"><em>Everything looks settled for now.</em></td></tr>
        <?php else: foreach ($ordersNotSettled as $o): ?>
          <tr>
            <td><?= (int)$o['id'] ?></td>
            <td><a href="#orders/<?= urlencode((string)$o['id']) ?>" class="order-link" data-id="<?= (int)$o['id'] ?>"><?= h($o['order_number'] ?? '') ?></a></td>
            <td><?= h($o['status'] ?? '') ?></td>
            <td><?= h($o['psp_ref'] ?? '') ?></td>
            <td><?= h($o['settled_at'] ?? '') ?></td>
            <td><?= h($o['settlement_batch'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  $('#reconFilters').on('submit', function(e){
    e.preventDefault();
    const params = $(this).serialize();
    $('#app').html('Loading…').load('/components/reconciliation.php?' + params, function(res, status, xhr){
      if (status === 'error') {
        $('#app').html('<div class="card"><h3>Error</h3><p>' + xhr.status + ' ' + xhr.statusText + '</p></div>');
      }
    });
  });

  const feeData = {
    labels: ['Commission','Markup','Scheme','Interchange'],
    datasets: [{
      data: [
        <?= json_encode((float)$kpi['fee_commission']) ?>,
        <?= json_encode((float)$kpi['fee_markup']) ?>,
        <?= json_encode((float)$kpi['fee_scheme']) ?>,
        <?= json_encode((float)$kpi['fee_interchange']) ?>
      ]
    }]
  };
  const ctx = document.getElementById('feeDonut');
  if (ctx && window.Chart) {
    new Chart(ctx, {
      type: 'doughnut',
      data: feeData,
      options: {
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function(c){
                const v = c.parsed;
                const ccy = <?= json_encode($currency) ?>;
                return c.label + ': ' + ccy + ' ' + Number(v).toFixed(2);
              }
            }
          }
        },
        cutout: '60%'
      }
    });
  }
})();
</script>

<?php if (!empty($kpi_error) || !empty($recon_error)): ?>
  <div class="card" style="margin-top:1rem;">
    <h3>Notes</h3>
    <?php if (!empty($kpi_error)): ?><p class="text-error">KPI query issue: <?= h($kpi_error) ?></p><?php endif; ?>
    <?php if (!empty($recon_error)): ?><p class="text-error">Recon-rate query issue: <?= h($recon_error) ?></p><?php endif; ?>
  </div>
<?php endif; ?>
