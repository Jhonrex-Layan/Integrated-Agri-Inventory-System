<?php
// One-time creation of the first administrator.
//  - Only reachable from this computer (127.0.0.1 / ::1).
//  - Refuses to run once any administrator exists.
//  - After setup, delete this file.
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$cfg = app_config();
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$done = ((int)db_scalar("SELECT COUNT(*) FROM users WHERE role = 'administrator'")) > 0;
$errors = [];
$created = false;
$old = ['username' => '', 'full_name' => '', 'email' => ''];

if ($local && !$done && is_post()) {
    csrf_verify();
    $old['username']  = trim((string)($_POST['username'] ?? ''));
    $old['full_name'] = trim((string)($_POST['full_name'] ?? ''));
    $old['email']     = trim((string)($_POST['email'] ?? ''));
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $old['username'])) {
        $errors[] = 'Username must be 3-50 characters: letters, numbers, dot, dash, underscore.';
    }
    if ($old['full_name'] === '' || mb_strlen($old['full_name']) > 120) {
        $errors[] = 'Full name is required (max 120 characters).';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 150) {
        $errors[] = 'A valid email address is required.';
    }
    if ($err = password_policy_error($pw)) {
        $errors[] = $err;
    }
    if ($pw !== $pw2) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stillNone = ((int)db_scalar("SELECT COUNT(*) FROM users WHERE role = 'administrator' FOR UPDATE")) === 0;
            if ($stillNone) {
                $pdo->prepare('INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?,?,?,?,?)')
                    ->execute([$old['username'], $old['full_name'], $old['email'], password_hash($pw, PASSWORD_DEFAULT), 'administrator']);
                $newId = (int)$pdo->lastInsertId();
                $pdo->commit();
                audit('admin_created', 'user', $newId, 'via setup_admin.php', $newId);
                $created = true;
                $done = true;
            } else {
                $pdo->rollBack();
                $done = true;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            app_log('setup_admin failed: ' . $e->getMessage());
            $errors[] = 'Could not create the user (username or email may already exist).';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup | <?= e($cfg['app_title']) ?></title>
<link rel="icon" type="image/png" href="<?= e(url('assets/img/favicon.png')) ?>">
<script src="<?= e(url('assets/js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(vendor_url('bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(vendor_url('bootstrap-icons/bootstrap-icons.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="auth single">
  <main class="auth-main">
    <div class="auth-tools">
      <button type="button" class="btn btn-icon" data-theme-toggle aria-label="Switch between light and dark theme" title="Switch theme">
        <i class="bi bi-moon-stars when-light"></i><i class="bi bi-brightness-high when-dark"></i>
      </button>
    </div>
    <div class="auth-card wide">
      <img class="auth-logo" src="<?= e(url('assets/img/logo-full.png')) ?>" alt="<?= e($cfg['app_title']) ?>" width="168">
      <h1 class="auth-heading">Create the first administrator</h1>
      <p class="auth-sub">One-time setup for <?= e($cfg['app_title']) ?>.</p>
      <?php if (!$local): ?>
        <div class="alert alert-danger" role="alert">Setup can only be run from the computer hosting XAMPP (localhost).</div>
      <?php elseif ($created): ?>
        <div class="alert alert-success" role="alert">Administrator created. <strong>Delete setup_admin.php</strong> from the project folder, then
          <a href="<?= e(url('login.php')) ?>">sign in</a>.</div>
      <?php elseif ($done): ?>
        <div class="alert alert-info" role="alert">Setup has already been completed. Delete <code>setup_admin.php</code> from the project folder.</div>
      <?php else: ?>
        <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2" role="alert"><?= e($er) ?></div><?php endforeach; ?>
        <form method="post" class="needs-validation" novalidate autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label" for="su_username">Username</label>
            <input class="form-control" id="su_username" name="username" value="<?= e($old['username']) ?>" required maxlength="50" autocapitalize="none" spellcheck="false">
            <div class="invalid-feedback">Username is required.</div></div>
          <div class="mb-3"><label class="form-label" for="su_full_name">Full name</label>
            <input class="form-control" id="su_full_name" name="full_name" value="<?= e($old['full_name']) ?>" required maxlength="120">
            <div class="invalid-feedback">Full name is required.</div></div>
          <div class="mb-3"><label class="form-label" for="su_email">Email</label>
            <input class="form-control" type="email" id="su_email" name="email" value="<?= e($old['email']) ?>" required maxlength="150">
            <div class="invalid-feedback">Enter a valid email address.</div></div>
          <div class="mb-3"><label class="form-label" for="su_password">Password <span class="text-muted small fw-normal">(10+ characters, letters and numbers)</span></label>
            <div class="auth-field">
              <input class="form-control has-toggle" style="padding-left:.85rem" type="password" id="su_password" name="password" required autocomplete="new-password">
              <button type="button" class="pw-toggle" data-pw-toggle="su_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>
            </div>
            <div class="invalid-feedback">Password is required.</div>
            <div class="auth-hint" id="capsHint" role="status"><i class="bi bi-capslock"></i> Caps Lock is on.</div></div>
          <div class="mb-4"><label class="form-label" for="su_password2">Confirm password</label>
            <input class="form-control" type="password" id="su_password2" name="password2" required autocomplete="new-password">
            <div class="invalid-feedback">Confirm the password.</div></div>
          <button class="btn btn-success w-100 auth-submit" type="submit" data-loading-text="Creating..." data-label="Create administrator">
            <span class="spinner-border" role="status" aria-hidden="true"></span><i class="bi bi-person-check"></i> <span class="label">Create administrator</span>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </main>
</div>
<script src="<?= e(url('assets/js/auth.js')) ?>"></script>
</body>
</html>
