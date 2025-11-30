<?php
/**
 * seed.php
 *
 * Populates sample data for coffees, sizes, sweeteners, and creamers.
 * Run from CLI after schema.php: php seed.php
 */

declare(strict_types=1);

require __DIR__ . '/db.php';

$pdo = getPdo();

/**
 * Basic validation for catalog rows to avoid accidental bad inserts.
 *
 * @param array<string, mixed> $row
 */
function validateRow(array $row, array $requiredKeys): bool
{
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $row)) {
            return false;
        }
    }

    return true;
}

$coffees = [
    ['name' => 'Espresso', 'description' => 'Rich, concentrated coffee shot', 'base_price' => 2.50],
    ['name' => 'Latte', 'description' => 'Espresso with steamed milk', 'base_price' => 3.40],
    ['name' => 'Cappuccino', 'description' => 'Equal parts espresso, milk, and foam', 'base_price' => 3.75],
    ['name' => 'Mocha', 'description' => 'Chocolate, espresso, and steamed milk', 'base_price' => 3.95],
];

$sizes = [
    ['label' => 'Small', 'ounces' => 8, 'price_modifier' => 0.00],
    ['label' => 'Medium', 'ounces' => 12, 'price_modifier' => 0.75],
    ['label' => 'Large', 'ounces' => 16, 'price_modifier' => 1.25],
];

$sweeteners = [
    ['name' => 'Sugar', 'additional_cost' => 0.10],
    ['name' => 'Honey', 'additional_cost' => 0.25],
    ['name' => 'Stevia', 'additional_cost' => 0.20],
    ['name' => 'Maple Syrup', 'additional_cost' => 0.35],
];

$creamers = [
    ['name' => 'Half & Half', 'is_flavored' => 0, 'additional_cost' => 0.20],
    ['name' => 'Vanilla Creamer', 'is_flavored' => 1, 'additional_cost' => 0.35],
    ['name' => 'Hazelnut Creamer', 'is_flavored' => 1, 'additional_cost' => 0.35],
    ['name' => 'Caramel Creamer', 'is_flavored' => 1, 'additional_cost' => 0.40],
];

$pdo->beginTransaction();

try {
    $coffeeStmt = $pdo->prepare("
        INSERT INTO coffees (name, description, base_price)
        VALUES (:name, :description, :base_price)
        ON DUPLICATE KEY UPDATE description = VALUES(description), base_price = VALUES(base_price)
    ");
    foreach ($coffees as $coffee) {
        if (!validateRow($coffee, ['name', 'description', 'base_price'])) {
            continue;
        }
        $coffeeStmt->execute([
            ':name' => $coffee['name'],
            ':description' => $coffee['description'],
            ':base_price' => max(0, (float) $coffee['base_price']),
        ]);
    }

    $sizeStmt = $pdo->prepare("
        INSERT INTO sizes (label, ounces, price_modifier)
        VALUES (:label, :ounces, :price_modifier)
        ON DUPLICATE KEY UPDATE ounces = VALUES(ounces), price_modifier = VALUES(price_modifier)
    ");
    foreach ($sizes as $size) {
        if (!validateRow($size, ['label', 'ounces', 'price_modifier'])) {
            continue;
        }
        $sizeStmt->execute([
            ':label' => $size['label'],
            ':ounces' => max(1, (int) $size['ounces']),
            ':price_modifier' => max(0, (float) $size['price_modifier']),
        ]);
    }

    $sweetStmt = $pdo->prepare("
        INSERT INTO sweeteners (name, additional_cost)
        VALUES (:name, :additional_cost)
        ON DUPLICATE KEY UPDATE additional_cost = VALUES(additional_cost)
    ");
    foreach ($sweeteners as $sweetener) {
        if (!validateRow($sweetener, ['name', 'additional_cost'])) {
            continue;
        }
        $sweetStmt->execute([
            ':name' => $sweetener['name'],
            ':additional_cost' => max(0, (float) $sweetener['additional_cost']),
        ]);
    }

    $creamerStmt = $pdo->prepare("
        INSERT INTO creamers (name, is_flavored, additional_cost)
        VALUES (:name, :is_flavored, :additional_cost)
        ON DUPLICATE KEY UPDATE is_flavored = VALUES(is_flavored), additional_cost = VALUES(additional_cost)
    ");
    foreach ($creamers as $creamer) {
        if (!validateRow($creamer, ['name', 'is_flavored', 'additional_cost'])) {
            continue;
        }
        $creamerStmt->execute([
            ':name' => $creamer['name'],
            ':is_flavored' => (int) $creamer['is_flavored'] === 1 ? 1 : 0,
            ':additional_cost' => max(0, (float) $creamer['additional_cost']),
        ]);
    }

    $pdo->commit();
    echo "Seed data inserted.\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, "Seed failed: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
