<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_amount(?int $minor, string $currency): string {
    if ($minor === null) return '';
    // Default 2 decimals for AED & most currencies
    return number_format($minor / 100, 2) . ' ' . $currency;
}

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
    $stmt = db()->prepare("
        SELECT id, sku, name, price_minor, currency, created_at
        FROM products
        ORDER BY name ASC
        LIMIT 200
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="card"><h3>DB Error</h3><pre>'.h($e->getMessage()).'</pre></div>';
    exit;
}
?>
<div class="card">
  <h3 style="margin:0 0 8px 0;">Products</h3>
  <p class="badge"><?= count($rows) ?> shown</p>
</div>

<table class="table">
  <thead>
    <tr>
      <th>ID</th>
      <th>SKU</th>
      <th>Name</th>
      <th>Price</th>
      <th>Currency</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="6">No products yet.</td></tr>
  <?php else: foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= h($r['sku']) ?></td>
      <td><?= h($r['name']) ?></td>
      <td><?= fmt_amount((int)$r['price_minor'], $r['currency']) ?></td>
      <td><?= h($r['currency']) ?></td>
      <td><?= h($r['created_at']) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
