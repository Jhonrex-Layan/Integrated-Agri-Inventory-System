<?php
// PDO connection helper. Errors are logged, never shown with credentials.
declare(strict_types=1);

function app_config(): array
{
    static $cfg = null;
    return $cfg ??= require __DIR__ . "/config.php";
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = app_config()["db"];
    $dsn = "mysql:host={$c["host"]};port={$c["port"]};dbname={$c["name"]};charset={$c["charset"]}";
    try {
        $pdo = new PDO($dsn, $c["user"], $c["pass"], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log("[db] connection failed: " . $e->getMessage() . "\n", 3, app_config()["log_file"]);
        if (defined('API_MODE')) {
            throw $e;                 // the REST API turns this into a JSON 503
        }
        http_response_code(503);
        exit("Database connection failed. Check config/config.php and that MySQL is running in XAMPP.");
    }
    return $pdo;
}
