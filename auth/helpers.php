<?php

require_once __DIR__ . '/../database/connection.php';

date_default_timezone_set('Asia/Manila');

function start_auth_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function valid_csrf_token($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_authenticated_role(string $requiredRole): void
{
    require_authenticated_roles([$requiredRole]);
}

function require_authenticated_roles(array $allowedRoles): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        header('Location: ../auth/login.php');
        exit;
    }

    if (!in_array($_SESSION['user_role'], $allowedRoles, true)) {
        redirect_for_role((string) $_SESSION['user_role']);
    }
}

function redirect_for_role(string $role): void
{
    if ($role === 'staff') {
        header('Location: ../staff/inventory.php');
    } elseif ($role === 'admin') {
        header('Location: ../admin/inventory.php');
    } else {
        header('Location: ../index.php');
    }
    exit;
}

function show_database_error(PDOException $exception): void
{
    error_log('RentalSystem database error: ' . $exception->getMessage());
}

start_auth_session();
