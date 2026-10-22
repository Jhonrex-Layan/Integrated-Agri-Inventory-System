<?php
// Stock helpers (Inventory system). Every quantity change goes through stock_apply().
declare(strict_types=1);

const STOCK_IN_TYPES  = ['stock_in', 'adjustment_in', 'delivery_receipt', 'sale_void'];
const STOCK_OUT_TYPES = ['stock_out', 'adjustment_out', 'damaged', 'sale'];

/**
 * Change a product's quantity and write the movement row. MUST be called inside a transaction:
 * the product row is locked (FOR UPDATE) so concurrent sales/receipts cannot oversell or lose updates.
 * Returns the new balance. Throws RuntimeException (negative stock, archived product, bad quantity).
 */
function stock_apply(PDO $pdo, int $productId, string $type, float $qty, string $reason, string $refType, ?int $refId, int $userId, string $review = 'not_required'): float
{
    $in = in_array($type, STOCK_IN_TYPES, true);
    if (!$in && !in_array($type, STOCK_OUT_TYPES, true)) {
        throw new RuntimeException('Unknown stock movement type.');
    }
    $qty = round($qty, 2);
    if ($qty <= 0) {
        throw new RuntimeException('Quantity must be greater than zero.');
    }
    $st = $pdo->prepare('SELECT product_code, name, quantity, status FROM products WHERE id = ? FOR UPDATE');
    $st->execute([$productId]);
    $p = $st->fetch();
    if (!$p) {
        throw new RuntimeException('Product not found.');
    }
    if ($p['status'] === 'archived' && $type !== 'sale_void') {
        throw new RuntimeException($p['product_code'] . ' is archived. Restore it before changing stock.');
    }
    $new = round((float)$p['quantity'] + ($in ? $qty : -$qty), 2);
    if ($new < 0) {
        throw new RuntimeException('Insufficient stock for ' . $p['name'] . ': ' . fmt_qty($p['quantity']) . ' on hand, ' . fmt_qty($qty) . ' requested.');
    }
    $pdo->prepare('UPDATE products SET quantity = ? WHERE id = ?')->execute([$new, $productId]);
    $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, balance_after, reason, reference_type, reference_id, review_status, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$productId, $type, $qty, $new, mb_substr($reason, 0, 255), $refType, $refId, $review, $userId]);
    return $new;
}

/** Display-code prefix for a category: Seeds S, Fertilizers F, Animal Feeds AF, Pesticides P, Tools T; otherwise initials. */
function product_category_prefix(string $categoryName): string
{
    $n = strtolower(trim($categoryName));
    $known = ['animal feed' => 'AF', 'animal feeds' => 'AF', 'seeds' => 'S', 'seed' => 'S', 'fertilizer' => 'F', 'fertilizers' => 'F', 'fertiliser' => 'F', 'fertilisers' => 'F',
              'pesticide' => 'P', 'pesticides' => 'P', 'tools' => 'T', 'tool' => 'T'];
    if (isset($known[$n])) {
        return $known[$n];
    }
    $words = array_values(array_filter(preg_split('/[^a-z0-9]+/i', trim($categoryName)) ?: [], fn($w) => $w !== ''));
    if (!$words) {
        return 'C';
    }
    return strtoupper(substr($words[0], 0, 1) . (count($words) > 1 ? substr($words[1], 0, 1) : ''));
}

function product_max_sequence(string $prefix): int
{
    $max = 0;
    foreach (db_all('SELECT product_code FROM products WHERE product_code LIKE ?', [$prefix . '%']) as $r) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', (string)$r['product_code'], $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max;
}

function format_product_code(string $prefix, int $seq): string
{
    return $prefix . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}
