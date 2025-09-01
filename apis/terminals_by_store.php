<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $apiKey  = env('ADYEN_MGMT_API_KEY', '');
    $baseUrl = rtrim(env('ADYEN_MGMT_BASE_URL', 'https://management-test.adyen.com/v3'), '/');

    $storeId = isset($_GET['storeId']) ? trim((string)$_GET['storeId']) : '';
    if ($apiKey === '' || $storeId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing ADYEN_MGMT_API_KEY or storeId']);
        exit;
    }

    $page = 1; $pageSize = 100; $pagesTotal = null; $items = [];

    do {
        $url = $baseUrl . '/terminals?storeIds=' . rawurlencode($storeId) . '&pageNumber=' . $page . '&pageSize=' . $pageSize;

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
            http_response_code(502);
            echo json_encode(['error' => 'Upstream connection error', 'details' => $err]);
            exit;
        }
        if ($http < 200 || $http >= 300) {
            http_response_code($http ?: 502);
            echo json_encode(['error' => 'Upstream error', 'status' => $http, 'raw' => $body]);
            exit;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            http_response_code(502);
            echo json_encode(['error' => 'Invalid upstream JSON']);
            exit;
        }

        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
        $items = array_merge($items, $data);

        if ($pagesTotal === null) {
            $pagesTotal = (int)($json['pagesTotal'] ?? 1);
            if ($pagesTotal <= 0) $pagesTotal = 1;
        }

        $page++;
    } while ($page <= $pagesTotal);

    echo json_encode(['data' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
