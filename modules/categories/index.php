<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('categories.manage');

$errors = [];
$new = ['name' => '', 'description' => ''];
if (is_post()) {
    csrf_verify();
    $new['name'] = trim((string)($_POST['name'] ?? ''));
    $new['description'] = trim((string)($_POST['description'] ?? ''));
    if ($new['name'] === '' || mb_strlen($new['name']) > 80) {
        $errors['name'] = 'Category name is required (max 80 characters).';
    } elseif ((int)db_scalar('SELECT COUNT(*) FROM categories WHERE name = ?', [$new['name']]) > 0) {
        $errors['name'] = 'A category with this name already exists.';
    }
    if (mb_strlen($new['description']) > 255) {
        $errors['description'] = 'Description is too long (max 255 characters).';
    }
    if (!$errors) {
        try {
            db()->prepare('INSERT INTO categories (name, description) VALUES (?, ?)')->execute([$new['name'], $new['description'] ?: null]);
            audit('category_created', 'category', (int)db()->lastInsertId(), $new['name']);
            flash('success', 'Category added.');
            redirect('modules/categories/index.php');
        } catch (PDOException $e) {
            app_log('category insert failed: ' . $e->getMessage());
            $errors['name'] = is_duplicate_key($e) ? 'A category with this name already exists.' : 'The category could not be saved.';
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$showArchived = ($_GET['archived'] ?? '') === '1';
$where = [$showArchived ? 'c.is_archived = 1' : 'c.is_archived = 0'];
$p = [];
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.description LIKE ?)';
    $l = like_param($q);
    array_push($p, $l, $l);
}
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM categories c WHERE $W", $p));
$rows = db_all("SELECT c.*, (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id AND pr.status <> 'archived') AS active_products,
                       (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id) AS all_products
                  FROM categories c WHERE $W ORDER BY c.name LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);

$pageTitle = 'Categories';
$activeMenu = 'categories';
require APP_ROOT . '/includes/layout_header.php';
?>
<div class="card mb-3"><div class="card-body">
  <form method="post" class="row g-2 align-items-start needs-validation" novalidate>
    <?= csrf_field() ?>
    <?= form_input('name', 'New category', $new, $errors, ['required' => true, 'maxlength' => 80, 'col' => 'col-md-4']) ?>
    <?= form_input('description', 'Description', $new, $errors, ['maxlength' => 255, 'col' => 'col-md-6']) ?>
    <div class="col-md-2"><label class="form-label d-none d-md-block">&nbsp;</label><button class="btn btn-success w-100" type="submit"><i class="bi bi-plus-lg"></i> Add</button></div>
  </form></div></div>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search categories"></div>
  <div class="col-auto form-check pt-2"><input class="form-check-input" type="checkbox" name="archived" value="1" id="arch" <?= $showArchived ? 'checked' : '' ?>><label class="form-check-label" for="arch">Archived</label></div>
  <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Name</th><th>Description</th><th class="text-center">Products</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $c): $cid = (int)$c['id']; ?>
    <tr><td><?= e($c['name']) ?></td><td><?= e($c['description'] ?? '') ?></td><td class="text-center"><?= (int)$c['active_products'] ?></td>
      <td class="text-end text-nowrap">
      <?php if ($c['is_archived']): ?>
        <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="action" value="restore">
          <button class="btn btn-sm btn-outline-success" data-confirm="Restore this category?"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-success" href="form.php?id=<?= $cid ?>"><i class="bi bi-pencil"></i> Edit</a>
        <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="action" value="archive">
          <button class="btn btn-sm btn-outline-warning" data-confirm="Archive category <?= e($c['name']) ?>?"><i class="bi bi-archive"></i></button></form>
      <?php endif; ?>
      <?php if ((int)$c['all_products'] === 0): ?>
        <form class="d-inline" method="post" action="action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="action" value="delete">
          <button class="btn btn-sm btn-outline-danger" data-confirm="Permanently delete category <?= e($c['name']) ?>? This cannot be undone."><i class="bi bi-trash"></i></button></form>
      <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="text-muted p-3">No categories found.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">Delete is offered only for categories that no product has ever used. Others can be archived once they have no active products.</p>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
