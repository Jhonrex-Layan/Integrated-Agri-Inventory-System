<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('api.manage');

$allScopes = ['read' => 'Read suppliers, catalog, purchase orders, deliveries', 'receive' => 'Acknowledge received deliveries', 'refund' => 'Submit and read refund requests'];
$errors = [];
$newSecret = $_SESSION['new_api_client_secret'] ?? null;   // shown exactly once
unset($_SESSION['new_api_client_secret']);

if (is_post()) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'create');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'toggle' && $id > 0) {
        $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
        db()->prepare('UPDATE api_clients SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        audit($active ? 'api_client_activated' : 'api_client_deactivated', 'api_client', $id);
        flash('success', $active ? 'API client activated.' : 'API client deactivated. Its key stops working immediately.');
        redirect('modules/admin/api_clients.php');
    }
    if ($action === 'update' && $id > 0) {
        $scopes = array_values(array_intersect(array_keys($allScopes), (array)($_POST['scopes'] ?? [])));
        $rate = (int)($_POST['rate_limit_per_min'] ?? 0);
        if (!$scopes || $rate < 1 || $rate > 10000) {
            flash('danger', 'Choose at least one scope and a rate limit between 1 and 10000.');
        } else {
            db()->prepare('UPDATE api_clients SET scopes = ?, rate_limit_per_min = ? WHERE id = ?')->execute([implode(',', $scopes), $rate, $id]);
            audit('api_client_updated', 'api_client', $id, 'scopes=' . implode(',', $scopes) . ' rate=' . $rate);
            flash('success', 'API client updated.');
        }
        redirect('modules/admin/api_clients.php');
    }
    $name = trim((string)($_POST['client_name'] ?? ''));
    $scopes = array_values(array_intersect(array_keys($allScopes), (array)($_POST['scopes'] ?? [])));
    $rate = (int)($_POST['rate_limit_per_min'] ?? 120);
    if ($name === '' || mb_strlen($name) > 80) {
        $errors['client_name'] = 'Enter a client name (max 80 characters).';
    }
    if (!$scopes) {
        $errors['scopes'] = 'Select at least one scope.';
    }
    if ($rate < 1 || $rate > 10000) {
        $errors['rate_limit_per_min'] = 'Rate limit must be between 1 and 10000 requests per minute.';
    }
    if (!$errors) {
        $secret = 'ags_' . bin2hex(random_bytes(32));          // 256 random bits; only the SHA-256 hash is stored
        db()->prepare('INSERT INTO api_clients (client_name, key_prefix, key_hash, scopes, rate_limit_per_min, is_active, created_by) VALUES (?,?,?,?,?,1,?)')
            ->execute([$name, substr($secret, 0, 8), hash('sha256', $secret), implode(',', $scopes), $rate, (int)current_user()['id']]);
        audit('api_client_created', 'api_client', (int)db()->lastInsertId(), $name . ' scopes=' . implode(',', $scopes));
        $_SESSION['new_api_client_secret'] = $secret;
        flash('success', 'API client created. Copy the key now; it cannot be shown again.');
        redirect('modules/admin/api_clients.php');
    }
}
$rows = db_all('SELECT * FROM api_clients ORDER BY id DESC');
$f = ['client_name' => trim((string)($_POST['client_name'] ?? '')), 'rate_limit_per_min' => (string)($_POST['rate_limit_per_min'] ?? '120')];
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(app_config()['base_url'], '/') . '/api/v1';

$pageTitle = 'API clients';
$activeMenu = 'api';
require APP_ROOT . '/includes/layout_header.php';
?>
<?php if ($newSecret): ?>
<div class="card border-success mb-3"><div class="card-body">
  <h2 class="h5">New API key — copy it now</h2>
  <p class="mb-2">Shown only once. Put it in the Inventory system's <code>config/api_client.php</code> (or <code>C:/xampp/agri_secrets/inventory_api.php</code>). The database keeps only its SHA-256 hash.</p>
  <div class="input-group"><input id="newKey" class="form-control font-monospace" readonly value="<?= e($newSecret) ?>">
    <button class="btn btn-outline-success" type="button" id="copyKey">Copy</button></div>
  <div class="small text-muted mt-2">API base URL: <code><?= e($base) ?></code></div>
</div></div>
<script>document.getElementById('copyKey').addEventListener('click',function(){var i=document.getElementById('newKey');i.select();navigator.clipboard.writeText(i.value).then(function(){document.getElementById('copyKey').textContent='Copied';});});</script>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card"><div class="card-header">Create API client</div><div class="card-body">
    <?= errors_alert($errors) ?>
    <form method="post" class="needs-validation" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="row g-3">
      <?= form_input('client_name', 'Client name', $f, $errors, ['required' => true, 'maxlength' => 80, 'col' => 'col-12', 'placeholder' => 'e.g. AgriInventory']) ?>
      <div class="col-12"><label class="form-label">Scopes</label>
        <?php foreach ($allScopes as $k => $label): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="<?= e($k) ?>" id="s_<?= e($k) ?>" <?= !is_post() || in_array($k, (array)($_POST['scopes'] ?? []), true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="s_<?= e($k) ?>"><strong><?= e($k) ?></strong> — <?= e($label) ?></label></div><?php endforeach; ?>
        <div class="form-text">The Inventory system needs all three.</div></div>
      <?= form_input('rate_limit_per_min', 'Rate limit (requests/minute)', $f, $errors, ['type' => 'number', 'min' => 1, 'max' => 10000, 'required' => true, 'col' => 'col-12']) ?>
      <div class="col-12"><button class="btn btn-success w-100" type="submit"><i class="bi bi-key"></i> Generate key</button></div></div>
    </form></div></div></div>
  <div class="col-lg-8"><div class="card"><div class="card-header">Registered clients</div><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Client</th><th>Key</th><th>Scopes / rate</th><th>Status</th><th>Last used</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['client_name']) ?></td><td><code><?= e($r['key_prefix']) ?>…</code></td>
        <td><form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php $have = explode(',', (string)$r['scopes']); foreach (array_keys($allScopes) as $k): ?>
            <label class="small"><input type="checkbox" name="scopes[]" value="<?= e($k) ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>> <?= e($k) ?></label><?php endforeach; ?>
          <input class="form-control form-control-sm" style="width:90px" type="number" min="1" max="10000" name="rate_limit_per_min" value="<?= (int)$r['rate_limit_per_min'] ?>">
          <button class="btn btn-sm btn-outline-secondary" type="submit">Save</button></form></td>
        <td><?= $r['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
        <td class="small"><?= e(fmt_dt($r['last_used_at'])) ?></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="active" value="<?= $r['is_active'] ? 0 : 1 ?>">
          <button class="btn btn-sm <?= $r['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit" <?= $r['is_active'] ? 'data-confirm="Deactivate this client? Its key stops working immediately."' : '' ?>><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-muted p-3">No API clients yet. Create one for the Inventory system.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
