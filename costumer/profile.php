<?php

require_once __DIR__ . '/../auth/helpers.php';
require_authenticated_role('customer');

$connection = get_database_connection();
$userId = (int) $_SESSION['user_id'];
$error = '';
$account = null;

try {
    $accountStatement = $connection->prepare(
        'SELECT full_name, username, email, contact_number, address, password_hash
         FROM users
         WHERE id = :id AND role = :role'
    );
    $accountStatement->execute(['id' => $userId, 'role' => 'customer']);
    $account = $accountStatement->fetch();
    if (!$account) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'Your account details could not be loaded. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account) {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $email = trim((string) ($_POST['email'] ?? ''));
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (
        $fullName === ''
        || strlen($fullName) > 100
        || !preg_match('/\A[a-z0-9][a-z0-9_.-]{2,29}\z/', $username)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 254
        || strlen($contactNumber) > 30
        || strlen($address) > 255
    ) {
        $error = 'Check your details. Usernames must be 3–30 characters using letters, numbers, dots, dashes, or underscores.';
    } elseif (($newPassword !== '' || $passwordConfirmation !== '') && (
        strlen($newPassword) < 8
        || strlen($newPassword) > 256
        || !password_verify($currentPassword, (string) $account['password_hash'])
        || $newPassword !== $passwordConfirmation
    )) {
        $error = 'To change your password, enter your current password and confirm a new password of at least 8 characters.';
    } else {
        try {
            if ($newPassword !== '') {
                $updateStatement = $connection->prepare(
                    'UPDATE users
                     SET full_name = :full_name,
                         username = :username,
                         email = :email,
                         contact_number = :contact_number,
                         address = :address,
                         password_hash = :password_hash
                     WHERE id = :id AND role = :role'
                );
                $updateStatement->execute([
                    'full_name' => $fullName,
                    'username' => $username,
                    'email' => $email,
                    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                    'address' => $address !== '' ? $address : null,
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => $userId,
                    'role' => 'customer',
                ]);
            } else {
                $updateStatement = $connection->prepare(
                    'UPDATE users
                     SET full_name = :full_name,
                         username = :username,
                         email = :email,
                         contact_number = :contact_number,
                         address = :address
                     WHERE id = :id AND role = :role'
                );
                $updateStatement->execute([
                    'full_name' => $fullName,
                    'username' => $username,
                    'email' => $email,
                    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                    'address' => $address !== '' ? $address : null,
                    'id' => $userId,
                    'role' => 'customer',
                ]);
            }

            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_email'] = $email;
            header('Location: profile.php');
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'That username or email address is already being used by another account.';
            } else {
                show_database_error($exception);
                $error = 'Your account details could not be saved. Please try again.';
            }
        }
    }

    $account = array_merge($account, [
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'contact_number' => $contactNumber,
        'address' => $address,
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit profile | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page profile-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Customer account</p>
                <h1>Edit your profile</h1>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <?php if ($account): ?>
            <section class="admin-form-card profile-info-card" aria-labelledby="profile-form-heading">
                <h2 id="profile-form-heading">Account information</h2>
                <p class="profile-info-intro">Update your personal details and contact information.</p>
                <form method="post" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                    <div class="admin-product-form profile-info-fields">
                        <div class="field">
                            <label for="full_name">Full name</label>
                            <input id="full_name" name="full_name" type="text" maxlength="100" value="<?= escape_html((string) $account['full_name']) ?>" autocomplete="name" required>
                        </div>
                        <div class="field">
                            <label for="username">Username</label>
                            <input id="username" name="username" type="text" minlength="3" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{2,29}" value="<?= escape_html((string) $account['username']) ?>" autocomplete="username" required>
                        </div>
                        <div class="field">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email" maxlength="254" value="<?= escape_html((string) $account['email']) ?>" autocomplete="email" required>
                        </div>
                        <div class="field">
                            <label for="contact_number">Contact number</label>
                            <input id="contact_number" name="contact_number" type="tel" maxlength="30" value="<?= escape_html((string) ($account['contact_number'] ?? '')) ?>" autocomplete="tel">
                        </div>
                        <div class="field profile-address-field">
                            <label for="address">Address</label>
                            <input id="address" name="address" type="text" maxlength="255" value="<?= escape_html((string) ($account['address'] ?? '')) ?>" autocomplete="street-address">
                        </div>
                    </div>

                    <h2>Change password</h2>
                    <p class="field-hint">Leave these fields blank to keep your current password.</p>
                    <div class="admin-product-form">
                        <div class="field">
                            <label for="current_password">Current password</label>
                            <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                        </div>
                        <div class="field">
                            <label for="new_password">New password</label>
                            <input id="new_password" name="new_password" type="password" minlength="8" maxlength="256" autocomplete="new-password">
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Confirm new password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="256" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="admin-form-actions">
                        <button class="shop-button" type="submit">Save changes</button>
                        <a class="text-link" href="../index.php">Cancel</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
