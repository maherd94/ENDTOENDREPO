<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    db()->exec('SET search_path TO "ENDTOEND", public');
    $stmt = db()->prepare("
        SELECT id, shopper_reference, email, phone, created_at
        FROM customers
        ORDER BY created_at DESC
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
  <h3 style="margin:0 0 8px 0;">Customers</h3>
  <p class="badge"><?= count($rows) ?> shown</p>
</div>

<table class="table">
  <thead>
    <tr>
      <th>ID</th>
      <th>Shopper Ref</th>
      <th>Email</th>
      <th>Phone</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="5">No customers yet.</td></tr>
  <?php else: foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= h($r['shopper_reference']) ?></td>
      <td><?= h($r['email']) ?></td>
      <td><?= h($r['phone']) ?></td>
      <td><?= h($r['created_at']) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
