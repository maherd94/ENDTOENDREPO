<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

try {
    db()->exec('SET search_path TO "ENDTOEND", public');

    $sql = "
      SELECT
        (SELECT COUNT(*) FROM stores)   AS stores_count,
        (SELECT COUNT(*) FROM products) AS products_count,
        (SELECT COUNT(*) FROM orders)   AS orders_count
    ";
    $row = db()->query($sql)->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="card"><h3>DB Error</h3><pre>'.htmlspecialchars($e->getMessage()).'</pre></div>';
    exit;
}
?>
<div class="cardgrid">
  <div class="card">
    <h3>Stores</h3>
    <div class="big"><?= (int)$row['stores_count'] ?></div>
  </div>
  <div class="card">
    <h3>Products</h3>
    <div class="big"><?= (int)$row['products_count'] ?></div>
  </div>
  <div class="card">
    <h3>Orders</h3>
    <div class="big"><?= (int)$row['orders_count'] ?></div>
  </div>
  <div class="card">
    <h3>Net Payout (Demo)</h3>
    <div class="big badge">Coming soon</div>
  </div>
</div>
