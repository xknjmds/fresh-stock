<?php
/**
 * categories.php — Full CRUD for product categories.
 * Add / edit through a Bootstrap modal (dialog), delete through the shared
 * confirmation dialog. A category that still has products cannot be
 * deleted (foreign key ON DELETE RESTRICT).
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

/* ---------- Handle POST actions (create / update / delete) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action      = $_POST['action'] ?? '';
    $id          = (int) ($_POST['id'] ?? 0);
    $name        = post('name');
    $description = post('description');

    try {
        if ($action === 'create' || $action === 'update') {
            if ($name === '' || mb_strlen($name) > 80) {
                flash('danger', 'Category name is required (max 80 characters).');
                redirect('categories.php');
            }
            if ($action === 'create') {
                // INSERT
                $stmt = $conn->prepare('INSERT INTO categories (name, description) VALUES (?, ?)');
                $stmt->bind_param('ss', $name, $description);
                $stmt->execute();
                flash('success', "Category \"$name\" created.");
            } else {
                // UPDATE
                $stmt = $conn->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?');
                $stmt->bind_param('ssi', $name, $description, $id);
                $stmt->execute();
                flash('success', "Category \"$name\" updated.");
            }
        } elseif ($action === 'delete') {
            // DELETE
            $stmt = $conn->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            flash($stmt->affected_rows ? 'success' : 'warning', $stmt->affected_rows ? 'Category deleted.' : 'Category not found.');
        }
    } catch (mysqli_sql_exception $e) {
        // 1062 = duplicate entry, 1451 = row is referenced by a foreign key
        if ($e->getCode() === 1062) {
            flash('danger', "A category named \"$name\" already exists.");
        } elseif ($e->getCode() === 1451) {
            flash('danger', 'This category still has products. Move or delete those products first.');
        } else {
            flash('danger', 'Something went wrong. Please try again.');
        }
    }
    redirect('categories.php');
}

/* ---------- SELECT categories with product stats ---------- */
$categories = $conn->query("
    SELECT c.*, COUNT(p.id) AS product_count,
           COALESCE(SUM(p.quantity), 0) AS units,
           COALESCE(SUM(p.quantity * p.cost_price), 0) AS value,
           COALESCE(SUM(p.quantity <= p.reorder_level), 0) AS low
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name
")->fetch_all(MYSQLI_ASSOC);

/** Pick an icon based on keywords in the category name (purely visual). */
function category_icon(string $name): string
{
    $map = [
        'bever' => 'bi-cup-straw', 'drink' => 'bi-cup-straw', 'snack' => 'bi-cookie', 'can' => 'bi-archive',
        'dairy' => 'bi-droplet-half', 'egg' => 'bi-egg', 'instant' => 'bi-fire', 'noodle' => 'bi-fire',
        'bread' => 'bi-basket', 'bake' => 'bi-basket', 'personal' => 'bi-person-heart', 'care' => 'bi-person-heart',
        'house' => 'bi-house', 'clean' => 'bi-stars', 'fruit' => 'bi-apple', 'frozen' => 'bi-snow',
        'meat' => 'bi-shop', 'condiment' => 'bi-cup-hot', 'rice' => 'bi-bag',
    ];
    foreach ($map as $k => $icon) {
        if (stripos($name, $k) !== false) {
            return $icon;
        }
    }
    return 'bi-tag';
}

$pageTitle  = 'Categories';
$activePage = 'categories';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1>Categories</h1>
        <p>Group products so they're easier to find and report on.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" data-mode="create">
        <i class="bi bi-plus-lg"></i> New category
    </button>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="input-icon" style="width:20rem;max-width:100%">
        <i class="bi bi-search"></i>
        <input type="search" class="form-control" placeholder="Filter categories..." id="catSearch" aria-label="Filter categories">
    </div>
    <span class="text-sm text-muted-fg"><?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?></span>
</div>

<div class="row g-3 g-xl-4" id="catGrid">
    <?php if (!$categories): ?>
        <div class="col-12"><div class="card border-dashed">
            <?= empty_state('bi-tags', 'No categories yet', 'Create your first category to start organizing products.',
                '<button class="btn btn-outline btn-sm" data-bs-toggle="modal" data-bs-target="#categoryModal" data-mode="create"><i class="bi bi-plus-lg"></i> New category</button>') ?>
        </div></div>
    <?php endif; ?>
    <?php foreach ($categories as $c): ?>
        <div class="col-sm-6 col-xl-3 cat-item" data-search="<?= e(strtolower($c['name'] . ' ' . $c['description'])) ?>">
            <div class="card card-interactive h-100 reveal">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <span class="icon-tile"><i class="bi <?= category_icon($c['name']) ?>"></i></span>
                        <div class="dropdown">
                            <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= e($c['name']) ?>"><i class="bi bi-three-dots"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="products.php?category=<?= $c['id'] ?>"><i class="bi bi-box-seam"></i> View products</a></li>
                                <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#categoryModal" data-mode="update"
                                            data-id="<?= $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-description="<?= e($c['description']) ?>">
                                        <i class="bi bi-pencil"></i> Edit</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button class="dropdown-item text-destructive btn-delete" data-id="<?= $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-url="categories.php"
                                            data-warning="<?= $c['product_count'] ? 'This category still has ' . (int) $c['product_count'] . ' product(s), so the database will block the deletion. Move those products first.' : 'This permanently deletes the category. This action cannot be undone.' ?>">
                                        <i class="bi bi-trash3"></i> Delete</button></li>
                            </ul>
                        </div>
                    </div>
                    <h2 class="h6 fw-semibold mb-1"><?= e($c['name']) ?></h2>
                    <p class="text-sm text-muted-fg flex-grow-1 mb-3"><?= e($c['description'] ?: 'No description.') ?></p>

                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <span class="s-badge s-badge-secondary"><?= (int) $c['product_count'] ?> product<?= $c['product_count'] == 1 ? '' : 's' ?></span>
                        <?php if ($c['low'] > 0): ?>
                            <a href="products.php?category=<?= $c['id'] ?>&amp;status=low" class="s-badge s-badge-warning"><span class="dot"></span><?= (int) $c['low'] ?> low</a>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex justify-content-between text-sm border-top pt-3">
                        <div><div class="text-xs text-muted-fg">Units</div><div class="fw-semibold tabular"><?= number_format((int) $c['units']) ?></div></div>
                        <div class="text-end"><div class="text-xs text-muted-fg">Stock value</div><div class="fw-semibold tabular"><?= peso($c['value']) ?></div></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-12 d-none" id="catEmpty"><div class="card border-dashed"><?= empty_state('bi-search', 'No matches', 'No categories match your filter.') ?></div></div>
</div>

<!-- Create / Edit dialog -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:28rem">
        <form method="post" class="modal-content needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="catAction" value="create">
            <input type="hidden" name="id" id="catId">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="categoryModalLabel">New category</h2>
                    <p class="text-muted-fg mb-0 mt-1" id="catModalDesc">Add a new group for your products.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body d-flex flex-column gap-3">
                <div>
                    <label class="form-label" for="catName">Name</label>
                    <input type="text" class="form-control" id="catName" name="name" maxlength="80" required placeholder="e.g. Frozen Goods">
                    <div class="invalid-feedback">Category name is required.</div>
                </div>
                <div>
                    <label class="form-label" for="catDesc">Description <span class="text-muted-fg fw-normal">(optional)</span></label>
                    <textarea class="form-control" id="catDesc" name="description" rows="3" maxlength="255" placeholder="What kind of items belong here?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="catSubmit">Create category</button>
            </div>
        </form>
    </div>
</div>

<?php
$pageScript = <<<'JS'
<script>
// Fill the dialog for "create" or "update" mode
(function () {
    var modal = document.getElementById('categoryModal');
    modal.addEventListener('show.bs.modal', function (e) {
        var b = e.relatedTarget, edit = b && b.dataset.mode === 'update';
        var form = modal.querySelector('form'), submit = document.getElementById('catSubmit');
        form.classList.remove('was-validated');
        submit.disabled = false;
        submit.textContent = edit ? 'Save changes' : 'Create category';
        document.getElementById('catAction').value = edit ? 'update' : 'create';
        document.getElementById('catId').value = edit ? b.dataset.id : '';
        document.getElementById('catName').value = edit ? b.dataset.name : '';
        document.getElementById('catDesc').value = edit ? b.dataset.description : '';
        document.getElementById('categoryModalLabel').textContent = edit ? 'Edit category' : 'New category';
        document.getElementById('catModalDesc').textContent = edit ? 'Make changes to this category.' : 'Add a new group for your products.';
    });
    modal.addEventListener('shown.bs.modal', function () { document.getElementById('catName').focus(); });

    // Live filter over the cards
    document.getElementById('catSearch').addEventListener('input', function () {
        var q = this.value.toLowerCase().trim(), shown = 0;
        document.querySelectorAll('.cat-item').forEach(function (el) {
            var hit = el.dataset.search.indexOf(q) !== -1;
            el.classList.toggle('d-none', !hit);
            if (hit) shown++;
        });
        document.getElementById('catEmpty').classList.toggle('d-none', shown > 0);
    });
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
