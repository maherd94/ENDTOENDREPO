<?php
/**
 * REPORT_AVAILABLE webhook handler for Adyen Settlement details CSV.
 *
 * Workflow:
 * 1) Receive webhook JSON (classic notifications payload)
 * 2) For each REPORT_AVAILABLE & success=true item:
 *      - Extract download URL from "reason"
 *      - Download CSV with Report Service API key
 *      - Parse and upsert:
 *          a) settlement_details (per row)
 *          b) settlements (batch-level totals)
 *      - Insert a "SETTLED" transaction (per row with Type=Settled), and optionally mark order as SETTLED
 *
 * Notes:
 * - Designed for PostgreSQL. Uses numeric(20,4) for money columns to avoid float issues.
 * - Converts Net amounts to minor units for the transactions table (uses currency exponent mapping).
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap your app (must provide db(): PDO). Replace with your own require.
// ─────────────────────────────────────────────────────────────────────────────
$root = __DIR__;
$bootstrap = $root . '/bootstrap.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap; // should define db()
} else {
    // Fallback connector (edit DSN/USER/PASS to your env or just delete this block)
    function db(): PDO {
        static $pdo = null;
        if ($pdo) return $pdo;
        $dsn  = getenv('DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=endtoend';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: 'postgres';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return $pdo;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Config
// ─────────────────────────────────────────────────────────────────────────────
$REPORT_API_KEY = getenv('REPORT_API_KEY'); // Report service credential API key
if (!$REPORT_API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'Missing REPORT_API_KEY environment variable']);
    exit;
}

// Optional: HMAC verification for classic notifications (if you enabled it)
// function verifyHmac(array $item, string $hmacKeyHex): bool { /* … */ return true; }

// ─────────────────────────────────────────────────────────────────────────────
// Utilities
// ─────────────────────────────────────────────────────────────────────────────
function jsonInput(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON');
    }
    return $data;
}

/** Extract first HTTPS URL from a string (Adyen puts the download link in "reason"). */
function extractUrlFromReason(?string $reason): ?string {
    if (!$reason) return null;
    if (preg_match('~https://\S+~', $reason, $m)) {
        // Trim trailing punctuation if present
        return rtrim($m[0], '.,;)\'"');
    }
    return null;
}

/** Simple currency exponent map (extend as needed). */
function currencyExponent(string $ccy): int {
    $ccy = strtoupper($ccy);
    // Zero-decimal currencies
    $zero = ['JPY','KRW','CLP','VND','MGA','UGX','XOF','XAF','XPF','KMF','BIF','DJF','GNF','PYG','RWF','VUV'];
    if (in_array($ccy, $zero, true)) return 0;
    // Three-decimal currencies
    $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];
    if (in_array($ccy, $three, true)) return 3;
    return 2; // default
}

/** Convert decimal amount string to minor units int using currency exponent. */
function toMinor(string $amt, string $ccy): int {
    $amt = trim($amt);
    if ($amt === '' || !is_numeric($amt)) return 0;
    $exp = currencyExponent($ccy);
    return (int) round(((float)$amt) * (10 ** $exp));
}

/** Parse CSV into generator of associative rows keyed by header. */
function readCsv(string $filePath): Generator {
    $fh = fopen($filePath, 'r');
    if (!$fh) throw new RuntimeException("Cannot open CSV: $filePath");
    $headers = fgetcsv($fh);
    if (!$headers) throw new RuntimeException("Empty CSV: $filePath");
    // Normalize headers
    $headers = array_map(static fn($h) => trim((string)$h), $headers);
    while (($row = fgetcsv($fh)) !== false) {
        // Handle ragged rows
        $row = array_pad($row, count($headers), null);
        yield array_combine($headers, array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row));
    }
    fclose($fh);
}

/** Download report with Report Service API key. Returns local path. */
function downloadReport(string $url, string $reportApiKey): string {
    $dir = sys_get_temp_dir() . '/adyen_reports';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = 'settlement_' . md5($url) . '.csv';
    $target = $dir . '/' . $name;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $reportApiKey],
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Download failed: $err");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Download failed, HTTP $status");
    }
    file_put_contents($target, $body);
    return $target;
}

/** Create tables if not exist (Postgres). Safe to run every time. */
function ensureTables(): void {
    $sql = [
        // Parent: settlements (aggregated per Batch Number & file)
        <<<SQL
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
SQL,
        // Child: settlement_details (per line)
        <<<SQL
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
CREATE INDEX IF NOT EXISTS idx_settlement_details_psp ON settlement_details(psp_reference);
SQL
    ];
    $pdo = db();
    foreach ($sql as $stmt) {
        $pdo->exec($stmt);
    }
}

/** Find or create the settlements parent row for (batch_number, report_filename). Returns id. */
function upsertSettlementParent(array $agg, string $reportPspRef, string $reportFile): int {
    $pdo = db();
    $sel = $pdo->prepare("SELECT id FROM settlements WHERE batch_number = :b AND report_filename = :f LIMIT 1");
    $sel->execute([':b' => $agg['batch_number'], ':f' => $reportFile]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $upd = $pdo->prepare("
            UPDATE settlements
               SET report_psp_reference = COALESCE(:rpr, report_psp_reference),
                   gross_currency = COALESCE(:gc, gross_currency),
                   net_currency   = COALESCE(:nc, net_currency),
                   gross_debit = COALESCE(gross_debit,0) + :gd,
                   gross_credit= COALESCE(gross_credit,0)+ :gc2,
                   net_debit   = COALESCE(net_debit,0)  + :nd,
                   net_credit  = COALESCE(net_credit,0) + :nc2,
                   commission  = COALESCE(commission,0) + :comm,
                   markup      = COALESCE(markup,0)     + :markup,
                   scheme_fees = COALESCE(scheme_fees,0)+ :scheme,
                   interchange = COALESCE(interchange,0)+ :inter
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

/** Insert/update a settlement_details row and return its id. */
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

/** Insert a SETTLED transaction and (optionally) mark the order as SETTLED. */
function insertSettledTransaction(string $pspRef, ?string $merchantRef, string $currency, int $amountMinor, int $batchNumber): void {
    $pdo = db();

    // Try to find order_id by psp_ref first, else by merchant_reference
    $orderId = null;

    $q1 = $pdo->prepare("SELECT order_id FROM transactions WHERE psp_ref = :psp ORDER BY id DESC LIMIT 1");
    $q1->execute([':psp' => $pspRef]);
    $orderId = $q1->fetchColumn();

    if (!$orderId && $merchantRef) {
        // If you store merchant_reference in transactions, use that path too.
        $q2 = $pdo->prepare("SELECT order_id FROM transactions WHERE psp_ref = :mref OR :mref = ANY(ARRAY[merchant_reference]) ORDER BY id DESC LIMIT 1");
        try { $q2->execute([':mref' => $merchantRef]); $orderId = $q2->fetchColumn(); } catch (Throwable $e) {}
    }

    if ($orderId) {
        // Insert transaction
        $insTxn = $pdo->prepare("
            INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
            VALUES (:order_id, 'SETTLED', 'SUCCESS', :amount_minor, :currency, :psp_ref, 'REPORT')
        ");
        $insTxn->execute([
            ':order_id'     => $orderId,
            ':amount_minor' => $amountMinor,
            ':currency'     => $currency,
            ':psp_ref'      => $pspRef,
        ]);

        // Optionally mark order as SETTLED if you have an orders table
        try {
            $updOrder = $pdo->prepare("
                UPDATE orders
                   SET status = 'SETTLED',
                       settled_at = NOW(),
                       settlement_batch = :batch
                 WHERE id = :order_id
            ");
            $updOrder->execute([':batch' => $batchNumber, ':order_id' => $orderId]);
        } catch (Throwable $e) {
            // If you don't have an orders table, ignore.
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Main handler
// ─────────────────────────────────────────────────────────────────────────────
try {
    ensureTables();

    $payload = jsonInput();
    $items = $payload['notificationItems'] ?? [];

    foreach ($items as $wrap) {
        $it = $wrap['NotificationRequestItem'] ?? [];
        $eventCode = $it['eventCode'] ?? '';
        $success   = strtolower((string)($it['success'] ?? 'false')) === 'true';

        if ($eventCode !== 'REPORT_AVAILABLE' || !$success) {
            continue;
        }

        $reportPspRef  = $it['pspReference'] ?? null;
        $reportReason  = $it['reason'] ?? null;
        $reportUrl     = extractUrlFromReason($reportReason);

        if (!$reportUrl) {
            throw new RuntimeException('REPORT_AVAILABLE without a download URL in reason.');
        }

        // Download and parse CSV
        $csvPath = downloadReport($reportUrl, $REPORT_API_KEY);
        $reportFileName = basename($csvPath);

        // Aggregate by batch for parent table
        $aggregates = []; // [batch => totals]

        foreach (readCsv($csvPath) as $row) {
            // Column names based on your attached CSV
            $psp                = (string)($row['Psp Reference'] ?? '');
            $mref               = (string)($row['Merchant Reference'] ?? '');
            $type               = (string)($row['Type'] ?? '');
            $creationDate       = (string)($row['Creation Date'] ?? '');
            $tz                 = (string)($row['TimeZone'] ?? '');
            $modRef             = (string)($row['Modification Reference'] ?? '');
            $grossCcy           = (string)($row['Gross Currency'] ?? '');
            $grossDebit         = (string)($row['Gross Debit (GC)'] ?? '0');
            $grossCredit        = (string)($row['Gross Credit (GC)'] ?? '0');
            $netCcy             = (string)($row['Net Currency'] ?? '');
            $netDebit           = (string)($row['Net Debit (NC)'] ?? '0');
            $netCredit          = (string)($row['Net Credit (NC)'] ?? '0');
            $commission         = (string)($row['Commission (NC)'] ?? '0');
            $markup             = (string)($row['Markup (NC)'] ?? '0');
            $schemeFees         = (string)($row['Scheme Fees (NC)'] ?? '0');
            $interchange        = (string)($row['Interchange (NC)'] ?? '0');
            $pm                 = (string)($row['Payment Method'] ?? '');
            $pmVariant          = (string)($row['Payment Method Variant'] ?? '');
            $modMref            = (string)($row['Modification Merchant Reference'] ?? '');
            $batch              = (int)($row['Batch Number'] ?? 0);

            // Skip empty rows
            if ($psp === '' && $type === '' && $batch === 0) continue;

            // Prepare numeric as strings for numeric(20,4)
            $gdebit   = ($grossDebit === '' ? '0' : $grossDebit);
            $gcredit  = ($grossCredit === '' ? '0' : $grossCredit);
            $ndebit   = ($netDebit === '' ? '0' : $netDebit);
            $ncredit  = ($netCredit === '' ? '0' : $netCredit);
            $comm     = ($commission === '' ? '0' : $commission);
            $mrkup    = ($markup === '' ? '0' : $markup);
            $scheme   = ($schemeFees === '' ? '0' : $schemeFees);
            $inter    = ($interchange === '' ? '0' : $interchange);

            // Build detail row payload
            $detail = [
                'psp_reference'                => $psp ?: null,
                'modification_reference'       => $modRef ?: null,
                'merchant_reference'           => $mref ?: null,
                'type'                         => $type ?: null,
                'creation_date'                => $creationDate ? date('c', strtotime($creationDate)) : null,
                'timezone'                     => $tz ?: null,
                'gross_currency'               => $grossCcy ?: null,
                'gross_debit'                  => $gdebit,
                'gross_credit'                 => $gcredit,
                'net_currency'                 => $netCcy ?: null,
                'net_debit'                    => $ndebit,
                'net_credit'                   => $ncredit,
                'commission'                   => $comm,
                'markup'                       => $mrkup,
                'scheme_fees'                  => $scheme,
                'interchange'                  => $inter,
                'payment_method'               => $pm ?: null,
                'payment_method_variant'       => $pmVariant ?: null,
                'modification_merchant_reference' => $modMref ?: null,
                'batch_number'                 => $batch,
            ];

            // Aggregate per batch for parent
            if (!isset($aggregates[$batch])) {
                $aggregates[$batch] = [
                    'batch_number'  => $batch,
                    'gross_currency'=> $grossCcy ?: null,
                    'net_currency'  => $netCcy ?: null,
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
            $aggregates[$batch]['gross_debit'] += (float)$gdebit;
            $aggregates[$batch]['gross_credit']+= (float)$gcredit;
            $aggregates[$batch]['net_debit']   += (float)$ndebit;
            $aggregates[$batch]['net_credit']  += (float)$ncredit;
            $aggregates[$batch]['commission']  += (float)$comm;
            $aggregates[$batch]['markup']      += (float)$mrkup;
            $aggregates[$batch]['scheme_fees'] += (float)$scheme;
            $aggregates[$batch]['interchange'] += (float)$inter;

            // Upsert settlement parent row now to get ID (idempotent per (batch, file))
            $settlementId = upsertSettlementParent($aggregates[$batch], $reportPspRef, $reportFileName);

            // Upsert details row
            upsertSettlementDetail($detail, $settlementId);

            // If the line is a transaction settlement (Type = "Settled"), add a SETTLED transaction
            if (strcasecmp($type, 'Settled') === 0 && $psp) {
                // Choose a currency to store in transactions: use Net Currency if present, else Gross Currency
                $txnCcy = $netCcy ?: ($grossCcy ?: 'AED');
                // Use net movement as amount (credits positive)
                $netMovement = (float)$ncredit - (float)$ndebit;
                // Convert to minor units
                $amountMinor = toMinor((string)$netMovement, $txnCcy);
                if ($amountMinor !== 0) {
                    insertSettledTransaction($psp, $mref ?: null, $txnCcy, $amountMinor, $batch);
                }
            }
        }
    }

    // Respond 200 OK to Adyen
    header('Content-Type: application/json');
    echo json_encode(['notificationResponse' => '[accepted]']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
