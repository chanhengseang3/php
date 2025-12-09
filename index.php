<?php
/**
 * index.php
 *
 * Main kiosk page: shows menu, accepts an order, validates input,
 * writes to MySQL, and displays a summary with totals and flavored creamer options.
 */

declare(strict_types=1);

require __DIR__ . '/security.php';
require __DIR__ . '/db.php';

$hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
$serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
$allowedHosts = array_filter([
    parse_url('http://' . $hostHeader, PHP_URL_HOST),
    parse_url('http://' . $serverName, PHP_URL_HOST),
]);

startSecureSession();
$csrfToken = getCsrfToken();
$pdo = getPdo();

$loginError = $_SESSION['login_error'] ?? '';
$loginSuccess = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_success']);

$isAuthenticated = (bool) ($_SESSION['is_authenticated'] ?? false);
$userEmail = $_SESSION['user_email'] ?? 'Guest';
$cartCount = 0;
if (!empty($_SESSION['cart_items']) && is_array($_SESSION['cart_items'])) {
    foreach ($_SESSION['cart_items'] as $line) {
        $cartCount += (int) ($line['quantity'] ?? 0);
    }
}

// Fetch catalog data once for display + validation.
$coffees = $pdo->query("SELECT id, name, description, base_price FROM coffees ORDER BY name")->fetchAll();
$sizes = $pdo->query("SELECT id, label, ounces, price_modifier FROM sizes ORDER BY ounces")->fetchAll();
$sweeteners = $pdo->query("SELECT id, name, additional_cost FROM sweeteners ORDER BY name")->fetchAll();
$creamers = $pdo->query("SELECT id, name, is_flavored, additional_cost FROM creamers ORDER BY name")->fetchAll();

$errors = [];
$orderSummary = null;
$coffeeId = 0;
$sizeId = 0;
$quantity = 1;
$selectedSweetenerIds = [];
$selectedCreamerIds = [];

// Handle form submission in a dedicated processor (step 5).
require __DIR__ . '/process_order.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coffee Kiosk Order</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; }
        .panel { border: 1px solid #ccc; padding: 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .error { color: #b00020; }
        .success { color: #0b6623; }
        label { display: block; margin-top: 0.5rem; }
        input[type="text"], input[type="email"], select, textarea {
            width: 100%;
            padding: 0.35rem;
            margin-top: 0.25rem;
        }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; }
        .muted { color: #555; }
        .menu-card { border: 1px solid #eee; padding: 0.75rem; border-radius: 6px; background: #fafafa; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .preview { margin-top: 0.5rem; font-weight: bold; }
        .form-actions { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <header class="topbar">
        <div>
            <h1 style="margin: 0;">Coffee Kiosk</h1>
            <div class="muted">Logged in as <?= h($userEmail); ?></div>
        </div>
        <div>
            <a href="cart.php">Cart (<span data-cart-count><?= (int) $cartCount; ?></span>)</a>
        </div>
    </header>

    <div class="panel">
        <h2>Menu</h2>
        <div class="grid" data-menu-grid>
            <p class="muted">Loading menu…</p>
        </div>
        <noscript>
            <div class="grid">
                <?php foreach ($coffees as $coffee): ?>
                    <div class="menu-card">
                        <strong><?= h($coffee['name']); ?></strong><br>
                        <span class="muted"><?= h($coffee['description']); ?></span><br>
                        Base: $<?= number_format((float) $coffee['base_price'], 2); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </noscript>
    </div>

    <div class="panel">
        <h2>Login</h2>
        <?php if ($loginError): ?>
            <p class="error"><?= h($loginError); ?></p>
        <?php endif; ?>
        <?php if ($loginSuccess): ?>
            <p class="success"><?= h($loginSuccess); ?></p>
        <?php endif; ?>

        <?php if ($isAuthenticated): ?>
            <p class="success">You are logged in as <?= h($userEmail); ?>.</p>
        <?php else: ?>
            <form method="post" action="login.php" data-login-form>
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                <label>
                    Email
                    <input type="email" name="login_email" required autocomplete="username">
                </label>
                <label>
                    Password
                    <input type="password" name="login_password" required autocomplete="current-password">
                </label>
                <button type="submit">Login</button>
                <span class="muted" data-login-status></span>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="panel error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($orderSummary): ?>
        <div class="panel success">
            <h2>Order Summary</h2>
            <p><?= h($orderSummary['customer']); ?> — <?= h($orderSummary['email']); ?></p>
            <p><?= h($orderSummary['quantity']); ?> x <?= h($orderSummary['size']); ?> <?= h($orderSummary['coffee']); ?></p>
            <p>Sweeteners: <?= $orderSummary['sweeteners'] ? h(implode(', ', $orderSummary['sweeteners'])) : 'None'; ?></p>
            <p>Creamers: <?= $orderSummary['creamers'] ? h(implode(', ', $orderSummary['creamers'])) : 'None'; ?></p>
            <p>Base total: $<?= number_format((float) $orderSummary['line_total'], 2); ?></p>
            <p>Extras total: $<?= number_format((float) $orderSummary['extras_total'], 2); ?></p>
            <p><strong>Grand total: $<?= number_format((float) $orderSummary['order_total'], 2); ?></strong></p>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Place an Order</h2>
        <?php require __DIR__ . '/order_form.php'; ?>
    </div>
    <script src="assets/app.js" defer></script>
</body>
</html>
