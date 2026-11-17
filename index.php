<?php
/**
 * index.php — Dashboard
 * Summary cards, charts, low-stock / expiring alerts and recent activity.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

/* ---------- Summary statistics ---------- */
$stats = $conn->query("
    SELECT
        COUNT(*)                                                    AS total_products,
        COALESCE(SUM(quantity), 0)                                  AS total_units,
        COALESCE(SUM(quantity * cost_price), 0)                     AS inventory_cost,
        COALESCE(SUM(quantity * selling_price), 0)                  AS inventory_retail,
        COALESCE(SUM(quantity <= reorder_level), 0)                 AS low_stock,
        COALESCE(SUM(quantity <= 0), 0)                             AS out_of_stock,
        COALESCE(SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS expiring_soon,
        COALESCE(SUM(expiry_date < CURDATE()), 0)                   AS expired
    FROM products
")->fetch_assoc();

$categoryCount = (int) $conn->query('SELECT COUNT(*) FROM categories')->fetch_row()[0];
$supplierCount = (int) $conn->query('SELECT COUNT(*) FROM suppliers')->fetch_row()[0];

/* ---------- Chart: units per category ---------- */
$catRows = $conn->query("
    SELECT c.name, COALESCE(SUM(p.quantity), 0) AS units
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id, c.name
    ORDER BY units DESC
")->fetch_all(MYSQLI_ASSOC);

/* ---------- Chart: stock in vs out for the last 7 days ---------- */
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[$d] = ['label' => date('D', strtotime($d)), 'in' => 0, 'out' => 0];
}
$moveRows = $conn->query("
    SELECT DATE(created_at) AS d, type, SUM(quantity) AS qty
    FROM stock_movements
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND type IN ('in','out')
    GROUP BY DATE(created_at), type
")->fetch_all(MYSQLI_ASSOC);
foreach ($moveRows as $r) {
    if (isset($days[$r['d']])) {
        $days[$r['d']][$r['type']] = (int) $r['qty'];
    }
}

/* ---------- Alerts ---------- */
$lowStock = $conn->query("
    SELECT id, sku, name, quantity, reorder_level, unit
    FROM products
    WHERE quantity <= reorder_level
    ORDER BY quantity ASC, name
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$expiring = $conn->query("
    SELECT id, sku, name, expiry_date, quantity
    FROM products
    WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY expiry_date ASC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

/* ---------- Recent activity ---------- */
$recent = $conn->query("
    SELECT m.type, m.quantity, m.remarks, m.created_at, p.name AS product, u.full_name AS user
    FROM stock_movements m
    JOIN products p ON p.id = m.product_id
    LEFT JOIN users u ON u.id = m.user_id
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

/* ---------- Derived numbers ---------- */
$hour          = (int) date('G');
$greeting      = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$totalUnitsCat = array_sum(array_column($catRows, 'units'));
$profit        = (float) $stats['inventory_retail'] - (float) $stats['inventory_cost'];
$marginPct     = $stats['inventory_retail'] > 0 ? $profit / $stats['inventory_retail'] * 100 : 0;
$weekIn        = array_sum(array_column($days, 'in'));
$weekOut       = array_sum(array_column($days, 'out'));

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$useCharts  = true;
require __DIR__ . '/includes/header.php';
?>

<!-- Heading -->
<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1>Dashboard</h1>
        <p><?= $greeting ?>, <?= e($_SESSION['full_name']) ?>. Here's how your store looks today, <?= date('F j') ?>.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="stock.php" class="btn btn-outline"><i class="bi bi-arrow-left-right"></i> Record stock</a>
        <a href="product_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add product</a>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 g-xl-4 mb-4">
    <?php
    $cards = [
        ['Total products', $stats['total_products'], 0, '', 'bi-box-seam', number_format((int) $stats['total_units']) . ' units on hand', false],
        ['Inventory value', $stats['inventory_cost'], 2, '₱', 'bi-wallet2', peso($stats['inventory_retail']) . ' at retail price', false],
        ['Low stock', $stats['low_stock'], 0, '', 'bi-exclamation-triangle', (int) $stats['out_of_stock'] . ' completely out of stock', (int) $stats['low_stock'] > 0],
        ['Expiring soon', $stats['expiring_soon'], 0, '', 'bi-calendar-event', 'Within 30 days · ' . (int) $stats['expired'] . ' expired', (int) $stats['expiring_soon'] > 0],
    ];
    foreach ($cards as [$label, $value, $dec, $prefix, $icon, $hint, $alert]): ?>
        <div class="col-6 col-xl-3">
            <div class="card card-interactive h-100 reveal">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="stat-title"><?= e($label) ?></span>
                        <?php if ($alert): ?><span class="pulse-dot" title="Needs attention"></span><?php else: ?><i class="bi <?= $icon ?> stat-icon-sm"></i><?php endif; ?>
                    </div>
                    <div class="stat-value" data-count="<?= (float) $value ?>" data-decimals="<?= $dec ?>" data-prefix="<?= $prefix ?>">0</div>
                    <div class="stat-hint text-truncate"><?= e($hint) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Charts -->
<div class="row g-3 g-xl-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100 reveal">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h2 class="card-title">Stock movement</h2>
                    <p class="card-desc">Units received vs. released over the last 7 days</p>
                </div>
                <div class="d-flex gap-4">
                    <div>
                        <div class="text-xs text-muted-fg d-flex align-items-center gap-1"><span class="d-inline-block rounded-1" style="width:8px;height:8px;background:hsl(var(--chart-1))"></span>Stock in</div>
                        <div class="fs-5 fw-semibold tabular" data-count="<?= $weekIn ?>">0</div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-fg d-flex align-items-center gap-1"><span class="d-inline-block rounded-1" style="width:8px;height:8px;background:hsl(var(--chart-2))"></span>Stock out</div>
                        <div class="fs-5 fw-semibold tabular" data-count="<?= $weekOut ?>">0</div>
                    </div>
                </div>
            </div>
            <div class="card-body pt-3 d-flex flex-column"><div class="flex-grow-1 position-relative" style="min-height:260px"><canvas id="movementChart" aria-label="Stock movement chart" role="img"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100 reveal">
            <div class="card-header">
                <h2 class="card-title">Units by category</h2>
                <p class="card-desc">Share of on-hand stock</p>
            </div>
            <div class="card-body d-flex flex-column">
                <div class="position-relative mx-auto" style="height:180px;width:180px">
                    <canvas id="categoryChart" aria-label="Units by category chart" role="img"></canvas>
                    <div class="position-absolute top-50 start-50 translate-middle text-center pe-none">
                        <div class="fs-3 fw-bold tracking-tight tabular" data-count="<?= (int) $totalUnitsCat ?>">0</div>
                        <div class="text-xs text-muted-fg">Units</div>
                    </div>
                </div>
                <div class="mt-4 d-flex flex-column gap-2" id="catLegend"></div>
            </div>
        </div>
    </div>
</div>

<!-- Alerts -->
<div class="row g-3 g-xl-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100 reveal">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h2 class="card-title">Needs restocking</h2>
                    <p class="card-desc">At or below the reorder level</p>
                </div>
                <a href="products.php?status=low" class="btn btn-ghost btn-sm">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            <div>
                <?php if (!$lowStock): ?>
                    <?= empty_state('bi-check2-circle', 'All stocked up', 'Every product is above its reorder level.') ?>
                <?php endif; ?>
                <?php foreach ($lowStock as $p):
                    $pct = $p['reorder_level'] > 0 ? min(100, round($p['quantity'] / ($p['reorder_level'] * 2) * 100)) : 100; ?>
                    <div class="list-row">
                        <?= product_thumb($p['name']) ?>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="fw-medium text-truncate"><?= e($p['name']) ?></span>
                                <span class="text-xs text-muted-fg text-nowrap tabular"><?= (int) $p['quantity'] ?> / <?= (int) $p['reorder_level'] ?> <?= e($p['unit']) ?></span>
                            </div>
                            <div class="s-progress mt-2"><span class="<?= $p['quantity'] <= 0 ? 'danger' : 'warning' ?>" data-width="<?= $pct ?>"></span></div>
                        </div>
                        <a href="stock.php?product_id=<?= (int) $p['id'] ?>&amp;type=in" class="btn btn-outline btn-icon btn-sm" data-bs-toggle="tooltip" title="Stock in" aria-label="Stock in <?= e($p['name']) ?>">
                            <i class="bi bi-plus"></i>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100 reveal">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h2 class="card-title">Expiring soon</h2>
                    <p class="card-desc">Perishables expiring in the next 30 days</p>
                </div>
                <a href="products.php?status=expiring" class="btn btn-ghost btn-sm">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            <div>
                <?php if (!$expiring): ?>
                    <?= empty_state('bi-calendar-check', 'Nothing expiring', 'No products expire in the next 30 days.') ?>
                <?php endif; ?>
                <?php foreach ($expiring as $p):
                    $d = days_until($p['expiry_date']);
                    $variant = $d < 0 ? 'neutral' : ($d <= 7 ? 'danger' : 'warning');
                    $text = $d < 0 ? 'Expired ' . abs($d) . 'd ago' : ($d === 0 ? 'Today' : "In $d day" . ($d > 1 ? 's' : '')); ?>
                    <div class="list-row">
                        <?= product_thumb($p['name']) ?>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-medium text-truncate"><?= e($p['name']) ?></div>
                            <div class="text-xs text-muted-fg"><?= nice_date($p['expiry_date']) ?> · <?= (int) $p['quantity'] ?> left</div>
                        </div>
                        <span class="s-badge s-badge-<?= $variant ?>"><?= $text ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent activity + summary -->
<div class="row g-3 g-xl-4">
    <div class="col-lg-8">
        <div class="card h-100 reveal">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h2 class="card-title">Recent activity</h2>
                    <p class="card-desc">Latest stock movements</p>
                </div>
                <a href="stock.php" class="btn btn-ghost btn-sm">Open log <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover animate-rows">
                    <thead><tr><th>Product</th><th>Type</th><th class="text-end">Qty</th><th class="d-none d-md-table-cell">By</th><th class="text-end">When</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?>
                        <tr><td colspan="5"><?= empty_state('bi-clock-history', 'No activity yet', 'Stock movements will appear here.') ?></td></tr>
                    <?php endif; ?>
                    <?php
                    $typeMap = ['in' => ['success', 'Stock in', '+'], 'out' => ['danger', 'Stock out', '−'], 'adjust' => ['neutral', 'Adjusted', '=']];
                    foreach ($recent as $m): [$v, $lbl, $sign] = $typeMap[$m['type']]; ?>
                        <tr>
                            <td class="fw-medium"><?= e($m['product']) ?></td>
                            <td><span class="s-badge s-badge-<?= $v ?>"><?= $lbl ?></span></td>
                            <td class="text-end tabular fw-medium"><?= $sign . (int) $m['quantity'] ?></td>
                            <td class="d-none d-md-table-cell text-muted-fg"><?= e($m['user'] ?? '—') ?></td>
                            <td class="text-end text-muted-fg text-nowrap"><?= nice_date($m['created_at'], 'M d, g:i A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100 reveal">
            <div class="card-header">
                <h2 class="card-title">Inventory summary</h2>
                <p class="card-desc">If all current stock sells at retail</p>
            </div>
            <div class="card-body">
                <div class="text-xs text-muted-fg">Potential profit</div>
                <div class="d-flex flex-wrap align-items-baseline gap-2">
                    <div class="fs-2 fw-bold tracking-tight tabular" data-count="<?= $profit ?>" data-decimals="2" data-prefix="₱">0</div>
                    <span class="s-badge s-badge-success"><i class="bi bi-arrow-up-right"></i><?= number_format($marginPct, 1) ?>% margin</span>
                </div>
                <hr class="my-4">
                <dl class="row g-0 mb-0 text-sm" style="row-gap:.75rem">
                    <dt class="col-7 fw-normal text-muted-fg">Retail value</dt><dd class="col-5 text-end mb-0 fw-medium tabular"><?= peso($stats['inventory_retail']) ?></dd>
                    <dt class="col-7 fw-normal text-muted-fg">Cost value</dt><dd class="col-5 text-end mb-0 fw-medium tabular"><?= peso($stats['inventory_cost']) ?></dd>
                    <dt class="col-7 fw-normal"><a href="categories.php" class="text-muted-fg">Categories</a></dt><dd class="col-5 text-end mb-0 fw-medium"><?= $categoryCount ?></dd>
                    <dt class="col-7 fw-normal"><a href="suppliers.php" class="text-muted-fg">Suppliers</a></dt><dd class="col-5 text-end mb-0 fw-medium"><?= $supplierCount ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php
// Chart data passed to JavaScript as JSON
$chartData = [
    'days'      => array_column(array_values($days), 'label'),
    'in'        => array_column(array_values($days), 'in'),
    'out'       => array_column(array_values($days), 'out'),
    'catLabels' => array_column($catRows, 'name'),
    'catValues' => array_map('intval', array_column($catRows, 'units')),
];
$pageScript = '<script>window.dashboardData = ' . json_encode($chartData, JSON_HEX_TAG) . ';</script>
<script src="assets/js/dashboard.js?v=2"></script>';
require __DIR__ . '/includes/footer.php';
