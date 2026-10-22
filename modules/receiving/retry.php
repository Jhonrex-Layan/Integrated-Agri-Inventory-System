<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/integration_ops.php';
require_permission('receiving.accept');
if (!is_post()) {
    redirect('modules/receiving/index.php');
}
csrf_verify();
$id = (int)($_POST['receipt_id'] ?? 0);
$res = ack_receipt($id);
audit('ack_retry', 'stock_receipt', $id, $res['message']);
flash($res['ok'] ? 'success' : 'warning', $res['message']);
redirect('modules/receiving/view.php?id=' . $id);
