<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
// If your ecom page is on a different origin, uncomment the following CORS lines:
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Headers: Content-Type');
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$apiKey     = env('ADYEN_API_KEY');
$baseUrl    = rtrim(env('ADYEN_CHECKOUT_BASE_URL', 'https://checkout-test.adyen.com/checkout'), '/');
$apiVersion = env('ADYEN_CHECKOUT_API_VERSION', 'v70');
$endpoint   = $baseUrl . '/' . $apiVersion . '/sessions';

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'ADYEN_API_KEY is not set in .env']);
    exit;
}

// Parse JSON body (empty body -> empty array)
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput ?: '[]', true);
if ($rawInput && $input === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON request body']);
    exit;
}

$requestData = is_array($input) ? $input : [];

// Inject defaults from env if not provided by the client
if (empty($requestData['merchantAccount']) && env('ADYEN_MERCHANT_ACCOUNT')) {
    $requestData['merchantAccount'] = env('ADYEN_MERCHANT_ACCOUNT');
}
if (empty($requestData['returnUrl']) && env('ADYEN_RETURN_URL')) {
    $requestData['returnUrl'] = env('ADYEN_RETURN_URL');
}

// (Optional) basic guard: require merchantAccount
if (empty($requestData['merchantAccount'])) {
    http_response_code(400);
    echo json_encode(['error' => 'merchantAccount is required (set it in request body or ADYEN_MERCHANT_ACCOUNT in .env)']);
    exit;
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($requestData, JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
    ],
]);

$responseBody = curl_exec($ch);
$curlErrNo    = curl_errno($ch);
$curlErr      = curl_error($ch);
$httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErrNo !== 0) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Upstream connection error',
        'details' => $curlErr,
    ]);
    exit;
}

// Try to pass through upstream JSON; if not JSON, wrap it
$decoded = json_decode($responseBody, true);
if ($decoded === null && $responseBody !== 'null') {
    // Upstream returned non-JSON (unlikely). Wrap it.
    http_response_code($httpCode ?: 502);
    echo json_encode([
        'error' => 'Unexpected upstream response',
        'status' => $httpCode,
        'raw' => $responseBody,
    ]);
    exit;
}

// Forward upstream status code & body
http_response_code($httpCode ?: 200);
echo $responseBody;
