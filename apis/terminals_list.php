<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $apiKey  = env('ADYEN_MGMT_API_KEY');
    $baseUrl = rtrim(env('ADYEN_MGMT_BASE_URL', 'https://management-test.adyen.com/v3'), '/');

    if (!$apiKey) {
        http_response_code(500);
        echo json_encode(['error' => 'ADYEN_MGMT_API_KEY is not set in .env']);
        exit;
    }

    // Allow optional paging overrides via querystring
    $pageSize  = isset($_GET['pageSize']) ? max(1, min(200, (int)$_GET['pageSize'])) : 100;
    $maxPages  = isset($_GET['maxPages']) ? max(1, min(20,  (int)$_GET['maxPages'])) : 10;

    $ids       = [];
    $page      = 1;
    $pagesTotal = null;

    do {
        $url = $baseUrl . '/terminals?pageNumber=' . $page . '&pageSize=' . $pageSize;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'Accept: application/json',
            ],
        ]);

        $body  = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
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
        foreach ($data as $t) {
            if (is_array($t) && isset($t['id']) && $t['id'] !== '') {
                $ids[] = (string)$t['id'];
            }
        }

        // Use pagesTotal if provided; otherwise stop when a short page is returned
        if ($pagesTotal === null) {
            $pagesTotal = (int)($json['pagesTotal'] ?? 1);
            if ($pagesTotal <= 0) $pagesTotal = 1;
        }

        $page++;
    } while ($page <= $pagesTotal && $page <= $maxPages);

    echo json_encode(['data' => $ids], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
