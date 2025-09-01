<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php'; // provides db(), env()

// Use the ENDTOEND schema
try {
    db()->exec('SET search_path TO "ENDTOEND", public');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'DB connection error', 'details' => $e->getMessage()]);
    exit;
}

// --- helpers ---
function extractUrlFromReason(?string $reason): ?string {
    if (!$reason) return null;
    if (preg_match('~https://\S+~', $reason, $m)) {
        return rtrim($m[0], ".,;)\"'");
    }
    return null;
}

// --- read JSON body ---
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    // Bad body; still acknowledge to avoid retries
    header('Content-Type: text/plain; charset=utf-8');
    echo '[accepted]';
    exit;
}

$items = isset($body['notificationItems']) && is_array($body['notificationItems'])
    ? $body['notificationItems']
    : [];

// Prepare insert once
$ins = db()->prepare(
    'INSERT INTO report_notifications
        (psp_reference, merchant_account, event_code, event_date, success, reason, download_url, raw_json)
     VALUES
        (:psp_reference, :merchant_account, :event_code, :event_date, :success, :reason, :download_url, CAST(:raw_json AS jsonb))'
);

// Process only REPORT_AVAILABLE items
foreach ($items as $wrap) {
    $nri = $wrap['NotificationRequestItem'] ?? null;
    if (!is_array($nri)) continue;

    $eventCode = (string)($nri['eventCode'] ?? '');
    if ($eventCode !== 'REPORT_AVAILABLE') continue;

    $pspRef    = $nri['pspReference'] ?? null;
    $macc      = $nri['merchantAccountCode'] ?? null;
    $eventDate = $nri['eventDate'] ?? null; // ISO 8601 string is fine for timestamptz
    $success   = strtolower((string)($nri['success'] ?? 'false')) === 'true';
    $reason    = $nri['reason'] ?? null;
    $dlUrl     = extractUrlFromReason($reason);

    try {
        $ins->execute([
            ':psp_reference'   => $pspRef,
            ':merchant_account'=> $macc,
            ':event_code'      => $eventCode,
            ':event_date'      => $eventDate,           // let PG cast to timestamptz
            ':success'         => $success ? 1 : 0,     // boolean
            ':reason'          => $reason,
            ':download_url'    => $dlUrl,
            ':raw_json'        => json_encode($nri, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // Swallow per-item errors to avoid retries; you can log if you want
        // error_log('report_notifications insert error: '.$e->getMessage());
        continue;
    }
}

// Always acknowledge so Adyen does not retry
header('Content-Type: text/plain; charset=utf-8');
echo '[accepted]';
