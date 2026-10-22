<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('categories.manage');
if (!is_post()) {
    redirect('modules/categories/index.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$cat = db_row('SELECT * FROM categories WHERE id = ?', [$id]);
if (!$cat) {
    flash('danger', 'Category not found.');
    redirect('modules/categories/index.php');
}
$active = (int)db_scalar("SELECT COUNT(*) FROM products WHERE category_id = ? AND status <> 'archived'", [$id]);
$all = (int)db_scalar('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id]);

if ($action === 'archive') {
    if ($active > 0) {
        flash('danger', "Cannot archive: $active active product(s) still use this category. Move or archive them first.");
    } else {
        db()->prepare('UPDATE categories SET is_archived = 1 WHERE id = ?')->execute([$id]);
        audit('category_archived', 'category', $id, $cat['name']);
        flash('success', 'Category archived.');
    }
} elseif ($action === 'restore') {
    db()->prepare('UPDATE categories SET is_archived = 0 WHERE id = ?')->execute([$id]);
    audit('category_restored', 'category', $id, $cat['name']);
    flash('success', 'Category restored.');
} elseif ($action === 'delete') {
    if ($all > 0) {
        flash('danger', "Cannot delete: $all product record(s) reference this category. Archive it instead.");
    } else {
        try {
            db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            audit('category_deleted', 'category', $id, $cat['name']);
            flash('success', 'Category deleted.');
        } catch (PDOException $e) {
            app_log('category delete blocked: ' . $e->getMessage());
            flash('danger', 'Cannot delete: the category is still referenced by existing records.');
        }
    }
} else {
    flash('danger', 'Unknown action.');
}
redirect('modules/categories/index.php');
