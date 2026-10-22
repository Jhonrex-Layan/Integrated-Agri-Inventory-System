<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('feedback.record');
if (!is_post()) {
    redirect('modules/suppliers/index.php');
}
csrf_verify();

$sid  = (int)($_POST['supplier_id'] ?? 0);
$type = (string)($_POST['note_type'] ?? 'general');
$note = trim((string)($_POST['note'] ?? ''));
$rating = (string)($_POST['rating'] ?? '');
$back = 'modules/suppliers/view.php?id=' . $sid . '#notes';

if (!db_row('SELECT id FROM suppliers WHERE id = ?', [$sid])) {
    flash('danger', 'Supplier not found.');
    redirect('modules/suppliers/index.php');
}
if (!in_array($type, ['contact', 'feedback', 'general'], true)) {
    $type = 'general';
}
if ($note === '' || mb_strlen($note) > 2000) {
    flash('danger', 'Enter a note (up to 2000 characters).');
    redirect($back);
}
$ratingVal = null;
if ($type === 'feedback' && $rating !== '') {
    if (!ctype_digit($rating) || (int)$rating < 1 || (int)$rating > 5) {
        flash('danger', 'Rating must be between 1 and 5.');
        redirect($back);
    }
    $ratingVal = (int)$rating;
}
db()->prepare('INSERT INTO supplier_notes (supplier_id, note_type, rating, note, created_by) VALUES (?,?,?,?,?)')
    ->execute([$sid, $type, $ratingVal, $note, current_user()['id']]);
audit('supplier_note_added', 'supplier', $sid, $type);
flash('success', 'Note recorded.');
redirect($back);
