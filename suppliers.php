<?php
/**
 * suppliers.php — Full CRUD for suppliers.
 * Add / edit through a Bootstrap modal (dialog), delete through the shared
 * confirmation dialog. A supplier linked to products cannot be deleted
 * (foreign key ON DELETE RESTRICT).
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

/* ---------- Handle POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action  = $_POST['action'] ?? '';
    $id      = (int) ($_POST['id'] ?? 0);
    $name    = post('name');
    $contact = post('contact_person');
    $phone   = post('phone');
    $email   = post('email');
    $address = post('address');

    try {
        if ($action === 'create' || $action === 'update') {
            // Server-side validation
            if ($name === '' || mb_strlen($name) > 120) {
                flash('danger', 'Supplier name is required (max 120 characters).');
                redirect('suppliers.php');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('danger', 'Please enter a valid email address.');
                redirect('suppliers.php');
            }
            if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,30}$/', $phone)) {
                flash('danger', 'Please enter a valid phone number.');
                redirect('suppliers.php');
            }

            if ($action === 'create') {
                // INSERT
                $stmt = $conn->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('sssss', $name, $contact, $phone, $email, $address);
                $stmt->execute();
                flash('success', "Supplier \"$name\" added.");
            } else {
                // UPDATE
                $stmt = $conn->prepare('UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE id=?');
                $stmt->bind_param('sssssi', $name, $contact, $phone, $email, $address, $id);
                $stmt->execute();
                flash('success', "Supplier \"$name\" updated.");
            }
        } elseif ($action === 'delete') {
            // DELETE
            $stmt = $conn->prepare('DELETE FROM suppliers WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            flash($stmt->affected_rows ? 'success' : 'warning', $stmt->affected_rows ? 'Supplier deleted.' : 'Supplier not found.');
        }
    } catch (mysqli_sql_exception $e) {
        flash('danger', $e->getCode() === 1451
            ? 'This supplier is still linked to products. Reassign those products first.'
            : 'Something went wrong. Please try again.');
    }
    redirect('suppliers.php');
}

/* ---------- SELECT suppliers with product counts ---------- */
$suppliers = $conn->query("
    SELECT s.*, COUNT(p.id) AS product_count
    FROM suppliers s
    LEFT JOIN products p ON p.supplier_id = s.id
    GROUP BY s.id
    ORDER BY s.name
")->fetch_all(MYSQLI_ASSOC);

$pageTitle  = 'Suppliers';
$activePage = 'suppliers';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1>Suppliers</h1>
        <p>The distributors and companies that deliver your stock.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supplierModal" data-mode="create">
        <i class="bi bi-plus-lg"></i> Add supplier
    </button>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="input-icon" style="width:20rem;max-width:100%">
        <i class="bi bi-search"></i>
        <input type="search" class="form-control" placeholder="Filter suppliers..." data-table-filter="#supplierTable" aria-label="Filter suppliers">
    </div>
    <span class="text-sm text-muted-fg"><?= count($suppliers) ?> supplier<?= count($suppliers) === 1 ? '' : 's' ?></span>
</div>

<div class="table-card">
    <div class="table-responsive">
        <table class="table table-hover animate-rows" id="supplierTable">
            <thead>
            <tr>
                <th>Supplier</th>
                <th>Contact</th>
                <th class="d-none d-lg-table-cell">Address</th>
                <th class="text-center">Products</th>
                <th><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$suppliers): ?>
                <tr class="no-filter"><td colspan="5"><?= empty_state('bi-truck', 'No suppliers yet', 'Add the companies that deliver your stock.') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($suppliers as $s):
                $words = preg_split('/\s+/', $s['name']);
                $ini = strtoupper(substr($words[0], 0, 1) . substr($words[1] ?? '', 0, 1)); ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <span class="s-avatar round"><?= e($ini) ?></span>
                            <div class="min-w-0">
                                <div class="fw-medium"><?= e($s['name']) ?></div>
                                <div class="text-xs text-muted-fg">Added <?= nice_date($s['created_at'], 'M Y') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="fw-medium"><?= e($s['contact_person'] ?: '—') ?></div>
                        <div class="text-xs text-muted-fg d-flex flex-column">
                            <?php if ($s['phone']): ?><a href="tel:<?= e($s['phone']) ?>" class="text-muted-fg"><i class="bi bi-telephone me-1"></i><?= e($s['phone']) ?></a><?php endif; ?>
                            <?php if ($s['email']): ?><a href="mailto:<?= e($s['email']) ?>" class="text-muted-fg"><i class="bi bi-envelope me-1"></i><?= e($s['email']) ?></a><?php endif; ?>
                        </div>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted-fg"><?= e($s['address'] ?: '—') ?></td>
                    <td class="text-center">
                        <a href="products.php?supplier=<?= $s['id'] ?>" class="s-badge s-badge-secondary" data-bs-toggle="tooltip" title="View products"><?= (int) $s['product_count'] ?></a>
                    </td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Actions for <?= e($s['name']) ?>">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header text-xs">Actions</h6></li>
                                <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#supplierModal" data-mode="update"
                                            data-id="<?= $s['id'] ?>" data-name="<?= e($s['name']) ?>" data-contact="<?= e($s['contact_person']) ?>"
                                            data-phone="<?= e($s['phone']) ?>" data-email="<?= e($s['email']) ?>" data-address="<?= e($s['address']) ?>">
                                        <i class="bi bi-pencil"></i> Edit</button></li>
                                <li><a class="dropdown-item" href="products.php?supplier=<?= $s['id'] ?>"><i class="bi bi-box-seam"></i> View products</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button class="dropdown-item text-destructive btn-delete" data-id="<?= $s['id'] ?>" data-name="<?= e($s['name']) ?>" data-url="suppliers.php"
                                            data-warning="<?= $s['product_count'] ? 'This supplier is linked to ' . (int) $s['product_count'] . ' product(s), so the database will block the deletion. Reassign those products first.' : 'This permanently deletes the supplier. This action cannot be undone.' ?>">
                                        <i class="bi bi-trash3"></i> Delete</button></li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr class="no-filter filter-empty" style="display:none"><td colspan="5"><?= empty_state('bi-search', 'No matches', 'No suppliers match your filter.') ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit dialog -->
<div class="modal fade" id="supplierModal" tabindex="-1" aria-labelledby="supplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:36rem">
        <form method="post" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="supAction" value="create">
            <input type="hidden" name="id" id="supId">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="supplierModalLabel">Add supplier</h2>
                    <p class="text-muted-fg mb-0 mt-1" id="supModalDesc">Enter the supplier's company and contact details.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="supName">Company name</label>
                    <input type="text" class="form-control" id="supName" name="name" maxlength="120" required>
                    <div class="invalid-feedback">Supplier name is required.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="supContact">Contact person</label>
                    <input type="text" class="form-control" id="supContact" name="contact_person" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="supPhone">Phone</label>
                    <input type="tel" class="form-control" id="supPhone" name="phone" maxlength="30" pattern="[0-9+\-\s()]{6,30}" placeholder="0917-555-0000">
                    <div class="invalid-feedback">Enter a valid phone number.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="supEmail">Email</label>
                    <input type="email" class="form-control" id="supEmail" name="email" maxlength="120" placeholder="orders@supplier.com">
                    <div class="invalid-feedback">Enter a valid email address.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="supAddress">Address</label>
                    <input type="text" class="form-control" id="supAddress" name="address" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="supSubmit">Add supplier</button>
            </div>
        </form>
    </div>
</div>

<?php
$pageScript = <<<'JS'
<script>
(function () {
    var modal = document.getElementById('supplierModal');
    modal.addEventListener('show.bs.modal', function (e) {
        var b = e.relatedTarget, edit = b && b.dataset.mode === 'update', d = edit ? b.dataset : {};
        var submit = document.getElementById('supSubmit');
        modal.querySelector('form').classList.remove('was-validated');
        submit.disabled = false;
        submit.textContent = edit ? 'Save changes' : 'Add supplier';
        document.getElementById('supAction').value  = edit ? 'update' : 'create';
        document.getElementById('supId').value      = d.id || '';
        document.getElementById('supName').value    = d.name || '';
        document.getElementById('supContact').value = d.contact || '';
        document.getElementById('supPhone').value   = d.phone || '';
        document.getElementById('supEmail').value   = d.email || '';
        document.getElementById('supAddress').value = d.address || '';
        document.getElementById('supplierModalLabel').textContent = edit ? 'Edit supplier' : 'Add supplier';
        document.getElementById('supModalDesc').textContent = edit ? 'Update this supplier\'s details.' : 'Enter the supplier\'s company and contact details.';
    });
    modal.addEventListener('shown.bs.modal', function () { document.getElementById('supName').focus(); });
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
