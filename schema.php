<?php
/**
 * schema.php
 *
 * Creates the coffee_kiosk database and normalized tables.
 * Run from CLI: php schema.php
 * Author: Chanheng
 */

declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'coffee_kiosk';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: 'root';
$port = (int) (getenv('DB_PORT') ?: 3306);

// Connect without selecting a database so we can create it if needed.
$rootDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
$pdo = new PDO($rootDsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Create database if missing.
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database {$dbName} ensured.\n";

// Connect to the target database for table creation.
$pdo->exec("USE `{$dbName}`");

$tables = [
    // 3NF: customers have their own table.
    'customers' => "
        CREATE TABLE IF NOT EXISTS customers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_customers_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Base coffee catalog, decoupled from sizes.
    'coffees' => "
        CREATE TABLE IF NOT EXISTS coffees (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            description VARCHAR(255) NULL,
            base_price DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            UNIQUE KEY uniq_coffees_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Sizes drive price adjustments.
    'sizes' => "
        CREATE TABLE IF NOT EXISTS sizes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(40) NOT NULL,
            ounces TINYINT UNSIGNED NOT NULL,
            price_modifier DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            UNIQUE KEY uniq_sizes_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Sweeteners catalog.
    'sweeteners' => "
        CREATE TABLE IF NOT EXISTS sweeteners (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            additional_cost DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            UNIQUE KEY uniq_sweeteners_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Creamers catalog with flavored toggle.
    'creamers' => "
        CREATE TABLE IF NOT EXISTS creamers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            is_flavored TINYINT(1) NOT NULL DEFAULT 0,
            additional_cost DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            UNIQUE KEY uniq_creamers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Orders header.
    'orders' => "
        CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            pickup_note VARCHAR(120) NULL,
            order_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Order line items.
    'order_details' => "
        CREATE TABLE IF NOT EXISTS order_details (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            coffee_id INT UNSIGNED NOT NULL,
            size_id INT UNSIGNED NOT NULL,
            quantity TINYINT UNSIGNED NOT NULL,
            unit_price DECIMAL(7,2) NOT NULL,
            line_total DECIMAL(8,2) NOT NULL,
            CONSTRAINT fk_od_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_od_coffee FOREIGN KEY (coffee_id) REFERENCES coffees(id),
            CONSTRAINT fk_od_size FOREIGN KEY (size_id) REFERENCES sizes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Normalized sweetener selections per line item.
    'order_detail_sweeteners' => "
        CREATE TABLE IF NOT EXISTS order_detail_sweeteners (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_detail_id INT UNSIGNED NOT NULL,
            sweetener_id INT UNSIGNED NOT NULL,
            quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
            additional_cost DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            CONSTRAINT fk_ods_detail FOREIGN KEY (order_detail_id) REFERENCES order_details(id) ON DELETE CASCADE,
            CONSTRAINT fk_ods_sweetener FOREIGN KEY (sweetener_id) REFERENCES sweeteners(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    // Normalized creamer selections per line item.
    'order_detail_creamers' => "
        CREATE TABLE IF NOT EXISTS order_detail_creamers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_detail_id INT UNSIGNED NOT NULL,
            creamer_id INT UNSIGNED NOT NULL,
            quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
            additional_cost DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            CONSTRAINT fk_odc_detail FOREIGN KEY (order_detail_id) REFERENCES order_details(id) ON DELETE CASCADE,
            CONSTRAINT fk_odc_creamer FOREIGN KEY (creamer_id) REFERENCES creamers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
];

foreach ($tables as $name => $sql) {
    $pdo->exec($sql);
    echo "Table {$name} created/verified.\n";
}

echo "Schema setup complete.\n";
