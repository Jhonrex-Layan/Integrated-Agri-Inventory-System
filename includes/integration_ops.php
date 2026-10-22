<?php
// Operations that talk to the Supplier system and must be safe to repeat: acknowledgments and refund requests.
declare(strict_types=1);
require_once __DIR__ . '/SupplierApiClient.php';

/**
 * Send (or re-send) the acknowledgment for an accepted/rejected receipt.
 * Idempotent on both sides: the receipt_ref is the Idempotency-Key and the Supplier API recognises repeats.
 * @return array{ok:bool,message:string}
 */
function ack_receipt(int $receiptId): array
{
    $r = db_row('SELECT * FROM stock_receipts WHERE id = ?', [$receiptId]);
    if (!$r) {
        return ['ok' => false, 'message' => 'Receipt not found.'];
    }
    if ($r['ack_status'] === 'sent') {
        return ['ok' => true, 'message' => 'Already acknowledged.'];
    }
    $client = SupplierApiClient::load();
    if (!$client) {
        db()->prepare("UPDATE stock_receipts SET ack_status = 'pending', ack_last_error = ? WHERE id = ?")->execute(['API not configured.', $receiptId]);
        return ['ok' => false, 'message' => 'The Supplier API is not configured, so the acknowledgment is queued.'];
    }
    $items = [];
    foreach (db_all('SELECT external_delivery_item_id, qty_accepted FROM stock_receipt_items WHERE receipt_id = ? ORDER BY id', [$receiptId]) as $i) {
        $items[] = ['delivery_item_id' => (int)$i['external_delivery_item_id'], 'qty_accepted' => (float)$i['qty_accepted']];
    }
    $res = $client->acknowledge((int)$r['external_delivery_id'], (string)$r['receipt_ref'], $items);
    $attempts = (int)$r['ack_attempts'] + 1;
    if ($res['ok']) {
        db()->prepare("UPDATE stock_receipts SET ack_status = 'sent', ack_attempts = ?, ack_last_error = NULL, ack_sent_at = ? WHERE id = ?")
            ->execute([$attempts, date('Y-m-d H:i:s'), $receiptId]);
        db()->prepare("UPDATE api_sync_logs SET status = 'resolved' WHERE receipt_id = ? AND status IN ('failed','pending_retry')")->execute([$receiptId]);
        return ['ok' => true, 'message' => 'Acknowledged by the Supplier system.'];
    }
    $transient = SupplierApiClient::isTransient($res);
    db()->prepare('UPDATE stock_receipts SET ack_status = ?, ack_attempts = ?, ack_last_error = ? WHERE id = ?')
        ->execute([$transient ? 'pending' : 'failed', $attempts, mb_substr((string)$res['error'], 0, 255), $receiptId]);
    db()->prepare("INSERT INTO api_sync_logs (direction, method, endpoint, http_status, status, idempotency_key, attempts, error_message, receipt_id) VALUES ('push','POST',?,?,?,?,?,?,?)")
        ->execute(['deliveries/' . (int)$r['external_delivery_id'] . '/acknowledge', $res['status'], $transient ? 'pending_retry' : 'failed', $r['receipt_ref'], $attempts, mb_substr((string)$res['error'], 0, 255), $receiptId]);
    return ['ok' => false, 'message' => ($transient ? 'The Supplier system could not be reached; the acknowledgment will be retried. ' : 'The Supplier system rejected the acknowledgment: ') . $res['error']];
}

/** @return array{ok:bool,message:string} */
function submit_refund(int $refundId): array
{
    $r = db_row('SELECT rq.*, dr.quantity, dr.unit_cost, dr.reason, p.name FROM refund_requests rq JOIN damage_reports dr ON dr.id = rq.damage_report_id JOIN products p ON p.id = dr.product_id WHERE rq.id = ?', [$refundId]);
    if (!$r) {
        return ['ok' => false, 'message' => 'Refund request not found.'];
    }
    if ($r['submit_status'] === 'submitted') {
        return ['ok' => true, 'message' => 'Already submitted.'];
    }
    $client = SupplierApiClient::load();
    if (!$client) {
        return ['ok' => false, 'message' => 'The Supplier API is not configured; the request stays queued locally.'];
    }
    $res = $client->submitRefund([
        'request_ref' => $r['request_ref'], 'supplier_id' => (int)$r['external_supplier_id'],
        'supplier_product_id' => $r['external_supplier_product_id'] !== null ? (int)$r['external_supplier_product_id'] : null,
        'product_name' => $r['name'], 'quantity' => (float)$r['quantity'], 'unit_cost' => (float)$r['unit_cost'], 'reason' => $r['reason'],
    ]);
    $attempts = (int)$r['submit_attempts'] + 1;
    if ($res['ok']) {
        $remote = (string)($res['data']['data']['status'] ?? 'received');
        db()->prepare("UPDATE refund_requests SET submit_status = 'submitted', submit_attempts = ?, last_error = NULL, remote_status = ?, last_synced_at = ? WHERE id = ?")
            ->execute([$attempts, $remote, date('Y-m-d H:i:s'), $refundId]);
        return ['ok' => true, 'message' => 'Refund request sent to the supplier.'];
    }
    $transient = SupplierApiClient::isTransient($res);
    db()->prepare('UPDATE refund_requests SET submit_status = ?, submit_attempts = ?, last_error = ? WHERE id = ?')
        ->execute([$transient ? 'pending' : 'failed', $attempts, mb_substr((string)$res['error'], 0, 255), $refundId]);
    return ['ok' => false, 'message' => ($transient ? 'Supplier system unreachable; the request is queued and can be resent. ' : 'Supplier system rejected the request: ') . $res['error']];
}

/** Pull the supplier's decisions for open refund requests. @return array{ok:bool,message:string,updated:int} */
function sync_refunds(): array
{
    $open = db_all("SELECT id, request_ref, damage_report_id FROM refund_requests WHERE submit_status = 'submitted' AND (remote_status IS NULL OR remote_status NOT IN ('rejected','refunded')) LIMIT 100");
    if (!$open) {
        return ['ok' => true, 'message' => 'No open refund requests to sync.', 'updated' => 0];
    }
    $client = SupplierApiClient::load();
    if (!$client) {
        return ['ok' => false, 'message' => 'The Supplier API is not configured.', 'updated' => 0];
    }
    $res = $client->refundStatuses(array_column($open, 'request_ref'));
    if (!$res['ok']) {
        return ['ok' => false, 'message' => 'Could not sync refund decisions: ' . $res['error'], 'updated' => 0];
    }
    $by = [];
    foreach (($res['data']['data'] ?? []) as $x) {
        $by[$x['request_ref']] = $x;
    }
    $n = 0;
    foreach ($open as $o) {
        $x = $by[$o['request_ref']] ?? null;
        if (!$x) {
            continue;
        }
        db()->prepare('UPDATE refund_requests SET remote_status = ?, remote_note = ?, last_synced_at = ? WHERE id = ?')
            ->execute([$x['status'], $x['response_note'], date('Y-m-d H:i:s'), (int)$o['id']]);
        if ($x['status'] === 'refunded') {
            db()->prepare("UPDATE damage_reports SET status = 'resolved' WHERE id = ?")->execute([(int)$o['damage_report_id']]);
        }
        $n++;
    }
    return ['ok' => true, 'message' => "Synced $n refund request(s).", 'updated' => $n];
}
