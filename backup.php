<?php
require __DIR__ . '/security.php';
startSecureSession();

$hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
$serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
$allowedHosts = array_filter([
    parse_url('http://' . $hostHeader, PHP_URL_HOST),
    parse_url('http://' . $serverName, PHP_URL_HOST),
]);

const RECEIPT_FILE = __DIR__ . '/receipts.log';
const TAX_RATE = 0.0725; // 7.25% city tax rate

/**
 * Formats dollar currency consistently with two decimals and spacing.
 */
function formatCurrency(float $amount): string
{
    return '$' . number_format(abs($amount), 2, '.', ',');
}

/**
 * Cleans user-provided strings by trimming, collapsing whitespace, and title-casing.
 */
function cleanString(string $rawValue): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($rawValue));

    return ucwords(strtolower((string) $normalized));
}

/**
 * Generates a padded label/value pair for receipts.
 */
function formatReceiptLine(string $label, string $value, int $width = 18): string
{
    return str_pad($label . ':', $width) . $value;
}

/**
 * Calculates a pickup time by adding minutes to the order timestamp.
 */
function estimatePickupTime(DateTimeImmutable $orderTime, int $prepMinutes): DateTimeImmutable
{
    $minutes = max(1, $prepMinutes);

    return $orderTime->add(new DateInterval('PT' . $minutes . 'M'));
}

/**
 * Generates a random quantity selection for each coffee menu item.
 */
function generateRandomOrderSelections(Menu $menu, int $minQty = 0, int $maxQty = 4): array
{
    $selections = [];
    $hasSelection = false;

    foreach ($menu->getItems() as $coffee) {
        $quantity = random_int($minQty, $maxQty);
        if ($quantity > 0) {
            $hasSelection = true;
        }

        $selections[] = [
            'name' => $coffee->name,
            'quantity' => $quantity,
        ];
    }

    if (!$hasSelection && !empty($selections)) {
        $index = array_rand($selections);
        $selections[$index]['quantity'] = max(1, $minQty + 1);
    }

    return $selections;
}

/**
 * Class for coffee
 * @Author Chanheng
 */
class Coffee
{
    public function __construct(
        public string $name,
        public string $size,
        public float $price
    ) {
    }

    public function displayCoffee(): string
    {
        return "{$this->name} ({$this->size}) - $" . number_format($this->price, 2);
    }
}

/**
 * Class for sweetener
 * @Author Chanheng
 */
class Sweetener
{
    public function __construct(
        public string $type,
        public float $additionalCost
    ) {
    }

    public function displaySweetener(): string
    {
        return "{$this->type} (+$" . number_format($this->additionalCost, 2) . ")";
    }
}

/**
 * Class for menu
 * @Author Chanheng
 */
class Menu
{
    /**
     * @param Coffee[] $items
     */
    public function __construct(private array $items = [])
    {
    }

    public function addCoffee(Coffee $coffee): void
    {
        $this->items[] = $coffee;
    }

    /**
     * @return Coffee[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * show coffee list on UI
     */
    public function displayMenu(): string
    {
        $lines = [];
        foreach ($this->items as $item) {
            $lines[] = $item->displayCoffee();
        }

        return implode('<br>', $lines);
    }
}

/**
 * Function requirement 8: display the menu from a raw array.
 *
 * @param Coffee[] $menuItems
 */
function displayMenu(array $menuItems): string
{
    $output = [];
    foreach ($menuItems as $coffee) {
        $output[] = $coffee->displayCoffee();
    }

    return implode('<br>', $output);
}

/**
 * Function requirement 9: display sweeteners from an array.
 *
 * @param Sweetener[] $sweeteners
 */
function displaySweeteners(array $sweeteners): string
{
    $output = [];
    foreach ($sweeteners as $sweetener) {
        $output[] = $sweetener->displaySweetener();
    }

    return implode('<br>', $output);
}

/**
 * Finds a coffee by name and returns its price.
 */
function orderCoffee(Menu $menu, string $coffeeName): ?float
{
    foreach ($menu->getItems() as $coffee) {
        if (strcasecmp($coffee->name, $coffeeName) === 0) {
            return $coffee->price;
        }
    }

    return null;
}

/**
 * Returns a Coffee instance by name when additional metadata is needed.
 */
function findCoffee(Menu $menu, string $coffeeName): ?Coffee
{
    foreach ($menu->getItems() as $coffee) {
        if (strcasecmp($coffee->name, $coffeeName) === 0) {
            return $coffee;
        }
    }

    return null;
}

/**
 * Requirement 6 & 10: calculates totals either for a list of prices or for
 * a structured order with optional sweeteners.
 *
 * @param array $orderOrPrices Either [float, float] or ['Latte' => ['price' => 3.50, 'quantity' => 2]]
 * @param array $selectedSweeteners [['type' => 'Honey', 'additionalCost' => 0.25, 'quantity' => 2]]
 */
function calculateTotal(array $orderOrPrices, array $selectedSweeteners = []): float
{
    $isPriceList = array_reduce(
        $orderOrPrices,
        fn($carry, $value) => $carry && is_numeric($value),
        true
    );

    $total = 0.0;

    if ($isPriceList) {
        foreach ($orderOrPrices as $price) {
            $total += (float) $price;
        }

        return round($total, 2);
    }

    foreach ($orderOrPrices as $details) {
        if (!isset($details['price'], $details['quantity'])) {
            continue;
        }

        $total += (float) $details['price'] * (int) $details['quantity'];
    }

    foreach ($selectedSweeteners as $sweetener) {
        if (!isset($sweetener['additionalCost'], $sweetener['quantity'])) {
            continue;
        }

        $total += (float) $sweetener['additionalCost'] * (int) $sweetener['quantity'];
    }

    return round($total, 2);
}

/**
 * Calculates prep minutes based on total quantity using array helpers.
 */
function calculatePrepMinutes(array $orderDetails): int
{
    if (empty($orderDetails)) {
        return 0;
    }

    $totalCups = array_sum(array_map(
        fn($details) => (int) ($details['quantity'] ?? 0),
        $orderDetails
    ));

    return max(5, $totalCups * 3);
}

/**
 * Produces a detailed breakdown including taxes, sweeteners, and discounts.
 *
 * @return array{subtotal: float, sweeteners: float, loyaltyDiscount: float, tax: float, grandTotal: float}
 */
function calculateOrderBreakdown(array $orderDetails, array $selectedSweeteners, float $taxRate = TAX_RATE): array
{
    $subtotal = 0.0;
    foreach ($orderDetails as $details) {
        if (!isset($details['price'], $details['quantity'])) {
            continue;
        }

        $subtotal += (float) $details['price'] * (int) $details['quantity'];
    }

    $sweetenerTotal = array_reduce(
        $selectedSweeteners,
        fn($carry, $sweetener) => $carry + ((float) ($sweetener['additionalCost'] ?? 0) * (int) ($sweetener['quantity'] ?? 0)),
        0.0
    );

    // Demonstrates exponentiation to reward larger single-item orders.
    $loyaltyPoints = array_sum(array_map(
        fn($item) => pow((int) ($item['quantity'] ?? 0), 2),
        $orderDetails
    ));
    $loyaltyDiscount = round(min(1.50, $loyaltyPoints * 0.05), 2);

    $preTax = max(0.0, $subtotal + $sweetenerTotal - $loyaltyDiscount);
    $tax = round($preTax * $taxRate, 2);
    $grandTotal = round($preTax + $tax, 2);

    return [
        'subtotal' => round($subtotal, 2),
        'sweeteners' => round($sweetenerTotal, 2),
        'loyaltyDiscount' => $loyaltyDiscount,
        'tax' => $tax,
        'grandTotal' => $grandTotal,
    ];
}

/**
 * Builds a multiline receipt for display and file persistence.
 */
function generateReceipt(
    string $customerName,
    array $orderDetails,
    array $sweeteners,
    array $totals,
    DateTimeImmutable $orderTime,
    DateTimeImmutable $pickupTime
): string {
    $lines = [
        str_repeat('=', 34),
        'Chanheng Coffee Kiosk',
        formatReceiptLine('Customer', $customerName),
        formatReceiptLine('Ordered', $orderTime->format('M d, Y g:i A')),
        formatReceiptLine('Pickup ETA', $pickupTime->format('M d, Y g:i A')),
        str_repeat('-', 34),
    ];

    foreach ($orderDetails as $name => $details) {
        $itemLabel = "{$name} x{$details['quantity']}";
        $itemValue = formatCurrency((float) $details['price'] * (int) $details['quantity']);
        $lines[] = formatReceiptLine($itemLabel, $itemValue);
    }

    if (!empty($sweeteners)) {
        $lines[] = str_repeat('-', 34);
        foreach ($sweeteners as $sweetener) {
            $label = "{$sweetener['type']} x{$sweetener['quantity']}";
            $value = formatCurrency((float) $sweetener['additionalCost'] * (int) $sweetener['quantity']);
            $lines[] = formatReceiptLine($label, $value);
        }
    }

    $lines[] = str_repeat('-', 34);
    $lines[] = formatReceiptLine('Subtotal', formatCurrency($totals['subtotal']));
    $lines[] = formatReceiptLine('Sweeteners', formatCurrency($totals['sweeteners']));
    $lines[] = formatReceiptLine('Loyalty Disc.', formatCurrency($totals['loyaltyDiscount'] * -1));
    $lines[] = formatReceiptLine('Tax', formatCurrency($totals['tax']));
    $lines[] = formatReceiptLine('Grand Total', formatCurrency($totals['grandTotal']));
    $lines[] = str_repeat('=', 34);

    return implode("\n", $lines);
}

/**
 * Persists the receipt as a JSON line entry.
 *
 * @param array $payload ['customer' => '', 'total' => 12.34, 'timestamp' => '', 'receipt' => '']
 */
function logReceiptToFile(array $payload): void
{
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }

    file_put_contents(RECEIPT_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Fetches the last N receipt entries for display.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchRecentReceipts(int $limit = 5): array
{
    if (!file_exists(RECEIPT_FILE)) {
        return [];
    }

    $lines = file(RECEIPT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recentLines = array_slice($lines, -$limit);
    $entries = [];

    foreach ($recentLines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return array_reverse($entries);
}

/**
 * Creates a simple comma-separated summary of items for the log display.
 */
function summarizeItemsForLog(array $items): string
{
    if (empty($items)) {
        return 'No items recorded';
    }

    $segments = [];
    foreach ($items as $name => $details) {
        $segments[] = "{$name} x" . ($details['quantity'] ?? 0);
    }

    return implode(', ', $segments);
}

/**
 * Formats ISO timestamps stored in the log for UI display.
 */
function formatLogTimestamp(?string $timestamp): string
{
    if (empty($timestamp)) {
        return 'Unknown time';
    }

    try {
        $date = new DateTimeImmutable($timestamp);

        return $date->format('M d, Y g:i A');
    } catch (Exception $exception) {
        return $timestamp;
    }
}

// ---------- Data setup ----------
$menuItems = [
    new Coffee('Espresso', 'Small', 2.50),
    new Coffee('Latte', 'Medium', 3.75),
    new Coffee('Cappuccino', 'Large', 4.10),
    new Coffee('Mocha', 'Medium', 4.25),
];

$sweeteners = [
    new Sweetener('Sugar', 0.10),
    new Sweetener('Honey', 0.25),
    new Sweetener('Stevia', 0.20),
];

$menu = new Menu($menuItems);

// ---------- Authentication ----------
$validUsername = 'chanheng';
$validPassword = 'chanhengCoffeShop!@#123';
$authError = '';
$loginCsrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    /**
     * Validate CSRF tokens in submit handlers
     * Reject requests without matching tokens
     * Optionally validate origin or referer headers for sensitive endpoints
     */
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $authError = 'Security verification failed. Please refresh and try again.';
    } elseif (!validateRequestOrigin($allowedHosts)) {
        $authError = 'Request origin could not be verified.';
    } else {
        $username = $_POST['username'];
        $password = $_POST['password'];

        if ($username === $validUsername && $password === $validPassword) {
            $_SESSION['isAuthenticated'] = true;
            $_SESSION['username'] = $username;
        } else {
            $authError = 'Access denied. Invalid credentials. Please try again.';
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$isLoggedIn = $_SESSION['isAuthenticated'] ?? false;

// ---------- Ordering simulation, timestamps, and persistence ----------
$orderedPrices = [];
$structuredOrder = [];
$orderTotals = null;
$receiptText = '';
$orderTimestamp = null;
$pickupTime = null;
$orderPrepMinutes = 0;
$customerName = '';

$baseSweetenerSelection = [
    ['type' => 'honey', 'additionalCost' => 0.25, 'quantity' => 2],
];
$seasonalSweetenerSelection = [
    ['type' => '   STEVIA ', 'additionalCost' => 0.20, 'quantity' => 1],
];
$selectedSweeteners = array_map(
    fn($sweetener) => [
        'type' => cleanString($sweetener['type']),
        'additionalCost' => (float) $sweetener['additionalCost'],
        'quantity' => (int) $sweetener['quantity'],
    ],
    array_merge($baseSweetenerSelection, $seasonalSweetenerSelection)
);

$customerProfile = [
    'name' => '   sOPHIA    tran   ',
    'pickupNote' => 'counter 3',
];

$coffeeSelections = generateRandomOrderSelections($menu, 0, 4);

if ($isLoggedIn) {
    $customerName = cleanString($customerProfile['name']);

    foreach ($coffeeSelections as $selection) {
        $coffee = findCoffee($menu, $selection['name']);
        $quantity = (int) ($selection['quantity'] ?? 0);

        if ($coffee === null || $quantity <= 0) {
            continue;
        }

        for ($i = 0; $i < $quantity; $i++) {
            $orderedPrices[] = $coffee->price;
        }
        $structuredOrder[$coffee->name] = [
            'price' => $coffee->price,
            'quantity' => $quantity,
            'size' => $coffee->size,
        ];
    }

    $orderTimestamp = new DateTimeImmutable('now');
    $orderPrepMinutes = calculatePrepMinutes($structuredOrder);
    $pickupTime = estimatePickupTime($orderTimestamp, $orderPrepMinutes);
    $orderTotals = calculateOrderBreakdown($structuredOrder, $selectedSweeteners);
    $receiptText = generateReceipt(
        $customerName,
        $structuredOrder,
        $selectedSweeteners,
        $orderTotals,
        $orderTimestamp,
        $pickupTime
    );

    if (!empty($structuredOrder)) {
        logReceiptToFile([
            'customer' => $customerName,
            'timestamp' => $orderTimestamp->format(DateTimeInterface::ATOM),
            'total' => $orderTotals['grandTotal'],
            'items' => $structuredOrder,
            'sweeteners' => $selectedSweeteners,
            'pickup_note' => cleanString($customerProfile['pickupNote']),
            'receipt' => $receiptText,
        ]);
    }
}

$simpleTotal = $isLoggedIn ? calculateTotal($orderedPrices) : 0.0;
$grandTotal = $isLoggedIn ? ($orderTotals['grandTotal'] ?? 0.0) : 0.0;
$recentReceipts = $isLoggedIn ? fetchRecentReceipts(4) : [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Chanheng Coffee Kiosk Menu</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
        }

        .panel {
            border: 1px solid #ccc;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 6px;
        }

        .error {
            color: #b00020;
        }

        .success {
            color: #0b6623;
        }
    </style>
</head>

<body>
    <h1>Welcome to the Coffee Kiosk</h1>

    <?php if (!$isLoggedIn): ?>
        <div class="panel">
            <h2>Login</h2>
            <?php if ($authError): ?>
                <p class="error"><?= htmlspecialchars($authError) ?></p>
            <?php endif; ?>
            <form method="post">
                <?php
                /**
                 * Implement CSRF token generation
                 * Store token in the session
                 * Add hidden field with token to preference and login forms
                 */
                ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($loginCsrfToken); ?>">
                <label>
                    Username:
                    <input type="text" name="username" required>
                </label>
                <br><br>
                <label>
                    Password:
                    <input type="password" name="password" required>
                </label>
                <br><br>
                <button type="submit">Login</button>
            </form>
        </div>
    <?php else: ?>
        <p class="success">
            Logged in as <?= htmlspecialchars($_SESSION['username']) ?> |
            <a href="?logout=1">Logout</a>
        </p>
    <?php endif; ?>

    <?php if ($isLoggedIn): ?>
        <div class="panel">
            <h2>Menu (via Menu class)</h2>
            <p><?= $menu->displayMenu(); ?></p>
        </div>

        <div class="panel">
            <h2>Menu (via displayMenu function)</h2>
            <p><?= displayMenu($menuItems); ?></p>
        </div>

        <div class="panel">
            <h2>Sweeteners</h2>
            <p><?= displaySweeteners($sweeteners); ?></p>
        </div>

        <div class="panel">
            <h2>Order Simulation</h2>
            <p><strong>Customer:</strong> <?= htmlspecialchars($customerName); ?></p>
            <p>Ordered prices list (requirement 6):
                <?= implode(', ', array_map(fn($price) => formatCurrency($price), $orderedPrices)); ?>
            </p>
            <p>Total (prices only): <?= formatCurrency($simpleTotal); ?></p>

            <h3>Order with Quantities</h3>
            <ul>
                <?php foreach ($structuredOrder as $name => $details): ?>
                    <li>
                        <?= htmlspecialchars($name); ?> (<?= htmlspecialchars($details['size']); ?>)
                        x <?= $details['quantity']; ?> @ <?= formatCurrency($details['price']); ?>
                        = <?= formatCurrency($details['price'] * $details['quantity']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h3>Selected Sweeteners</h3>
            <ul>
                <?php foreach ($selectedSweeteners as $sweetener): ?>
                    <li><?= htmlspecialchars($sweetener['type']); ?> x <?= $sweetener['quantity']; ?>
                        (<?= formatCurrency($sweetener['additionalCost']); ?> each)
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($orderTotals): ?>
                <h3>Totals Breakdown</h3>
                <ul>
                    <li>Subtotal: <?= formatCurrency($orderTotals['subtotal']); ?></li>
                    <li>Sweeteners: <?= formatCurrency($orderTotals['sweeteners']); ?></li>
                    <li>Loyalty Discount: <?= formatCurrency($orderTotals['loyaltyDiscount'] * -1); ?></li>
                    <li>Tax: <?= formatCurrency($orderTotals['tax']); ?></li>
                    <li><strong>Grand Total: <?= formatCurrency($orderTotals['grandTotal']); ?></strong></li>
                </ul>

                <h3>Order Timeline</h3>
                <p>
                    Ordered <?= $orderTimestamp ? $orderTimestamp->format('M d, Y g:i A') : 'N/A'; ?>
                    | Prep time <?= $orderPrepMinutes; ?> minutes
                    | Pickup <?= $pickupTime ? $pickupTime->format('M d, Y g:i A') : 'N/A'; ?>
                </p>

                <h3>Receipt Preview</h3>
                <pre><?= htmlspecialchars($receiptText); ?></pre>
            <?php endif; ?>

            <h3>Recent Receipts</h3>
            <?php if ($recentReceipts): ?>
                <ul>
                    <?php foreach ($recentReceipts as $entry): ?>
                        <li>
                            <strong><?= htmlspecialchars($entry['customer'] ?? 'Guest'); ?></strong>
                            — <?= htmlspecialchars(summarizeItemsForLog($entry['items'] ?? [])); ?>
                            — <?= formatCurrency((float) ($entry['total'] ?? 0)); ?>
                            (<?= htmlspecialchars(formatLogTimestamp($entry['timestamp'] ?? null)); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No receipts recorded yet.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="panel">
            <h2>Kiosk Locked</h2>
            <p class="error">Please log in to access the kiosk interface.</p>
        </div>
    <?php endif; ?>
</body>

</html>
