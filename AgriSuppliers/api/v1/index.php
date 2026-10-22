<?php
/**
 * AgriSuppliers REST API v1  -  see docs/API.md
 *
 * Auth     Authorization: Bearer <key>   (keys are stored only as SHA-256 hashes)
 * Scopes   read     GET suppliers, products, purchase orders, deliveries
 *          receive  POST deliveries/{id}/acknowledge, POST purchase-orders/{id}/receive
 *          refund   POST/GET refund-requests
 * Success  {"data": ..., "meta": {...}}      Error  {"error": {"code": "...", "message": "..."}}
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('API_MODE', true);
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/po.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

final class ApiError extends Exception
{
    public function __construct(public int $status, public string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

/** @var array{id:int,scopes:string[]}|null $GLOBALS['api_client'] */
$GLOBALS['api_client'] = null;
$GLOBALS['api_idem'] = null;

function api_log(int $status, string $outcome, ?string $message = null): void
{
    try {
        $uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        db()->prepare('INSERT INTO api_sync_logs (client_id, method, endpoint, http_status, idempotency_key, outcome, message, ip_address) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$GLOBALS['api_client']['id'] ?? null, substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 8), substr($uri, 0, 200), $status,
                       $GLOBALS['api_idem'], $outcome, $message !== null ? substr($message, 0, 255) : null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) {
        error_log('[api] log failed: ' . $e->getMessage());
    }
}

function api_out(int $status, array $body, string $outcome = 'success', ?string $message = null, bool $log = true): void
{
    if ($log) {
        api_log($status, $outcome, $message);
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function api_ok(mixed $data, array $meta = [], int $status = 200, string $outcome = 'success'): void
{
    api_out($status, $meta ? ['data' => $data, 'meta' => $meta] : ['data' => $data], $outcome);
}

function bearer_key(): string
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower((string)$k) === 'authorization') {
                $h = (string)$v;
            }
        }
    }
    return preg_match('/^Bearer\s+([A-Za-z0-9._~-]{16,200})$/i', $h, $m) ? $m[1] : '';
}

function authenticate(): void
{
    $key = bearer_key();
    if ($key === '') {
        throw new ApiError(401, 'unauthorized', 'A Bearer API key is required.');
    }
    $hash = hash('sha256', $key);
    $client = null;
    foreach (db_all('SELECT id, key_hash, scopes, rate_limit_per_min FROM api_clients WHERE key_prefix = ? AND is_active = 1', [substr($key, 0, 8)]) as $c) {
        if (hash_equals((string)$c['key_hash'], $hash)) {
            $client = $c;
            break;
        }
    }
    if (!$client) {
        throw new ApiError(401, 'invalid_api_key', 'The API key is invalid or inactive.');
    }
    $GLOBALS['api_client'] = ['id' => (int)$client['id'], 'scopes' => array_filter(array_map('trim', explode(',', (string)$client['scopes'])))];
    $cid = (int)$client['id'];
    $pdo = db();
    $pdo->prepare('UPDATE api_clients SET last_used_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $cid]);
    // rate limit: fixed one-minute window per client
    $window = date('Y-m-d H:i:00');
    $pdo->prepare('INSERT INTO api_rate_limits (client_id, window_start, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$cid, $window]);
    $hits = (int)db_scalar('SELECT hits FROM api_rate_limits WHERE client_id = ? AND window_start = ?', [$cid, $window]);
    if (random_int(1, 100) === 1) {
        $pdo->prepare('DELETE FROM api_rate_limits WHERE window_start < ?')->execute([date('Y-m-d H:i:00', time() - 3600)]);
    }
    if ($hits > max(1, (int)$client['rate_limit_per_min'])) {
        header('Retry-After: ' . (60 - (int)date('s')));
        throw new ApiError(429, 'rate_limit_exceeded', 'Too many requests. Try again in the next minute.');
    }
}

function need_scope(string $scope): void
{
    if (!in_array($scope, $GLOBALS['api_client']['scopes'] ?? [], true)) {
        throw new ApiError(403, 'insufficient_scope', "This API key does not have the '$scope' scope.");
    }
}

function paging(): array
{
    $per = min(200, max(1, (int)($_GET['per_page'] ?? 25)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    return [$page, $per, ($page - 1) * $per];
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $b = json_decode((string)$raw, true);
    if (!is_array($b)) {
        throw new ApiError(400, 'invalid_json', 'The request body must be a JSON object.');
    }
    return $b;
}

function num(mixed $v, string $field, bool $positive = false): float
{
    if (!is_numeric($v) || (float)$v < 0 || ($positive && (float)$v <= 0)) {
        throw new ApiError(422, 'invalid_field', "'$field' must be a " . ($positive ? 'positive' : 'non-negative') . ' number.');
    }
    return round((float)$v, 2);
}

// ---------------------------------------------------------------- read endpoints
function supplier_row(array $s, bool $detail = false): array
{
    $o = ['id' => (int)$s['id'], 'supplier_code' => $s['supplier_code'], 'business_name' => $s['business_name'], 'contact_person' => $s['contact_person'],
          'telephone' => $s['telephone'], 'email' => $s['email'], 'status' => $s['status']];
    if ($detail) {
        $o['address'] = $s['address'];
    }
    return $o;
}

function product_row(array $p): array
{
    return ['id' => (int)$p['id'], 'supplier_id' => (int)$p['supplier_id'], 'supplier_sku' => $p['supplier_sku'], 'product_name' => $p['product_name'],
            'description' => $p['description'], 'category_hint' => $p['category_hint'], 'unit' => $p['unit'], 'current_price' => (float)$p['current_price'],
            'min_order_qty' => (float)$p['min_order_qty'], 'availability' => $p['availability']];
}

function ep_suppliers_list(): void
{
    need_scope('read');
    [$page, $per, $off] = paging();
    $where = ['1=1'];
    $p = [];
    $status = (string)($_GET['status'] ?? 'active');
    if (in_array($status, ['active', 'inactive', 'archived'], true)) {
        $where[] = 'status = ?';
        $p[] = $status;
    } elseif ($status !== 'all') {
        throw new ApiError(422, 'invalid_status', 'status must be active, inactive, archived or all.');
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(business_name LIKE ? OR supplier_code LIKE ? OR contact_person LIKE ?)';
        $l = like_param_api($q);
        array_push($p, $l, $l, $l);
    }
    $W = implode(' AND ', $where);
    $total = (int)db_scalar("SELECT COUNT(*) FROM suppliers WHERE $W", $p);
    $rows = db_all("SELECT * FROM suppliers WHERE $W ORDER BY business_name LIMIT $per OFFSET $off", $p);
    api_ok(array_map('supplier_row', $rows), ['page' => $page, 'per_page' => $per, 'total' => $total]);
}

function like_param_api(string $q): string
{
    return '%' . addcslashes($q, '%_\\') . '%';
}

function find_supplier(int $id): array
{
    $s = db_row('SELECT * FROM suppliers WHERE id = ?', [$id]);
    if (!$s) {
        throw new ApiError(404, 'not_found', 'Supplier not found.');
    }
    return $s;
}

function ep_supplier_get(int $id): void
{
    need_scope('read');
    api_ok(supplier_row(find_supplier($id), true));
}

function ep_supplier_products(int $id): void
{
    need_scope('read');
    $s = find_supplier($id);
    $rows = $s['status'] === 'archived' ? [] : db_all('SELECT * FROM supplier_products WHERE supplier_id = ? AND is_approved = 1 AND is_archived = 0 ORDER BY product_name', [$id]);
    api_ok(array_map('product_row', $rows), ['total' => count($rows)]);
}

function ep_products_list(): void
{
    need_scope('read');
    [$page, $per, $off] = paging();
    $where = ["sp.is_approved = 1 AND sp.is_archived = 0 AND s.status <> 'archived'"];
    $p = [];
    if ((int)($_GET['supplier_id'] ?? 0) > 0) {
        $where[] = 'sp.supplier_id = ?';
        $p[] = (int)$_GET['supplier_id'];
    }
    $W = implode(' AND ', $where);
    $total = (int)db_scalar("SELECT COUNT(*) FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE $W", $p);
    $rows = db_all("SELECT sp.* FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE $W ORDER BY sp.product_name LIMIT $per OFFSET $off", $p);
    api_ok(array_map('product_row', $rows), ['page' => $page, 'per_page' => $per, 'total' => $total]);
}

function ep_product_get(int $id): void
{
    need_scope('read');
    $r = db_row("SELECT sp.* FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE sp.id = ? AND sp.is_approved = 1 AND sp.is_archived = 0 AND s.status <> 'archived'", [$id]);
    if (!$r) {
        throw new ApiError(404, 'not_found', 'Supplier product not found or not published.');
    }
    api_ok(product_row($r));
}

function ep_po_get(int $id): void
{
    need_scope('read');
    $po = db_row('SELECT po.*, s.business_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = ?', [$id]);
    if (!$po || in_array($po['status'], ['draft', 'pending_approval', 'rejected'], true)) {
        throw new ApiError(404, 'not_found', 'Purchase order not found.');      // unapproved orders are internal
    }
    $items = db_all('SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id', [$id]);
    api_ok([
        'id' => (int)$po['id'], 'po_number' => $po['po_number'], 'status' => $po['status'], 'supplier_id' => (int)$po['supplier_id'], 'supplier_name' => $po['business_name'],
        'order_date' => $po['order_date'], 'expected_date' => $po['expected_date'], 'total_amount' => (float)$po['total_amount'],
        'items' => array_map(fn($i) => [
            'id' => (int)$i['id'], 'supplier_product_id' => (int)$i['supplier_product_id'], 'product_name' => $i['product_name'], 'supplier_sku' => $i['supplier_sku'], 'unit' => $i['unit'],
            'qty_ordered' => (float)$i['qty_ordered'], 'qty_received' => (float)$i['qty_received'], 'qty_outstanding' => (float)$i['qty_ordered'] - (float)$i['qty_received'],
            'unit_price' => (float)$i['unit_price'],
        ], $items),
    ]);
}

function delivery_head(array $d): array
{
    return ['id' => (int)$d['id'], 'delivery_ref' => $d['delivery_ref'], 'status' => $d['status'], 'purchase_order_id' => (int)$d['purchase_order_id'], 'po_number' => $d['po_number'],
            'supplier_id' => (int)$d['supplier_id'], 'supplier_name' => $d['business_name'], 'arrival_date' => $d['arrival_date'], 'confirmed_at' => $d['confirmed_at'],
            'acknowledged_at' => $d['acknowledged_at'], 'ack_receipt_ref' => $d['ack_receipt_ref']];
}

const DELIVERY_SELECT = 'SELECT d.*, po.po_number, po.supplier_id, s.business_name FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id JOIN suppliers s ON s.id = po.supplier_id';

function ep_deliveries_list(): void
{
    need_scope('read');
    [$page, $per, $off] = paging();
    $status = (string)($_GET['status'] ?? 'confirmed');
    if (!in_array($status, ['confirmed', 'acknowledged', 'cancelled'], true)) {
        throw new ApiError(422, 'invalid_status', 'status must be confirmed, acknowledged or cancelled.');
    }
    $total = (int)db_scalar('SELECT COUNT(*) FROM deliveries WHERE status = ? AND confirmed_at IS NOT NULL', [$status]);
    $rows = db_all(DELIVERY_SELECT . " WHERE d.status = ? AND d.confirmed_at IS NOT NULL ORDER BY d.id DESC LIMIT $per OFFSET $off", [$status]);
    $out = [];
    foreach ($rows as $d) {
        $h = delivery_head($d);
        $h['item_count'] = (int)db_scalar('SELECT COUNT(*) FROM delivery_items WHERE delivery_id = ?', [(int)$d['id']]);
        $out[] = $h;
    }
    api_ok($out, ['page' => $page, 'per_page' => $per, 'total' => $total]);
}

function ep_delivery_get(int $id): void
{
    need_scope('read');
    $d = db_row(DELIVERY_SELECT . ' WHERE d.id = ? AND d.confirmed_at IS NOT NULL', [$id]);
    if (!$d) {
        throw new ApiError(404, 'not_found', 'Delivery not found or not yet confirmed by the supplier.');
    }
    $h = delivery_head($d);
    $h['items'] = array_map(fn($i) => [
        'delivery_item_id' => (int)$i['id'], 'po_item_id' => (int)$i['po_item_id'], 'supplier_product_id' => (int)$i['supplier_product_id'], 'product_name' => $i['product_name'],
        'supplier_sku' => $i['supplier_sku'], 'unit' => $i['unit'], 'unit_price' => (float)$i['unit_price'], 'qty_delivered' => (float)$i['qty_delivered'],
        'qty_rejected' => (float)$i['qty_rejected'], 'qty_acknowledged' => $i['qty_acknowledged'] === null ? null : (float)$i['qty_acknowledged'],
    ], db_all('SELECT di.*, poi.supplier_product_id, poi.product_name, poi.supplier_sku, poi.unit, poi.unit_price FROM delivery_items di JOIN purchase_order_items poi ON poi.id = di.po_item_id WHERE di.delivery_id = ? ORDER BY di.id', [$id]));
    api_ok($h);
}

// ---------------------------------------------------------------- acknowledgment (stock receipt confirmation)
function ep_acknowledge(int $deliveryId, ?int $poId): void
{
    need_scope('receive');
    if ($GLOBALS['api_idem'] === null || $GLOBALS['api_idem'] === '') {
        throw new ApiError(400, 'idempotency_key_required', 'Send a unique Idempotency-Key header.');
    }
    $b = json_body();
    $receipt = trim((string)($b['receipt_ref'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $receipt)) {
        throw new ApiError(422, 'invalid_receipt_ref', 'receipt_ref must be 3-60 characters (letters, digits, . _ -).');
    }
    $items = $b['items'] ?? null;
    if (!is_array($items) || !$items) {
        throw new ApiError(422, 'invalid_items', 'items must be a non-empty array of {delivery_item_id, qty_accepted}.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM deliveries WHERE id = ? FOR UPDATE');
        $st->execute([$deliveryId]);
        $d = $st->fetch();
        if (!$d || ($poId !== null && (int)$d['purchase_order_id'] !== $poId) || $d['confirmed_at'] === null) {
            throw new ApiError(404, 'not_found', 'Delivery not found for this request.');
        }
        if ($d['status'] === 'acknowledged') {                      // safe retry of the same acknowledgment
            if (hash_equals((string)$d['ack_receipt_ref'], $receipt)) {
                $pdo->rollBack();
                $po = (string)db_scalar('SELECT status FROM purchase_orders WHERE id = ?', [(int)$d['purchase_order_id']]);
                api_ok(['delivery_id' => $deliveryId, 'receipt_ref' => $receipt, 'status' => 'acknowledged', 'po_status' => $po, 'duplicate' => true], [], 200, 'duplicate');
            }
            throw new ApiError(409, 'already_acknowledged', 'This delivery was already acknowledged with a different receipt reference.');
        }
        if ($d['status'] !== 'confirmed') {
            throw new ApiError(409, 'not_confirmed', 'Only confirmed deliveries can be acknowledged (current status: ' . $d['status'] . ').');
        }
        $lines = [];
        foreach (db_all('SELECT * FROM delivery_items WHERE delivery_id = ?', [$deliveryId]) as $l) {
            $lines[(int)$l['id']] = $l;
        }
        $seen = [];
        $accepted = 0.0;
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['delivery_item_id'], $it['qty_accepted'])) {
                throw new ApiError(422, 'invalid_item', 'Each item needs delivery_item_id and qty_accepted.');
            }
            $iid = (int)$it['delivery_item_id'];
            $qty = num($it['qty_accepted'], 'qty_accepted');
            if (!isset($lines[$iid]) || isset($seen[$iid])) {
                throw new ApiError(422, 'invalid_item', "delivery_item_id $iid is unknown for this delivery or repeated.");
            }
            $max = (float)$lines[$iid]['qty_delivered'] - (float)$lines[$iid]['qty_rejected'];
            if ($qty > $max + 0.0001) {
                throw new ApiError(422, 'quantity_exceeded', "qty_accepted for item $iid exceeds the deliverable quantity ($max).");
            }
            $seen[$iid] = $qty;
        }
        if (count($seen) !== count($lines)) {
            throw new ApiError(422, 'items_incomplete', 'Include every delivery item exactly once (use qty_accepted 0 to reject a line).');
        }
        foreach ($seen as $iid => $qty) {
            $pdo->prepare('UPDATE delivery_items SET qty_acknowledged = ? WHERE id = ?')->execute([$qty, $iid]);
            if ($qty > 0) {
                $st = $pdo->prepare('SELECT qty_ordered, qty_received FROM purchase_order_items WHERE id = ? FOR UPDATE');
                $st->execute([(int)$lines[$iid]['po_item_id']]);
                $poi = $st->fetch();
                if ((float)$poi['qty_received'] + $qty > (float)$poi['qty_ordered'] + 0.0001) {
                    throw new ApiError(422, 'exceeds_order', 'Accepting this quantity would exceed the quantity ordered.');
                }
                $pdo->prepare('UPDATE purchase_order_items SET qty_received = qty_received + ? WHERE id = ?')->execute([$qty, (int)$lines[$iid]['po_item_id']]);
            }
            $accepted += $qty;
        }
        $pdo->prepare("UPDATE deliveries SET status = 'acknowledged', ack_receipt_ref = ?, acknowledged_at = ? WHERE id = ?")->execute([$receipt, date('Y-m-d H:i:s'), $deliveryId]);
        $poStatus = po_refresh_status($pdo, (int)$d['purchase_order_id']);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (NULL, ?, ?, ?, ?, ?)')
            ->execute(['delivery_acknowledged', 'delivery', $deliveryId, 'Inventory receipt ' . $receipt . ', accepted ' . $accepted, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $pdo->commit();
        api_ok(['delivery_id' => $deliveryId, 'receipt_ref' => $receipt, 'status' => 'acknowledged', 'po_status' => $poStatus, 'accepted_total' => $accepted]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[api] ack failed: ' . $e->getMessage());
        throw new ApiError(409, 'conflict', 'The acknowledgment could not be recorded (receipt reference may be in use). Retrying with the same key is safe.');
    } catch (ApiError $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ---------------------------------------------------------------- refund requests
function refund_view(array $r): array
{
    return ['request_ref' => $r['request_ref'], 'supplier_id' => (int)$r['supplier_id'], 'product_name' => $r['product_name'], 'quantity' => (float)$r['quantity'],
            'amount' => (float)$r['amount'], 'status' => $r['status'], 'response_note' => $r['response_note'], 'updated_at' => $r['updated_at']];
}

function ep_refund_create(): void
{
    need_scope('refund');
    $b = json_body();
    $ref = trim((string)($b['request_ref'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._-]{6,40}$/', $ref)) {
        throw new ApiError(422, 'invalid_request_ref', 'request_ref must be 6-40 characters (letters, digits, . _ -).');
    }
    $sid = (int)($b['supplier_id'] ?? 0);
    $s = find_supplier($sid);
    if ($s['status'] === 'archived') {
        throw new ApiError(422, 'supplier_archived', 'That supplier is archived.');
    }
    $spid = isset($b['supplier_product_id']) && $b['supplier_product_id'] !== null ? (int)$b['supplier_product_id'] : null;
    if ($spid !== null && !db_row('SELECT id FROM supplier_products WHERE id = ? AND supplier_id = ?', [$spid, $sid])) {
        throw new ApiError(422, 'invalid_supplier_product', 'supplier_product_id does not belong to this supplier.');
    }
    $name = trim((string)($b['product_name'] ?? ''));
    $reason = trim((string)($b['reason'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150 || $reason === '' || mb_strlen($reason) > 255) {
        throw new ApiError(422, 'invalid_field', 'product_name (max 150) and reason (max 255) are required.');
    }
    $qty = num($b['quantity'] ?? null, 'quantity', true);
    $cost = num($b['unit_cost'] ?? null, 'unit_cost');
    $amount = round($qty * $cost, 2);
    $existing = db_row('SELECT * FROM refund_requests WHERE request_ref = ?', [$ref]);
    if ($existing) {                                                  // idempotent retry
        if ((int)$existing['supplier_id'] !== $sid || abs((float)$existing['quantity'] - $qty) > 0.001) {
            throw new ApiError(409, 'request_ref_conflict', 'request_ref was already used for a different request.');
        }
        api_ok(refund_view($existing), [], 200, 'duplicate');
    }
    try {
        db()->prepare('INSERT INTO refund_requests (request_ref, supplier_id, supplier_product_id, product_name, quantity, unit_cost, amount, reason, client_id) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$ref, $sid, $spid, $name, $qty, $cost, $amount, $reason, $GLOBALS['api_client']['id']]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            api_ok(refund_view(db_row('SELECT * FROM refund_requests WHERE request_ref = ?', [$ref]) ?? []), [], 200, 'duplicate');
        }
        throw $e;
    }
    db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (NULL, ?, ?, ?, ?, ?)')
        ->execute(['refund_received', 'refund_request', (int)db()->lastInsertId(), $ref . ' ' . $name . ' x ' . $qty, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    api_ok(refund_view(db_row('SELECT * FROM refund_requests WHERE request_ref = ?', [$ref])), [], 201);
}

function ep_refund_status(?string $ref): void
{
    need_scope('refund');
    if ($ref !== null) {
        $r = db_row('SELECT * FROM refund_requests WHERE request_ref = ?', [$ref]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Refund request not found.');
        }
        api_ok(refund_view($r));
    }
    $refs = array_slice(array_filter(array_map('trim', explode(',', (string)($_GET['refs'] ?? '')))), 0, 100);
    if (!$refs) {
        throw new ApiError(422, 'refs_required', 'Pass refs=REF1,REF2,... (max 100).');
    }
    $in = implode(',', array_fill(0, count($refs), '?'));
    api_ok(array_map('refund_view', db_all("SELECT * FROM refund_requests WHERE request_ref IN ($in)", $refs)));
}

// ---------------------------------------------------------------- router
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$pos = strpos($uri, '/api/v1');
$path = rtrim($pos === false ? '/' : substr($uri, $pos + 7), '/') ?: '/';
$GLOBALS['api_idem'] = substr((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''), 0, 80) ?: null;

try {
    try {
        db();
    } catch (Throwable $e) {
        throw new ApiError(503, 'database_unavailable', 'The Supplier system database is unavailable.');
    }
    if ($path === '/ping' && $method === 'GET') {
        api_out(200, ['data' => ['status' => 'ok', 'service' => 'AgriSuppliers API', 'version' => 'v1', 'time' => date(DATE_ATOM)]], 'success', null, false);
    }
    authenticate();
    $m = [];
    $routes = [
        ['GET', '#^/suppliers$#', fn() => ep_suppliers_list()],
        ['GET', '#^/suppliers/(\d+)$#', fn($m) => ep_supplier_get((int)$m[1])],
        ['GET', '#^/suppliers/(\d+)/products$#', fn($m) => ep_supplier_products((int)$m[1])],
        ['GET', '#^/supplier-products$#', fn() => ep_products_list()],
        ['GET', '#^/supplier-products/(\d+)$#', fn($m) => ep_product_get((int)$m[1])],
        ['GET', '#^/purchase-orders/(\d+)$#', fn($m) => ep_po_get((int)$m[1])],
        ['POST', '#^/purchase-orders/(\d+)/receive$#', function ($m) {
            $b = json_body();
            $did = (int)($b['delivery_id'] ?? 0);
            if ($did <= 0) {
                throw new ApiError(422, 'invalid_delivery_id', 'delivery_id is required.');
            }
            ep_acknowledge($did, (int)$m[1]);
        }],
        ['GET', '#^/deliveries$#', fn() => ep_deliveries_list()],
        ['GET', '#^/deliveries/(\d+)$#', fn($m) => ep_delivery_get((int)$m[1])],
        ['POST', '#^/deliveries/(\d+)/acknowledge$#', fn($m) => ep_acknowledge((int)$m[1], null)],
        ['POST', '#^/refund-requests$#', fn() => ep_refund_create()],
        ['GET', '#^/refund-requests$#', fn() => ep_refund_status(null)],
        ['GET', '#^/refund-requests/([A-Za-z0-9._-]{6,40})$#', fn($m) => ep_refund_status($m[1])],
    ];
    $pathExists = false;
    foreach ($routes as [$rm, $re, $fn]) {
        if (preg_match($re, $path, $m)) {
            $pathExists = true;
            if ($rm === $method) {
                $fn($m);
            }
        }
    }
    throw $pathExists ? new ApiError(405, 'method_not_allowed', 'HTTP method not allowed for this route.') : new ApiError(404, 'route_not_found', 'Unknown API route.');
} catch (ApiError $e) {
    api_out($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], in_array($e->status, [400, 401, 403, 404, 405, 409, 422, 429], true) ? 'rejected' : 'error', $e->getMessage(), $e->status !== 503);
} catch (Throwable $e) {
    error_log('[api] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_out(500, ['error' => ['code' => 'server_error', 'message' => 'An unexpected error occurred.']], 'error', 'server error');
}
