<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('categories.manage');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$cat = db_row('SELECT * FROM categories WHERE id = ?', [$id]);
if (!$cat) {
    abort_page(404, 'Category not found.');
}
$f = ['name' => $cat['name'], 'description' => (string)$cat['description']];
$errors = [];
if (is_post()) {
    csrf_verify();
    $f['name'] = trim((string)($_POST['name'] ?? ''));
    $f['description'] = trim((string)($_POST['description'] ?? ''));
    if ($f['name'] === '' || mb_strlen($f['name']) > 80) {
        $errors['name'] = 'Category name is required (max 80 characters).';
    } elseif ((int)db_scalar('SELECT COUNT(*) FROM categories WHERE name = ? AND id <> ?', [$f['name'], $id]) > 0) {
        $errors['name'] = 'Another category already uses this name.';
    }
    if (mb_strlen($f['description']) > 255) {
        $errors['description'] = 'Description is too long (max 255 characters).';
    }
    if (!$errors) {
        try {
            db()->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?')->execute([$f['name'], $f['description'] ?: null, $id]);
            audit('category_updated', 'category', $id, changed_fields($cat, $f, ['name', 'description']));
            flash('success', 'Category updated.');
            redirect('modules/categories/index.php');
        } catch (PDOException $e) {
            app_log('category update failed: ' . $e->getMessage());
            $errors['name'] = is_duplicate_key($e) ? 'Another category already uses this name.' : 'The category could not be saved.';
        }
    }
}
$pageTitle = 'Edit category';
$activeMenu = 'categories';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<div class="card" style="max-width:700px"><div class="card-body">
<form method="post" class="row g-3 needs-validation" novalidate>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <?= form_input('name', 'Name', $f, $errors, ['required' => true, 'maxlength' => 80, 'col' => 'col-12']) ?>
  <?= form_input('description', 'Description', $f, $errors, ['maxlength' => 255, 'col' => 'col-12']) ?>
  <div class="col-12 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="bi bi-check-lg"></i> Save</button><a class="btn btn-light" href="index.php">Cancel</a></div>
</form></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
