-- coffee_kiosk.sql
-- SQL script to create the coffee_kiosk database and normalized tables.

CREATE DATABASE IF NOT EXISTS coffee_kiosk
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE coffee_kiosk;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coffees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    base_price DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uniq_coffees_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sizes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(40) NOT NULL,
    ounces TINYINT UNSIGNED NOT NULL,
    price_modifier DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uniq_sizes_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sweeteners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    additional_cost DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uniq_sweeteners_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS creamers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    is_flavored TINYINT(1) NOT NULL DEFAULT 0,
    additional_cost DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uniq_creamers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    pickup_note VARCHAR(120) NULL,
    order_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_detail_sweeteners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_detail_id INT UNSIGNED NOT NULL,
    sweetener_id INT UNSIGNED NOT NULL,
    quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
    additional_cost DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_ods_detail FOREIGN KEY (order_detail_id) REFERENCES order_details(id) ON DELETE CASCADE,
    CONSTRAINT fk_ods_sweetener FOREIGN KEY (sweetener_id) REFERENCES sweeteners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_detail_creamers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_detail_id INT UNSIGNED NOT NULL,
    creamer_id INT UNSIGNED NOT NULL,
    quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
    additional_cost DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_odc_detail FOREIGN KEY (order_detail_id) REFERENCES order_details(id) ON DELETE CASCADE,
    CONSTRAINT fk_odc_creamer FOREIGN KEY (creamer_id) REFERENCES creamers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
