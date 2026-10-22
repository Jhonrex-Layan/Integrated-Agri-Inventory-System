<?php
// Purchase-order helpers (Supplier system).
declare(strict_types=1);

const PO_OPEN_STATUSES = ['pending_approval', 'approved', 'sent', 'partially_received'];
const PO_DELIVERABLE   = ['approved', 'sent', 'partially_received'];

/** Quantity of a PO line that can still be put on a NEW confirmed delivery. */
function po_line_available(int $poItemId): float
{
    $row = db_row('SELECT qty_ordered, qty_received FROM purchase_order_items WHERE id = ?', [$poItemId]);
    if (!$row) {
        return 0.0;
    }
    // accepted-in-waiting: confirmed deliveries that Inventory has not acknowledged yet
    $pending = (float)db_scalar("SELECT COALESCE(SUM(di.qty_delivered - di.qty_rejected), 0)
                                   FROM delivery_items di JOIN deliveries d ON d.id = di.delivery_id
                                  WHERE di.po_item_id = ? AND d.status = 'confirmed'", [$poItemId]);
    return max(0.0, (float)$row['qty_ordered'] - (float)$row['qty_received'] - $pending);
}

/** Recompute the PO status after received quantities changed. Call inside the transaction that changed them. */
function po_refresh_status(PDO $pdo, int $poId): string
{
    $st = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = ? FOR UPDATE');
    $st->execute([$poId]);
    $status = (string)$st->fetchColumn();
    if (!in_array($status, PO_DELIVERABLE, true)) {
        return $status;
    }
    $st = $pdo->prepare('SELECT COALESCE(SUM(qty_ordered),0) AS o, COALESCE(SUM(qty_received),0) AS r, COALESCE(SUM(qty_received >= qty_ordered),0) AS done, COUNT(*) AS n
                           FROM purchase_order_items WHERE purchase_order_id = ?');
    $st->execute([$poId]);
    $t = $st->fetch();
    $new = $status;
    if ((int)$t['n'] > 0 && (int)$t['done'] === (int)$t['n']) {
        $new = 'completed';
    } elseif ((float)$t['r'] > 0) {
        $new = 'partially_received';
    }
    if ($new !== $status) {
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$new, $poId]);
    }
    return $new;
}

function po_recalc_total(PDO $pdo, int $poId): void
{
    $pdo->prepare('UPDATE purchase_orders SET total_amount = (SELECT COALESCE(SUM(line_total),0) FROM purchase_order_items WHERE purchase_order_id = ?) WHERE id = ?')
        ->execute([$poId, $poId]);
}

function po_number_for(int $id): string
{
    return sprintf('PO-%s-%05d', date('Y'), $id);
}
