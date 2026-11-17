<?php
/**
 * export.php — Download the product list as a CSV file (opens in Excel).
 * Accepts the same q / category / supplier / status filters as products.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$q        = trim($_GET['q'] ?? '');
$category = (int) ($_GET['category'] ?? 0);
$supplier = (int) ($_GET['supplier'] ?? 0);
$status   = $_GET['status'] ?? '';

$where = [];
$params = [];
$types = '';
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
    $types .= 'ss';
}
if ($category > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $category;
    $types .= 'i';
}
if ($supplier > 0) {
    $where[] = 'p.supplier_id = ?';
    $params[] = $supplier;
    $types .= 'i';
}
$statusSql = [
    'in'       => 'p.quantity > p.reorder_level',
    'low'      => 'p.quantity <= p.reorder_level',
    'out'      => 'p.quantity <= 0',
    'expiring' => 'p.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)',
    'expired'  => 'p.expiry_date < CURDATE()',
];
if (isset($statusSql[$status])) {
    $where[] = $statusSql[$status];
}

$sql = 'SELECT p.*, c.name AS category_name, s.name AS supplier_name
        FROM products p
        JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY p.name';
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="products_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows symbols correctly
fputcsv($out, ['SKU', 'Product', 'Category', 'Supplier', 'Unit', 'Cost Price', 'Selling Price',
    'Quantity', 'Reorder Level', 'Stock Value', 'Expiry Date', 'Status']);

while ($p = $result->fetch_assoc()) {
    [$label] = stock_status($p);
    fputcsv($out, [
        $p['sku'], $p['name'], $p['category_name'], $p['supplier_name'] ?? '', $p['unit'],
        $p['cost_price'], $p['selling_price'], $p['quantity'], $p['reorder_level'],
        number_format($p['quantity'] * $p['cost_price'], 2, '.', ''),
        $p['expiry_date'] ?? '', $label,
    ]);
}
fclose($out);
exit;
