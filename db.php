<?php
/**
 * Simple PDO connection helper for the coffee_kiosk database.
 *
 * Update the DSN/credentials below to match your local MySQL setup.
 * For security, you can also set environment variables:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
 */

declare(strict_types=1);

function getPdo(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'coffee_kiosk';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS') ?: 'root';
    $port = (int) (getenv('DB_PORT') ?: 3306);

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
