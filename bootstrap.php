<?php
declare(strict_types=1);

/**
 * Project bootstrap:
 * - Loads Composer autoload (for vlucas/phpdotenv if installed)
 * - Loads .env into $_ENV
 * - Pulls in the PDO helper (db())
 */

$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require $autoloader;

    // Load .env if phpdotenv is available
    if (class_exists(\Dotenv\Dotenv::class)) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
    }
}

/**
 * Small env helper with default
 */
function env(string $key, $default = null) {
    if (array_key_exists($key, $_ENV)) return $_ENV[$key];
    $v = getenv($key);
    return $v !== false ? $v : $default;
}

// Optional: dev-friendly error settings
if (env('APP_ENV', 'production') !== 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Pull in DB (defines db(): PDO)
require_once __DIR__ . '/lib/db.php';
