<?php
// components/reports.php
declare(strict_types=1);

// If you already have a bootstrap that defines db(): PDO, include it.
$root = dirname(__DIR__); // adjust if your structure differs
$bootstrap = $root . '/bootstrap.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap; // should define db()
} else {
    function db(): PDO {
        static $pdo = null;
        if ($pdo) return $pdo;
        $dsn  = getenv('DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=endtoend';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: 'postgres';
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo;
    }
}

/**
 * Fetch latest reports; these rows are created by getreportwebhooks.php (store-only webhook).
 * We only display (no heavy processing here). The Fill button posts to getreportwebhooks.php.
 */
$pdo = db();
$stmt = $pdo->query("
    SELECT
        r.id,
        r.merchant_account,
        r.file_name,
        r.report_type,
        r.batch_number_from_name,
        r.report_date,
        r.status,
        rn.psp_reference,
        rn.download_url,
        r.file_path
    FROM reports r
    LEFT JOIN report_notifications rn ON rn.id = r.notification_id
    ORDER BY r.id DESC
    LIMIT 500
");
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Small helpers
function h(?string $s): string { return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDate($ts): string {
    if (!$ts) return '-';
    try { return (new DateTime($ts))->format('Y-m-d H:i'); } catch (Throwable $e) { return h($ts); }
}
?>

<section class="section reports-section">
  <div class="section-header">
    <h2>Reports</h2>
    <p class="muted">
      <!-- Source: <code>getreportwebhooks.php</code> stores the raw webhook JSON and metadata. -->
      Click <b>Fill data</b> to ingest CSV into <code>settlements</code> &amp; <code>settlement_details</code> and post <code>SETTLED</code> transactions.
    </p>
  </div>

  <div class="card">
    <div class="card-body" style="overflow:auto;">
      <table class="table table-striped" style="width:100%; min-width:980px;">
        <thead>
          <tr>
            <th style="white-space:nowrap;">ID</th>
            <th>Merchant Account</th>
            <th>Batch (from name)</th>
            <th>Report Type</th>
            <th>File Name</th>
            <th>Report Date</th>
            <th>Status</th>
            <th style="white-space:nowrap;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$reports): ?>
          <tr><td colspan="8" class="muted">No reports yet.</td></tr>
        <?php else: ?>
          <?php foreach ($reports as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['merchant_account']) ?></td>
              <td><?= $r['batch_number_from_name'] !== null ? (int)$r['batch_number_from_name'] : '-' ?></td>
              <td><?= h($r['report_type'] ?: 'Unknown') ?></td>
              <td>
                <?php if (!empty($r['file_name'])): ?>
                  <code><?= h($r['file_name']) ?></code>
                <?php else: ?>
                  -
                <?php endif; ?>
              </td>
              <td><?= fmtDate($r['report_date']) ?></td>
              <td>
                <span class="badge"><?= h($r['status'] ?: 'NEW') ?></span>
              </td>
              <td style="white-space:nowrap;">
                <?php if (strtoupper((string)$r['status']) === 'PROCESSED'): ?>
                  <button type="button" class="btn" disabled>Processed</button>
                <?php else: ?>
                  <!-- Pure HTML form POST; app.js can optionally intercept -->
                  <form method="post"
                        action="/getreportwebhooks.php?action=process&id=<?= (int)$r['id'] ?>"
                        style="display:inline;">
                    <button type="submit" class="btn btn-primary">Fill data</button>
                  </form>
                <?php endif; ?>

                <?php
                  // Optional conveniences: expose links if available (no JS needed)
                  $local = $r['file_path'] ?? null;
                  $http  = $r['download_url'] ?? null;
                ?>
                <?php if ($local && is_readable($local)): ?>
                  <span class="muted" title="Local CSV available">• Local</span>
                <?php elseif ($http): ?>
                  <a href="<?= h($http) ?>" target="_blank" rel="noopener">Open link</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="section-footer muted" style="margin-top:8px;">
    <!-- Columns shown: <b>Merchant Account</b>, <b>Batch (from file name)</b>, <b>File Name</b>, <b>Report Date</b>, <b>Report Type</b>.
    Raw webhook JSON is stored in <code>report_notifications.raw_json</code>. -->
  </div>
</section>
