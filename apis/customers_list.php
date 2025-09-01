<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

function display_name_from_email(?string $email): string {
    if (!$email) return '';
    $local = explode('@', $email)[0] ?? '';
    return $local !== '' ? ucfirst($local) : '';
}

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
    $stmt = db()->query("
      SELECT id, shopper_reference, email, phone, created_at
      FROM customers
      ORDER BY created_at DESC
      LIMIT 500
    ");
    $rows = $stmt->fetchAll();
    // Add display_name (no DB change needed)
    foreach ($rows as &$r) {
        $r['display_name'] = display_name_from_email($r['email']);
    }
    echo json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
