<?php
/**
 * products.php — Product list (READ)
 * Search, filter by category / supplier / stock status, sort and paginate.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

/* ---------- Read filters from the query string ---------- */
$q        = trim($_GET['q'] ?? '');
$category = (int) ($_GET['category'] ?? 0);
$supplier = (int) ($_GET['supplier'] ?? 0);
$status   = $_GET['status'] ?? '';
$sort     = $_GET['sort'] ?? 'name';
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 10;

// Whitelisted sort options (never put raw user input into ORDER BY)
$sortOptions = [
    'name'     => ['p.name ASC', 'Name (A–Z)'],
    'qty_asc'  => ['p.quantity ASC', 'Quantity ↑'],
    'qty_desc' => ['p.quantity DESC', 'Quantity ↓'],
    'price'    => ['p.selling_price DESC', 'Price ↓'],
    'expiry'   => ['p.expiry_date IS NULL, p.expiry_date ASC', 'Expiry (soonest)'],
    'newest'   => ['p.created_at DESC', 'Recently added'],
];
if (!isset($sortOptions[$sort])) {
    $sort = 'name';
}

$statusOptions = [
    ''         => 'All',
    'in'       => 'In stock',
    'low'      => 'Low',
    'expiring' => 'Expiring',
    'expired'  => 'Expired',
];

/* ---------- Build WHERE clause with bound parameters ---------- */
$where  = [];
$params = [];
$types  = '';

if ($q !== '') {
    $where[]  = '(p.name LIKE ? OR p.sku LIKE ?)';
    $like     = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if ($category > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $category;
    $types   .= 'i';
}
if ($supplier > 0) {
    $where[]  = 'p.supplier_id = ?';
    $params[] = $supplier;
    $types   .= 'i';
}
switch ($status) {
    case 'in':       $where[] = 'p.quantity > p.reorder_level'; break;
    case 'low':      $where[] = 'p.quantity <= p.reorder_level'; break;
    case 'out':      $where[] = 'p.quantity <= 0'; break;
    case 'expiring': $where[] = 'p.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; break;
    case 'expired':  $where[] = 'p.expiry_date < CURDATE()'; break;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/** Run a prepared statement with the dynamic filter params. */
function run_query(mysqli $conn, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/* ---------- Count + pagination ---------- */
$total      = (int) run_query($conn, "SELECT COUNT(*) FROM products p $whereSql", $types, $params)->fetch_row()[0];
$totalPages = max(1, (int) ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

/* ---------- Fetch page of products (JOIN category + supplier) ---------- */
$sql = "SELECT p.*, c.name AS category_name, s.name AS supplier_name
        FROM products p
        JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        $whereSql
        ORDER BY {$sortOptions[$sort][0]}, p.id
        LIMIT $perPage OFFSET $offset";
$products = run_query($conn, $sql, $types, $params)->fetch_all(MYSQLI_ASSOC);

$categories = $conn->query('SELECT id, name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$supplierName = null;
if ($supplier) {
    $stmt = $conn->prepare('SELECT name FROM suppliers WHERE id = ?');
    $stmt->bind_param('i', $supplier);
    $stmt->execute();
    $supplierName = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
}

/** Build a URL keeping current filters but overriding some. */
function page_url(array $override = []): string
{
    $query = array_merge($_GET, $override);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return 'products.php' . ($query ? '?' . http_build_query($query) : '');
}
$hasFilters = $q !== '' || $category || $supplier || $status !== '' || $sort !== 'name';

$pageTitle  = 'Products';
$activePage = 'products';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1>Products</h1>
        <p>Manage every item on your shelves.</p>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="export.php?<?= e(http_build_query($_GET)) ?>" class="btn btn-outline"><i class="bi bi-download"></i><span class="d-none d-sm-inline">Export</span></a>
        <button type="button" onclick="window.print()" class="btn btn-outline btn-icon d-none d-md-inline-flex" data-bs-toggle="tooltip" title="Print"><i class="bi bi-printer"></i></button>
        <a href="product_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add product</a>
    </div>
</div>

<!-- Status tabs -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div class="s-tabs overflow-auto" style="max-width:100%">
        <?php foreach ($statusOptions as $val => $label): ?>
            <a href="<?= e(page_url(['status' => $val, 'page' => null])) ?>" class="<?= $status === $val ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($supplierName): ?>
        <span class="s-badge s-badge-secondary">
            <i class="bi bi-truck"></i> <?= e($supplierName) ?>
            <a href="<?= e(page_url(['supplier' => null, 'page' => null])) ?>" class="text-muted-fg ms-1" aria-label="Clear supplier filter"><i class="bi bi-x"></i></a>
        </span>
    <?php endif; ?>
</div>

<!-- Toolbar -->
<form method="get" class="d-flex flex-wrap gap-2 mb-3 no-print" id="filterForm">
    <?php if ($supplier): ?><input type="hidden" name="supplier" value="<?= $supplier ?>"><?php endif; ?>
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="input-icon flex-grow-1" style="max-width:20rem;min-width:12rem">
        <i class="bi bi-search"></i>
        <input type="search" name="q" class="form-control" placeholder="Filter by name or SKU..." value="<?= e($q) ?>" id="searchInput">
    </div>
    <select name="category" class="form-select w-auto auto-submit" aria-label="Category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $category === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort" class="form-select w-auto auto-submit" aria-label="Sort by">
        <?php foreach ($sortOptions as $val => [, $label]): ?>
            <option value="<?= $val ?>" <?= $sort === $val ? 'selected' : '' ?>>Sort: <?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($hasFilters): ?>
        <a href="products.php" class="btn btn-ghost">Reset <i class="bi bi-x-lg"></i></a>
    <?php endif; ?>
</form>

<!-- Product table -->
<div class="table-card">
    <div class="table-responsive">
        <table class="table table-hover animate-rows">
            <thead>
            <tr>
                <th>Product</th>
                <th class="d-none d-md-table-cell">Category</th>
                <th class="text-end d-none d-lg-table-cell">Cost</th>
                <th class="text-end">Price</th>
                <th style="min-width:120px">Stock</th>
                <th class="d-none d-lg-table-cell">Expiry</th>
                <th>Status</th>
                <th class="no-print"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$products): ?>
                <tr><td colspan="8">
                    <?= empty_state('bi-inbox', 'No products found', $hasFilters ? 'Try adjusting your search or filters.' : 'Add your first product to get started.',
                        '<a href="' . ($hasFilters ? 'products.php' : 'product_form.php') . '" class="btn btn-outline btn-sm">' . ($hasFilters ? 'Clear filters' : '<i class="bi bi-plus-lg"></i> Add product') . '</a>') ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($products as $p):
                $max = max((int) $p['reorder_level'] * 3, 1);
                $pct = min(100, round($p['quantity'] / $max * 100));
                [, $variant] = stock_status($p);
                $d = days_until($p['expiry_date']); ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <?= product_thumb($p['name']) ?>
                            <div class="min-w-0">
                                <a href="product_form.php?id=<?= $p['id'] ?>" class="fw-medium d-block text-truncate" style="max-width:240px"><?= e($p['name']) ?></a>
                                <div class="text-xs text-muted-fg"><span class="font-mono"><?= e($p['sku']) ?></span><?= $p['supplier_name'] ? ' · ' . e($p['supplier_name']) : '' ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><span class="s-badge"><?= e($p['category_name']) ?></span></td>
                    <td class="text-end d-none d-lg-table-cell text-muted-fg tabular"><?= peso($p['cost_price']) ?></td>
                    <td class="text-end fw-medium tabular"><?= peso($p['selling_price']) ?></td>
                    <td>
                        <div class="d-flex justify-content-between text-xs mb-1">
                            <span class="fw-medium tabular text-sm"><?= (int) $p['quantity'] ?></span>
                            <span class="text-muted-fg"><?= e($p['unit']) ?></span>
                        </div>
                        <div class="s-progress"><span class="<?= $variant === 'neutral' ? '' : $variant ?>" data-width="<?= $pct ?>"></span></div>
                    </td>
                    <td class="d-none d-lg-table-cell text-nowrap">
                        <span class="<?= $p['expiry_date'] ? '' : 'text-muted-fg' ?>"><?= nice_date($p['expiry_date']) ?></span>
                        <?php if ($d !== null && $d >= 0 && $d <= 30): ?>
                            <div class="text-xs text-destructive fw-medium"><?= $d === 0 ? 'Today' : "$d day" . ($d === 1 ? '' : 's') . ' left' ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= stock_badge($p) ?></td>
                    <td class="text-end no-print">
                        <div class="dropdown">
                            <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Actions for <?= e($p['name']) ?>">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header text-xs">Actions</h6></li>
                                <li><a class="dropdown-item" href="product_form.php?id=<?= $p['id'] ?>"><i class="bi bi-pencil"></i> Edit</a></li>
                                <li><a class="dropdown-item" href="stock.php?product_id=<?= $p['id'] ?>&amp;type=in"><i class="bi bi-box-arrow-in-down"></i> Stock in</a></li>
                                <li><a class="dropdown-item" href="stock.php?product_id=<?= $p['id'] ?>&amp;type=out"><i class="bi bi-box-arrow-up"></i> Stock out</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-destructive btn-delete"
                                            data-id="<?= $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-url="product_delete.php"
                                            data-warning="This permanently deletes the product and its stock movement history. This action cannot be undone.">
                                        <i class="bi bi-trash3"></i> Delete</button></li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 no-print">
    <span class="text-sm text-muted-fg">
        <?= $total ? 'Showing ' . ($offset + 1) . '–' . min($offset + $perPage, $total) . ' of ' . $total . ' product' . ($total === 1 ? '' : 's') : 'No results' ?>
    </span>
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Product pages">
            <ul class="pagination mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(page_url(['page' => $page - 1])) ?>" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e(page_url(['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(page_url(['page' => $page + 1])) ?>" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php
// Auto-submit filters: dropdowns immediately, search after the user stops typing
$pageScript = <<<'JS'
<script>
(function () {
    var form = document.getElementById('filterForm'), timer;
    document.querySelectorAll('.auto-submit').forEach(function (s) { s.addEventListener('change', function () { form.requestSubmit(); }); });
    var search = document.getElementById('searchInput');
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { form.requestSubmit(); }, 500); });
    // Keep the caret at the end after the page reloads with a search term
    if (search.value) { search.focus(); search.setSelectionRange(search.value.length, search.value.length); }
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
