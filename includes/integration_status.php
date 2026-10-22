<?php
declare(strict_types=1);

function setting_get(string $key): ?string
{
    $v = db_scalar('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    return $v === null ? null : (string)$v;
}

function setting_set(string $key, ?string $value): void
{
    db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
}

/** Connection status as last recorded by a real API call or a "Check connection" action. */
function integration_status(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    try {
        $state = setting_get('api_connection_status') ?? 'unknown';
        $last  = setting_get('last_successful_sync');
    } catch (Throwable $e) {
        return $s = ['state' => 'unknown', 'class' => 'secondary', 'label' => 'Supplier API: unknown', 'detail' => 'Status could not be read.', 'last' => null];
    }
    $lastTxt = $last ? 'Last successful contact: ' . fmt_dt($last) : 'No successful contact recorded yet.';
    return $s = match ($state) {
        'connected'      => ['state' => 'connected', 'class' => 'success', 'label' => 'Supplier API: connected', 'detail' => $lastTxt, 'last' => $last],
        'disconnected'   => ['state' => 'disconnected', 'class' => 'danger', 'label' => 'Supplier API: disconnected', 'detail' => $lastTxt, 'last' => $last],
        'not_configured' => ['state' => 'not_configured', 'class' => 'secondary', 'label' => 'Supplier API: not configured', 'detail' => 'Set the API key in config/api_client.php.', 'last' => null],
        default          => ['state' => 'unknown', 'class' => 'secondary', 'label' => 'Supplier API: not checked', 'detail' => 'Use "Check connection" on the dashboard.', 'last' => $last],
    };
}
