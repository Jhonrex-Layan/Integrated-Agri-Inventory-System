<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('products.manage');
if (!is_post()) {
    redirect('modules/products/index.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$prod = db_row('SELECT * FROM products WHERE id = ?', [$id]);
if (!$prod) {
    flash('danger', 'Product not found.');
    redirect('modules/products/index.php');
}
if ($action === 'archive') {
    if ((float)$prod['quantity'] > 0) {
        flash('danger', 'Cannot archive: ' . fmt_qty($prod['quantity']) . ' ' . $prod['unit'] . ' still in stock. Bring stock to zero first (stock-out, adjustment or sale).');
    } else {
        db()->prepare("UPDATE products SET status = 'archived' WHERE id = ?")->execute([$id]);
        audit('product_archived', 'product', $id, $prod['product_code']);
        flash('success', 'Product archived. Sales and movement history are preserved.');
    }
} elseif ($action === 'restore') {
    $cat = db_row('SELECT is_archived FROM categories WHERE id = ?', [(int)$prod['category_id']]);
    if ($cat && $cat['is_archived']) {
        flash('danger', 'Restore the product\'s category first.');
    } else {
        db()->prepare("UPDATE products SET status = 'inactive' WHERE id = ? AND status = 'archived'")->execute([$id]);
        audit('product_restored', 'product', $id, $prod['product_code']);
        flash('success', 'Product restored as inactive. Edit it to set it active again.');
    }
} else {
    flash('danger', 'Unknown action.');
}
redirect('modules/products/index.php');
