<?php
/**
 * product_delete.php — DELETE a product (POST only, CSRF protected).
 * Related stock_movements rows are removed automatically (ON DELETE CASCADE).
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('products.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);

// Fetch the name first so we can show it in the message
$stmt = $conn->prepare('SELECT name FROM products WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    flash('danger', 'Product not found.');
    redirect('products.php');
}

$stmt = $conn->prepare('DELETE FROM products WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

flash('success', 'Product "' . $product['name'] . '" deleted.');
redirect('products.php');
