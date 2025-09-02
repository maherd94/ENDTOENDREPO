<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

/**
 * Adyen Standard Webhook (classic notifications, JSON)
 * - No HMAC validation
 * - If any item fails -> 400 with JSON details; otherwise "[accepted]"
 * - Pay by Link:
 *    - on AUTHORISATION success => mark payment_links as PAID
 *    - on REFUND success        => mark payment_links as REFUNDED
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
if (!is_array($body)) respondError(400, 'Invalid JSON body');

$items = isset($body['notificationItems']) && is_array($body['notificationItems']) ? $body['notificationItems'] : [];
if (!$items) respondError(400, 'No notificationItems in payload');

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
function getOrderAmountMinor(PDO $pdo, int $orderId): ?int {
    $st = $pdo->prepare('SELECT amount_minor FROM orders WHERE id = :id');
    $st->execute([':id' => $orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['amount_minor'] : null;
}
function sumRefundedMinor(PDO $pdo, int $orderId): int {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(amount_minor),0) AS s
          FROM transactions
         WHERE order_id = :id AND type = 'REFUND' AND status = 'SUCCESS'
    ");
    $st->execute([':id' => $orderId]);
    return (int)$st->fetchColumn();
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
function insertTransaction(PDO $pdo, int $orderId, string $type, string $status, int $amountMinor, string $currency, ?string $pspRef, ?string $rawMethod): void {
    $st = $pdo->prepare('
        INSERT INTO transactions (order_id, type, status, amount_minor, currency, psp_ref, raw_method)
        VALUES (:order_id, :type, :status, :amt, :cur, :psp, :method)
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
}

/* Map eventCode -> order status (successes only, REFUND handled separately) */
function mapOrderStatus(string $eventCode, bool $success): ?string {
    if (!$success) return null;
    static $map = [
        'AUTHORISATION' => 'AUTHORISED',
        'CAPTURE'       => 'CAPTURED',
        'CANCELLATION'  => 'CANCELLED',
        'CHARGEBACK'    => 'CHARGEBACK',
        // 'REFUND' handled by refund logic
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
    $id = $nri['additionalData']['paymentLinkId'] ?? null;
    return (is_string($id) && $id !== '') ? $id : null;
}

/* Pay-by-Link updaters */
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
function markPaymentLinkRefunded(PDO $pdo, array $args): void {
    $merchantRef = $args['merchantRef'];
    $linkId      = $args['linkId'] ?? null;

    if ($linkId) {
        $st = $pdo->prepare("UPDATE payment_links
            SET status='REFUNDED', refunded_at = now()
            WHERE link_id = :lid AND status <> 'REFUNDED'");
        $st->execute([':lid' => $linkId]);
        if ($st->rowCount() > 0) return;
    }
    $st2 = $pdo->prepare("UPDATE payment_links
        SET status='REFUNDED', refunded_at = now()
        WHERE order_number = :ord AND status <> 'REFUNDED'");
    $st2->execute([':ord' => $merchantRef]);
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

        if ($merchantRef === '') {
            $errors[] = ['index' => $i, 'error' => 'Empty merchantReference'];
            continue;
        }
        if ($eventCode === '') {
            $errors[] = ['index' => $i, 'merchantReference' => $merchantRef, 'error' => 'Missing eventCode'];
            continue;
        }

        // Best-effort PBL updates (even if order not yet created)
        if ($eventCode === 'AUTHORISATION' && $success === true) {
            try { markPaymentLinkPaid(db(), ['merchantRef' => $merchantRef, 'pspRef' => $pspRef, 'linkId' => $pblId]); }
            catch (\Throwable $ignore) {}
        } elseif ($eventCode === 'REFUND' && $success === true) {
            try { markPaymentLinkRefunded(db(), ['merchantRef' => $merchantRef, 'linkId' => $pblId]); }
            catch (\Throwable $ignore) {}
        }

        // Find local order (if not present and it's a PBL item, we accept silently)
        $orderId = findOrderIdByMerchantReference(db(), $merchantRef);
        if ($orderId === null) {
            if ($pblId) continue; // accept item without an order
            $errors[] = ['index' => $i, 'merchantReference' => $merchantRef, 'error' => 'Order not found'];
            continue;
        }

        // Insert transaction
        $txnType   = mapTxnType($eventCode);
        $txnStatus = mapTxnStatus($success);
        $txnRef    = $pspRef !== '' ? $pspRef : ($origRef !== '' ? $origRef : null);

        insertTransaction(db(), $orderId, $txnType, $txnStatus, $amountMinor, $currency, $txnRef, $method);

        // Update order status for non-refund *success* events via simple map
        $orderStatus = mapOrderStatus($eventCode, $success);
        if ($orderStatus !== null) {
            updateOrderStatus(db(), $orderId, $orderStatus, $pspRef !== '' ? $pspRef : null);
        }

        // Special handling for REFUND success: only mark REFUNDED when fully refunded
        if ($eventCode === 'REFUND' && $success === true) {
            $orderTotal = getOrderAmountMinor(db(), $orderId);
            if ($orderTotal !== null) {
                $refunded = sumRefundedMinor(db(), $orderId);
                if ($refunded >= (int)$orderTotal) {
                    // Fully refunded
                    updateOrderStatus(db(), $orderId, 'REFUNDED', null);
                } else {
                    // Partial refund: leave order status as-is (you can add PARTIALLY_REFUNDED if your schema supports it)
                    // updateOrderStatus(db(), $orderId, 'PARTIALLY_REFUNDED', null);
                }
            }
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
