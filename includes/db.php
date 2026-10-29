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

// Helper to look up an environment variable across getenv, $_ENV, and $_SERVER with aliases
if (!function_exists('get_db_env_value')) {
    function get_db_env_value(array $names, bool $isPassword = false) {
        foreach ($names as $name) {
            $val = getenv($name);
            if ($val !== false) {
                if ($isPassword || $val !== '') {
                    return $val;
                }
            }
            if (isset($_ENV[$name])) {
                $val = (string) $_ENV[$name];
                if ($isPassword || $val !== '') {
                    return $val;
                }
            }
            if (isset($_SERVER[$name])) {
                $val = (string) $_SERVER[$name];
                if ($isPassword || $val !== '') {
                    return $val;
                }
            }
        }
        return false;
    }
}

$dbHost = get_db_env_value(['DB_HOST', 'MYSQL_HOST', 'MYSQLHOST', 'DATABASE_HOST']);
$dbUser = get_db_env_value(['DB_USER', 'DB_USERNAME', 'MYSQL_USER', 'MYSQLUSER']);
$dbPass = get_db_env_value(['DB_PASSWORD', 'DB_PASS', 'MYSQL_PASSWORD', 'MYSQLPASSWORD'], true);
$dbName = get_db_env_value(['DB_NAME', 'DB_DATABASE', 'MYSQL_DATABASE', 'MYSQLDATABASE']);
$dbPort = get_db_env_value(['DB_PORT', 'MYSQL_PORT', 'MYSQLPORT']) ?: '3306';

// Support DATABASE_URL if individual variables are not set
$databaseUrl = get_db_env_value(['DATABASE_URL', 'JAWSDB_URL', 'CLEARDB_DATABASE_URL']);
if ($databaseUrl) {
    $parsed = parse_url($databaseUrl);
    if ($parsed) {
        if (($dbHost === false || $dbHost === '') && !empty($parsed['host'])) {
            $dbHost = $parsed['host'];
        }
        if (($dbUser === false || $dbUser === '') && !empty($parsed['user'])) {
            $dbUser = $parsed['user'];
        }
        if ($dbPass === false && isset($parsed['pass'])) {
            $dbPass = $parsed['pass'];
        }
        if (($dbName === false || $dbName === '') && !empty($parsed['path'])) {
            $dbName = ltrim($parsed['path'], '/');
        }
        if (!empty($parsed['port']) && ($dbPort === '3306' || $dbPort === false)) {
            $dbPort = (string) $parsed['port'];
        }
    }
}

// Identify missing configuration parameters
$missingVars = [];
if ($dbHost === false || $dbHost === '') {
    $missingVars[] = 'DB_HOST';
}
if ($dbUser === false || $dbUser === '') {
    $missingVars[] = 'DB_USER';
}
if ($dbPass === false) {
    $missingVars[] = 'DB_PASSWORD';
}
if ($dbName === false || $dbName === '') {
    $missingVars[] = 'DB_NAME';
}

if (!empty($missingVars)) {
    error_log('[DATABASE CONFIGURATION ERROR] Missing required environment variable(s): ' . implode(', ', $missingVars) . '. Please configure them under Render Dashboard -> Your Web Service -> Environment.');
    http_response_code(500);
    exit('Application configuration error. Please try again later.');
}

try {
    $conn = mysqli_init();

    // Set connection timeout to 10 seconds
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

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
    $code = $e->getCode();
    $rawMessage = $e->getMessage();

    // Distinguish failure types in server logs without exposing sensitive credentials
    if ($code === 1045) {
        // Authentication error
        error_log("[DATABASE AUTH ERROR] Authentication failed (Error 1045): Access denied for user '{$dbUser}' connecting to host '{$dbHost}'. Verify DB_USER and DB_PASSWORD in Render and FreeSQLDatabase.");
    } elseif ($code === 2002 || $code === 2003 || stripos($rawMessage, 'timed out') !== false || stripos($rawMessage, 'refused') !== false) {
        // Connection timeout or unreachable server
        error_log("[DATABASE NETWORK ERROR] Connection failed to host '{$dbHost}:{$dbPort}' (Error {$code}): {$rawMessage}. Verify that the database server is running and reachable.");
    } elseif ($code === 1044 || $code === 1049) {
        // Database permission or non-existent database error
        error_log("[DATABASE PERMISSION ERROR] Access denied to database '{$dbName}' (Error {$code}): {$rawMessage}. Verify that DB_NAME exists and the user has permissions.");
    } else {
        // General database error
        error_log("[DATABASE CONNECTION ERROR] Error {$code}: {$rawMessage}");
    }

    http_response_code(500);
    exit('Unable to connect to the database. Please try again later.');
}
