<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('sales.create');

$products = db_all("SELECT id, product_code, name, unit, selling_price, quantity FROM products WHERE status = 'active' AND quantity > 0 ORDER BY name");
$byId = [];
foreach ($products as $pr) {
    $byId[(int)$pr['id']] = $pr;
}
$errors = [];
$f = ['customer_name' => '', 'payment_method' => 'cash', 'amount_tendered' => '', 'extra_discount' => ''];
$lines = [['pid' => '', 'qty' => '', 'disc' => '']];

if (is_post()) {
    csrf_verify();
    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $pids = (array)($_POST['product_id'] ?? []);
    $qtys = (array)($_POST['qty'] ?? []);
    $discs = (array)($_POST['disc'] ?? []);
    $lines = [];
    $merged = [];
    foreach ($pids as $i => $pid) {
        $pid = (int)$pid;
        $qRaw = trim((string)($qtys[$i] ?? ''));
        $dRaw = trim((string)($discs[$i] ?? ''));
        if ($pid === 0 && $qRaw === '') {
            continue;
        }
        $lines[] = ['pid' => (string)$pid, 'qty' => $qRaw, 'disc' => $dRaw];
        $q = parse_decimal($qRaw);
        $dsc = parse_decimal($dRaw === '' ? '0' : $dRaw);
        if (!isset($byId[$pid])) {
            $errors['lines'] = 'Choose an in-stock product on every line.';
        } elseif ($q === null || (float)$q <= 0 || $dsc === null) {
            $errors['lines'] = 'Enter a valid quantity and discount on every line.';
        } else {
            $merged[$pid]['qty'] = ($merged[$pid]['qty'] ?? 0) + (float)$q;      // the same product on two lines is combined
            $merged[$pid]['disc'] = ($merged[$pid]['disc'] ?? 0) + (float)$dsc;
        }
    }
    if (!$merged && !isset($errors['lines'])) {
        $errors['lines'] = 'Add at least one product.';
    }
    if (!in_array($f['payment_method'], ['cash', 'card', 'bank_transfer', 'e_wallet', 'credit'], true)) {
        $errors['payment_method'] = 'Choose a payment method.';
    }
    $extra = parse_decimal($f['extra_discount'] === '' ? '0' : $f['extra_discount']);
    if ($extra === null) {
        $errors['extra_discount'] = 'Enter a valid discount amount.';
    }
    $tender = $f['amount_tendered'] === '' ? null : parse_decimal($f['amount_tendered']);
    if ($f['amount_tendered'] !== '' && $tender === null) {
        $errors['amount_tendered'] = 'Enter a valid amount received.';
    }
    if (mb_strlen($f['customer_name']) > 120) {
        $errors['customer_name'] = 'Customer name is too long.';
    }
    if (!$errors) {
        ksort($merged);                                                              // consistent lock order prevents deadlocks
        $pdo = db();
        $uid = (int)current_user()['id'];
        try {
            $pdo->beginTransaction();
            $subtotal = 0.0;
            $lineDisc = 0.0;
            $rows = [];
            foreach ($merged as $pid => $m) {
                $st = $pdo->prepare('SELECT id, product_code, name, cost_price, selling_price, status FROM products WHERE id = ? FOR UPDATE');
                $st->execute([$pid]);
                $pr = $st->fetch();                                                  // price comes from the database, never from the form
                if (!$pr || $pr['status'] !== 'active') {
                    throw new RuntimeException('A product on this sale is no longer active.');
                }
                $gross = round((float)$pr['selling_price'] * $m['qty'], 2);
                if ($m['disc'] > $gross) {
                    throw new RuntimeException('Discount on ' . $pr['name'] . ' exceeds the line amount.');
                }
                $rows[] = ['pr' => $pr, 'qty' => $m['qty'], 'disc' => round($m['disc'], 2), 'gross' => $gross];
                $subtotal += $gross;
                $lineDisc += round($m['disc'], 2);
            }
            $totalDisc = round($lineDisc + (float)$extra, 2);
            if ($totalDisc > $subtotal) {
                throw new RuntimeException('Total discount cannot exceed the sale subtotal.');
            }
            $total = round($subtotal - $totalDisc, 2);
            if ($f['payment_method'] === 'cash' && $tender !== null && (float)$tender + 0.001 < $total) {
                throw new RuntimeException('Cash received (' . money($tender) . ') is less than the total (' . money($total) . ').');
            }
            $pdo->prepare('INSERT INTO sales (sale_no, sale_date, customer_name, subtotal, discount_amount, total_amount, payment_method, amount_tendered, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute(['TMP' . bin2hex(random_bytes(8)), date('Y-m-d H:i:s'), $f['customer_name'] ?: null, round($subtotal, 2), $totalDisc, $total, $f['payment_method'], $tender, 'completed', $uid]);
            $saleId = (int)$pdo->lastInsertId();
            $no = sprintf('SL-%s-%05d', date('Ymd'), $saleId);
            $pdo->prepare('UPDATE sales SET sale_no = ? WHERE id = ?')->execute([$no, $saleId]);
            $ins = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, product_code, product_name, unit_cost, unit_price, quantity, discount_amount, line_total) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $ins->execute([$saleId, (int)$r['pr']['id'], $r['pr']['product_code'], $r['pr']['name'], $r['pr']['cost_price'], $r['pr']['selling_price'], $r['qty'], $r['disc'], round($r['gross'] - $r['disc'], 2)]);
                stock_apply($pdo, (int)$r['pr']['id'], 'sale', $r['qty'], 'Sale ' . $no, 'sale', $saleId, $uid);     // throws on insufficient stock -> whole sale rolls back
            }
            $pdo->commit();
            audit('sale_created', 'sale', $saleId, $no . ' total ' . $total);
            flash('success', 'Sale ' . $no . ' completed. Total ' . money($total) . '.');
            redirect('modules/sales/view.php?id=' . $saleId);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors['_form'] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            app_log('sale failed: ' . $e->getMessage());
            $errors['_form'] = 'The sale could not be completed. No stock was changed.';
        }
    }
}
if (!$lines) { $lines[] = ['pid' => '', 'qty' => '', 'disc' => '']; }

$pageTitle = 'New sale';
$activeMenu = 'sales';
$pageScripts = '<script>(function(){'
  . 'var prods=' . json_for_script($products) . ',lines=' . json_for_script($lines) . ';'
  . 'var body=document.getElementById("lineBody");'
  . 'function m(n){return "₱"+Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}'
  . 'function find(id){for(var i=0;i<prods.length;i++)if(String(prods[i].id)===String(id))return prods[i];return null;}'
  . 'function opts(sel){var h="<option value=\"\">Select product…</option>";prods.forEach(function(p){h+="<option value=\""+p.id+"\""+(String(p.id)===String(sel)?" selected":"")+">"+p.product_code+" — "+p.name+" ("+Number(p.quantity)+" "+p.unit+")</option>";});return h;}'
  . 'function recalc(){var sub=0,disc=0;body.querySelectorAll("tr").forEach(function(tr){var p=find(tr.querySelector("select").value),q=parseFloat(tr.querySelector(".q").value)||0,d=parseFloat(tr.querySelector(".d").value)||0;'
  . 'var price=p?parseFloat(p.selling_price):0;tr.querySelector(".pr").textContent=p?m(price):"—";tr.querySelector(".lt").textContent=m(price*q-d);'
  . 'var warn=p&&q>parseFloat(p.quantity);tr.querySelector(".q").classList.toggle("is-invalid",!!warn);sub+=price*q;disc+=d;});'
  . 'var ex=parseFloat(document.getElementById("f_extra_discount").value)||0;var tot=sub-disc-ex;document.getElementById("sub").textContent=m(sub);document.getElementById("grand").textContent=m(tot);'
  . 'var t=parseFloat(document.getElementById("f_amount_tendered").value);document.getElementById("chg").textContent=isNaN(t)?"—":m(t-tot);}'
  . 'function add(l){var tr=document.createElement("tr");tr.innerHTML="<td><select name=\"product_id[]\" class=\"form-select\">"+opts(l.pid)+"</select></td>"'
  . '+"<td style=\"width:110px\"><input class=\"form-control q\" name=\"qty[]\" inputmode=\"decimal\" value=\""+(l.qty||"")+"\"></td><td class=\"pr text-end\">—</td>"'
  . '+"<td style=\"width:120px\"><input class=\"form-control d\" name=\"disc[]\" inputmode=\"decimal\" placeholder=\"0.00\" value=\""+(l.disc||"")+"\"></td><td class=\"lt text-end\">₱0.00</td>"'
  . '+"<td class=\"text-end\"><button type=\"button\" class=\"btn btn-sm btn-outline-danger rm\"><i class=\"bi bi-x\"></i></button></td>";body.appendChild(tr);'
  . 'tr.querySelectorAll("select,input").forEach(function(el){el.addEventListener("input",recalc);el.addEventListener("change",recalc);});'
  . 'tr.querySelector(".rm").addEventListener("click",function(){if(body.children.length>1){tr.remove();recalc();}});}'
  . 'lines.forEach(add);document.getElementById("addLine").addEventListener("click",function(){add({});});'
  . '["f_extra_discount","f_amount_tendered"].forEach(function(id){document.getElementById(id).addEventListener("input",recalc);});recalc();'
  . '})();</script>';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<?php if (!$products): ?><div class="alert alert-info">There are no active products with stock to sell.</div><?php endif; ?>
<form method="post" class="needs-validation" novalidate><?= csrf_field() ?>
  <div class="card mb-3"><div class="card-header">Items <small class="text-muted">Prices come from the product record; stock is checked and deducted when the sale is completed.</small></div>
    <?php if (isset($errors['lines'])): ?><div class="alert alert-danger m-3 py-2"><?= e($errors['lines']) ?></div><?php endif; ?>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Product</th><th>Qty</th><th class="text-end">Price</th><th>Discount (₱)</th><th class="text-end">Line total</th><th></th></tr></thead><tbody id="lineBody"></tbody></table></div>
    <div class="card-body"><button type="button" class="btn btn-outline-success btn-sm" id="addLine"><i class="bi bi-plus-lg"></i> Add item</button></div></div>
  <div class="card mb-3"><div class="card-body row g-3">
    <?= form_input('customer_name', 'Customer (optional)', $f, $errors, ['maxlength' => 120, 'col' => 'col-md-3']) ?>
    <?= form_select('payment_method', 'Payment method', ['cash' => 'Cash', 'card' => 'Card', 'bank_transfer' => 'Bank transfer', 'e_wallet' => 'E-wallet', 'credit' => 'Credit'], $f['payment_method'], $errors, ['col' => 'col-md-3']) ?>
    <?= form_input('extra_discount', 'Extra discount on whole sale (₱)', $f, $errors, ['inputmode' => 'decimal', 'col' => 'col-md-3']) ?>
    <?= form_input('amount_tendered', 'Amount received (₱)', $f, $errors, ['inputmode' => 'decimal', 'col' => 'col-md-3']) ?>
    <div class="col-12 text-end"><div>Subtotal <strong id="sub">₱0.00</strong></div><div class="h4 mb-0">Total <strong id="grand">₱0.00</strong></div><div class="text-muted">Change <span id="chg">—</span></div></div>
  </div></div>
  <div class="d-flex gap-2"><button class="btn btn-success btn-lg" type="submit"><i class="bi bi-check2-circle"></i> Complete sale</button><a class="btn btn-light btn-lg" href="index.php">Cancel</a></div>
</form>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
