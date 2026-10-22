<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('modules/dashboard/index.php');
}

$error = '';
$username = '';
$noUsers = ((int)db_scalar('SELECT COUNT(*) FROM users')) === 0;

if (is_post()) {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        [$ok, $msg] = attempt_login($username, $password);
        if ($ok) {
            redirect('modules/dashboard/index.php');
        }
        $error = $msg;
    }
}
$cfg = app_config();
$flashes = flash_take();
$brand    = (string)($cfg['brand_name'] ?? $cfg['app_name']);
$headline = (string)($cfg['login_headline'] ?? 'Welcome back');
$lead     = (string)($cfg['login_lead'] ?? '');
$points   = is_array($cfg['login_points'] ?? null) ? $cfg['login_points'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | <?= e($cfg['app_title']) ?></title>
<link rel="icon" type="image/png" href="<?= e(url('assets/img/favicon.png')) ?>">
<script src="<?= e(url('assets/js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(vendor_url('bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(vendor_url('bootstrap-icons/bootstrap-icons.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="auth">
  <section class="auth-aside">
    <h2 class="aside-title"><?= e($headline) ?></h2>
    <?php if ($lead !== ''): ?><p class="aside-lead"><?= e($lead) ?></p><?php endif; ?>
    <?php if ($points): ?>
    <ul class="aside-points">
      <?php foreach ($points as $pt): ?>
        <li><i class="bi bi-<?= e($pt[0] ?? 'check2') ?>"></i><span><?= e($pt[1] ?? '') ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div class="aside-foot">&copy; <?= date('Y') ?> <?= e($brand) ?></div>
  </section>

  <main class="auth-main">
    <div class="auth-tools">
      <button type="button" class="btn btn-icon" data-theme-toggle aria-label="Switch between light and dark theme" title="Switch theme">
        <i class="bi bi-moon-stars when-light"></i><i class="bi bi-brightness-high when-dark"></i>
      </button>
    </div>

    <div class="auth-card">
      <img class="auth-logo" src="<?= e(url('assets/img/logo-full.png')) ?>" alt="<?= e($cfg['app_title']) ?>" width="168">
      <h1 class="auth-heading">Sign in</h1>
      <p class="auth-sub">Enter your account details to continue.</p>

      <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : e($f['type']) ?>" role="alert"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>
      <?php if ($noUsers): ?>
        <div class="alert alert-warning" role="alert">No users exist yet. Create the first administrator by opening
          <code>setup_admin.php</code> from this computer (see README.md).</div>
      <?php endif; ?>

      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <div class="auth-field">
            <i class="bi bi-person lead-icon"></i>
            <input class="form-control" id="username" name="username" value="<?= e($username) ?>" required <?= $username === '' ? 'autofocus' : '' ?> maxlength="50" autocomplete="username" autocapitalize="none" spellcheck="false">
          </div>
          <div class="invalid-feedback">Enter your username.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <div class="auth-field">
            <i class="bi bi-lock lead-icon"></i>
            <input class="form-control has-toggle" type="password" id="password" name="password" required <?= $username !== '' ? 'autofocus' : '' ?> autocomplete="current-password">
            <button type="button" class="pw-toggle" data-pw-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>
          </div>
          <div class="invalid-feedback">Enter your password.</div>
          <div class="auth-hint" id="capsHint" role="status"><i class="bi bi-capslock"></i> Caps Lock is on.</div>
        </div>
        <button class="btn btn-success w-100 auth-submit" type="submit" data-loading-text="Signing in..." data-label="Sign in">
          <span class="spinner-border" role="status" aria-hidden="true"></span><i class="bi bi-box-arrow-in-right"></i> <span class="label">Sign in</span>
        </button>
      </form>
      <p class="auth-note">Forgot your password? Ask an administrator to reset it.</p>
    </div>
    <p class="auth-foot"><?= e($cfg['app_title']) ?></p>
  </main>
</div>
<script src="<?= e(vendor_url('bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/auth.js')) ?>"></script>
</body>
</html>
