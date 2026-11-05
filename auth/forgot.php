<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254) {
            if (!password_reset_email_is_configured()) {
                $error = 'Password reset email is not configured. Please contact the shop administrator.';
            } else {
                try {
                    $connection = get_database_connection();
                    $statement = $connection->prepare(
                        'SELECT u.id, u.full_name, u.email, t.created_at AS token_created_at
                         FROM users u
                         LEFT JOIN password_reset_tokens t ON t.user_id = u.id
                         WHERE u.email = :email AND u.role = \'customer\'
                         LIMIT 1'
                    );
                    $statement->execute(['email' => $email]);
                    $user = $statement->fetch();

                    if (
                        $user
                        && (
                            $user['token_created_at'] === null
                            || strtotime((string) $user['token_created_at']) <= time() - 120
                        )
                    ) {
                        issue_customer_password_reset(
                            $connection,
                            (int) $user['id'],
                            (string) $user['email'],
                            (string) $user['full_name']
                        );
                    }
                } catch (PDOException $exception) {
                    show_database_error($exception);
                    $error = 'We could not process your request right now. Please try again.';
                } catch (Throwable $exception) {
                    error_log('RentalSystem password reset request failed: ' . $exception->getMessage());
                    $error = 'We could not process your request right now. Please try again.';
                }
            }
        }

        if ($error === '') {
            $notice = 'If that address belongs to a customer account, a password reset link will be sent if it is eligible. Check your inbox and spam folder.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password | Angels Bridal Rental Shop</title>
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
            <a class="header-login-link" href="login.php">Log In</a>
        </div>
    </header>
    <main class="auth-layout auth-layout-centered">
        <section class="form-panel" aria-labelledby="forgot-heading">
            <div class="auth-card">
                <h1 id="forgot-heading">Forgot your password?</h1>
                <p class="form-intro">Enter the email address linked to your customer account. We’ll email you a password reset link if the account is eligible.</p>
                <?php if ($error !== ''): ?>
                    <p class="form-error" role="alert"><?= escape_html($error) ?></p>
                <?php endif; ?>
                <?php if ($notice !== ''): ?>
                    <p class="store-notice" role="status"><?= escape_html($notice) ?></p>
                <?php endif; ?>
                <form method="post" action="forgot.php">
                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                    <div class="field">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
                    </div>
                    <button class="submit-button" type="submit">Send password reset link</button>
                </form>
                <div class="form-links"><a href="login.php">Return to sign in</a></div>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
