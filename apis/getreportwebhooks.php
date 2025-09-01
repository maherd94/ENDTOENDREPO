<?php
declare(strict_types=1);

/**
 * getreportswebhook.php
 *
 * - POST with Adyen classic notifications payload (REPORT_AVAILABLE):
 *     Stores raw JSON in report_notifications + a row in reports with parsed metadata.
 * - GET  ?action=list
 *     Returns JSON array of reports for the UI.
 * - POST ?action=process&id=123
 *     Processes the chosen report: reads CSV (local or https with REPORT_API_KEY),
 *     populates settlements + settlement_details and posts SETTLED transactions.
 *
 * ENV:
 *   REPORT_API_KEY (optional) — needed only when processing/downloading https:// reports
 */

////////////////////////////////////////////////////////////////////////////////
// Bootstrap DB (replace with your bootstrap.php if you have one)
////////////////////////////////////////////////////////////////////////////////
$root = __DIR__;
$bootstrap = $root . '/bootstrap.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap; // should define db(): PDO
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

////////////////////////////////////////////////////////////////////////////////
// Small helpers
////////////////////////////////////////////////////////////////////////////////
function jsonInput(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Invalid JSON');
    return $data;
}
function jsonOut($data, int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function now(): string { return date('c'); }

function ensureCoreTables(): void {
    $sql = file_get_contents(__FILE__); // cheap trick not used; we’ll inline simple ensures below
    $pdo = db();

    // report_notifications
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_notifications (
          id BIGSERIAL PRIMARY KEY,
          psp_reference TEXT,
          merchant_account TEXT,
          event_code TEXT,
          event_date TIMESTAMPTZ,
          success BOOLEAN,
          reason TEXT,
          download_url TEXT,
          raw_json JSONB NOT NULL,
          received_at TIMESTAMPTZ DEFAULT now()
        );
    ");
    // reports
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reports (
          id BIGSERIAL PRIMARY KEY,
          notification_id BIGINT REFERENCES report_notifications(id) ON DELETE CASCADE,
          merchant_account TEXT,
          file_name TEXT,
          file_path TEXT,
          report_type TEXT,
          batch_number_from_name INTEGER,
          report_date TIMESTAMPTZ,
          status TEXT DEFAULT 'NEW',
          created_at TIMESTAMPTZ DEFAULT now(),
          updated_at TIMESTAMPTZ DEFAULT now()
        );
    ");
    // settlements
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settlements (
          id BIGSERIAL PRIMARY KEY,
          batch_number INTEGER NOT NULL,
          report_psp_reference TEXT,
          report_filename TEXT NOT NULL,
          report_date TIMESTAMPTZ DEFAULT now(),
          gross_currency TEXT,
          net_currency TEXT,
          gross_debit NUMERIC(20,4) DEFAULT 0,
          gross_credit NUMERIC(20,4) DEFAULT 0,
          net_debit NUMERIC(20,4) DEFAULT 0,
          net_credit NUMERIC(20,4) DEFAULT 0,
          commission NUMERIC(20,4) DEFAULT 0,
          markup NUMERIC(20,4) DEFAULT 0,
          scheme_fees NUMERIC(20,4) DEFAULT 0,
          interchange NUMERIC(20,4) DEFAULT 0,
          CONSTRAINT settlements_uq UNIQUE (batch_number, report_filename)
        );
    ");
    // settlement_details
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settlement_details (
          id BIGSERIAL PRIMARY KEY,
          psp_reference TEXT,
          modification_reference TEXT,
          merchant_reference TEXT,
          type TEXT,
          creation_date TIMESTAMPTZ,
          timezone TEXT,
          gross_currency TEXT,
          gross_debit NUMERIC(20,4),
          gross_credit NUMERIC(20,4),
          net_currency TEXT,
          net_debit NUMERIC(20,4),
          net_credit NUMERIC(20,4),
          commission NUMERIC(20,4),
          markup NUMERIC(20,4),
          scheme_fees NUMERIC(20,4),
          interchange NUMERIC(20,4),
          payment_method TEXT,
          payment_method_variant TEXT,
          modification_merchant_reference TEXT,
          batch_number INTEGER,
          settlement_id BIGINT REFERENCES settlements(id),
          CONSTRAINT settlement_details_uq UNIQUE (psp_reference, type, COALESCE(modification_reference, ''), batch_number)
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settlement_details_psp ON settlement_details(psp_reference);");
}

/** Parse a https URL from reason (if present). */
function parseUrlFromReason(?string $reason): ?string {
    if (!$reason) return null;
    if (preg_match('~https://\S+~', $reason, $m)) return rtrim($m[0], '.,;)\'"');
    return null;
}

/** Extract a basename for file name from URL or local reason string. */
function basenameFromReason(?string $reason): ?string {
    if (!$reason) return null;
    // file://absolute or absolute/relative path
    if (stripos($reason, 'file://') === 0) {
        $p = parse_url($reason, PHP_URL_PATH);
        return $p ? basename($p) : null;
    }
    if (preg_match('~^https?://~i', $reason)) {
        $p = parse_url($reason, PHP_URL_PATH) ?: '';
        $b = basename($p);
        return $b ?: null;
    }
    // treat as file name
    return basename($reason);
}

/** Derive report_type from file name. */
function inferReportTypeFromName(?string $name): string {
    $n = strtolower($name ?? '');
    if (str_contains($n, 'settlement_detail')) return 'Settlement details report';
    if (str_contains($n, 'aggregate'))         return 'Aggregate settlement details report';
    if (str_contains($n, 'payment_accounting'))return 'Payment accounting report';
    if (str_contains($n, 'payout'))            return 'Payout report';
    return 'Unknown';
}

/** Try to find a batch number in file name like batch_41, batch-41, _b41, etc. */
function parseBatchFromName(?string $name): ?int {
    if (!$name) return null;
    if (preg_match('/batch[_-]?(\d{1,6})/i', $name, $m)) return (int)$m[1];
    if (preg_match('/[_-]b(\d{1,6})/i', $name, $m)) return (int)$m[1];
    return null; // not present in some Adyen names; OK
}

/** Try to parse a date from file name (YYYY-MM-DD). Fallback to eventDate. */
function parseDateFromNameOrEvent(?string $name, ?string $eventDate): ?string {
    if ($name && preg_match('/\d{4}-\d{2}-\d{2}/', $name, $m)) return $m[0] . 'T00:00:00Z';
    return $eventDate ?: null;
}

/** Currency exponent map + toMinor for transaction posting. */
function currencyExponent(string $ccy): int {
    $ccy = strtoupper($ccy);
    $zero = ['JPY','KRW','CLP','VND','MGA','UGX','XOF','XAF','XPF','KMF','BIF','DJF','GNF','PYG','RWF','VUV'];
    if (in_array($ccy, $zero, true)) return 0;
    $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];
    if (in_array($ccy, $three, true)) return 3;
    return 2;
}
function toMinor(string $amt, string $ccy): int {
    $amt = trim($amt);
    if ($amt === '' || !is_numeric($amt)) return 0;
    return (int) round(((float)$amt) * (10 ** currencyExponent($ccy)));
}

/** Read CSV fully to array. */
function readCsvToArray(string $filePath): array {
    $fh = fopen($filePath, 'r');
    if (!$fh) throw new RuntimeException("Cannot open CSV: $filePath");
    $headers = fgetcsv($fh);
    if (!$headers) throw new RuntimeException("Empty CSV: $filePath");
    $headers = array_map('trim', $headers);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        $row = array_pad($row, count($headers), null);
        $rows[] = array_combine($headers, array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row));
    }
    fclose($fh);
    return $rows;
}

/** Download CSV (for https links) using REPORT_API_KEY. */
function downloadReport(string $url, string $apiKey): string {
    $dir = sys_get_temp_dir() . '/adyen_reports';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $target = $dir . '/settlement_' . md5($url) . '.csv';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $apiKey],
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Download failed: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) throw new RuntimeException("Download failed, HTTP $code");

    file_put_contents($target, $body);
    return $target;
}

/** Upsert parent settlement with exact totals for a given report file. */
function upsertSettlementParentExact(array $agg, ?string $reportPspRef, string $reportFile): int {
    $pdo = db();
    $sel = $pdo->prepare("SELECT id FROM settlements WHERE batch_number = :b AND report_filename = :f LIMIT 1");
    $sel->execute([':b' => $agg['batch_number'], ':f' => $reportFile]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $upd = $pdo->prepare("
            UPDATE settlements
               SET report_psp_reference = COALESCE(:rpr, report_psp_reference),
                   gross_currency = :gc,
                   net_currency   = :nc,
                   gross_debit = :gd,
                   gross_credit= :gc2,
                   net_debit   = :nd,
                   net_credit  = :nc2,
                   commission  = :comm,
                   markup      = :markup,
                   scheme_fees = :scheme,
                   interchange = :inter,
                   report_date = now()
             WHERE id = :id
        ");
        $upd->execute([
            ':rpr'   => $reportPspRef,
            ':gc'    => $agg['gross_currency'] ?? null,
            ':nc'    => $agg['net_currency'] ?? null,
            ':gd'    => $agg['gross_debit'],
            ':gc2'   => $agg['gross_credit'],
            ':nd'    => $agg['net_debit'],
            ':nc2'   => $agg['net_credit'],
            ':comm'  => $agg['commission'],
            ':markup'=> $agg['markup'],
            ':scheme'=> $agg['scheme_fees'],
            ':inter' => $agg['interchange'],
            ':id'    => (int)$row['id'],
        ]);
        return (int)$row['id'];
    } else {
        $ins = $pdo->prepare("
            INSERT INTO settlements
                (batch_number, report_psp_reference, report_filename,
                 gross_currency, net_currency,
                 gross_debit, gross_credit, net_debit, net_credit,
                 commission, markup, scheme_fees, interchange)
            VALUES
                (:b, :rpr, :f, :gc, :nc, :gd, :gc2, :nd, :nc2, :comm, :markup, :scheme, :inter)
            RETURNING id
        ");
        $ins->execute([
            ':b'     => $agg['batch_number'],
            ':rpr'   => $reportPspRef,
            ':f'     => $reportFile,
            ':gc'    => $agg['gross_currency'] ?? null,
            ':nc'    => $agg['net_currency'] ?? null,
            ':gd'    => $agg['gross_debit'],
            ':gc2'   => $agg['gross_credit'],
            ':nd'    => $agg['net_debit'],
            ':nc2'   => $agg['net_credit'],
            ':comm'  => $agg['commission'],
            ':markup'=> $agg['markup'],
            ':scheme'=> $agg['scheme_fees'],
            ':inter' => $agg['interchange'],
        ]);
        return (int)$ins->fetchColumn();
    }
}

/** Upsert a settlement_details row. */
function upsertSettlementDetail(array $r, ?int $settlementId): void {
    $pdo = db();
    $sel = $pdo->prepare("
        SELECT id FROM settlement_details
         WHERE psp_reference = :psp
           AND type = :type
           AND COALESCE(modification_reference,'') = COALESCE(:mod,'')
           AND batch_number = :batch
         LIMIT 1
    ");
    $sel->execute([
        ':psp'   => $r['psp_reference'],
        ':type'  => $r['type'],
        ':mod'   => $r['modification_reference'],
        ':batch' => $r['batch_number'],
    ]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $upd = $pdo->prepare("
            UPDATE settlement_details
               SET merchant_reference = :mref,
                   creation_date = :cdate,
                   timezone = :tz,
                   gross_currency = :gc,
                   gross_debit = :gdebit,
                   gross_credit = :gcredit,
                   net_currency = :nc,
                   net_debit = :ndebit,
                   net_credit = :ncredit,
                   commission = :comm,
                   markup = :markup,
                   scheme_fees = :scheme,
                   interchange = :inter,
                   payment_method = :pm,
                   payment_method_variant = :pmv,
                   modification_merchant_reference = :mmr,
                   settlement_id = :sid
             WHERE id = :id
        ");
        $upd->execute([
            ':mref'  => $r['merchant_reference'],
            ':cdate' => $r['creation_date'],
            ':tz'    => $r['timezone'],
            ':gc'    => $r['gross_currency'],
            ':gdebit'=> $r['gross_debit'],
            ':gcredit'=> $r['gross_credit'],
            ':nc'    => $r['net_currency'],
            ':ndebit'=> $r['net_debit'],
            ':ncredit'=> $r['net_credit'],
            ':comm'  => $r['commission'],
            ':markup'=> $r['markup'],
            ':scheme'=> $r['scheme_fees'],
            ':inter' => $r['interchange'],
            ':pm'    => $r['payment_method'],
            ':pmv'   => $r['payment_method_variant'],
            ':mmr'   => $r['modification_merchant_reference'],
            ':sid'   => $settlementId,
            ':id'    => (int)$row['id'],
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO settlement_details
                (psp_reference, modification_reference, merchant_reference, type,
                 creation_date, timezone,
                 gross_currency, gross_debit, gross_credit,
                 net_currency, net_debit, net_credit,
                 commission, markup, scheme_fees, interchange,
                 payment_method, payment_method_variant,
                 modification_merchant_reference,
                 batch_number, settlement_id)
            VALUES
                (:psp, :mod, :mref, :type,
                 :cdate, :tz,
                 :gc, :gdebit, :gcredit,
                 :nc, :ndebit, :ncredit,
                 :comm, :markup, :scheme, :inter,
                 :pm, :pmv,
                 :mmr,
                 :batch, :sid)
        ");
        $ins->execute([
            ':psp'   => $r['psp_reference'],
            ':mod'   => $r['modification_reference'],
            ':mref'  => $r['merchant_reference'],
            ':type'  => $r['type'],
            ':cdate' => $r['creation_date'],
            ':tz'    => $r['timezone'],
            ':gc'    => $r['gross_currency'],
            ':gdebit'=> $r['gross_debit'],
            ':gcredit'=> $r['gross_credit'],
            ':nc'    => $r['net_currency'],
            ':ndebit'=> $r['net_debit'],
            ':ncredit'=> $r['net_credit'],
            ':comm'  => $r['commission'],
            ':markup'=> $r['markup'],
            ':scheme'=> $r['scheme_fees'],
            ':inter' => $r['interchange'],
            ':pm'    => $r['payment_method'],
            ':pmv'   => $r['payment_method_variant'],
            ':mmr'   => $r['modification_merchant_reference'],
            ':batch' => $r['batch_number'],
            ':sid'   => $settlementId,
        ]);
    }
}

/** Insert a SETTLED transaction and update order if present. */
function insertSettledTransaction(string $pspRef, ?string $merchantRef, string $currency, int $amountMinor, int $batchNumber): void {
    $pdo = db();
    $q1 = $pdo->prepare("SELECT order_id FROM transactions WHERE psp_ref = :psp ORDER BY id DESC LIMIT 1");
    $q1->execute([':psp' => $pspRef]);
    $orderId = $q1->fetchColumn();
    if (!$orderId) return;

    $ins = $pdo->prepare("
        INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
        VALUES (:order_id, 'SETTLED', 'SUCCESS', :amount_minor, :currency, :psp_ref, 'REPORT_BUTTON')
    ");
    $ins->execute([
        ':order_id'     => $orderId,
        ':amount_minor' => $amountMinor,
        ':currency'     => $currency,
        ':psp_ref'      => $pspRef,
    ]);

    try {
        $upd = $pdo->prepare("
            UPDATE orders
               SET status = 'SETTLED',
                   settled_at = NOW(),
                   settlement_batch = :batch
             WHERE id = :order_id
        ");
        $upd->execute([':batch' => $batchNumber, ':order_id' => $orderId]);
    } catch (Throwable $e) {}
}

////////////////////////////////////////////////////////////////////////////////
// Actions
////////////////////////////////////////////////////////////////////////////////
ensureCoreTables();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($method === 'POST' && !$action) {
    // Webhook mode: store-only
    $payload = jsonInput();
    $items = $payload['notificationItems'] ?? [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($items as $wrap) {
            $it = $wrap['NotificationRequestItem'] ?? [];
            $eventCode = $it['eventCode'] ?? '';
            $success   = strtolower((string)($it['success'] ?? 'false')) === 'true';
            if ($eventCode !== 'REPORT_AVAILABLE' || !$success) continue;

            $merchantAccount = $it['merchantAccountCode'] ?? null;
            $eventDate       = $it['eventDate'] ?? null;
            $pspRef          = $it['pspReference'] ?? null;
            $reason          = $it['reason'] ?? null;

            $downloadUrl     = parseUrlFromReason($reason);
            $fileName        = basenameFromReason($reason);
            $reportType      = inferReportTypeFromName($fileName);
            $batchFromName   = parseBatchFromName($fileName);
            $reportDate      = parseDateFromNameOrEvent($fileName, $eventDate);

            // 1) store raw webhook
            $ins1 = $pdo->prepare("
                INSERT INTO report_notifications
                    (psp_reference, merchant_account, event_code, event_date, success, reason, download_url, raw_json)
                VALUES
                    (:psp, :ma, :ec, :ed, :ok, :reason, :url, :raw)
                RETURNING id
            ");
            $ins1->execute([
                ':psp'    => $pspRef,
                ':ma'     => $merchantAccount,
                ':ec'     => $eventCode,
                ':ed'     => $eventDate ? date('c', strtotime($eventDate)) : null,
                ':ok'     => $success,
                ':reason' => $reason,
                ':url'    => $downloadUrl,
                ':raw'    => json_encode($it, JSON_UNESCAPED_SLASHES),
            ]);
            $notifId = (int)$ins1->fetchColumn();

            // 2) create report catalog row (store-only)
            $ins2 = $pdo->prepare("
                INSERT INTO reports
                    (notification_id, merchant_account, file_name, file_path, report_type, batch_number_from_name, report_date, status)
                VALUES
                    (:nid, :ma, :fn, :fp, :rtype, :batch, :rdate, 'NEW')
            ");
            $ins2->execute([
                ':nid'   => $notifId,
                ':ma'    => $merchantAccount,
                ':fn'    => $fileName,
                ':fp'    => (stripos((string)$reason, 'file://') === 0 || (!preg_match('~^https?://~i', (string)$reason) && $reason))
                            ? (stripos((string)$reason, 'file://') === 0 ? (parse_url((string)$reason, PHP_URL_PATH) ?: null) : $reason)
                            : null,
                ':rtype' => $reportType,
                ':batch' => $batchFromName,
                ':rdate' => $reportDate,
            ]);
        }
        $pdo->commit();
        jsonOut(['notificationResponse' => '[accepted]']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonOut(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'list') {
    // Return a JSON list for the UI
    $pdo = db();
    $stmt = $pdo->query("
        SELECT id, merchant_account, file_name, report_type, batch_number_from_name, report_date, status, created_at
          FROM reports
         ORDER BY id DESC
         LIMIT 500
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonOut(['reports' => $rows]);
}

if ($action === 'process' && $method === 'POST') {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) jsonOut(['error' => 'Missing id'], 400);

    $pdo = db();
    $sel = $pdo->prepare("
        SELECT r.*, rn.psp_reference, rn.download_url
          FROM reports r
          JOIN report_notifications rn ON rn.id = r.notification_id
         WHERE r.id = :id
         LIMIT 1
    ");
    $sel->execute([':id' => $id]);
    $rep = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$rep) jsonOut(['error' => 'Report not found'], 404);

    // Resolve CSV path (local path from file_path OR https via download_url)
    $csvPath = null;
    if (!empty($rep['file_path']) && is_readable($rep['file_path'])) {
        $csvPath = $rep['file_path'];
    } elseif (!empty($rep['download_url'])) {
        $apiKey = getenv('REPORT_API_KEY');
        if (!$apiKey) jsonOut(['error' => 'REPORT_API_KEY required to download https reports'], 400);
        $csvPath = downloadReport($rep['download_url'], $apiKey);
        // mark as DOWNLOADED
        $upd = $pdo->prepare("UPDATE reports SET status = 'DOWNLOADED', updated_at = now() WHERE id = :id");
        $upd->execute([':id' => $id]);
    } else {
        jsonOut(['error' => 'No file_path or download_url available.'], 400);
    }

    // Parse CSV and ingest
    $rows = readCsvToArray($csvPath);

    // Build aggregates per Batch Number
    $aggregates = [];
    foreach ($rows as $row) {
        $batch = (int)($row['Batch Number'] ?? 0);
        if (!isset($aggregates[$batch])) {
            $aggregates[$batch] = [
                'batch_number'  => $batch,
                'gross_currency'=> (string)($row['Gross Currency'] ?? null) ?: null,
                'net_currency'  => (string)($row['Net Currency'] ?? null) ?: null,
                'gross_debit'   => 0.0,
                'gross_credit'  => 0.0,
                'net_debit'     => 0.0,
                'net_credit'    => 0.0,
                'commission'    => 0.0,
                'markup'        => 0.0,
                'scheme_fees'   => 0.0,
                'interchange'   => 0.0,
            ];
        }
        $aggregates[$batch]['gross_debit']    += (float) (($row['Gross Debit (GC)'] ?? '0') ?: 0);
        $aggregates[$batch]['gross_credit']   += (float) (($row['Gross Credit (GC)'] ?? '0') ?: 0);
        $aggregates[$batch]['net_debit']      += (float) (($row['Net Debit (NC)'] ?? '0') ?: 0);
        $aggregates[$batch]['net_credit']     += (float) (($row['Net Credit (NC)'] ?? '0') ?: 0);
        $aggregates[$batch]['commission']     += (float) (($row['Commission (NC)'] ?? '0') ?: 0);
        $aggregates[$batch]['markup']         += (float) (($row['Markup (NC)'] ?? '0') ?: 0);
        $aggregates[$batch]['scheme_fees']    += (float) (($row['Scheme Fees (NC)'] ?? '0') ?: 0);
        $aggregates[$batch]['interchange']    += (float) (($row['Interchange (NC)'] ?? '0') ?: 0);
        if (!$aggregates[$batch]['gross_currency']) $aggregates[$batch]['gross_currency'] = (string)($row['Gross Currency'] ?? null) ?: null;
        if (!$aggregates[$batch]['net_currency'])   $aggregates[$batch]['net_currency']   = (string)($row['Net Currency'] ?? null) ?: null;
    }

    // Upsert parent per batch (exact totals for this file)
    $parentIds = [];
    foreach ($aggregates as $batch => $agg) {
        if ($batch === 0) continue;
        $parentIds[$batch] = upsertSettlementParentExact($agg, $rep['psp_reference'] ?? null, $rep['file_name'] ?? basename($csvPath));
    }

    // Upsert details and add transactions for "Settled" lines
    foreach ($rows as $row) {
        $psp          = (string)($row['Psp Reference'] ?? '');
        $mref         = (string)($row['Merchant Reference'] ?? '');
        $type         = (string)($row['Type'] ?? '');
        $creationDate = (string)($row['Creation Date'] ?? '');
        $tz           = (string)($row['TimeZone'] ?? '');
        $modRef       = (string)($row['Modification Reference'] ?? '');
        $grossCcy     = (string)($row['Gross Currency'] ?? '');
        $gdebit       = (string)($row['Gross Debit (GC)'] ?? '0');
        $gcredit      = (string)($row['Gross Credit (GC)'] ?? '0');
        $netCcy       = (string)($row['Net Currency'] ?? '');
        $ndebit       = (string)($row['Net Debit (NC)'] ?? '0');
        $ncredit      = (string)($row['Net Credit (NC)'] ?? '0');
        $commission   = (string)($row['Commission (NC)'] ?? '0');
        $markup       = (string)($row['Markup (NC)'] ?? '0');
        $schemeFees   = (string)($row['Scheme Fees (NC)'] ?? '0');
        $interchange  = (string)($row['Interchange (NC)'] ?? '0');
        $pm           = (string)($row['Payment Method'] ?? '');
        $pmVariant    = (string)($row['Payment Method Variant'] ?? '');
        $modMref      = (string)($row['Modification Merchant Reference'] ?? '');
        $batch        = (int)($row['Batch Number'] ?? 0);

        if ($psp === '' && $type === '' && $batch === 0) continue;

        $detail = [
            'psp_reference'                  => $psp ?: null,
            'modification_reference'         => $modRef ?: null,
            'merchant_reference'             => $mref ?: null,
            'type'                           => $type ?: null,
            'creation_date'                  => $creationDate ? date('c', strtotime($creationDate)) : null,
            'timezone'                       => $tz ?: null,
            'gross_currency'                 => $grossCcy ?: null,
            'gross_debit'                    => $gdebit === '' ? '0' : $gdebit,
            'gross_credit'                   => $gcredit === '' ? '0' : $gcredit,
            'net_currency'                   => $netCcy ?: null,
            'net_debit'                      => $ndebit === '' ? '0' : $ndebit,
            'net_credit'                     => $ncredit === '' ? '0' : $ncredit,
            'commission'                     => $commission === '' ? '0' : $commission,
            'markup'                         => $markup === '' ? '0' : $markup,
            'scheme_fees'                    => $schemeFees === '' ? '0' : $schemeFees,
            'interchange'                    => $interchange === '' ? '0' : $interchange,
            'payment_method'                 => $pm ?: null,
            'payment_method_variant'         => $pmVariant ?: null,
            'modification_merchant_reference'=> $modMref ?: null,
            'batch_number'                   => $batch,
        ];

        $settlementId = $parentIds[$batch] ?? null;
        upsertSettlementDetail($detail, $settlementId);

        if (strcasecmp($type, 'Settled') === 0 && $psp) {
            $txnCcy = $netCcy ?: ($grossCcy ?: 'AED');
            $netMovement = (float)$ncredit - (float)$ndebit;
            $amountMinor = toMinor((string)$netMovement, $txnCcy);
            if ($amountMinor !== 0) insertSettledTransaction($psp, $mref ?: null, $txnCcy, $amountMinor, $batch);
        }
    }

    // Mark as processed
    $upd = $pdo->prepare("UPDATE reports SET status = 'PROCESSED', updated_at = now() WHERE id = :id");
    $upd->execute([':id' => $id]);

    jsonOut(['ok' => true, 'message' => 'Report processed and data filled.', 'reportId' => $id]);
}

jsonOut(['error' => 'Invalid route'], 404);
