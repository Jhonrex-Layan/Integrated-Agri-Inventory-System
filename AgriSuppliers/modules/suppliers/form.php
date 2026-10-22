<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('suppliers.manage');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$sup = null;
if ($id > 0) {
    $sup = db_row('SELECT * FROM suppliers WHERE id = ?', [$id]);
    if (!$sup) {
        abort_page(404, 'Supplier not found.');
    }
    if ($sup['status'] === 'archived') {
        flash('warning', 'Archived suppliers cannot be edited. Restore the supplier first.');
        redirect('modules/suppliers/view.php?id=' . $id);
    }
}
$keys = ['business_name', 'contact_person', 'telephone', 'email', 'address', 'registration_no', 'tax_id', 'status'];
$f = [];
foreach ($keys as $k) {
    $f[$k] = $sup[$k] ?? ($k === 'status' ? 'active' : '');
}
$errors = [];

if (is_post()) {
    csrf_verify();
    foreach ($keys as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if ($f['business_name'] === '' || mb_strlen($f['business_name']) > 150) {
        $errors['business_name'] = 'Business name is required (max 150 characters).';
    }
    if ($f['contact_person'] === '' || mb_strlen($f['contact_person']) > 120) {
        $errors['contact_person'] = 'Contact person is required (max 120 characters).';
    }
    if (!preg_match('/^[0-9+()\-\s.]{5,40}$/', $f['telephone'])) {
        $errors['telephone'] = 'Enter a valid telephone number (digits, spaces, + ( ) - .).';
    }
    if ($f['email'] !== '' && (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || strlen($f['email']) > 150)) {
        $errors['email'] = 'Enter a valid email address or leave it blank.';
    }
    if ($f['address'] === '' || mb_strlen($f['address']) > 255) {
        $errors['address'] = 'Address is required (max 255 characters).';
    }
    foreach (['registration_no', 'tax_id'] as $k) {
        if (mb_strlen($f[$k]) > 60) {
            $errors[$k] = 'Maximum 60 characters.';
        }
    }
    if (!in_array($f['status'], ['active', 'inactive'], true)) {
        $errors['status'] = 'Choose Active or Inactive.';
    }
    if (!$errors && (int)db_scalar('SELECT COUNT(*) FROM suppliers WHERE business_name = ? AND id <> ?', [$f['business_name'], $id]) > 0) {
        $errors['business_name'] = 'A supplier with this business name already exists.';
    }
    if (!$errors && $f['registration_no'] !== ''
        && (int)db_scalar('SELECT COUNT(*) FROM suppliers WHERE registration_no = ? AND id <> ?', [$f['registration_no'], $id]) > 0) {
        $errors['registration_no'] = 'This registration number is already recorded for another supplier.';
    }
    if (!$errors) {
        $vals = [$f['business_name'], $f['contact_person'], $f['telephone'], $f['email'] ?: null, $f['address'],
                 $f['registration_no'] ?: null, $f['tax_id'] ?: null, $f['status']];
        $pdo = db();
        try {
            if ($sup) {
                $pdo->prepare('UPDATE suppliers SET business_name=?, contact_person=?, telephone=?, email=?, address=?, registration_no=?, tax_id=?, status=? WHERE id=?')
                    ->execute([...$vals, $id]);
                audit('supplier_updated', 'supplier', $id, changed_fields($sup, $f, $keys));
                flash('success', 'Supplier updated.');
                redirect('modules/suppliers/view.php?id=' . $id);
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO suppliers (supplier_code, business_name, contact_person, telephone, email, address, registration_no, tax_id, status, created_by)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute(['TMP' . bin2hex(random_bytes(6)), ...$vals, current_user()['id']]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE suppliers SET supplier_code = ? WHERE id = ?')->execute([sprintf('SUP-%04d', $newId), $newId]);
            $pdo->commit();
            audit('supplier_created', 'supplier', $newId, $f['business_name']);
            flash('success', 'Supplier registered as ' . sprintf('SUP-%04d', $newId) . '.');
            redirect('modules/suppliers/view.php?id=' . $newId);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('supplier save failed: ' . $e->getMessage());
            $errors['_form'] = 'The supplier could not be saved. Details were written to the log.';
        }
    }
}

$pageTitle = $sup ? 'Edit supplier' : 'Register supplier';
$activeMenu = 'suppliers';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<div class="card" style="max-width:900px"><div class="card-body">
<?php if ($sup): ?><p class="text-muted">Supplier code <strong><?= e($sup['supplier_code']) ?></strong> (assigned automatically, cannot be changed)</p><?php endif; ?>
<form method="post" class="row g-3 needs-validation" novalidate>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <?= form_input('business_name', 'Business name', $f, $errors, ['required' => true, 'maxlength' => 150]) ?>
  <?= form_input('contact_person', 'Contact person', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
  <?= form_input('telephone', 'Telephone', $f, $errors, ['required' => true, 'maxlength' => 40]) ?>
  <?= form_input('email', 'Email', $f, $errors, ['type' => 'email', 'maxlength' => 150]) ?>
  <?= form_input('address', 'Address', $f, $errors, ['required' => true, 'maxlength' => 255, 'col' => 'col-12']) ?>
  <?= form_input('registration_no', 'Business registration no.', $f, $errors, ['maxlength' => 60]) ?>
  <?= form_input('tax_id', 'Tax ID', $f, $errors, ['maxlength' => 60]) ?>
  <?= form_select('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive'], $f['status'], $errors, ['required' => true, 'help' => 'Use "Archive" on the supplier list to retire a supplier.']) ?>
  <div class="col-12 d-flex gap-2">
    <button class="btn btn-success" type="submit"><i class="bi bi-check-lg"></i> Save supplier</button>
    <a class="btn btn-light" href="<?= $sup ? 'view.php?id=' . (int)$id : 'index.php' ?>">Cancel</a>
  </div>
</form></div></div>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
