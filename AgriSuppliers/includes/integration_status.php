<?php
declare(strict_types=1);

/**
 * Supplier side: the API is served here, so "connected" means an active API client has actually called in recently.
 * Derived from api_clients.last_used_at, not guessed.
 */
function integration_status(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    try {
        $row = db_row('SELECT COUNT(*) AS n, MAX(last_used_at) AS last_used FROM api_clients WHERE is_active = 1');
    } catch (Throwable $e) {
        return $s = ['state' => 'unknown', 'class' => 'secondary', 'label' => 'API: unknown', 'detail' => 'Could not read API client data.', 'last' => null];
    }
    $n = (int)($row['n'] ?? 0);
    $last = $row['last_used'] ?? null;
    if ($n === 0) {
        return $s = ['state' => 'disconnected', 'class' => 'secondary', 'label' => 'API: no client configured',
                     'detail' => 'No active API client. Create one under Administration > API Clients.', 'last' => null];
    }
    if ($last && (time() - strtotime($last)) <= 900) {
        return $s = ['state' => 'connected', 'class' => 'success', 'label' => 'API: connected',
                     'detail' => 'An API client called in the last 15 minutes (' . fmt_dt($last) . ').', 'last' => $last];
    }
    return $s = ['state' => 'idle', 'class' => 'warning', 'label' => 'API: no recent calls',
                 'detail' => $last ? 'Last client call: ' . fmt_dt($last) : 'No client has called the API yet.', 'last' => $last];
}
