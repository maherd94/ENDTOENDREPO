<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)(json_decode(file_get_contents('php://input'), true)['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing settlement id']);
        exit;
    }

    // Join to orders by merchant_reference → order_number (if present)
    $sql = "
      SELECT
        sd.id,
        sd.psp_reference,
        sd.merchant_reference,
        sd.type,
        sd.creation_date,
        sd.net_currency,
        sd.net_debit,
        sd.net_credit,
        sd.commission,
        sd.markup,
        sd.scheme_fees,
        sd.interchange,
        sd.payment_method,
        sd.payment_method_variant,
        o.id AS order_id
      FROM settlement_details sd
      LEFT JOIN orders o
        ON o.order_number = sd.merchant_reference
      WHERE sd.settlement_id = :sid
      ORDER BY sd.creation_date NULLS LAST, sd.id ASC
    ";
    $st = db()->prepare($sql);
    $st->execute([':sid' => $id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
