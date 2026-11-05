<?php

function get_database_connection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('RENTAL_DB_HOST') ?: '127.0.0.1';
    $database = getenv('RENTAL_DB_NAME') ?: 'rental_system';
    $username = getenv('RENTAL_DB_USER') ?: 'root';
    $password = getenv('RENTAL_DB_PASSWORD') ?: '';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);

    $connection = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET time_zone = '+08:00'");

    return $connection;
}
