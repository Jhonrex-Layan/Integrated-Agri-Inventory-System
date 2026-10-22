<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    csrf_verify();
    $u = current_user();
    if ($u) {
        audit('logout', 'user', (int)$u['id']);
    }
    logout_user();
    flash('success', 'You have been signed out.');
}
redirect('login.php');
