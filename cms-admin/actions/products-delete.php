<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/products.php', true, 302);
    exit;
}

$redirect = '../pages/products.php';
$deleteId = (int) ($_POST['id'] ?? 0);

if ($deleteId <= 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid product.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$pdo->prepare('DELETE FROM product_images WHERE product_id = :id')->execute(['id' => $deleteId]);
$delete = $pdo->prepare('DELETE FROM products WHERE id = :id');
$delete->execute(['id' => $deleteId]);

if ($delete->rowCount() < 1) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Product not found or already deleted.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Product deleted successfully.'];
header('Location: ' . $redirect, true, 302);
exit;
