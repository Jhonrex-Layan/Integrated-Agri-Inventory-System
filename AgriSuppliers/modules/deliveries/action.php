<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('deliveries.manage');
if (!is_post()) {
    redirect('modules/deliveries/index.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$pdo = db();
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM deliveries WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) {
        $pdo->rollBack();
        flash('danger', 'Delivery not found.');
        redirect('modules/deliveries/index.php');
    }
    if ($action !== 'cancel') {
        $pdo->rollBack();
        flash('danger', 'Unknown action.');
    } elseif (!in_array($d['status'], ['expected', 'confirmed'], true)) {
        $pdo->rollBack();
        flash('danger', 'Only expected or not-yet-acknowledged deliveries can be cancelled.');
    } elseif ($reason === '') {
        $pdo->rollBack();
        flash('danger', 'A cancellation reason is required.');
    } else {
        $pdo->prepare("UPDATE deliveries SET status='cancelled', notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?")
            ->execute([($d['notes'] ? "\n" : '') . 'Cancelled: ' . mb_substr($reason, 0, 200), $id]);
        $pdo->commit();
        audit('delivery_cancelled', 'delivery', $id, $d['delivery_ref'] . ': ' . $reason);
        flash('success', 'Delivery cancelled.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_log('delivery action failed: ' . $e->getMessage());
    flash('danger', 'The action could not be completed.');
}
redirect('modules/deliveries/view.php?id=' . $id);
