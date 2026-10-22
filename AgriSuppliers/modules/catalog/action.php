<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
if (!is_post()) {
    redirect('modules/catalog/index.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$needs = in_array($action, ['approve', 'unapprove'], true) ? 'catalog.approve' : 'catalog.manage';
require_permission($needs);

$item = db_row('SELECT sp.*, s.status AS supplier_status FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE sp.id = ?', [$id]);
if (!$item) {
    flash('danger', 'Catalog product not found.');
    redirect('modules/catalog/index.php');
}
$pdo = db();
switch ($action) {
    case 'approve':
        if ($item['is_archived'] || $item['supplier_status'] === 'archived') {
            flash('danger', 'Archived products or products of archived suppliers cannot be approved.');
        } else {
            $pdo->prepare('UPDATE supplier_products SET is_approved = 1 WHERE id = ?')->execute([$id]);
            audit('catalog_approved', 'supplier_product', $id, $item['supplier_sku'] . ' at ' . $item['current_price']);
            flash('success', 'Product approved and published to the Inventory system.');
        }
        break;
    case 'unapprove':
        $pdo->prepare('UPDATE supplier_products SET is_approved = 0 WHERE id = ?')->execute([$id]);
        audit('catalog_unapproved', 'supplier_product', $id, $item['supplier_sku']);
        flash('success', 'Approval withdrawn. The product is no longer published.');
        break;
    case 'archive':
        $refs = (int)db_scalar("SELECT COUNT(*) FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
                                 WHERE poi.supplier_product_id = ? AND po.status IN ('draft','pending_approval','approved','sent','partially_received')", [$id]);
        if ($refs > 0) {
            flash('danger', "Cannot archive: it is on $refs open purchase order(s).");
        } else {
            $pdo->prepare('UPDATE supplier_products SET is_archived = 1, is_approved = 0 WHERE id = ?')->execute([$id]);
            audit('catalog_archived', 'supplier_product', $id, $item['supplier_sku']);
            flash('success', 'Catalog product archived.');
        }
        break;
    case 'restore':
        if ($item['supplier_status'] === 'archived') {
            flash('danger', 'Restore the supplier first.');
        } else {
            $pdo->prepare('UPDATE supplier_products SET is_archived = 0, is_approved = 0 WHERE id = ?')->execute([$id]);
            audit('catalog_restored', 'supplier_product', $id, $item['supplier_sku']);
            flash('success', 'Product restored. It needs approval before it is published.');
        }
        break;
    default:
        flash('danger', 'Unknown action.');
}
redirect('modules/catalog/index.php');
