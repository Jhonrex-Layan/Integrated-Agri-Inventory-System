<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('users.manage');

$me = current_user();
$roles = enum_values('users', 'role');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$user = null;
if ($id > 0) {
    $user = db_row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$user) {
        abort_page(404, 'User not found.');
    }
}
$isSelf = $user && (int)$user['id'] === (int)$me['id'];
$f = [
    'username'  => $user['username'] ?? '',
    'full_name' => $user['full_name'] ?? '',
    'email'     => $user['email'] ?? '',
    'role'      => $user['role'] ?? $roles[1] ?? $roles[0],
    'is_active' => $user ? (string)$user['is_active'] : '1',
];
$errors = [];

if (is_post()) {
    csrf_verify();
    $f['full_name'] = trim((string)($_POST['full_name'] ?? ''));
    $f['email']     = trim((string)($_POST['email'] ?? ''));
    if (!$user) {
        $f['username'] = trim((string)($_POST['username'] ?? ''));
    }
    $f['role']      = (string)($_POST['role'] ?? '');
    $f['is_active'] = isset($_POST['is_active']) ? '1' : '0';
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    $unlock = isset($_POST['unlock']);

    if ($isSelf) {                       // nobody can lock themselves out or change their own privileges
        $f['role'] = $user['role'];
        $f['is_active'] = '1';
    }
    if (!$user && !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $f['username'])) {
        $errors['username'] = 'Username must be 3-50 characters: letters, numbers, dot, dash, underscore.';
    }
    if ($f['full_name'] === '' || mb_strlen($f['full_name']) > 120) {
        $errors['full_name'] = 'Full name is required (max 120 characters).';
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || strlen($f['email']) > 150) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (!in_array($f['role'], $roles, true)) {
        $errors['role'] = 'Choose a valid role.';
    }
    if (!$user || $pw !== '') {
        if ($e1 = password_policy_error($pw)) {
            $errors['password'] = $e1;
        } elseif ($pw !== $pw2) {
            $errors['password2'] = 'The two passwords do not match.';
        }
    }
    if (!$errors) {
        $dup = db_scalar('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$f['email'], $id]);
        if ((int)$dup > 0) {
            $errors['email'] = 'Another user already uses this email address.';
        }
        if (!$user && (int)db_scalar('SELECT COUNT(*) FROM users WHERE username = ?', [$f['username']]) > 0) {
            $errors['username'] = 'This username is already taken.';
        }
    }
    // keep at least one active administrator
    if (!$errors && $user && $user['role'] === 'administrator' && $user['is_active']
        && ($f['role'] !== 'administrator' || $f['is_active'] !== '1')) {
        $others = (int)db_scalar("SELECT COUNT(*) FROM users WHERE role = 'administrator' AND is_active = 1 AND id <> ?", [$id]);
        if ($others < 1) {
            $errors['role'] = 'At least one active administrator must remain.';
        }
    }
    if (!$errors) {
        try {
            if ($user) {
                $sql = 'UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ?';
                $params = [$f['full_name'], $f['email'], $f['role'], (int)$f['is_active']];
                if ($pw !== '') {
                    $sql .= ', password_hash = ?';
                    $params[] = password_hash($pw, PASSWORD_DEFAULT);
                }
                if ($unlock || $pw !== '') {
                    $sql .= ', failed_logins = 0, locked_until = NULL';
                }
                $params[] = $id;
                db()->prepare($sql . ' WHERE id = ?')->execute($params);
                $d = changed_fields($user, $f, ['full_name', 'email', 'role', 'is_active']);
                audit('user_updated', 'user', $id, $d . ($pw !== '' ? '; password reset' : '') . ($unlock ? '; unlocked' : ''));
                flash('success', 'User updated.');
            } else {
                db()->prepare('INSERT INTO users (username, full_name, email, password_hash, role, is_active) VALUES (?,?,?,?,?,?)')
                    ->execute([$f['username'], $f['full_name'], $f['email'], password_hash($pw, PASSWORD_DEFAULT), $f['role'], (int)$f['is_active']]);
                $newId = (int)db()->lastInsertId();
                audit('user_created', 'user', $newId, 'role: ' . $f['role']);
                flash('success', 'User created.');
            }
            redirect('modules/admin/users.php');
        } catch (PDOException $e) {
            app_log('user save failed: ' . $e->getMessage());
            $errors['_form'] = is_duplicate_key($e) ? 'That username or email is already in use.' : 'The user could not be saved.';
        }
    }
}

$roleOpts = [];
foreach ($roles as $r) {
    $roleOpts[$r] = role_label($r);
}
$pageTitle = $user ? 'Edit user' : 'New user';
$activeMenu = 'users';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<div class="card" style="max-width:760px"><div class="card-body">
<form method="post" class="row g-3" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <?php if ($user): ?>
    <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" value="<?= e($f['username']) ?>" disabled></div>
  <?php else: ?>
    <?= form_input('username', 'Username', $f, $errors, ['required' => true, 'maxlength' => 50]) ?>
  <?php endif; ?>
  <?= form_input('full_name', 'Full name', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
  <?= form_input('email', 'Email', $f, $errors, ['type' => 'email', 'required' => true, 'maxlength' => 150]) ?>
  <?= form_select('role', 'Role', $roleOpts, $f['role'], $errors, ['required' => true, 'disabled' => $isSelf, 'help' => $isSelf ? 'You cannot change your own role.' : null]) ?>
  <?= form_input('password', $user ? 'New password (leave blank to keep)' : 'Password', $f + ['password' => ''], $errors, ['type' => 'password', 'required' => !$user, 'help' => '10+ characters with letters and numbers.']) ?>
  <?= form_input('password2', 'Confirm password', $f + ['password2' => ''], $errors, ['type' => 'password', 'required' => !$user]) ?>
  <div class="col-12">
    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= $f['is_active'] === '1' ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
      <label class="form-check-label" for="is_active">Account is active</label></div>
    <?php if ($user): ?>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="unlock" id="unlock" value="1">
      <label class="form-check-label" for="unlock">Clear failed-login lockout</label></div>
    <?php endif; ?>
  </div>
  <div class="col-12 d-flex gap-2">
    <button class="btn btn-success" type="submit"><i class="bi bi-check-lg"></i> Save</button>
    <a class="btn btn-light" href="users.php">Cancel</a>
  </div>
</form></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
