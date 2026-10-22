<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('refunds.manage');
if (!is_post()) {
    redirect('modules/refunds/index.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$decision = (string)($_POST['decision'] ?? '');
$note = trim((string)($_POST['note'] ?? ''));
if (in_array($decision, ['approved', 'rejected', 'refunded'], true)) {
    require_permission('refunds.decide');
}
$allowed = [                       // current status => decisions that may follow
    'received'     => ['under_review', 'approved', 'rejected'],
    'under_review' => ['approved', 'rejected'],
    'approved'     => ['refunded'],
];
$pdo = db();
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM refund_requests WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        $pdo->rollBack();
        flash('danger', 'Refund request not found.');
    } elseif (!in_array($decision, $allowed[$r['status']] ?? [], true)) {
        $pdo->rollBack();
        flash('danger', 'That decision is not possible from the current status (' . role_label($r['status']) . ').');
    } elseif ($decision === 'rejected' && $note === '') {
        $pdo->rollBack();
        flash('danger', 'Enter a note explaining the rejection.');
    } else {
        $pdo->prepare('UPDATE refund_requests SET status = ?, response_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?')
            ->execute([$decision, $note !== '' ? mb_substr($note, 0, 255) : $r['response_note'], (int)current_user()['id'], date('Y-m-d H:i:s'), $id]);
        $pdo->commit();
        audit('refund_' . $decision, 'refund_request', $id, $r['request_ref'] . ($note !== '' ? ': ' . $note : ''));
        flash('success', 'Refund request updated to ' . role_label($decision) . '. The Inventory system will see this on its next sync.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_log('refund decision failed: ' . $e->getMessage());
    flash('danger', 'The decision could not be saved.');
}
redirect('modules/refunds/index.php');
