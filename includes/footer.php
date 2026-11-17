        </main>

        <footer class="app-footer d-flex flex-column flex-sm-row justify-content-between gap-1">
            <span>&copy; <?= date('Y') ?> FreshStock · Grocery Stock Management System</span>
            <span>PHP · MySQL · Bootstrap 5</span>
        </footer>
    </div>
</div>

<!-- ============ Alert dialog (delete confirmation) ============ -->
<div class="modal fade" id="confirmDialog" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:28rem">
        <div class="modal-content">
            <div class="modal-header flex-column gap-2">
                <h2 class="modal-title" id="confirmTitle">Are you absolutely sure?</h2>
                <p class="text-muted-fg mb-0" id="confirmText">This action cannot be undone.</p>
            </div>
            <form method="post" id="deleteForm" class="modal-footer pt-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger" id="confirmBtn">Delete</button>
            </form>
        </div>
    </div>
</div>

<!-- ============ Command menu (Ctrl/⌘ + K) ============ -->
<div class="modal fade cmd-dialog" id="cmdk" tabindex="-1" aria-label="Command menu" aria-hidden="true">
    <div class="modal-dialog" style="max-width:32rem;margin-top:12vh">
        <div class="modal-content">
            <div class="cmd-input-wrap">
                <i class="bi bi-search"></i>
                <input type="text" class="cmd-input" id="cmdInput" placeholder="Type a command or search products..." autocomplete="off">
                <kbd>Esc</kbd>
            </div>
            <div class="cmd-list" id="cmdList" role="listbox"></div>
        </div>
    </div>
</div>
<script>
    // Data for the command menu (pages + products)
    window.cmdData = {
        pages: [
            { label: 'Dashboard', icon: 'bi-grid-1x2', url: 'index.php' },
            { label: 'Products', icon: 'bi-box-seam', url: 'products.php' },
            { label: 'Add new product', icon: 'bi-plus-circle', url: 'product_form.php' },
            { label: 'Stock In / Out', icon: 'bi-arrow-left-right', url: 'stock.php' },
            { label: 'Low stock items', icon: 'bi-exclamation-triangle', url: 'products.php?status=low' },
            { label: 'Expiring soon', icon: 'bi-calendar-event', url: 'products.php?status=expiring' },
            { label: 'Categories', icon: 'bi-tags', url: 'categories.php' },
            { label: 'Suppliers', icon: 'bi-truck', url: 'suppliers.php' },
            { label: 'Export products (CSV)', icon: 'bi-download', url: 'export.php' },
            { label: 'Log out', icon: 'bi-box-arrow-right', url: 'logout.php' }
        ],
        products: <?= json_encode(array_map(fn($p) => [
            'id' => (int) $p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'qty' => (int) $p['quantity'],
        ], $cmdProducts ?? []), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>
    };
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($useCharts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="assets/js/app.js?v=2"></script>
<?php if (!empty($pageScript)) echo $pageScript; ?>
</body>
</html>
