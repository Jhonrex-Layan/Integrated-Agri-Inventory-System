<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
if (!is_post()) {
    redirect('modules/purchase_orders/index.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$need = ['submit' => 'po.create', 'send' => 'po.create', 'approve' => 'po.approve', 'reject' => 'po.approve', 'close' => 'po.approve'];
require_permission($need[$action] ?? 'po.view');          // cancel is checked below (preparer or approver)

$pdo = db();
$me = current_user();
$back = 'modules/purchase_orders/view.php?id=' . $id;
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $po = $st->fetch();
    if (!$po) {
        $pdo->rollBack();
        flash('danger', 'Purchase order not found.');
        redirect('modules/purchase_orders/index.php');
    }
    $status = $po['status'];
    $now = date('Y-m-d H:i:s');
    $fail = function (string $msg) use ($pdo, $back): void {
        $pdo->rollBack();
        flash('danger', $msg);
        redirect($back);
    };
    $itemCount = (int)db_scalar('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ?', [$id]);

    switch ($action) {
        case 'submit':
            if (!in_array($status, ['draft', 'rejected'], true)) { $fail('Only draft or rejected orders can be submitted.'); }
            if ($itemCount === 0) { $fail('Add at least one line before submitting.'); }
            $pdo->prepare("UPDATE purchase_orders SET status='pending_approval', rejection_reason=NULL, submitted_at=? WHERE id=?")->execute([$now, $id]);
            $msg = 'Submitted for approval.';
            break;
        case 'approve':
            if ($status !== 'pending_approval') { $fail('Only orders pending approval can be approved.'); }
            if ((int)$po['created_by'] === (int)$me['id'] && $me['role'] !== 'administrator') {
                $fail('You cannot approve an order you prepared. Ask another approving officer.');
            }
            // re-check that every ordered product is still approved and not archived
            $bad = (int)db_scalar("SELECT COUNT(*) FROM purchase_order_items poi JOIN supplier_products sp ON sp.id = poi.supplier_product_id
                                    WHERE poi.purchase_order_id = ? AND (sp.is_approved = 0 OR sp.is_archived = 1)", [$id]);
            if ($bad > 0) { $fail("$bad line(s) refer to catalog products that are no longer approved. Edit or reject the order."); }
            $pdo->prepare("UPDATE purchase_orders SET status='approved', approved_by=?, approved_at=? WHERE id=?")->execute([(int)$me['id'], $now, $id]);
            $msg = 'Approved. The order is now locked.';
            break;
        case 'reject':
            if ($status !== 'pending_approval') { $fail('Only orders pending approval can be rejected.'); }
            if ($reason === '') { $fail('A rejection reason is required.'); }
            $pdo->prepare("UPDATE purchase_orders SET status='rejected', rejection_reason=? WHERE id=?")->execute([mb_substr($reason, 0, 255), $id]);
            $msg = 'Rejected.';
            break;
        case 'send':
            if ($status !== 'approved') { $fail('Only approved orders can be marked as sent.'); }
            $pdo->prepare("UPDATE purchase_orders SET status='sent', sent_at=? WHERE id=?")->execute([$now, $id]);
            $msg = 'Marked as sent to the supplier.';
            break;
        case 'cancel':
            if (!can('po.create') && !can('po.approve')) { $fail('You do not have permission to cancel orders.'); }
            if (!in_array($status, ['draft', 'pending_approval', 'approved', 'sent'], true)) { $fail('This order can no longer be cancelled.'); }
            if ((int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE purchase_order_id = ? AND status <> 'cancelled'", [$id]) > 0) {
                $fail('Cancel or complete the existing deliveries first.');
            }
            $pdo->prepare("UPDATE purchase_orders SET status='cancelled', closed_note=? WHERE id=?")->execute([$reason !== '' ? mb_substr($reason, 0, 255) : 'Cancelled', $id]);
            $msg = 'Order cancelled.';
            break;
        case 'close':
            if ($status !== 'partially_received') { $fail('Only partially received orders can be closed short.'); }
            if ($reason === '') { $fail('A reason is required to close an order short.'); }
            if ((int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE purchase_order_id = ? AND status = 'confirmed'", [$id]) > 0) {
                $fail('A confirmed delivery is still waiting for the Inventory system to acknowledge it.');
            }
            $pdo->prepare("UPDATE purchase_orders SET status='completed', closed_note=? WHERE id=?")->execute(['Closed short: ' . mb_substr($reason, 0, 230), $id]);
            $msg = 'Order closed with the quantities received.';
            break;
        default:
            $fail('Unknown action.');
    }
    $pdo->commit();
    audit('po_' . $action, 'purchase_order', $id, $po['po_number'] . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : ''));
    flash('success', $msg);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_log('po action failed: ' . $e->getMessage());
    flash('danger', 'The action could not be completed.');
}
redirect($back);
