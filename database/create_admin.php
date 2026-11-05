<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/connection.php';

$email = getenv('ADMIN_EMAIL') ?: 'admin@rentalsystem.local';
$username = strtolower(getenv('ADMIN_USERNAME') ?: 'admin');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ADMIN_EMAIL must be a valid email address.\n");
    exit(1);
}

if (!preg_match('/\A[a-z0-9][a-z0-9_.-]{2,29}\z/', $username)) {
    fwrite(STDERR, "ADMIN_USERNAME must be 3-30 characters using letters, numbers, dots, dashes, or underscores.\n");
    exit(1);
}

$password = bin2hex(random_bytes(12));

try {
    $statement = get_database_connection()->prepare(
        'INSERT INTO users (full_name, username, email, password_hash, role)
         VALUES (:full_name, :username, :email, :password_hash, :role)'
    );
    $statement->execute([
        'full_name' => 'Administrator',
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
    ]);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        fwrite(STDERR, "That admin email or username is already in use. No account was created.\n");
        exit(1);
    }

    fwrite(STDERR, "Could not create the admin account: {$exception->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "Admin account created for Angels Bridal Rental Shop.\nUsername: {$username}\nEmail: {$email}\nTemporary password: {$password}\n");
fwrite(STDOUT, "Save this password securely; it will not be shown again.\n");
