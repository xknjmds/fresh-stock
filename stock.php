<?php
/**
 * stock.php — Record stock movements and view the movement history.
 *   in     : delivery / restock  -> quantity increases
 *   out    : sale / damage / use -> quantity decreases (cannot go below 0)
 *   adjust : physical count      -> quantity is set to the counted value
 * Each movement updates products.quantity and inserts a stock_movements
 * row inside ONE transaction so the two never get out of sync.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

/* ---------- Record a movement ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record') {
    verify_csrf();
    $productId = (int) ($_POST['product_id'] ?? 0);
    $type      = $_POST['type'] ?? '';
    $qty       = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $remarks   = mb_substr(post('remarks'), 0, 255);
    $userId    = (int) $_SESSION['user_id'];

    if ($productId <= 0 || !in_array($type, ['in', 'out', 'adjust'], true) || $qty === false || ($type !== 'adjust' && $qty < 1)) {
        flash('danger', 'Please choose a product, a movement type and a valid quantity.');
        redirect('stock.php?product_id=' . $productId);
    }

    $conn->begin_transaction();
    try {
        // Lock the product row so two users can't oversell at the same time
        $stmt = $conn->prepare('SELECT name, quantity, unit FROM products WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        $current = (int) $product['quantity'];
        $newQty  = match ($type) {
            'in'     => $current + $qty,
            'out'    => $current - $qty,
            'adjust' => $qty,
        };
        if ($newQty < 0) {
            throw new RuntimeException("Not enough stock. Only $current {$product['unit']} of {$product['name']} available.");
        }

        // UPDATE product quantity
        $stmt = $conn->prepare('UPDATE products SET quantity = ? WHERE id = ?');
        $stmt->bind_param('ii', $newQty, $productId);
        $stmt->execute();
        $stmt->close();

        // INSERT movement log
        if ($remarks === '' && $type === 'adjust') {
            $remarks = "Count correction: $current → $newQty";
        }
        $stmt = $conn->prepare('INSERT INTO stock_movements (product_id, user_id, type, quantity, remarks) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('iisis', $productId, $userId, $type, $qty, $remarks);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $verb = ['in' => 'added to', 'out' => 'removed from', 'adjust' => 'set for'][$type];
        flash('success', ($type === 'adjust' ? "Quantity $verb" : "$qty {$product['unit']} $verb") . " {$product['name']}. New stock: $newQty.");
    } catch (RuntimeException $e) {
        $conn->rollback();
        flash('danger', $e->getMessage());
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        flash('danger', 'Could not record the movement. Please try again.');
    }
    redirect('stock.php?product_id=' . $productId);
}

/* ---------- Delete a movement log entry (does NOT change stock) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare('DELETE FROM stock_movements WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    flash($stmt->affected_rows ? 'success' : 'warning', $stmt->affected_rows ? 'Log entry removed (stock quantity unchanged).' : 'Entry not found.');
    redirect('stock.php');
}

/* ---------- Data for the page ---------- */
$selectedId   = (int) ($_GET['product_id'] ?? 0);
$selectedType = in_array($_GET['type'] ?? '', ['in', 'out', 'adjust'], true) ? $_GET['type'] : 'in';
$filterType   = in_array($_GET['filter'] ?? '', ['in', 'out', 'adjust'], true) ? $_GET['filter'] : '';

$products = $conn->query('SELECT id, sku, name, quantity, unit, reorder_level FROM products ORDER BY name')->fetch_all(MYSQLI_ASSOC);

// History: last 100 movements, optionally filtered by type
$sql = "SELECT m.*, p.name AS product, p.sku, p.unit, u.full_name AS user
        FROM stock_movements m
        JOIN products p ON p.id = m.product_id
        LEFT JOIN users u ON u.id = m.user_id"
     . ($filterType ? ' WHERE m.type = ?' : '')
     . ' ORDER BY m.created_at DESC, m.id DESC LIMIT 100';
$stmt = $conn->prepare($sql);
if ($filterType) {
    $stmt->bind_param('s', $filterType);
}
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Today's totals
$today = $conn->query("
    SELECT COALESCE(SUM(type='in'  ) , 0) AS in_count,  COALESCE(SUM(IF(type='in',  quantity, 0)), 0) AS in_qty,
           COALESCE(SUM(type='out' ) , 0) AS out_count, COALESCE(SUM(IF(type='out', quantity, 0)), 0) AS out_qty
    FROM stock_movements WHERE DATE(created_at) = CURDATE()
")->fetch_assoc();

$typeMap = [
    // value => [badge variant, icon, label, description, sign]
    'in'     => ['success', 'bi-box-arrow-in-down', 'Stock in',  'Delivery or restock', '+'],
    'out'    => ['danger',  'bi-box-arrow-up',      'Stock out', 'Sale, damage or use', '−'],
    'adjust' => ['neutral', 'bi-sliders',           'Adjust',    'Set to counted qty',  '='],
];

$pageTitle  = 'Stock In / Out';
$activePage = 'stock';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1>Stock in / out</h1>
        <p>Record deliveries, sales and physical count corrections.</p>
    </div>
    <div class="d-flex gap-4">
        <div>
            <div class="text-xs text-muted-fg">Units in today</div>
            <div class="fs-4 fw-bold tabular text-success-fg">+<span data-count="<?= (int) $today['in_qty'] ?>">0</span></div>
        </div>
        <div>
            <div class="text-xs text-muted-fg">Units out today</div>
            <div class="fs-4 fw-bold tabular">−<span data-count="<?= (int) $today['out_qty'] ?>">0</span></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Record form -->
    <div class="col-lg-5 col-xl-4">
        <div class="card reveal" style="position:sticky;top:5rem">
            <div class="card-header">
                <h2 class="card-title">New movement</h2>
                <p class="card-desc">Stock levels update instantly.</p>
            </div>
            <div class="card-body">
                <form method="post" class="needs-validation d-flex flex-column gap-3" novalidate id="stockForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="record">

                    <div>
                        <span class="form-label d-block">Type</span>
                        <div class="row g-2">
                            <?php foreach ($typeMap as $val => [, $icon, $label, $desc]): ?>
                                <div class="col-4 radio-card">
                                    <input type="radio" name="type" id="type_<?= $val ?>" value="<?= $val ?>" <?= $selectedType === $val ? 'checked' : '' ?>>
                                    <label for="type_<?= $val ?>">
                                        <span class="rc-check"></span>
                                        <i class="bi <?= $icon ?> fs-5"></i>
                                        <span class="rc-title"><?= $label ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text" id="typeHint"></div>
                    </div>

                    <div>
                        <label class="form-label" for="product_id">Product</label>
                        <select class="form-select" id="product_id" name="product_id" required>
                            <option value="">Select a product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" data-qty="<?= (int) $p['quantity'] ?>" data-unit="<?= e($p['unit']) ?>" data-reorder="<?= (int) $p['reorder_level'] ?>"
                                    <?= $selectedId === (int) $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?> · <?= e($p['sku']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a product.</div>
                    </div>

                    <div>
                        <label class="form-label" for="quantity" id="qtyLabel">Quantity</label>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline btn-icon flex-shrink-0" type="button" id="qtyMinus" aria-label="Decrease"><i class="bi bi-dash"></i></button>
                            <input type="number" class="form-control text-center fw-semibold tabular" id="quantity" name="quantity" min="1" step="1" value="1" required>
                            <button class="btn btn-outline btn-icon flex-shrink-0" type="button" id="qtyPlus" aria-label="Increase"><i class="bi bi-plus"></i></button>
                        </div>
                        <div class="invalid-feedback d-block" id="qtyError" hidden></div>
                    </div>

                    <!-- Live preview -->
                    <div class="rounded-3 border bg-muted p-3 d-flex align-items-center justify-content-around text-center" id="preview">
                        <div>
                            <div class="text-xs text-muted-fg">Current</div>
                            <div class="fs-5 fw-semibold tabular" id="curQty">—</div>
                        </div>
                        <i class="bi bi-arrow-right text-muted-fg"></i>
                        <div>
                            <div class="text-xs text-muted-fg">After</div>
                            <div class="fs-5 fw-semibold tabular" id="newQty">—</div>
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="remarks">Remarks <span class="text-muted-fg fw-normal">(optional)</span></label>
                        <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255" placeholder="Add a note...">
                        <div class="d-flex flex-wrap gap-1 mt-2" id="quickRemarks"></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="submitBtn" data-loading="Recording...">Record movement</button>
                </form>
            </div>
        </div>
    </div>

    <!-- History -->
    <div class="col-lg-7 col-xl-8">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div class="s-tabs">
                <a href="stock.php" class="<?= $filterType === '' ? 'active' : '' ?>">All</a>
                <?php foreach ($typeMap as $val => [, , $label]): ?>
                    <a href="stock.php?filter=<?= $val ?>" class="<?= $filterType === $val ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <div class="input-icon" style="width:16rem;max-width:100%">
                <i class="bi bi-search"></i>
                <input type="search" class="form-control" placeholder="Search history..." data-table-filter="#historyTable" aria-label="Search history">
            </div>
        </div>

        <div class="table-card reveal">
            <div class="table-responsive" style="max-height:680px">
                <table class="table table-hover animate-rows" id="historyTable">
                    <thead class="sticky-top"><tr><th>Product</th><th>Type</th><th class="text-end">Qty</th><th class="d-none d-md-table-cell">Remarks</th><th class="text-end">Date</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr class="no-filter"><td colspan="6"><?= empty_state('bi-journal-text', 'No movements yet', 'Record your first delivery or sale using the form.') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($history as $m): [$variant, $icon, $label, , $sign] = $typeMap[$m['type']]; ?>
                        <tr>
                            <td>
                                <div class="fw-medium"><?= e($m['product']) ?></div>
                                <div class="text-xs text-muted-fg"><span class="font-mono"><?= e($m['sku']) ?></span> · <?= e($m['user'] ?? 'System') ?></div>
                            </td>
                            <td><span class="s-badge s-badge-<?= $variant ?>"><i class="bi <?= $icon ?>"></i><?= $label ?></span></td>
                            <td class="text-end tabular fw-semibold <?= $m['type'] === 'in' ? 'text-success-fg' : '' ?>"><?= $sign . (int) $m['quantity'] ?></td>
                            <td class="d-none d-md-table-cell text-muted-fg text-truncate" style="max-width:220px"><?= e($m['remarks'] ?: '—') ?></td>
                            <td class="text-end text-nowrap">
                                <div><?= nice_date($m['created_at'], 'M d, Y') ?></div>
                                <div class="text-xs text-muted-fg"><?= nice_date($m['created_at'], 'g:i A') ?></div>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-ghost btn-icon btn-sm text-muted-fg btn-delete" data-id="<?= $m['id'] ?>" data-name="this log entry" data-url="stock.php"
                                        data-warning="Only the history record is removed. The product's current quantity will not change."
                                        data-bs-toggle="tooltip" title="Remove entry" aria-label="Remove log entry">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="no-filter filter-empty" style="display:none"><td colspan="6"><?= empty_state('bi-search', 'No matches', 'No history entries match your search.') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-xs text-muted-fg mt-2 mb-0">Showing the latest <?= count($history) ?> movement<?= count($history) === 1 ? '' : 's' ?>.</p>
    </div>
</div>

<?php
$pageScript = <<<'JS'
<script>
// Live "current -> after" preview and validation for the stock form
(function () {
    var form = document.getElementById('stockForm');
    var sel = document.getElementById('product_id'), qty = document.getElementById('quantity');
    var cur = document.getElementById('curQty'), next = document.getElementById('newQty');
    var err = document.getElementById('qtyError'), btn = document.getElementById('submitBtn');
    var label = document.getElementById('qtyLabel'), quick = document.getElementById('quickRemarks'), hint = document.getElementById('typeHint');
    var remarks = { in: ['Supplier delivery', 'Restock', 'Customer return'], out: ['Sold', 'Damaged', 'Expired'], adjust: ['Physical count', 'Inventory audit'] };
    var hints = { in: 'Adds to the current quantity.', out: 'Subtracts from the current quantity.', adjust: 'Replaces the quantity with what you counted.' };
    var lastType = null, lastAfter = null;

    function type() { return form.querySelector('input[name="type"]:checked').value; }

    function update() {
        var t = type(), opt = sel.options[sel.selectedIndex];
        var have = opt && opt.value ? parseInt(opt.dataset.qty, 10) : null;
        var unit = opt && opt.value ? opt.dataset.unit : '';
        var q = parseInt(qty.value, 10) || 0;

        qty.min = t === 'adjust' ? 0 : 1;
        label.textContent = t === 'adjust' ? 'Counted quantity' : 'Quantity';
        hint.textContent = hints[t];

        if (t !== lastType) {
            lastType = t;
            quick.innerHTML = '';
            remarks[t].forEach(function (r) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 's-badge border-0 bg-muted'; b.textContent = r;
                b.onclick = function () { document.getElementById('remarks').value = r; };
                quick.appendChild(b);
            });
        }

        if (have === null) {
            cur.textContent = next.textContent = '—';
            err.hidden = true; qty.classList.remove('is-invalid'); btn.disabled = false;
            return;
        }
        var after = t === 'in' ? have + q : (t === 'out' ? have - q : q);
        cur.textContent = have + ' ' + unit;
        next.textContent = after + ' ' + unit;
        next.className = 'fs-5 fw-semibold tabular ' + (after < 0 ? 'text-destructive' : (after <= parseInt(opt.dataset.reorder, 10) ? 'text-warning-fg' : 'text-success-fg'));
        if (after !== lastAfter) {
            next.animate([{ transform: 'translateY(-4px)', opacity: .4 }, { transform: 'none', opacity: 1 }], { duration: 220, easing: 'ease-out' });
            lastAfter = after;
        }

        var bad = after < 0;
        err.textContent = bad ? 'Not enough stock. Only ' + have + ' ' + unit + ' available.' : '';
        err.hidden = !bad;
        qty.classList.toggle('is-invalid', bad);
        btn.disabled = bad;
    }

    function syncAdjust() {
        if (type() === 'adjust' && sel.value) qty.value = sel.options[sel.selectedIndex].dataset.qty;
        else if ((parseInt(qty.value, 10) || 0) < 1) qty.value = 1;
    }

    document.getElementById('qtyMinus').onclick = function () { qty.value = Math.max(parseInt(qty.min, 10), (parseInt(qty.value, 10) || 0) - 1); update(); };
    document.getElementById('qtyPlus').onclick  = function () { qty.value = (parseInt(qty.value, 10) || 0) + 1; update(); };
    sel.addEventListener('change', function () { syncAdjust(); update(); });
    qty.addEventListener('input', update);
    form.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', function () { syncAdjust(); update(); }); });
    syncAdjust();
    update();
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
