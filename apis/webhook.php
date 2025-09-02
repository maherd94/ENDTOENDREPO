<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

/**
 * Adyen Standard Webhook (classic notifications, JSON)
 * - No HMAC validation
 * - Persists each NotificationRequestItem to ENDTOEND.webhook_events immediately
 * - Updates orders/transactions after
 * - Pay by Link: on AUTHORISATION success, marks payment_links as PAID (if paymentLinkId present)
 * - REFUND: merchantReference can be custom (e.g., "rf_ord_..."); we extract embedded ord_... for lookup
 */

/* ---------- Response helpers ---------- */
function respondAccepted(): void {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo '[accepted]';
    exit;
}
function respondError(int $status, string $message, array $details = []): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode(['error' => $message, 'details' => $details], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Parse JSON body ---------- */
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    respondError(400, 'Invalid JSON body');
}
$items = isset($body['notificationItems']) && is_array($body['notificationItems'])
    ? $body['notificationItems']
    : [];
if (!$items) {
    respondError(400, 'No notificationItems in payload');
}

/* ---------- DB ---------- */
try {
    db()->exec('SET search_path TO "ENDTOEND", public');
} catch (Throwable $e) {
    respondError(500, 'DB connection error', ['message' => $e->getMessage()]);
}

/* ---------- Helpers ---------- */
function findOrderIdByMerchantReference(PDO $pdo, string $merchantRef): ?int {
    $st = $pdo->prepare('SELECT id FROM orders WHERE order_number = :r LIMIT 1');
    $st->execute([':r' => $merchantRef]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}
function findOrderIdByPspRef(PDO $pdo, string $pspRef): ?int {
    if ($pspRef === '') return null;
    $st = $pdo->prepare('SELECT order_id FROM transactions WHERE psp_ref = :p ORDER BY id DESC LIMIT 1');
    $st->execute([':p' => $pspRef]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}
function updateOrderStatus(PDO $pdo, int $orderId, ?string $status = null, ?string $pspRef = null): void {
    if ($status !== null && $pspRef !== null) {
        $st = $pdo->prepare('UPDATE orders SET status = :s, psp_ref = COALESCE(psp_ref, :p) WHERE id = :id');
        $st->execute([':s' => $status, ':p' => $pspRef, ':id' => $orderId]);
    } elseif ($status !== null) {
        $st = $pdo->prepare('UPDATE orders SET status = :s WHERE id = :id');
        $st->execute([':s' => $status, ':id' => $orderId]);
    } elseif ($pspRef !== null) {
        $st = $pdo->prepare('UPDATE orders SET psp_ref = COALESCE(psp_ref, :p) WHERE id = :id');
        $st->execute([':p' => $pspRef, ':id' => $orderId]);
    }
}
/** Insert a transaction and return its ID */
function insertTransaction(PDO $pdo, int $orderId, string $type, string $status, int $amountMinor, string $currency, ?string $pspRef, ?string $rawMethod): int {
    $st = $pdo->prepare('
        INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
        VALUES (:order_id, :type, :status, :amt, :cur, :psp, :method)
        RETURNING id
    ');
    $st->execute([
        ':order_id' => $orderId,
        ':type'     => $type,
        ':status'   => $status,
        ':amt'      => $amountMinor,
        ':cur'      => $currency,
        ':psp'      => $pspRef,
        ':method'   => $rawMethod
    ]);
    return (int)$st->fetchColumn();
}

/* Map eventCode -> order status (successes only) */
function mapOrderStatus(string $eventCode, bool $success): ?string {
    if (!$success) return null;
    static $map = [
        'AUTHORISATION' => 'AUTHORISED',
        'CAPTURE'       => 'CAPTURED',
        'REFUND'        => 'REFUNDED',
        'CANCELLATION'  => 'CANCELLED',
        'CHARGEBACK'    => 'CHARGEBACK',
    ];
    return $map[$eventCode] ?? null;
}
/* Map eventCode -> transaction type */
function mapTxnType(string $eventCode): string {
    static $map = [
        'AUTHORISATION' => 'AUTH',
        'CAPTURE'       => 'CAPTURE',
        'REFUND'        => 'REFUND',
        'CANCELLATION'  => 'CANCEL',
        'CHARGEBACK'    => 'CHARGEBACK',
    ];
    return $map[$eventCode] ?? strtoupper($eventCode);
}
/* Map success flag -> transaction status */
function mapTxnStatus(bool $success): string {
    return $success ? 'SUCCESS' : 'FAILED';
}

/* Extractors */
function extractPaymentMethod(array $nri): string {
    if (!empty($nri['paymentMethod'])) return (string)$nri['paymentMethod'];
    if (!empty($nri['additionalData']['cardPaymentMethod'])) return (string)$nri['additionalData']['cardPaymentMethod'];
    if (!empty($nri['additionalData']['paymentMethod'])) return (string)$nri['additionalData']['paymentMethod'];
    return '';
}
function extractAmount(array $nri): array {
    $value    = isset($nri['amount']['value']) ? (int)$nri['amount']['value'] : (int)($nri['additionalData']['authorisedAmountValue'] ?? 0);
    $currency = isset($nri['amount']['currency']) ? (string)$nri['amount']['currency'] : (string)($nri['additionalData']['authorisedAmountCurrency'] ?? 'AED');
    return ['value' => $value, 'currency' => $currency];
}
function extractPaymentLinkId(array $nri): ?string {
    $ad = $nri['additionalData'] ?? [];
    foreach (['paymentLinkId', 'paymentLinkReference', 'pblId'] as $k) {
        if (!empty($ad[$k]) && is_string($ad[$k])) return $ad[$k];
    }
    return null;
}
/** For REFUND: Extract embedded ord_... from merchantReference like "rf_ord_..." */
function extractOrdFromRefundMerchantRef(string $merchantRef): ?string {
    if ($merchantRef === '') return null;
    if (preg_match('/(ord_[A-Za-z0-9_]+)/', $merchantRef, $m)) {
        return $m[1];
    }
    return null;
}

/* Pay-by-Link updater */
function markPaymentLinkPaid(PDO $pdo, array $args): void {
    $merchantRef = $args['merchantRef'];
    $pspRef      = $args['pspRef'];
    $linkId      = $args['linkId'] ?? null;

    if ($linkId) {
        $st = $pdo->prepare("UPDATE payment_links
            SET status='PAID', paid_at = now(), psp_ref = COALESCE(psp_ref, :psp)
            WHERE link_id = :lid AND status <> 'PAID'");
        $st->execute([':psp' => $pspRef, ':lid' => $linkId]);
        if ($st->rowCount() > 0) return;
    }
    $st2 = $pdo->prepare("UPDATE payment_links
        SET status='PAID', paid_at = now(), psp_ref = COALESCE(psp_ref, :psp)
        WHERE order_number = :ord AND status <> 'PAID'");
    $st2->execute([':psp' => $pspRef, ':ord' => $merchantRef]);
}

/* ---------- New: Webhook event persistence ---------- */
/** Insert one webhook_events row per notification item, return event ID */
function insertWebhookEvent(PDO $pdo, string $eventCode, array $payload): int {
    $st = $pdo->prepare('
        INSERT INTO webhook_events (event_code, payload_json)
        VALUES (:ec, CAST(:payload AS jsonb))
        RETURNING id
    ');
    $st->execute([
        ':ec'       => $eventCode,
        ':payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return (int)$st->fetchColumn();
}
/** Update webhook_events row with order/transaction links (no overwrite if already set) */
function updateWebhookEventLinks(PDO $pdo, int $eventId, ?int $orderId = null, ?int $txnId = null): void {
    $st = $pdo->prepare('
        UPDATE webhook_events
           SET order_id = COALESCE(:oid, order_id),
               transaction_id = COALESCE(:tid, transaction_id)
         WHERE id = :id
    ');
    $st->execute([
        ':oid' => $orderId,
        ':tid' => $txnId,
        ':id'  => $eventId,
    ]);
}

/* ---------- Process items ---------- */
$errors = [];

foreach ($items as $i => $wrap) {
    try {
        $nri = isset($wrap['NotificationRequestItem']) ? $wrap['NotificationRequestItem'] : null;
        if (!$nri || !is_array($nri)) {
            $errors[] = ['index' => $i, 'error' => 'Missing NotificationRequestItem'];
            continue;
        }

        $eventCode    = (string)($nri['eventCode'] ?? '');
        $success      = strtolower((string)($nri['success'] ?? 'false')) === 'true';
        $merchantRef  = (string)($nri['merchantReference'] ?? '');
        $pspRef       = (string)($nri['pspReference'] ?? '');
        $origRef      = (string)($nri['originalReference'] ?? '');
        $method       = extractPaymentMethod($nri);
        $amt          = extractAmount($nri);
        $amountMinor  = (int)$amt['value'];
        $currency     = (string)$amt['currency'];
        $pblId        = extractPaymentLinkId($nri);

        if ($eventCode === '') {
            $errors[] = ['index' => $i, 'error' => 'Missing eventCode'];
            continue;
        }

        /* --- Persist the raw item FIRST --- */
        $eventId = null;
        try {
            $eventId = insertWebhookEvent(db(), $eventCode, $nri);
        } catch (Throwable $e) {
            // Don't block processing; record and continue
            $errors[] = ['index' => $i, 'error' => 'Failed to persist webhook event', 'details' => $e->getMessage()];
        }

        // Pay-by-Link marking (best-effort) on successful AUTHORISATION
        if ($eventCode === 'AUTHORISATION' && $success === true) {
            try {
                if ($merchantRef !== '' || $pblId) {
                    markPaymentLinkPaid(db(), ['merchantRef' => $merchantRef, 'pspRef' => $pspRef, 'linkId' => $pblId]);
                }
            } catch (\Throwable $ignore) { /* best-effort */ }
        }

        // Work out which merchant reference to use for order lookup
        $lookupMerchantRef = $merchantRef;

        // For REFUND items, merchantReference may be a custom refund ref containing ord_... inside
        if ($eventCode === 'REFUND' && $merchantRef !== '') {
            $embeddedOrd = extractOrdFromRefundMerchantRef($merchantRef);
            if ($embeddedOrd) {
                $lookupMerchantRef = $embeddedOrd;
            }
        }

        // Resolve order
        $orderId = null;
        if ($lookupMerchantRef !== '') {
            $orderId = findOrderIdByMerchantReference(db(), $lookupMerchantRef);
        }
        if ($orderId === null && $eventCode === 'REFUND' && $origRef !== '') {
            $orderId = findOrderIdByPspRef(db(), $origRef);
        }
        if ($orderId === null) {
            if ($pblId && $eventCode === 'AUTHORISATION' && $success === true) {
                // PBL only; order might be created later—do not fail the item
                // If we have an event row, tag the order_id when the order exists later (via some backfill if needed)
                continue;
            }
            $errors[] = [
                'index' => $i,
                'eventCode' => $eventCode,
                'merchantReference' => $merchantRef,
                'resolvedLookup' => $lookupMerchantRef,
                'originalReference' => $origRef,
                'error' => 'Order not found'
            ];
            continue;
        }

        // If we logged the event, attach order_id now
        if ($eventId) {
            try { updateWebhookEventLinks(db(), $eventId, $orderId, null); } catch (\Throwable $ignore) {}
        }

        // Insert transaction (AUTH/CAPTURE/REFUND/etc.)
        $txnType   = mapTxnType($eventCode);
        $txnStatus = mapTxnStatus($success);
        $txnRef    = $pspRef !== '' ? $pspRef : ($origRef !== '' ? $origRef : null);

        $txnId = insertTransaction(db(), $orderId, $txnType, $txnStatus, $amountMinor, $currency, $txnRef, $method);

        // Attach transaction_id to the stored webhook event row
        if ($eventId && $txnId) {
            try { updateWebhookEventLinks(db(), $eventId, null, $txnId); } catch (\Throwable $ignore) {}
        }

        // Update order status on positive events; also attach psp_ref when relevant
        $orderStatus = mapOrderStatus($eventCode, $success);
        if ($orderStatus !== null) {
            updateOrderStatus(db(), $orderId, $orderStatus, $pspRef !== '' ? $pspRef : null);
        } elseif ($pspRef !== '') {
            updateOrderStatus(db(), $orderId, null, $pspRef);
        }
    } catch (Throwable $e) {
        $errors[] = ['index' => $i, 'error' => $e->getMessage()];
        continue;
    }
}

/* ---------- Finalize ---------- */
if (!empty($errors)) {
    respondError(400, 'One or more notification items failed', $errors);
}
respondAccepted();
