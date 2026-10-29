<?php

// Prevent duplicate connections if already connected
if (isset($connection) && $connection instanceof mysqli) {
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Optional: Load local .env file if it exists (for local development)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (getenv($key) === false) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

$dbHost = getenv('DB_HOST') !== false ? getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? false));
$dbUser = getenv('DB_USER') !== false ? getenv('DB_USER') : ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? false));
$dbPass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($_ENV['DB_PASSWORD'] ?? ($_SERVER['DB_PASSWORD'] ?? false));
$dbName = getenv('DB_NAME') !== false ? getenv('DB_NAME') : ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? false));
$dbPort = (getenv('DB_PORT') !== false && getenv('DB_PORT') !== '') ? getenv('DB_PORT') : ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? '3306'));

if (
    $dbHost === false || $dbHost === '' ||
    $dbUser === false || $dbUser === '' ||
    $dbPass === false ||
    $dbName === false || $dbName === ''
) {
    error_log('Database configuration is missing.');
    http_response_code(500);
    exit('Application configuration error. Please try again later.');
}

try {
    $conn = mysqli_init();

    mysqli_real_connect(
        $conn,
        $dbHost,
        $dbUser,
        $dbPass,
        $dbName,
        (int) $dbPort
    );

    mysqli_set_charset($conn, 'utf8mb4');

    $connection = $conn;
    $GLOBALS['conn'] = $conn;
    $GLOBALS['connection'] = $conn;

} catch (mysqli_sql_exception $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to connect to the database. Please try again later.');
}
