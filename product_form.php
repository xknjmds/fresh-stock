<?php
/**
 * product_form.php — Add (INSERT) and Edit (UPDATE) a product.
 *   product_form.php        -> add new product
 *   product_form.php?id=5   -> edit product #5
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$id     = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

// Default values for a new product
$product = [
    'sku' => '', 'name' => '', 'category_id' => '', 'supplier_id' => '', 'unit' => 'pc',
    'cost_price' => '', 'selling_price' => '', 'quantity' => 0, 'reorder_level' => 10, 'expiry_date' => '',
];

/* ---------- Load existing product when editing ---------- */
if ($isEdit) {
    $stmt = $conn->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existing) {
        flash('danger', 'Product not found.');
        redirect('products.php');
    }
    $product = $existing;
}
$oldQty = (int) $product['quantity'];

/* ---------- Handle form submission ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $product = [
        'sku'           => strtoupper(post('sku')),
        'name'          => post('name'),
        'category_id'   => (int) post('category_id'),
        'supplier_id'   => (int) post('supplier_id') ?: null,
        'unit'          => post('unit') ?: 'pc',
        'cost_price'    => post('cost_price'),
        'selling_price' => post('selling_price'),
        'quantity'      => post('quantity', '0'),
        'reorder_level' => post('reorder_level', '0'),
        'expiry_date'   => post('expiry_date') ?: null,
    ];

    // ---- Server-side validation ----
    if ($product['sku'] === '' || !preg_match('/^[A-Z0-9\-]{2,40}$/', $product['sku'])) {
        $errors['sku'] = 'SKU is required (2–40 letters, numbers or dashes).';
    }
    if ($product['name'] === '' || mb_strlen($product['name']) > 120) {
        $errors['name'] = 'Product name is required (max 120 characters).';
    }
    if ($product['category_id'] <= 0) {
        $errors['category_id'] = 'Please choose a category.';
    }
    if (!is_numeric($product['cost_price']) || $product['cost_price'] < 0) {
        $errors['cost_price'] = 'Enter a valid cost price.';
    }
    if (!is_numeric($product['selling_price']) || $product['selling_price'] < 0) {
        $errors['selling_price'] = 'Enter a valid selling price.';
    }
    if (filter_var($product['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
        $errors['quantity'] = 'Quantity must be a whole number (0 or more).';
    }
    if (filter_var($product['reorder_level'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
        $errors['reorder_level'] = 'Reorder level must be a whole number (0 or more).';
    }
    if ($product['expiry_date'] && !DateTime::createFromFormat('Y-m-d', $product['expiry_date'])) {
        $errors['expiry_date'] = 'Invalid date.';
    }

    // Unique SKU check (ignore this product when editing)
    if (!isset($errors['sku'])) {
        $stmt = $conn->prepare('SELECT id FROM products WHERE sku = ? AND id <> ?');
        $stmt->bind_param('si', $product['sku'], $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors['sku'] = 'This SKU is already used by another product.';
        }
        $stmt->close();
    }

    if (!$errors) {
        $qty    = (int) $product['quantity'];
        $reord  = (int) $product['reorder_level'];
        $cost   = (float) $product['cost_price'];
        $price  = (float) $product['selling_price'];
        $userId = (int) $_SESSION['user_id'];

        $conn->begin_transaction();
        try {
            if ($isEdit) {
                // ---- UPDATE ----
                $stmt = $conn->prepare('UPDATE products SET sku=?, name=?, category_id=?, supplier_id=?, unit=?,
                    cost_price=?, selling_price=?, quantity=?, reorder_level=?, expiry_date=? WHERE id=?');
                $stmt->bind_param('ssiisddiisi', $product['sku'], $product['name'], $product['category_id'],
                    $product['supplier_id'], $product['unit'], $cost, $price, $qty, $reord, $product['expiry_date'], $id);
                $stmt->execute();
                $stmt->close();

                // Log a stock adjustment when the quantity was changed manually
                if ($qty !== $oldQty) {
                    $remarks = "Manual edit: $oldQty → $qty";
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, user_id, type, quantity, remarks) VALUES (?, ?, 'adjust', ?, ?)");
                    $stmt->bind_param('iiis', $id, $userId, $qty, $remarks);
                    $stmt->execute();
                    $stmt->close();
                }
                $msg = 'Product "' . $product['name'] . '" updated successfully.';
            } else {
                // ---- INSERT ----
                $stmt = $conn->prepare('INSERT INTO products (sku, name, category_id, supplier_id, unit, cost_price,
                    selling_price, quantity, reorder_level, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssiisddiis', $product['sku'], $product['name'], $product['category_id'],
                    $product['supplier_id'], $product['unit'], $cost, $price, $qty, $reord, $product['expiry_date']);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();

                // Record opening stock in the movement log
                if ($qty > 0) {
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, user_id, type, quantity, remarks) VALUES (?, ?, 'in', ?, 'Opening stock')");
                    $stmt->bind_param('iii', $newId, $userId, $qty);
                    $stmt->execute();
                    $stmt->close();
                }
                $msg = 'Product "' . $product['name'] . '" added successfully.';
            }
            $conn->commit();
            flash('success', $msg);
            if ($price < $cost) {
                flash('warning', 'Heads up: the selling price is lower than the cost price.');
            }
            redirect('products.php');
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $errors['general'] = 'Could not save the product. ' . ($e->getCode() === 1452 ? 'Invalid category or supplier.' : 'Please try again.');
        }
    }
}

$categories = $conn->query('SELECT id, name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$suppliers  = $conn->query('SELECT id, name FROM suppliers ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$units      = ['pc', 'pack', 'box', 'bottle', 'can', 'cup', 'bar', 'tube', 'strip', 'tray', 'loaf', 'kg', 'g', 'L', 'ml', 'dozen', 'sachet'];

/** Bootstrap class for invalid fields. */
function invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}
function feedback(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>' : '';
}


$pageTitle   = $isEdit ? 'Edit product' : 'New product';
$activePage  = 'products';
$crumbParent = ['Products', 'products.php'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <a href="products.php" class="btn btn-ghost btn-sm mb-2 ms-n2 text-muted-fg" style="margin-left:-.75rem"><i class="bi bi-arrow-left"></i> Back to products</a>
        <h1><?= $isEdit ? 'Edit product' : 'New product' ?></h1>
        <p><?= $isEdit ? 'Update the details of ' . e($product['name']) . '.' : 'Add a new item to your store inventory.' ?></p>
    </div>
    <?php if ($isEdit): ?>
        <div class="d-flex gap-2">
            <a href="stock.php?product_id=<?= $id ?>" class="btn btn-outline"><i class="bi bi-arrow-left-right"></i> Stock in / out</a>
            <button type="button" class="btn btn-outline text-destructive btn-delete" data-id="<?= $id ?>" data-name="<?= e($product['name']) ?>" data-url="product_delete.php"
                    data-warning="This permanently deletes the product and its stock movement history. This action cannot be undone.">
                <i class="bi bi-trash3"></i> Delete
            </button>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($errors['general'])): ?>
    <div class="alert d-flex gap-2 border rounded-3 mb-4" style="border-color:hsl(var(--destructive) / .4)!important;color:hsl(var(--destructive))" role="alert">
        <i class="bi bi-exclamation-circle"></i><div><div class="fw-medium">Error</div><div class="text-sm"><?= e($errors['general']) ?></div></div>
    </div>
<?php elseif ($errors): ?>
    <div class="alert d-flex gap-2 border rounded-3 mb-4" style="border-color:hsl(var(--destructive) / .4)!important;color:hsl(var(--destructive))" role="alert">
        <i class="bi bi-exclamation-circle"></i><div><div class="fw-medium">Please check the form</div><div class="text-sm">Some fields need your attention.</div></div>
    </div>
<?php endif; ?>

<form method="post" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <!-- Left column -->
        <div class="col-lg-8 d-flex flex-column gap-4">
            <div class="card reveal">
                <div class="card-header">
                    <h2 class="card-title">Details</h2>
                    <p class="card-desc">Basic information shown across the system.</p>
                </div>
                <div class="card-body row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="name">Product name</label>
                        <input type="text" class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" maxlength="120"
                               value="<?= e($product['name']) ?>" placeholder="e.g. Cola 1.5L" required>
                        <div class="invalid-feedback">Product name is required.</div>
                        <?= feedback($errors, 'name') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="sku">SKU</label>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control font-mono text-uppercase<?= invalid($errors, 'sku') ?>" id="sku" name="sku" maxlength="40"
                                   pattern="[A-Za-z0-9\-]{2,40}" value="<?= e($product['sku']) ?>" placeholder="BEV-001" required>
                            <button class="btn btn-outline btn-icon flex-shrink-0" type="button" id="genSku" data-bs-toggle="tooltip" title="Generate SKU" aria-label="Generate SKU"><i class="bi bi-stars"></i></button>
                        </div>
                        <div class="invalid-feedback">2–40 letters, numbers or dashes.</div>
                        <?= feedback($errors, 'sku') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-select<?= invalid($errors, 'category_id') ?>" id="category_id" name="category_id" required>
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (int) $product['category_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please choose a category.</div>
                        <?= feedback($errors, 'category_id') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="supplier_id">Supplier <span class="text-muted-fg fw-normal">(optional)</span></label>
                        <select class="form-select" id="supplier_id" name="supplier_id">
                            <option value="">No supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= (int) $product['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card reveal">
                <div class="card-header">
                    <h2 class="card-title">Pricing</h2>
                    <p class="card-desc">Cost is what you pay the supplier; price is what customers pay.</p>
                </div>
                <div class="card-body row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="cost_price">Cost price</label>
                        <div class="input-icon">
                            <i class="fst-normal text-sm">₱</i>
                            <input type="number" step="0.01" min="0" class="form-control tabular<?= invalid($errors, 'cost_price') ?>" id="cost_price" name="cost_price"
                                   value="<?= e($product['cost_price']) ?>" placeholder="0.00" required>
                        </div>
                        <?= feedback($errors, 'cost_price') ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="selling_price">Selling price</label>
                        <div class="input-icon">
                            <i class="fst-normal text-sm">₱</i>
                            <input type="number" step="0.01" min="0" class="form-control tabular<?= invalid($errors, 'selling_price') ?>" id="selling_price" name="selling_price"
                                   value="<?= e($product['selling_price']) ?>" placeholder="0.00" required>
                        </div>
                        <?= feedback($errors, 'selling_price') ?>
                    </div>
                    <div class="col-12">
                        <div class="rounded-3 border bg-muted p-3 d-flex flex-wrap gap-4" id="marginBox">
                            <div>
                                <div class="text-xs text-muted-fg">Profit per unit</div>
                                <div class="fw-semibold tabular" id="profitVal">₱0.00</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-fg">Markup</div>
                                <div class="fw-semibold tabular" id="marginVal">—</div>
                            </div>
                            <div class="ms-auto align-self-center" id="marginBadge"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div class="col-lg-4 d-flex flex-column gap-4">
            <div class="card reveal">
                <div class="card-header">
                    <h2 class="card-title">Inventory</h2>
                    <p class="card-desc">Quantity on hand and alerts.</p>
                </div>
                <div class="card-body row g-3">
                    <div class="col-7">
                        <label class="form-label" for="quantity">Quantity</label>
                        <input type="number" min="0" step="1" class="form-control tabular<?= invalid($errors, 'quantity') ?>" id="quantity" name="quantity"
                               value="<?= e($product['quantity']) ?>" required>
                        <?= feedback($errors, 'quantity') ?>
                    </div>
                    <div class="col-5">
                        <label class="form-label" for="unit">Unit</label>
                        <select class="form-select" id="unit" name="unit">
                            <?php foreach ($units as $u): ?>
                                <option <?= $product['unit'] === $u ? 'selected' : '' ?>><?= $u ?></option>
                            <?php endforeach; ?>
                            <?php if (!in_array($product['unit'], $units, true)): ?>
                                <option selected><?= e($product['unit']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php if ($isEdit): ?>
                        <div class="col-12 form-text mt-1">Editing quantity here is logged as an adjustment. For deliveries and sales use <a href="stock.php?product_id=<?= $id ?>" class="text-decoration-underline">Stock in / out</a>.</div>
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label" for="reorder_level">Reorder level</label>
                        <input type="number" min="0" step="1" class="form-control tabular<?= invalid($errors, 'reorder_level') ?>" id="reorder_level" name="reorder_level"
                               value="<?= e($product['reorder_level']) ?>" required>
                        <div class="form-text">Flag as low stock at or below this quantity.</div>
                        <?= feedback($errors, 'reorder_level') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="expiry_date">Expiry date <span class="text-muted-fg fw-normal">(optional)</span></label>
                        <input type="date" class="form-control<?= invalid($errors, 'expiry_date') ?>" id="expiry_date" name="expiry_date"
                               value="<?= e($product['expiry_date']) ?>">
                        <div class="form-text">Leave blank for non-perishable items.</div>
                        <?= feedback($errors, 'expiry_date') ?>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-column gap-2 reveal">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2"></i><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
                <a href="products.php" class="btn btn-outline w-100">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php
$pageScript = <<<'JS'
<script>
// Live profit / markup preview + SKU generator
(function () {
    var cost = document.getElementById('cost_price'), price = document.getElementById('selling_price');
    var profitEl = document.getElementById('profitVal'), marginEl = document.getElementById('marginVal'), badge = document.getElementById('marginBadge');
    function update() {
        var c = parseFloat(cost.value) || 0, p = parseFloat(price.value) || 0, profit = p - c;
        profitEl.textContent = (profit < 0 ? '−₱' : '₱') + Math.abs(profit).toFixed(2);
        profitEl.className = 'fw-semibold tabular ' + (profit < 0 ? 'text-destructive' : '');
        marginEl.textContent = c > 0 ? ((profit / c) * 100).toFixed(1) + '%' : '—';
        badge.innerHTML = !c && !p ? '' : profit < 0
            ? '<span class="s-badge s-badge-danger"><span class="dot"></span>Selling at a loss</span>'
            : '<span class="s-badge s-badge-success"><span class="dot"></span>Profitable</span>';
    }
    cost.addEventListener('input', update); price.addEventListener('input', update); update();

    // Generate a SKU from the category name, e.g. "Beverages" -> BEV-4821
    document.getElementById('genSku').addEventListener('click', function () {
        var sel = document.getElementById('category_id');
        var cat = sel.value ? sel.options[sel.selectedIndex].text : 'GEN';
        var prefix = cat.replace(/[^A-Za-z]/g, '').substring(0, 3).toUpperCase() || 'GEN';
        var sku = document.getElementById('sku');
        sku.value = prefix + '-' + Math.floor(1000 + Math.random() * 9000);
        sku.classList.add('is-valid');
        sku.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.03)' }, { transform: 'scale(1)' }], { duration: 250 });
        setTimeout(function () { sku.classList.remove('is-valid'); }, 1000);
    });
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
