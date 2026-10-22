<?php
// Expects: $pageTitle (string), $activeMenu (string). Optional: $pageActions (html string).
$cfg    = app_config();
$user   = current_user();
$menu   = require APP_ROOT . '/includes/menu.php';
$status = integration_status();
$title  = ($pageTitle ?? 'Dashboard') . ' | ' . $cfg['app_title'];
$activeMenu = $activeMenu ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="icon" type="image/png" href="<?= e(url('assets/img/favicon.png')) ?>">
<script src="<?= e(url('assets/js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(vendor_url('bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(vendor_url('bootstrap-icons/bootstrap-icons.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(url('modules/dashboard/index.php')) ?>">
      <span class="brand-mark"><img src="<?= e(url('assets/img/logo-emblem.png')) ?>" alt=""></span>
      <span class="brand-text"><strong><?= e($cfg['brand_name'] ?? $cfg['app_name']) ?></strong><small><?= e($cfg['brand_sub'] ?? $cfg['app_title']) ?></small></span>
    </a>
    <nav class="nav flex-column">
      <?php
      $lastGroup = null;
      foreach ($menu as $key => $item):
          if (!can($item['perm']) || !is_file(APP_ROOT . '/' . $item['path'])) {
              continue;
          }
          if (($item['group'] ?? null) !== $lastGroup) {
              $lastGroup = $item['group'] ?? null;
              if ($lastGroup) {
                  echo '<div class="nav-group">' . e($lastGroup) . '</div>';
              }
          }
      ?>
        <a class="nav-link <?= $activeMenu === $key ? 'active' : '' ?>" href="<?= e(url($item['path'])) ?>">
          <i class="bi bi-<?= e($item['icon']) ?>"></i> <?= e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="main">
    <header class="topbar">
      <button type="button" class="btn btn-outline-secondary d-lg-none" id="sidebarToggle" aria-label="Toggle navigation" aria-expanded="false"><i class="bi bi-list"></i></button>
      <h1 class="page-title"><?= e($pageTitle ?? 'Dashboard') ?></h1>
      <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
        <span class="badge rounded-pill text-bg-<?= e($status['class']) ?> integration-badge" title="<?= e($status['detail']) ?>">
          <i class="bi bi-<?= $status['state'] === 'connected' ? 'plug-fill' : 'plug' ?>"></i> <?= e($status['label']) ?>
        </span>
        <button type="button" class="btn btn-icon" data-theme-toggle aria-label="Switch between light and dark theme" title="Switch theme">
          <i class="bi bi-moon-stars when-light"></i><i class="bi bi-brightness-high when-dark"></i>
        </button>
        <?php if ($user): ?>
        <div class="dropdown">
          <button type="button" class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle"></i> <span class="d-none d-md-inline"><?= e($user['full_name']) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-muted"><?= e(role_label($user['role'])) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= e(url('logout.php')) ?>">
                <?= csrf_field() ?>
                <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button>
              </form>
            </li>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </header>
    <main class="content">
