<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('sales.void');
if (!is_post()) {
    redirect('modules/sales/index.php');
}
csrf_verify();
$id = (int)($_POST['id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$pdo = db();
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s || $s['status'] !== 'completed' || (string)($_POST['action'] ?? '') !== 'void' || $reason === '') {
        $pdo->rollBack();
        flash('danger', 'The sale cannot be voided (already voided, not found, or no reason given).');
    } else {
        $uid = (int)current_user()['id'];
        foreach (db_all('SELECT product_id, quantity FROM sale_items WHERE sale_id = ? ORDER BY product_id', [$id]) as $i) {
            stock_apply($pdo, (int)$i['product_id'], 'sale_void', (float)$i['quantity'], 'Void of ' . $s['sale_no'] . ': ' . mb_substr($reason, 0, 150), 'sale', $id, $uid);
        }
        $pdo->prepare("UPDATE sales SET status = 'voided', void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ?")->execute([mb_substr($reason, 0, 255), $uid, date('Y-m-d H:i:s'), $id]);
        $pdo->commit();
        audit('sale_voided', 'sale', $id, $s['sale_no'] . ': ' . $reason);
        flash('success', 'Sale voided and stock restored.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    app_log('void failed: ' . $e->getMessage());
    flash('danger', 'The sale could not be voided.');
}
redirect('modules/sales/view.php?id=' . $id);
