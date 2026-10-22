<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('adjustments.review');
if (!is_post()) {
    redirect('modules/inventory/index.php');
}
csrf_verify();
$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare("UPDATE stock_movements SET review_status = 'reviewed', reviewed_by = ?, reviewed_at = ? WHERE id = ? AND review_status = 'pending'");
$st->execute([(int)current_user()['id'], date('Y-m-d H:i:s'), $id]);
if ($st->rowCount() > 0) {
    audit('adjustment_reviewed', 'stock_movement', $id);
    flash('success', 'Adjustment marked as reviewed.');
} else {
    flash('info', 'That adjustment is not awaiting review.');
}
redirect('modules/inventory/index.php?review=pending');
