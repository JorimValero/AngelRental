<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$error = '';
$success = '';
$successType = 'success';
$fullName = '';
$username = '';
$email = '';
$contactNumber = '';
$address = '';

if (!empty($_SESSION['user_id'])) {
    redirect_for_role((string) $_SESSION['user_role']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $email = trim((string) ($_POST['email'] ?? ''));
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
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
        || strlen($password) < 8
        || strlen($password) > 256
    ) {
        $error = 'Check your details. Usernames must be 3–30 characters using letters, numbers, dots, dashes, or underscores, and passwords need at least 8 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $statement = get_database_connection()->prepare(
                'INSERT INTO users (full_name, username, email, contact_number, address, password_hash, role)
                 VALUES (:full_name, :username, :email, :contact_number, :address, :password_hash, :role)'
            );
            $statement->execute([
                'full_name' => $fullName,
                'username' => $username,
                'email' => $email,
                'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                'address' => $address !== '' ? $address : null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'customer',
            ]);

            $userId = (int) get_database_connection()->lastInsertId();
            if (issue_email_verification(get_database_connection(), $userId, $email, $fullName)) {
                $success = 'Your account has been created. Check your email for the verification link before signing in.';
            } else {
                $successType = 'warning';
                $success = 'Your account has been created, but we could not deliver the verification email. Please use the resend link below.';
            }
        } catch (PDOException $exception) {
            if (isset($userId) && $userId > 0) {
                show_database_error($exception);
                $successType = 'warning';
                $success = 'Your account has been created, but verification could not be prepared. Please use the resend link below.';
            } elseif ($exception->getCode() === '23000') {
                $error = 'That email address or username is already in use.';
            } else {
                show_database_error($exception);
                $error = 'We could not save your account. Please check that the database has been set up.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="auth-page">
    <header class="shop-header">
        <a class="shop-brand" href="../index.php"><img class="shop-brand-logo" src="../image/Angel%20Logo.png" alt="">Angels Bridal Rental Shop</a>
        <nav class="shop-nav" aria-label="Main navigation">
            <a href="../index.php#home">Home</a>
            <a href="../index.php#collections">Collections</a>
            <a href="../faqs.php">FAQs</a>
        </nav>
        <div class="shop-header-actions">
            <details class="navigation-dropdown">
                <summary aria-label="Open site navigation" title="Site navigation">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="5" r="1.8"></circle>
                        <circle cx="12" cy="12" r="1.8"></circle>
                        <circle cx="12" cy="19" r="1.8"></circle>
                    </svg>
                </summary>
                <nav class="navigation-menu-panel" aria-label="Main navigation">
                    <a href="../index.php#home">Home</a>
                    <a href="../index.php#collections">Collections</a>
                    <a href="../faqs.php">FAQs</a>
                </nav>
            </details>
            <a class="header-login-link" href="login.php">Log In</a>
        </div>
    </header>
    <main class="auth-layout register-layout">
        <section class="form-panel" aria-labelledby="register-heading">
            <div class="auth-card register-card">
                <h2 id="register-heading">Create your account</h2>
                <p class="form-intro">Enter your details to get started.</p>
                <?php if ($success !== ''): ?>
                    <p class="store-notice store-notice-<?= escape_html($successType) ?>" role="status"><?= escape_html($success) ?></p>
                    <div class="form-links">
                        <a href="verify.php">Resend verification email</a>
                        <a href="login.php">Return to sign in</a>
                    </div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <p class="form-error" role="alert"><?= escape_html($error) ?></p>
                <?php endif; ?>
                <?php if ($success === ''): ?>
                <form method="post" action="register.php" novalidate data-auth-validation="register">
                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                    <div class="form-fields register-fields">
                        <div class="field">
                            <label for="full_name">Full name</label>
                            <input id="full_name" name="full_name" type="text" autocomplete="name" maxlength="100" placeholder="Enter your full name" value="<?= escape_html($fullName) ?>" required>
                        </div>
                        <div class="field">
                            <label for="username">Username</label>
                            <input id="username" name="username" type="text" autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{2,29}" title="Use 3–30 letters, numbers, dots, dashes, or underscores. Start with a letter or number." placeholder="Choose a username" value="<?= escape_html($username) ?>" required>
                        </div>
                        <div class="field">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="Enter your email" value="<?= escape_html($email) ?>" required>
                        </div>
                        <div class="field">
                            <label for="contact_number">Contact number</label>
                            <input id="contact_number" name="contact_number" type="tel" autocomplete="tel" maxlength="30" placeholder="Enter your contact number" value="<?= escape_html($contactNumber) ?>">
                        </div>
                        <div class="field register-address">
                            <label for="address">Address</label>
                            <input id="address" name="address" type="text" autocomplete="street-address" maxlength="255" placeholder="Enter your address" value="<?= escape_html($address) ?>">
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="256" placeholder="At least 8 characters" required>
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Confirm password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="256" placeholder="Enter your password again" required>
                        </div>
                    </div>
                    <button class="submit-button" type="submit">Create account</button>
                </form>
                <div class="form-links auth-account-link">
                    <span>Already have an account?</span><a href="login.php">Sign in</a>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <section class="story-panel" aria-labelledby="story-heading">
            <div class="story-content">
                <h1 class="register-story-heading" id="story-heading">So happy you found us!</h1>
                <p class="story-copy">Let's find something you'll love wearing for your special day.</p>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>