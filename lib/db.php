<?php
declare(strict_types=1);

/**
 * Returns a shared PDO connection to PostgreSQL using .env variables.
 * Also sets the search_path to your schema (ENDTOEND by default).
 *
 * Required .env keys:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 * Optional:
 *   DB_SCHEMA (default ENDTOEND)
 *   DB_SSLMODE (disable|allow|prefer|require|verify-ca|verify-full) default: prefer
 *   DB_SSLROOTCERT (path to CA file, used if provided)
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host    = env('DB_HOST', 'localhost');
    $port    = (int)env('DB_PORT', 5432);
    $dbname  = env('DB_NAME', '');
    $user    = env('DB_USER', '');
    $pass    = env('DB_PASS', '');
    $schema  = env('DB_SCHEMA', 'ENDTOEND');
    $sslmode = env('DB_SSLMODE', 'prefer');
    $sslroot = env('DB_SSLROOTCERT'); // optional path

    // Build DSN
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
    if (!empty($sslroot)) {
        // Only append if file actually exists to avoid DSN errors
        if (is_file($sslroot)) {
            $dsn .= ";sslrootcert={$sslroot}";
        }
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);

    // Ensure queries hit the right schema without prefixing
    $schema = str_replace('"','""',$schema);
    $pdo->exec('SET search_path TO "'.$schema.'", public');

    return $pdo;
}
