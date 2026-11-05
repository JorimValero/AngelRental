<?php

require_once __DIR__ . '/helpers.php';

$error = '';
$identifier = '';

if (!empty($_SESSION['user_id'])) {
    redirect_for_role((string) $_SESSION['user_role']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (
        (strlen($identifier) > 254
            || (!filter_var($identifier, FILTER_VALIDATE_EMAIL)
                && !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,29}\z/', $identifier)))
        || $password === ''
    ) {
        $error = 'Enter a valid email address or username and password.';
    } else {
        try {
            $statement = get_database_connection()->prepare(
                'SELECT id, full_name, email, password_hash, role, email_verified_at
                 FROM users
                 WHERE email = :email OR username = :username
                 LIMIT 1'
            );
            $statement->execute([
                'email' => $identifier,
                'username' => strtolower($identifier),
            ]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['role'] === 'customer' && $user['email_verified_at'] === null) {
                    $_SESSION['verification_email'] = (string) ($user['email'] ?? '');
                    header('Location: verify.php?required=1');
                    exit;
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    unset($_SESSION['csrf_token']);
                    redirect_for_role($user['role']);
                }
            } else {
                $error = 'The email, username, or password you entered is incorrect.';
            }
        } catch (PDOException $exception) {
            show_database_error($exception);
            $error = 'We could not access the account database. Please check that the database has been set up.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | Angels Bridal Rental Shop</title>
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
            <a class="header-login-link" href="login.php" aria-current="page">Log In</a>
        </div>
    </header>
    <main class="auth-layout">
        <section class="story-panel" aria-labelledby="story-heading">
            <div class="story-content">
                <h1 class="login-story-heading" id="story-heading">Welcome, beautiful. Your dream look awaits.</h1>
                <p class="story-copy">Sign in to keep your rentals, reservation and account details together in one place</p>
            </div>
        </section>
        <section class="form-panel" aria-labelledby="login-heading">
            <div class="auth-card">
                <h2 id="login-heading">Welcome back</h2>
                <p class="form-intro">Please enter your account details.</p>
                <?php if ($error !== ''): ?>
                    <div class="form-error sign-in-error" role="alert" aria-live="assertive" aria-atomic="true">
                        <span class="form-error-title">Sign-in unsuccessful</span>
                        <span><?= escape_html($error) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (strpos($error, 'verify your email address') !== false): ?>
                    <p class="form-links"><a href="verify.php">Resend verification email</a></p>
                <?php endif; ?>
                <form method="post" action="login.php" novalidate data-auth-validation="login">
                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                    <div class="form-fields">
                        <div class="field">
                            <label for="identifier">Email address or username</label>
                            <input id="identifier" name="identifier" type="text" autocomplete="username" maxlength="254" placeholder="Enter your email or username" value="<?= escape_html($identifier) ?>" required>
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
                        </div>
                    </div>
                    <div class="form-links auth-forgot-link">
                        <a href="forgot.php">Forgot your password?</a>
                    </div>
                    <button class="submit-button" type="submit">Sign in</button>
                </form>
                <div class="form-links auth-account-link">
                    <span>New here?</span><a href="register.php">Create an account</a>
                </div>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>