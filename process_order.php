<?php
/**
 * process_order.php
 *
 * Handles step 5: validate and persist an order, plus prepare summary data.
 * Expects $pdo, catalog arrays ($coffees, $sizes, $sweeteners, $creamers),
 * and mutable state variables ($errors, $orderSummary, $coffeeId, etc.) to be defined by index.php.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerEmail = strtolower(trim((string) ($_POST['customer_email'] ?? '')));
$customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
$pickupNote = trim((string) ($_POST['pickup_note'] ?? ''));
$coffeeId = (int) ($_POST['coffee_id'] ?? 0);
$sizeId = (int) ($_POST['size_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));
$selectedSweetenerIds = array_map('intval', $_POST['sweeteners'] ?? []);
$selectedCreamerIds = array_map('intval', $_POST['creamers'] ?? []);

// Basic validation of user input to prevent invalid rows.
if ($customerName === '') {
    $errors[] = 'Customer name is required.';
}
if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
}
if ($coffeeId <= 0 || !array_filter($coffees, fn($row) => (int) $row['id'] === $coffeeId)) {
    $errors[] = 'Select a coffee.';
}
if ($sizeId <= 0 || !array_filter($sizes, fn($row) => (int) $row['id'] === $sizeId)) {
    $errors[] = 'Select a size.';
}
if ($quantity < 1 || $quantity > 12) {
    $errors[] = 'Quantity must be between 1 and 12.';
}

if (!empty($errors)) {
    return;
}

// Lookup selected catalog rows for pricing.
$coffee = array_values(array_filter($coffees, fn($row) => (int) $row['id'] === $coffeeId))[0] ?? null;
$size = array_values(array_filter($sizes, fn($row) => (int) $row['id'] === $sizeId))[0] ?? null;
$sweetenerLookup = array_column($sweeteners, null, 'id');
$creamerLookup = array_column($creamers, null, 'id');

$basePrice = (float) $coffee['base_price'] + (float) $size['price_modifier'];

$chosenSweeteners = array_values(array_filter(
    $sweetenerLookup,
    fn($row) => in_array((int) $row['id'], $selectedSweetenerIds, true)
));
$chosenCreamers = array_values(array_filter(
    $creamerLookup,
    fn($row) => in_array((int) $row['id'], $selectedCreamerIds, true)
));

$sweetenerExtra = array_sum(array_map(
    fn($row) => (float) $row['additional_cost'] * $quantity,
    $chosenSweeteners
));
$creamerExtra = array_sum(array_map(
    fn($row) => (float) $row['additional_cost'] * $quantity,
    $chosenCreamers
));

$lineTotal = $basePrice * $quantity;
$orderTotal = $lineTotal + $sweetenerExtra + $creamerExtra;

try {
    $pdo->beginTransaction();

    // Upsert customer then fetch id.
    $customerStmt = $pdo->prepare("
        INSERT INTO customers (full_name, email, phone)
        VALUES (:name, :email, :phone)
        ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)
    ");
    $customerStmt->execute([
        ':name' => $customerName,
        ':email' => $customerEmail,
        ':phone' => $customerPhone ?: null,
    ]);

    $customerId = (int) $pdo->lastInsertId();
    if ($customerId === 0) {
        $customerLookupStmt = $pdo->prepare("SELECT id FROM customers WHERE email = :email LIMIT 1");
        $customerLookupStmt->execute([':email' => $customerEmail]);
        $customerId = (int) $customerLookupStmt->fetchColumn();
    }

    // Insert order header with placeholder total (updated after line items).
    $orderStmt = $pdo->prepare("
        INSERT INTO orders (customer_id, pickup_note, order_total)
        VALUES (:customer_id, :pickup_note, :order_total)
    ");
    $orderStmt->execute([
        ':customer_id' => $customerId,
        ':pickup_note' => $pickupNote ?: null,
        ':order_total' => 0,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    // Insert order line item.
    $detailStmt = $pdo->prepare("
        INSERT INTO order_details (order_id, coffee_id, size_id, quantity, unit_price, line_total)
        VALUES (:order_id, :coffee_id, :size_id, :quantity, :unit_price, :line_total)
    ");
    $detailStmt->execute([
        ':order_id' => $orderId,
        ':coffee_id' => $coffeeId,
        ':size_id' => $sizeId,
        ':quantity' => $quantity,
        ':unit_price' => $basePrice,
        ':line_total' => $lineTotal,
    ]);
    $orderDetailId = (int) $pdo->lastInsertId();

    // Save sweeteners for this item.
    if ($chosenSweeteners) {
        $odsStmt = $pdo->prepare("
            INSERT INTO order_detail_sweeteners (order_detail_id, sweetener_id, quantity, additional_cost)
            VALUES (:detail_id, :sweetener_id, :quantity, :additional_cost)
        ");
        foreach ($chosenSweeteners as $sweetener) {
            $odsStmt->execute([
                ':detail_id' => $orderDetailId,
                ':sweetener_id' => $sweetener['id'],
                ':quantity' => $quantity,
                ':additional_cost' => $sweetener['additional_cost'],
            ]);
        }
    }

    // Save creamers for this item (includes flavored creamers).
    if ($chosenCreamers) {
        $odcStmt = $pdo->prepare("
            INSERT INTO order_detail_creamers (order_detail_id, creamer_id, quantity, additional_cost)
            VALUES (:detail_id, :creamer_id, :quantity, :additional_cost)
        ");
        foreach ($chosenCreamers as $creamer) {
            $odcStmt->execute([
                ':detail_id' => $orderDetailId,
                ':creamer_id' => $creamer['id'],
                ':quantity' => $quantity,
                ':additional_cost' => $creamer['additional_cost'],
            ]);
        }
    }

    // Update total now that everything is recorded.
    $pdo->prepare("UPDATE orders SET order_total = :total WHERE id = :id")
        ->execute([':total' => $orderTotal, ':id' => $orderId]);

    $pdo->commit();

    $orderSummary = [
        'customer' => $customerName,
        'email' => $customerEmail,
        'coffee' => $coffee['name'],
        'size' => $size['label'],
        'quantity' => $quantity,
        'sweeteners' => array_column($chosenSweeteners, 'name'),
        'creamers' => array_map(
            fn($row) => $row['name'] . ($row['is_flavored'] ? ' (flavored)' : ''),
            $chosenCreamers
        ),
        'base_price' => $basePrice,
        'line_total' => $lineTotal,
        'extras_total' => $sweetenerExtra + $creamerExtra,
        'order_total' => $orderTotal,
    ];
} catch (Throwable $exception) {
    $pdo->rollBack();
    $errors[] = 'Could not save order: ' . $exception->getMessage();
}
