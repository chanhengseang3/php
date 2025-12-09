<?php
declare(strict_types=1);

require __DIR__ . '/security.php';
require __DIR__ . '/db.php';

startSecureSession();

$hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
$serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
$allowedHosts = array_filter([
    parse_url('http://' . $hostHeader, PHP_URL_HOST),
    parse_url('http://' . $serverName, PHP_URL_HOST),
]);

$pdo = getPdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

header('Content-Type: application/json');

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function loadCatalog(PDO $pdo): array
{
    $coffees = $pdo->query("SELECT id, name, description, base_price FROM coffees ORDER BY name")->fetchAll();
    $sizes = $pdo->query("SELECT id, label, ounces, price_modifier FROM sizes ORDER BY ounces")->fetchAll();
    $sweeteners = $pdo->query("SELECT id, name, additional_cost FROM sweeteners ORDER BY name")->fetchAll();
    $creamers = $pdo->query("SELECT id, name, is_flavored, additional_cost FROM creamers ORDER BY name")->fetchAll();

    return compact('coffees', 'sizes', 'sweeteners', 'creamers');
}

function findRowById(array $rows, int $id): ?array
{
    foreach ($rows as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }

    return null;
}

function normalizeIds(array $input): array
{
    return array_values(array_unique(array_map('intval', $input)));
}

function getCartItems(): array
{
    if (!isset($_SESSION['cart_items']) || !is_array($_SESSION['cart_items'])) {
        $_SESSION['cart_items'] = [];
    }

    return $_SESSION['cart_items'];
}

function saveCartItems(array $items): void
{
    $_SESSION['cart_items'] = $items;
}

function buildLineItem(array $line, array $catalog): array
{
    $coffee = findRowById($catalog['coffees'], (int) $line['coffee_id']);
    $size = findRowById($catalog['sizes'], (int) $line['size_id']);

    if (!$coffee || !$size) {
        throw new RuntimeException('Invalid line item references.');
    }

    $sweetenerLookup = array_column($catalog['sweeteners'], null, 'id');
    $creamerLookup = array_column($catalog['creamers'], null, 'id');

    $sweeteners = [];
    foreach (normalizeIds($line['sweeteners'] ?? []) as $sweetenerId) {
        if (isset($sweetenerLookup[$sweetenerId])) {
            $sweeteners[] = $sweetenerLookup[$sweetenerId];
        }
    }

    $creamers = [];
    foreach (normalizeIds($line['creamers'] ?? []) as $creamerId) {
        if (isset($creamerLookup[$creamerId])) {
            $creamers[] = $creamerLookup[$creamerId];
        }
    }

    $quantity = max(1, min(12, (int) ($line['quantity'] ?? 1)));
    $basePrice = (float) $coffee['base_price'] + (float) $size['price_modifier'];

    $sweetenerExtras = array_sum(array_map(
        fn($row) => (float) $row['additional_cost'] * $quantity,
        $sweeteners
    ));
    $creamerExtras = array_sum(array_map(
        fn($row) => (float) $row['additional_cost'] * $quantity,
        $creamers
    ));

    $unitPrice = $basePrice + array_sum(array_map(
        fn($row) => (float) $row['additional_cost'],
        array_merge($sweeteners, $creamers)
    ));

    $lineTotal = $unitPrice * $quantity;

    return [
        'id' => (string) $line['id'],
        'coffee_id' => (int) $coffee['id'],
        'coffee' => $coffee['name'],
        'size_id' => (int) $size['id'],
        'size' => $size['label'],
        'quantity' => $quantity,
        'unit_price' => round($unitPrice, 2),
        'sweeteners' => array_values(array_map(fn($row) => $row['name'], $sweeteners)),
        'creamers' => array_values(array_map(fn($row) => $row['name'], $creamers)),
        'extras_total' => round($sweetenerExtras + $creamerExtras, 2),
        'line_total' => round($lineTotal, 2),
    ];
}

function cartSummary(array $items, array $catalog): array
{
    $lines = [];
    $cartTotal = 0.0;
    $cartCount = 0;

    foreach ($items as $line) {
        try {
            $built = buildLineItem($line, $catalog);
            $lines[] = $built;
            $cartTotal += $built['line_total'];
            $cartCount += $built['quantity'];
        } catch (Throwable $exception) {
            continue;
        }
    }

    return [
        'items' => $lines,
        'cart_total' => round($cartTotal, 2),
        'cart_count' => $cartCount,
    ];
}

function requireCsrf(array $allowedHosts): void
{
    $submittedToken = $_POST['csrf_token'] ?? null;
    if (!validateCsrfToken(is_string($submittedToken) ? $submittedToken : null)) {
        jsonResponse(['error' => 'Invalid or missing form token.'], 400);
    }

    if (!validateRequestOrigin($allowedHosts)) {
        jsonResponse(['error' => 'Request origin could not be verified.'], 400);
    }
}

$writeActions = ['add_to_cart', 'update_cart_line', 'remove_cart_line'];
if (in_array($action, $writeActions, true) && $method === 'POST') {
    requireCsrf($allowedHosts);
}

$catalog = loadCatalog($pdo);

if ($action === 'catalog') {
    jsonResponse(['catalog' => $catalog, 'csrf_token' => getCsrfToken()]);
}

if ($action === 'cart') {
    $items = getCartItems();
    jsonResponse(cartSummary($items, $catalog));
}

if ($action === 'add_to_cart' && $method === 'POST') {
    $coffeeId = (int) ($_POST['coffee_id'] ?? 0);
    $sizeId = (int) ($_POST['size_id'] ?? 0);
    $quantity = max(1, min(12, (int) ($_POST['quantity'] ?? 1)));
    $sweeteners = normalizeIds($_POST['sweeteners'] ?? []);
    $creamers = normalizeIds($_POST['creamers'] ?? []);

    $errors = [];
    if (!findRowById($catalog['coffees'], $coffeeId)) {
        $errors[] = 'Select a coffee.';
    }
    if (!findRowById($catalog['sizes'], $sizeId)) {
        $errors[] = 'Select a size.';
    }
    if ($quantity < 1 || $quantity > 12) {
        $errors[] = 'Quantity must be between 1 and 12.';
    }

    if ($errors) {
        jsonResponse(['errors' => $errors], 422);
    }

    $items = getCartItems();
    $lineId = bin2hex(random_bytes(4));
    $items[$lineId] = [
        'id' => $lineId,
        'coffee_id' => $coffeeId,
        'size_id' => $sizeId,
        'quantity' => $quantity,
        'sweeteners' => $sweeteners,
        'creamers' => $creamers,
    ];

    saveCartItems($items);

    $summary = cartSummary($items, $catalog);

    jsonResponse([
        'message' => 'Added to cart.',
        'cart' => $summary,
    ]);
}

if ($action === 'update_cart_line' && $method === 'POST') {
    $lineId = (string) ($_POST['line_id'] ?? '');
    $quantity = max(1, min(12, (int) ($_POST['quantity'] ?? 1)));

    $items = getCartItems();
    if (!isset($items[$lineId])) {
        jsonResponse(['error' => 'Item not found in cart.'], 404);
    }

    $items[$lineId]['quantity'] = $quantity;
    saveCartItems($items);

    jsonResponse([
        'message' => 'Cart updated.',
        'cart' => cartSummary($items, $catalog),
    ]);
}

if ($action === 'remove_cart_line' && $method === 'POST') {
    $lineId = (string) ($_POST['line_id'] ?? '');
    $items = getCartItems();

    if (isset($items[$lineId])) {
        unset($items[$lineId]);
        saveCartItems($items);
    }

    jsonResponse([
        'message' => 'Item removed.',
        'cart' => cartSummary($items, $catalog),
    ]);
}

jsonResponse(['error' => 'Unsupported action.'], 404);
