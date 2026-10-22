<?php
// Shared helpers for list/search/paginate pages and validated forms.
declare(strict_types=1);

function like_param(string $q): string
{
    return '%' . addcslashes($q, '%_\\') . '%';
}

function paginate(int $total, int $perPage = 15): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    return ['page' => $page, 'pages' => $pages, 'per' => $perPage, 'total' => $total, 'offset' => ($page - 1) * $perPage];
}

/** Current query string with overrides (used by pager and filter links). */
function qs(array $override = []): string
{
    $q = array_merge($_GET, $override);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
}

function pager(array $pg): string
{
    $info = '<div class="small text-muted">' . number_format($pg['total']) . ' record(s)'
          . ($pg['pages'] > 1 ? ' &middot; page ' . $pg['page'] . ' of ' . $pg['pages'] : '') . '</div>';
    if ($pg['pages'] <= 1) {
        return '<div class="mt-2">' . $info . '</div>';
    }
    $li = function (string $label, int $p, bool $disabled, bool $active = false): string {
        return '<li class="page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '') . '">'
             . '<a class="page-link" href="' . e(qs(['page' => $p])) . '">' . $label . '</a></li>';
    };
    $h = '<nav class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">' . $info . '<ul class="pagination pagination-sm mb-0">';
    $h .= $li('&laquo;', $pg['page'] - 1, $pg['page'] <= 1);
    for ($i = max(1, $pg['page'] - 2); $i <= min($pg['pages'], $pg['page'] + 2); $i++) {
        $h .= $li((string)$i, $i, false, $i === $pg['page']);
    }
    $h .= $li('&raquo;', $pg['page'] + 1, $pg['page'] >= $pg['pages']);
    return $h . '</ul></nav>';
}

function abort_page(int $code, string $message): void
{
    http_response_code($code);
    $pageTitle = $code === 404 ? 'Not found' : 'Error';
    $activeMenu = '';
    require APP_ROOT . '/includes/layout_header.php';
    echo '<div class="alert alert-warning">' . e($message) . '</div>';
    require APP_ROOT . '/includes/layout_footer.php';
    exit;
}

/** "1,234.50" -> "1234.50"; null when not a non-negative number with up to 2 decimals. */
function parse_decimal(string $v, int $maxIntDigits = 10): ?string
{
    $v = str_replace(',', '', trim($v));
    return preg_match('/^\d{1,' . $maxIntDigits . '}(\.\d{1,2})?$/', $v) ? $v : null;
}

function inv(array $errors, string $k): string
{
    return isset($errors[$k]) ? ' is-invalid' : '';
}

function fb(array $errors, string $k): string
{
    return isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';
}

function errors_alert(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $h = '<div class="alert alert-danger"><strong>Please fix the following:</strong><ul class="mb-0">';
    foreach ($errors as $m) {
        $h .= '<li>' . e($m) . '</li>';
    }
    return $h . '</ul></div>';
}

function form_input(string $name, string $label, array $f, array $errors, array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $req  = !empty($o['required']);
    $attrs = ' name="' . e($name) . '" id="f_' . e($name) . '" class="form-control' . inv($errors, $name) . '"';
    if ($req) {
        $attrs .= ' required';
    }
    if (isset($o['maxlength'])) {
        $attrs .= ' maxlength="' . (int)$o['maxlength'] . '"';
    }
    foreach (['step', 'min', 'max', 'placeholder', 'list', 'inputmode'] as $a) {
        if (isset($o[$a])) {
            $attrs .= ' ' . $a . '="' . e($o[$a]) . '"';
        }
    }
    if (!empty($o['readonly'])) {
        $attrs .= ' readonly';
    }
    $val = e($f[$name] ?? '');
    $input = $type === 'textarea'
        ? '<textarea' . $attrs . ' rows="' . (int)($o['rows'] ?? 3) . '">' . $val . '</textarea>'
        : '<input type="' . e($type) . '"' . $attrs . ' value="' . $val . '">';
    $help = isset($o['help']) ? '<div class="form-text">' . e($o['help']) . '</div>' : '';
    return '<div class="' . e($o['col'] ?? 'col-md-6') . '"><label class="form-label" for="f_' . e($name) . '">'
         . e($label) . ($req ? ' <span class="text-danger">*</span>' : '') . '</label>' . $input . fb($errors, $name) . $help . '</div>';
}

/** @param array<string,string> $options value => label */
function form_select(string $name, string $label, array $options, mixed $selected, array $errors, array $o = []): string
{
    $req = !empty($o['required']);
    $h = '<div class="' . e($o['col'] ?? 'col-md-6') . '"><label class="form-label" for="f_' . e($name) . '">' . e($label)
       . ($req ? ' <span class="text-danger">*</span>' : '') . '</label><select name="' . e($name) . '" id="f_' . e($name)
       . '" class="form-select' . inv($errors, $name) . '"' . ($req ? ' required' : '') . (!empty($o['disabled']) ? ' disabled' : '') . '>';
    if (!empty($o['blank'])) {
        $h .= '<option value="">' . e($o['blank']) . '</option>';
    }
    foreach ($options as $v => $l) {
        $h .= '<option value="' . e($v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h . '</select>' . fb($errors, $name) . (isset($o['help']) ? '<div class="form-text">' . e($o['help']) . '</div>' : '') . '</div>';
}

/** Human-readable list of changed fields for audit details (values for non-sensitive fields only). */
function changed_fields(array $old, array $new, array $keys): string
{
    $out = [];
    foreach ($keys as $k) {
        if ((string)($old[$k] ?? '') !== (string)($new[$k] ?? '')) {
            $out[] = $k . ': "' . mb_substr((string)($old[$k] ?? ''), 0, 60) . '" -> "' . mb_substr((string)($new[$k] ?? ''), 0, 60) . '"';
        }
    }
    return $out ? implode('; ', $out) : 'no field changes';
}

function enum_values(string $table, string $column): array
{
    $t = db_scalar('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    preg_match_all("/'([^']+)'/", (string)$t, $m);
    return $m[1] ?? [];
}

function is_duplicate_key(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? 0) === 1062;
}
