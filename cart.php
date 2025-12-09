<?php
declare(strict_types=1);

require __DIR__ . '/security.php';
require __DIR__ . '/db.php';

startSecureSession();
$csrfToken = getCsrfToken();

$cartCount = 0;
if (!empty($_SESSION['cart_items']) && is_array($_SESSION['cart_items'])) {
    foreach ($_SESSION['cart_items'] as $line) {
        $cartCount += (int) ($line['quantity'] ?? 0);
    }
}

$userEmail = $_SESSION['user_email'] ?? 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Cart — Coffee Kiosk</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; }
        .panel { border: 1px solid #ccc; padding: 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .flex { display: flex; gap: 1rem; align-items: center; justify-content: space-between; }
        .cart-item { border-bottom: 1px solid #eee; padding: 0.75rem 0; display: flex; justify-content: space-between; gap: 1rem; }
        .cart-item:last-child { border-bottom: none; }
        .muted { color: #666; }
        button { cursor: pointer; }
    </style>
</head>
<body>
    <header class="flex" style="margin-bottom: 1rem;">
        <div>
            <strong>Coffee Kiosk Cart</strong><br>
            Logged in as <?= htmlspecialchars($userEmail); ?>
        </div>
        <nav>
            <a href="index.php">Back to Menu</a>
            <span style="margin-left: 1rem;">Cart: <span id="cartCount" data-cart-count><?= (int) $cartCount; ?></span></span>
        </nav>
    </header>

    <div class="panel" data-cart-app data-csrf="<?= htmlspecialchars($csrfToken); ?>">
        <h2>Your Cart</h2>
        <div id="cartItems"></div>
        <div id="cartTotals" class="muted"></div>
        <p class="muted" id="cartStatus"></p>
        <noscript>Enable JavaScript to edit the cart without reloading.</noscript>
    </div>

    <script src="assets/app.js" defer></script>
</body>
</html>
