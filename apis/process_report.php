<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection error', 'details' => $e->getMessage()]);
    exit;
}

$REPORT_API_KEY = env('ADYEN_REPORT_API_KEY', env('REPORT_API_KEY', ''));
if ($REPORT_API_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Missing ADYEN_REPORT_API_KEY (or REPORT_API_KEY) in .env']);
    exit;
}

/* ----------------- helpers ----------------- */
function jsonInput(): array {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);
    if (!is_array($in)) throw new RuntimeException('Invalid JSON');
    return $in;
}
function extractUrlFromReason(?string $reason): ?string {
    if (!$reason) return null;
    if (preg_match('~https://\S+~', $reason, $m)) {
        return rtrim($m[0], ".,;)\"'");
    }
    return null;
}
function downloadCsv(string $url, string $apiKey, ?string &$serverFilename = null): string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $apiKey, 'Accept: text/csv'],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException("Download failed: $err"); }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    curl_close($ch);
    if ($status < 200 || $status >= 300) throw new RuntimeException("Download failed, HTTP $status");

    $serverFilename = null;
    if (preg_match('/Content-Disposition:\s*attachment;\s*filename="?([^"]+)"?/i', $headers, $m)) {
        $serverFilename = trim($m[1]);
    } else {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $base = basename($path);
        $serverFilename = $base ?: ('settlement_' . md5($url) . '.csv');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ady_csv_');
    file_put_contents($tmp, $body);
    return $tmp;
}

/**
 * CSV reader compatible with PHP 8.3+ (explicit $escape arg) and BOM-safe.
 */
function readCsv(string $file): Generator {
    $fh = fopen($file, 'r');
    if (!$fh) throw new RuntimeException('Cannot open CSV');

    $headers = fgetcsv($fh, 0, ',', '"', '\\');
    if ($headers === false) { fclose($fh); throw new RuntimeException('Empty CSV'); }

    $headers = array_map(static fn($h) => trim((string)$h), $headers);
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    }

    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $row = array_pad($row, count($headers), null);
        yield array_combine($headers, array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row));
    }
    fclose($fh);
}

function currencyExponent(string $ccy): int {
    $ccy = strtoupper($ccy);
    $zero = ['JPY','KRW','CLP','VND','MGA','UGX','XOF','XAF','XPF','KMF','BIF','DJF','GNF','PYG','RWF','VUV'];
    if (in_array($ccy, $zero, true)) return 0;
    $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];
    if (in_array($ccy, $three, true)) return 3;
    return 2;
}
function toMinor(string $amt, string $ccy): int {
    if ($amt === '' || !is_numeric($amt)) return 0;
    return (int) round(((float)$amt) * (10 ** currencyExponent($ccy)));
}

/* --- upserts (assumes tables already exist) ------------------- */
/**
 * Upsert parent by batch/report file using **per-row deltas** (prevents double counting).
 * We **do not** update 'processing_fee' here; we recompute it from details at the end.
 */
function upsertSettlementParent(array $delta, ?string $reportPspRef, string $reportFile): int {
    $pdo = db();
    $sel = $pdo->prepare("SELECT id FROM settlements WHERE batch_number = :b AND report_filename = :f LIMIT 1");
    $sel->execute([':b' => $delta['batch_number'], ':f' => $reportFile]);
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
                   -- processing_fee intentionally not updated here; recalculated from details later
                   markup      = COALESCE(markup,0)     + :markup,
                   scheme_fees = COALESCE(scheme_fees,0)+ :scheme,
                   interchange = COALESCE(interchange,0)+ :inter
             WHERE id = :id
        ");
        $upd->execute([
            ':rpr'   => $reportPspRef,
            ':gc'    => $delta['gross_currency'] ?? null,
            ':nc'    => $delta['net_currency'] ?? null,
            ':gd'    => $delta['gross_debit'],
            ':gc2'   => $delta['gross_credit'],
            ':nd'    => $delta['net_debit'],
            ':nc2'   => $delta['net_credit'],
            ':markup'=> $delta['markup'],
            ':scheme'=> $delta['scheme_fees'],
            ':inter' => $delta['interchange'],
            ':id'    => (int)$row['id'],
        ]);
        return (int)$row['id'];
    }

    $ins = $pdo->prepare("
        INSERT INTO settlements
            (batch_number, report_psp_reference, report_filename,
             gross_currency, net_currency,
             gross_debit, gross_credit, net_debit, net_credit,
             processing_fee, markup, scheme_fees, interchange)
        VALUES
            (:b, :rpr, :f, :gc, :nc, :gd, :gc2, :nd, :nc2,
             0,              -- processing_fee placeholder; will be recomputed from details
             :markup, :scheme, :inter)
        RETURNING id
    ");
    $ins->execute([
        ':b'     => $delta['batch_number'],
        ':rpr'   => $reportPspRef,
        ':f'     => $reportFile,
        ':gc'    => $delta['gross_currency'] ?? null,
        ':nc'    => $delta['net_currency'] ?? null,
        ':gd'    => $delta['gross_debit'],
        ':gc2'   => $delta['gross_credit'],
        ':nd'    => $delta['net_debit'],
        ':nc2'   => $delta['net_credit'],
        ':markup'=> $delta['markup'],
        ':scheme'=> $delta['scheme_fees'],
        ':inter' => $delta['interchange'],
    ]);
    return (int)$ins->fetchColumn();
}

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
                   processing_fee = :comm,
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
            ':comm'  => $r['processing_fee'], // now = processing fee per row (0.5 for Settled, else 0)
            ':markup'=> $r['markup'],
            ':scheme'=> $r['scheme_fees'],
            ':inter' => $r['interchange'],
            ':pm'    => $r['payment_method'],
            ':pmv'   => $r['payment_method_variant'],
            ':mmr'   => $r['modification_merchant_reference'],
            ':sid'   => $settlementId,
            ':id'    => (int)$row['id'],
        ]);
        return;
    }

    $ins = $pdo->prepare("
        INSERT INTO settlement_details
            (psp_reference, modification_reference, merchant_reference, type,
             creation_date, timezone,
             gross_currency, gross_debit, gross_credit,
             net_currency, net_debit, net_credit,
             processing_fee, markup, scheme_fees, interchange,
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
        ':comm'  => $r['processing_fee'], // now = processing fee per row (0.5 for Settled, else 0)
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

/* ----------------- main ----------------- */
try {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

    $sel = db()->prepare("
        SELECT id, event_code, success, download_url, reason, psp_reference
          FROM report_notifications
         WHERE id = :id
         LIMIT 1
    ");
    $sel->execute([':id' => $id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Row not found']); exit; }
    if ((string)$row['event_code'] !== 'REPORT_AVAILABLE' || !(bool)$row['success']) {
        http_response_code(400);
        echo json_encode(['error' => 'Row is not a successful REPORT_AVAILABLE']);
        exit;
    }

    $downloadUrl = (string)($row['download_url'] ?? '');
    if ($downloadUrl === '') {
        $downloadUrl = extractUrlFromReason((string)($row['reason'] ?? '')) ?? '';
    }
    if ($downloadUrl === '') { http_response_code(400); echo json_encode(['error' => 'No download_url on this row']); exit; }

    $serverFilename = null;
    $tmpCsv = downloadCsv($downloadUrl, $REPORT_API_KEY, $serverFilename);
    $reportFileName = $serverFilename ?: ('settlement_' . md5($downloadUrl) . '.csv');
    $reportPspRef = $row['psp_reference'] ?? null;

    $rowsParsed = 0;
    $detailsUpserted = 0;
    $settlementParentsTouched = 0;
    $ordersUpdated = 0;
    $txnsInserted = 0;

    // Prepared stmts
    $findOrderByNumber = db()->prepare("SELECT id FROM orders WHERE order_number = :mref LIMIT 1");
    $findOrderByTxnPsp = db()->prepare("SELECT order_id FROM transactions WHERE psp_ref = :psp ORDER BY id DESC LIMIT 1");
    $existSettledTxn   = db()->prepare("SELECT 1 FROM transactions WHERE order_id = :oid AND type = 'SETTLED' AND psp_ref = :psp LIMIT 1");
    $insTxn = db()->prepare("
        INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
        VALUES (:order_id, 'SETTLED', 'SUCCESS', :amount_minor, :currency, :psp_ref, 'REPORT')
    ");
    $updOrderSettled = db()->prepare("UPDATE orders SET status = 'SETTLED', settled_at = NOW() WHERE id = :id");
    $updOrderProcessingFee = db()->prepare("UPDATE orders SET processing_fee = 0.5 WHERE id = :id");

    // Track touched settlement ids for final processing_fee (processing fee) recompute
    $touchedSettlementIds = [];

    foreach (readCsv($tmpCsv) as $r) {
        $rowsParsed++;

        $psp   = (string)($r['Psp Reference'] ?? '');
        $mref  = (string)($r['Merchant Reference'] ?? '');
        $type  = (string)($r['Type'] ?? '');
        $creation = (string)($r['Creation Date'] ?? '');
        $tz    = (string)($r['TimeZone'] ?? '');
        $modRef= (string)($r['Modification Reference'] ?? '');
        $grossCcy    = (string)($r['Gross Currency'] ?? '');
        $grossDebit  = (string)($r['Gross Debit (GC)'] ?? '0');
        $grossCredit = (string)($r['Gross Credit (GC)'] ?? '0');
        $netCcy      = (string)($r['Net Currency'] ?? '');
        $netDebit    = (string)($r['Net Debit (NC)'] ?? '0');
        $netCredit   = (string)($r['Net Credit (NC)'] ?? '0');
        $markup      = (string)($r['Markup (NC)'] ?? '0');
        $schemeFees  = (string)($r['Scheme Fees (NC)'] ?? '0');
        $interchange = (string)($r['Interchange (NC)'] ?? '0');
        $pm          = (string)($r['Payment Method'] ?? '');
        $pmVariant   = (string)($r['Payment Method Variant'] ?? '');
        $modMref     = (string)($r['Modification Merchant Reference'] ?? '');
        $batch       = (int)($r['Batch Number'] ?? 0);

        if ($psp === '' && $type === '' && $batch === 0) continue;

        // Only rows with ord_ (or ord-) merchant refs
        if ($mref === '' || !preg_match('~\bord[_-]~i', $mref)) continue;

        // Skip Fee / Balance Transfer
        $typeLower = strtolower($type);
        if ($typeLower === 'fee' || $typeLower === 'balance transfer') continue;

        // Processing fee per kept row:
        //  - 0.5 for Settled rows
        //  - 0   for any other (kept) type
        $processingFeeThisRow = (strcasecmp($type, 'Settled') === 0) ? '0.5' : '0';

        // Build detail payload; processing_fee now carries "processing fee"
        $detail = [
            'psp_reference'                  => ($psp ?: null),
            'modification_reference'         => ($modRef ?: null),
            'merchant_reference'             => ($mref ?: null),
            'type'                           => ($type ?: null),
            'creation_date'                  => $creation ? date('c', strtotime($creation)) : null,
            'timezone'                       => ($tz ?: null),
            'gross_currency'                 => ($grossCcy ?: null),
            'gross_debit'                    => ($grossDebit === '' ? '0' : $grossDebit),
            'gross_credit'                   => ($grossCredit === '' ? '0' : $grossCredit),
            'net_currency'                   => ($netCcy ?: null),
            'net_debit'                      => ($netDebit === '' ? '0' : $netDebit),
            'net_credit'                     => ($netCredit === '' ? '0' : $netCredit),
            'processing_fee'                     => $processingFeeThisRow, // <-- processing fee here
            'markup'                         => ($markup === '' ? '0' : $markup),
            'scheme_fees'                    => ($schemeFees === '' ? '0' : $schemeFees),
            'interchange'                    => ($interchange === '' ? '0' : $interchange),
            'payment_method'                 => ($pm ?: null),
            'payment_method_variant'         => ($pmVariant ?: null),
            'modification_merchant_reference'=> ($modMref ?: null),
            'batch_number'                   => $batch,
        ];

        // Per-row delta for the parent (no double counting)
        $delta = [
            'batch_number'  => $batch,
            'gross_currency'=> $grossCcy ?: null,
            'net_currency'  => $netCcy ?: null,
            'gross_debit'   => (float)$detail['gross_debit'],
            'gross_credit'  => (float)$detail['gross_credit'],
            'net_debit'     => (float)$detail['net_debit'],
            'net_credit'    => (float)$detail['net_credit'],
            // processing_fee intentionally excluded here (we'll recompute from details)
            'markup'        => (float)$detail['markup'],
            'scheme_fees'   => (float)$detail['scheme_fees'],
            'interchange'   => (float)$detail['interchange'],
        ];

        // Upsert parent (deltas only)
        $sid = upsertSettlementParent($delta, $reportPspRef ?: null, $reportFileName);
        $touchedSettlementIds[$sid] = true;
        $settlementParentsTouched++;

        // Upsert details row
        upsertSettlementDetail($detail, $sid);
        $detailsUpserted++;

        // Resolve order to update processing_fee and (if Settled) insert SETTLED txn + mark order settled
        $orderId = null;
        if ($mref !== '') {
            $findOrderByNumber->execute([':mref' => $mref]);
            $orderId = $findOrderByNumber->fetchColumn();
        }
        if (!$orderId && $psp !== '') {
            $findOrderByTxnPsp->execute([':psp' => $psp]);
            $orderId = $findOrderByTxnPsp->fetchColumn();
        }

        if ($orderId) {
            $orderId = (int)$orderId;

            // Always set processing_fee = 0.5 for matched orders (per requirements)
            try {
                $updOrderProcessingFee->execute([':id' => $orderId]);
                if ($updOrderProcessingFee->rowCount() > 0) $ordersUpdated++;
            } catch (Throwable $e) { /* ignore missing column */ }

            // For Settled rows: insert SETTLED txn (idempotent-ish) + set order status
            if (strcasecmp((string)$type, 'Settled') === 0) {
                $netMovement = (float)$detail['net_credit'] - (float)$detail['net_debit']; // credits positive
                $ccy = $detail['net_currency'] ?: ($detail['gross_currency'] ?: 'AED');
                $amountMinor = toMinor((string)$netMovement, $ccy);

                $existSettledTxn->execute([':oid' => $orderId, ':psp' => ($psp ?: $mref)]);
                if (!$existSettledTxn->fetchColumn()) {
                    try {
                        $insTxn->execute([
                            ':order_id'     => $orderId,
                            ':amount_minor' => $amountMinor,
                            ':currency'     => $ccy,
                            ':psp_ref'      => ($psp ?: $mref)
                        ]);
                        $txnsInserted++;
                    } catch (Throwable $e) { /* ignore row error */ }
                }

                try {
                    $updOrderSettled->execute([':id' => $orderId]);
                    if ($updOrderSettled->rowCount() > 0) $ordersUpdated++;
                } catch (Throwable $e) { /* ignore if status enum missing */ }
            }
        }
    }

    // FINAL STEP: Recompute parent processing_fee (processing fee) = SUM(details.processing_fee)
    if (!empty($touchedSettlementIds)) {
        $pdo = db();
        $recalc = $pdo->prepare("
            UPDATE settlements s
               SET processing_fee = COALESCE((
                       SELECT SUM(d.processing_fee)
                         FROM settlement_details d
                        WHERE d.settlement_id = s.id
                   ), 0)
             WHERE s.id = :id
        ");
        foreach (array_keys($touchedSettlementIds) as $sid) {
            $recalc->execute([':id' => (int)$sid]);
        }
    }

    echo json_encode([
        'ok' => true,
        'rowsParsed' => $rowsParsed,
        'detailsUpserted' => $detailsUpserted,
        'settlementParentsTouched' => $settlementParentsTouched,
        'txnsInserted' => $txnsInserted,
        'ordersUpdated' => $ordersUpdated,
        'reportFile' => $reportFileName
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
