<?php
// Loaded first by every page. Sets up config, error handling, session and security headers.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/auth.php';
if (is_file(__DIR__ . '/po.php')) { require_once __DIR__ . '/po.php'; }
if (is_file(__DIR__ . '/stock.php')) { require_once __DIR__ . '/stock.php'; }
require_once __DIR__ . '/integration_status.php';

$__cfg = app_config();
date_default_timezone_set($__cfg['timezone'] ?? 'UTC');
error_reporting(E_ALL);
ini_set('display_errors', !empty($__cfg['debug']) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', $__cfg['log_file']);

set_exception_handler(function (Throwable $e): void {
    app_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (!empty(app_config()['debug'])) {
        echo '<pre>' . e($e) . '</pre>';
    } else {
        echo 'An unexpected error occurred. Details were written to storage/logs/app.log.';
    }
});

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$__https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name($__cfg['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => rtrim($__cfg['base_url'], '/') . '/',
    'secure'   => $__https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
