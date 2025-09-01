<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
    $stmt = db()->prepare("
      SELECT
        id, code, name, store_reference, description, shopper_statement, zip,
        address_line1, address_line2, address_line3, postal_code, city, state_province, phone_number, created_at
      FROM stores
      ORDER BY name ASC
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
  <h3 style="margin:0 0 8px 0;">Stores</h3>
  <p class="badge">Read-only list for now</p>
</div>

<table class="table">
  <thead>
    <tr>
      <th>Code</th>
      <th>Name</th>
      <th>Reference</th>
      <th>Shopper Statement</th>
      <th>City</th>
      <th>State</th>
      <th>Phone</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="8">No stores yet.</td></tr>
  <?php else: foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['code']) ?></td>
      <td><?= h($r['name']) ?></td>
      <td><?= h($r['store_reference']) ?></td>
      <td><?= h($r['shopper_statement']) ?></td>
      <td><?= h($r['city']) ?></td>
      <td><?= h($r['state_province']) ?></td>
      <td><?= h($r['phone_number']) ?></td>
      <td><?= h($r['created_at']) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
