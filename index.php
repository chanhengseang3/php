<?php
session_start();

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

        return $total;
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

    return $total;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === $validUsername && $password === $validPassword) {
        $_SESSION['isAuthenticated'] = true;
        $_SESSION['username'] = $username;
    } else {
        $authError = 'Invalid credentials. Please try again.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$isLoggedIn = $_SESSION['isAuthenticated'] ?? false;

// ---------- Ordering simulation ----------
$orderedPrices = [];
$structuredOrder = [];
$selectedSweeteners = [
    ['type' => 'Honey', 'additionalCost' => 0.25, 'quantity' => 2],
    ['type' => 'Stevia', 'additionalCost' => 0.20, 'quantity' => 1],
];

if ($isLoggedIn) {
    $coffeeSelections = [
        ['name' => 'Espresso', 'quantity' => 2],
        ['name' => 'Latte', 'quantity' => 1],
        ['name' => 'Cappuccino', 'quantity' => 1],
    ];

    foreach ($coffeeSelections as $selection) {
        $price = orderCoffee($menu, $selection['name']);
        if ($price !== null) {
            $orderedPrices[] = $price;
            $structuredOrder[$selection['name']] = [
                'price' => $price,
                'quantity' => $selection['quantity'],
            ];
        }
    }
}

$simpleTotal = $isLoggedIn ? calculateTotal($orderedPrices) : 0.0;
$grandTotal = $isLoggedIn ? calculateTotal($structuredOrder, $selectedSweeteners) : 0.0;
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

    <?php if ($isLoggedIn): ?>
        <div class="panel">
            <h2>Order Simulation</h2>
            <p>Ordered prices list (requirement 6):
                <?= implode(', ', array_map(fn($price) => '$' . number_format($price, 2), $orderedPrices)); ?>
            </p>
            <p>Total (prices only): $<?= number_format($simpleTotal, 2); ?></p>
            <h3>Order with Quantities</h3>
            <ul>
                <?php foreach ($structuredOrder as $name => $details): ?>
                    <li><?= htmlspecialchars($name); ?> x <?= $details['quantity']; ?> @
                        $<?= number_format($details['price'], 2); ?></li>
                <?php endforeach; ?>
            </ul>
            <h3>Selected Sweeteners</h3>
            <ul>
                <?php foreach ($selectedSweeteners as $sweetener): ?>
                    <li><?= htmlspecialchars($sweetener['type']); ?> x <?= $sweetener['quantity']; ?>
                        (+$<?= number_format($sweetener['additionalCost'], 2); ?> each)</li>
                <?php endforeach; ?>
            </ul>
            <p>Grand Total (including sweeteners): $<?= number_format($grandTotal, 2); ?></p>
        </div>
    <?php endif; ?>
</body>

</html>