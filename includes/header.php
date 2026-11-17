<?php
/**
 * header.php
 * ---------------------------------------------------------------
 * Shared layout: <head>, sidebar navigation, top navbar with
 * breadcrumbs, command menu trigger, theme toggle and toasts.
 *
 * Set before including:
 *   $pageTitle   string  title shown in tab + breadcrumb
 *   $activePage  string  key of the active sidebar link
 *   $crumbParent array   optional [label, url] parent breadcrumb
 * ---------------------------------------------------------------
 */
$pageTitle   = $pageTitle   ?? 'Dashboard';
$activePage  = $activePage  ?? '';
$crumbParent = $crumbParent ?? null;

// Badge counts for the sidebar
$navLowCount = (int) $conn->query('SELECT COUNT(*) FROM products WHERE quantity <= reorder_level')->fetch_row()[0];

// Lightweight product index for the ⌘K command menu
$cmdProducts = $conn->query('SELECT id, name, sku, quantity FROM products ORDER BY name LIMIT 300')->fetch_all(MYSQLI_ASSOC);

$navGroups = [
    'Overview'  => [
        'dashboard' => ['index.php', 'bi-grid-1x2', 'Dashboard', 0],
    ],
    'Inventory' => [
        'products'  => ['products.php', 'bi-box-seam', 'Products', $navLowCount],
        'stock'     => ['stock.php', 'bi-arrow-left-right', 'Stock In / Out', 0],
    ],
    'Manage'    => [
        'categories' => ['categories.php', 'bi-tags', 'Categories', 0],
        'suppliers'  => ['suppliers.php', 'bi-truck', 'Suppliers', 0],
    ],
];
$fullName = $_SESSION['full_name'] ?? 'Admin';
$initials = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', array_slice(preg_split('/\s+/', $fullName), 0, 2))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · FreshStock</title>
    <link rel="icon" href="images/logo.svg" type="image/svg+xml">

    <!-- Apply saved theme before first paint (prevents a white flash) -->
    <script>
        (function () {
            var t = 'light';
            try { t = localStorage.getItem('theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (e) {}
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=2" rel="stylesheet">
</head>
<body>
<div id="nprogress"></div>

<div class="app-shell">

    <!-- ============ SIDEBAR (offcanvas on mobile) ============ -->
    <aside class="sidebar offcanvas-lg offcanvas-start" id="sidebar" tabindex="-1" aria-label="Main navigation">
        <div class="sidebar-header d-flex align-items-center justify-content-between">
            <a href="index.php" class="brand flex-grow-1">
                <span class="brand-mark"><i class="bi bi-basket2"></i></span>
                <span>
                    <span class="brand-title d-block">FreshStock</span>
                    <span class="brand-sub">Convenience Store</span>
                </span>
            </a>
            <button type="button" class="btn btn-ghost btn-icon btn-sm d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <nav class="sidebar-content">
            <?php foreach ($navGroups as $group => $items): ?>
                <div class="sidebar-group">
                    <div class="sidebar-label"><?= $group ?></div>
                    <?php foreach ($items as $key => [$href, $icon, $label, $count]): ?>
                        <a href="<?= $href ?>" class="sidebar-link <?= $activePage === $key ? 'active' : '' ?>" <?= $activePage === $key ? 'aria-current="page"' : '' ?>>
                            <i class="bi <?= $icon ?>"></i>
                            <span><?= $label ?></span>
                            <?php if ($count > 0): ?>
                                <span class="count" title="<?= $count ?> low-stock item(s)"><?= $count ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="sidebar-group">
                <div class="sidebar-label">Quick actions</div>
                <a href="product_form.php" class="sidebar-link"><i class="bi bi-plus-circle"></i><span>New product</span></a>
                <a href="export.php" class="sidebar-link"><i class="bi bi-download"></i><span>Export CSV</span></a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="dropup">
                <button class="user-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="s-avatar sm"><?= e($initials) ?></span>
                    <span class="min-w-0 flex-grow-1">
                        <span class="d-block fw-medium text-truncate"><?= e($fullName) ?></span>
                        <span class="d-block text-xs text-muted-fg text-truncate">@<?= e($_SESSION['username'] ?? '') ?></span>
                    </span>
                    <i class="bi bi-chevron-expand text-muted-fg"></i>
                </button>
                <ul class="dropdown-menu w-100">
                    <li><h6 class="dropdown-header text-xs">My account</h6></li>
                    <li><button class="dropdown-item" type="button" data-theme-toggle><i class="bi bi-circle-half"></i> Toggle theme</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-destructive" href="logout.php"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
                </ul>
            </div>
        </div>
    </aside>

    <!-- ============ MAIN ============ -->
    <div class="app-main">
        <!-- Top navbar -->
        <header class="topbar">
            <button class="btn btn-ghost btn-icon btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
                <i class="bi bi-layout-sidebar"></i>
            </button>
            <div class="vr d-lg-none"></div>
            <nav class="crumbs" aria-label="Breadcrumb">
                <a href="index.php" class="d-none d-sm-inline">FreshStock</a>
                <i class="bi bi-chevron-right text-xs d-none d-sm-inline"></i>
                <?php if ($crumbParent): ?>
                    <a href="<?= e($crumbParent[1]) ?>"><?= e($crumbParent[0]) ?></a>
                    <i class="bi bi-chevron-right text-xs"></i>
                <?php endif; ?>
                <span class="current"><?= e($pageTitle) ?></span>
            </nav>

            <div class="ms-auto d-flex align-items-center gap-2">
                <button type="button" class="search-trigger d-none d-md-inline-flex" data-bs-toggle="modal" data-bs-target="#cmdk">
                    <i class="bi bi-search"></i><span class="flex-grow-1 text-start">Search...</span><kbd>Ctrl K</kbd>
                </button>
                <button type="button" class="btn btn-ghost btn-icon d-md-none" data-bs-toggle="modal" data-bs-target="#cmdk" aria-label="Search">
                    <i class="bi bi-search"></i>
                </button>
                <button type="button" class="btn btn-outline btn-icon" data-theme-toggle aria-label="Toggle theme" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Toggle theme">
                    <i class="bi bi-sun theme-icon-light"></i>
                </button>
            </div>
        </header>

        <!-- Sonner-style toasts for flash messages -->
        <div class="toaster" id="toaster" aria-live="polite">
            <?php
            $toastIcons = ['success' => 'bi-check-circle-fill', 'danger' => 'bi-x-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'];
            foreach (get_flashes() as $f): ?>
                <div class="s-toast <?= e($f['type']) ?>" role="status">
                    <i class="bi <?= $toastIcons[$f['type']] ?? 'bi-info-circle-fill' ?> t-icon"></i>
                    <div class="t-body"><?= e($f['message']) ?></div>
                    <button type="button" class="t-close" aria-label="Dismiss"><i class="bi bi-x"></i></button>
                </div>
            <?php endforeach; ?>
        </div>

        <main class="page">
