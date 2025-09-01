<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$apiKey   = env('ADYEN_MGMT_API_KEY', '');
$baseUrl  = rtrim(env('ADYEN_MGMT_BASE_URL', 'https://management-test.adyen.com/v3'), '/');
$merchant = env('ADYEN_POS_MERCHANT_ID', '');

if ($apiKey === '' || $merchant === '') {
  http_response_code(500);
  echo '<div class="card"><h3>Config error</h3><p>Set ADYEN_MGMT_API_KEY and ADYEN_POS_MERCHANT_ID in .env</p></div>';
  exit;
}

function mgmt_get(string $url, string $apiKey): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
      'x-api-key: ' . $apiKey,
      'Accept: application/json',
    ],
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false || $err) {
    throw new RuntimeException('Upstream connection error: ' . $err);
  }
  if ($http < 200 || $http >= 300) {
    throw new RuntimeException('Upstream returned ' . $http . ' with body: ' . $body);
  }
  $json = json_decode($body, true);
  if (!is_array($json)) {
    throw new RuntimeException('Invalid JSON from upstream');
  }
  return $json;
}

try {
  // Fetch stores (paged)
  $page = 1; $pageSize = 100; $pagesTotal = null; $stores = [];
  do {
    // Either /merchants/{merchantId}/stores or /stores?merchantId=...
    $url = $baseUrl . '/merchants/' . rawurlencode($merchant) . '/stores?pageNumber=' . $page . '&pageSize=' . $pageSize;
    $json = mgmt_get($url, $apiKey);

    $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
    $stores = array_merge($stores, $data);

    if ($pagesTotal === null) {
      $pagesTotal = (int)($json['pagesTotal'] ?? 1);
      if ($pagesTotal <= 0) $pagesTotal = 1;
    }
    $page++;
  } while ($page <= $pagesTotal);
} catch (Throwable $e) {
  http_response_code(500);
  echo '<div class="card"><h3>Adyen Management API error</h3><pre>'.h($e->getMessage()).'</pre></div>';
  exit;
}
?>
<div class="card">
  <h3 style="margin:0 0 8px 0;">Stores (Adyen)</h3>
  <p class="badge">Click a store to see its boarded terminals</p>
</div>

<table class="table" id="adyen-stores">
  <thead>
    <tr>
      <th>Reference</th>
      <th>Description</th>
      <th>Shopper Statement</th>
      <th>City</th>
      <th>State</th>
      <th>Phone</th>
      <th>Status</th>
      <th>Store ID</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$stores): ?>
    <tr><td colspan="8">No stores found for merchant <?= h($merchant) ?>.</td></tr>
  <?php else: foreach ($stores as $s):
      $addr = $s['address'] ?? [];
      $city = $addr['city'] ?? '';
      $state= $addr['stateOrProvince'] ?? '';
  ?>
    <tr class="store-row" style="cursor:pointer"
        data-id="<?= h($s['id'] ?? '') ?>"
        data-ref="<?= h($s['reference'] ?? '') ?>">
      <td><?= h($s['reference'] ?? '') ?></td>
      <td><?= h($s['description'] ?? '') ?></td>
      <td><?= h($s['shopperStatement'] ?? '') ?></td>
      <td><?= h($city) ?></td>
      <td><?= h($state) ?></td>
      <td><?= h($s['phoneNumber'] ?? '') ?></td>
      <td><?= h($s['status'] ?? '') ?></td>
      <td><?= h($s['id'] ?? '') ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<div class="card" id="terminals-card" style="margin-top:12px; display:none;">
  <h3 style="margin:0 0 8px 0;">Terminals for store: <span id="store-title"></span></h3>
  <table class="table" id="terminals-table">
    <thead>
      <tr>
        <th>Terminal ID</th>
        <th>Model</th>
        <th>Serial</th>
        <th>Status</th>
        <th>Firmware</th>
        <th>Last Activity</th>
      </tr>
    </thead>
    <tbody>
      <tr><td colspan="6">Loading…</td></tr>
    </tbody>
  </table>
</div>

<script>
(function(){
  function renderTerminals(rows){
    const $tb = document.querySelector('#terminals-table tbody');
    $tb.innerHTML = '';
    if (!rows || !rows.length) {
      $tb.innerHTML = '<tr><td colspan="6">No terminals boarded to this store.</td></tr>';
      return;
    }
    rows.forEach(t => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${(t.id||'')}</td>
        <td>${(t.model||'')}</td>
        <td>${(t.serialNumber||'')}</td>
        <td>${((t.assignment && t.assignment.status) || '')}</td>
        <td>${(t.firmwareVersion||'')}</td>
        <td>${(t.lastActivityAt||'')}</td>
      `;
      $tb.appendChild(tr);
    });
  }

  document.querySelectorAll('.store-row').forEach(row => {
    row.addEventListener('click', () => {
      const storeId = row.getAttribute('data-id');
      const ref     = row.getAttribute('data-ref');
      document.getElementById('store-title').textContent = `${ref || ''} (${storeId})`;
      document.getElementById('terminals-card').style.display = 'block';
      const $tb = document.querySelector('#terminals-table tbody');
      $tb.innerHTML = '<tr><td colspan="6">Loading…</td></tr>';

      fetch(`/apis/terminals_by_store.php?storeId=${encodeURIComponent(storeId)}`, {
        headers: { 'Accept': 'application/json' }
      })
      .then(r => r.json())
      .then(json => {
        if (json && json.data) renderTerminals(json.data);
        else if (json && json.error) {
          $tb.innerHTML = `<tr><td colspan="6" style="color:#b91c1c">Error: ${json.error}</td></tr>`;
        } else {
          renderTerminals([]);
        }
      })
      .catch(err => {
        $tb.innerHTML = `<tr><td colspan="6" style="color:#b91c1c">Failed to load: ${err}</td></tr>`;
      });
    });
  });
})();
</script>
