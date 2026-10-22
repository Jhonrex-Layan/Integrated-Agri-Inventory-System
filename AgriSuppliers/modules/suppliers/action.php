<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('suppliers.manage');
if (!is_post()) {
    redirect('modules/suppliers/index.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$sup = db_row('SELECT * FROM suppliers WHERE id = ?', [$id]);
if (!$sup) {
    flash('danger', 'Supplier not found.');
    redirect('modules/suppliers/index.php');
}
if ($action === 'archive') {
    if ($sup['status'] === 'archived') {
        flash('info', 'Supplier is already archived.');
    } else {
        $open = (int)db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status IN ('pending_approval','approved','sent','partially_received')", [$id]);
        if ($open > 0) {
            flash('danger', "Cannot archive: $open purchase order(s) are still open. Complete or cancel them first.");
        } else {
            db()->prepare("UPDATE suppliers SET status = 'archived' WHERE id = ?")->execute([$id]);
            audit('supplier_archived', 'supplier', $id, $sup['business_name']);
            flash('success', 'Supplier archived. Its ID remains reserved and is never reused.');
        }
    }
} elseif ($action === 'restore') {
    if ($sup['status'] === 'archived') {
        db()->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?")->execute([$id]);
        audit('supplier_restored', 'supplier', $id, $sup['business_name']);
        flash('success', 'Supplier restored as inactive. Edit it to set it active again.');
    }
} else {
    flash('danger', 'Unknown action.');
}
redirect('modules/suppliers/index.php');
