<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
    $stmt = db()->query("SELECT id, sku, name, price_minor, currency FROM products ORDER BY name ASC");
    echo json_encode(['data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
