<?php
declare(strict_types=1);

/** Escape for HTML output. */
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(app_config()['base_url'], '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function app_log(string $msg): void
{
    @error_log('[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", 3, app_config()['log_file']);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function money(float|int|string|null $n): string
{
    return '₱' . number_format((float)$n, 2);
}

function fmt_qty(float|int|string|null $n): string
{
    $s = number_format((float)$n, 2, '.', ',');
    return rtrim(rtrim($s, '0'), '.');
}

function fmt_dt(?string $dt, string $fmt = 'M j, Y g:i A'): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date($fmt, $t) : '—';
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_row(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function db_scalar(string $sql, array $params = []): mixed
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** Use a locally installed copy under assets/vendor/ when present, otherwise the CDN. */
function vendor_url(string $localRelative, string $cdnUrl): string
{
    return is_file(APP_ROOT . '/assets/vendor/' . $localRelative)
        ? url('assets/vendor/' . $localRelative)
        : $cdnUrl;
}

/** Safe JSON for embedding inside a <script> block. */
function json_for_script(mixed $v): string
{
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: 'null';
}

function role_label(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

function status_badge(string $status): string
{
    $map = [
        'active' => 'success', 'approved' => 'success', 'completed' => 'success', 'acknowledged' => 'success',
        'accepted' => 'success', 'available' => 'success', 'reviewed' => 'success',
        'inactive' => 'secondary', 'draft' => 'secondary', 'expected' => 'secondary', 'archived' => 'dark',
        'pending_approval' => 'warning', 'sent' => 'info', 'partially_received' => 'info', 'confirmed' => 'info',
        'pending' => 'warning', 'limited' => 'warning', 'received' => 'info', 'under_review' => 'info', 'refunded' => 'success', 'resolved' => 'success',
        'refund_requested' => 'warning', 'recorded' => 'secondary', 'written_off' => 'dark', 'submitted' => 'success',
        'cancelled' => 'danger', 'rejected' => 'danger', 'unavailable' => 'danger', 'failed' => 'danger',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . e(role_label($status)) . '</span>';
}

/** Server-side password policy shared by user creation and setup. Returns an error string or null. */
function password_policy_error(string $pw): ?string
{
    if (strlen($pw) < 10) {
        return 'Password must be at least 10 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

function audit(string $action, string $entityType, ?int $entityId = null, ?string $details = null, ?int $userId = null): void
{
    try {
        $uid = $userId ?? (current_user()['id'] ?? null);
        db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $action, $entityType, $entityId, $details, client_ip()]);
    } catch (Throwable $e) {
        app_log('audit failed: ' . $e->getMessage());
    }
}
