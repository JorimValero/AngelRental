<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$connection = get_database_connection();
$error = '';
$notice = '';
$verificationRequired = ($_GET['required'] ?? '') === '1';
$emailPrefill = (string) ($_SESSION['verification_email'] ?? '');
unset($_SESSION['verification_email']);
if ($verificationRequired) {
    $notice = 'Please verify your email address before signing in. You can resend your verification email below.';
}
$tokenValue = $_POST['token'] ?? $_GET['token'] ?? '';
$token = is_string($tokenValue) ? trim($tokenValue) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        $error = 'This verification link is invalid or expired. Request a new link below.';
    } else {
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'SELECT t.user_id
                 FROM email_verification_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = :token_hash
                   AND t.expires_at > CURRENT_TIMESTAMP
                   AND u.role = \'customer\'
                   AND u.email_verified_at IS NULL
                 FOR UPDATE'
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $userId = $statement->fetchColumn();
            if ($userId === false) {
                throw new RuntimeException('This verification link is invalid or expired. Request a new link below.');
            }

            $connection->prepare(
                'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP
                 WHERE id = :user_id AND email_verified_at IS NULL'
            )->execute(['user_id' => (int) $userId]);
            $connection->prepare(
                'DELETE FROM email_verification_tokens WHERE user_id = :user_id'
            )->execute(['user_id' => (int) $userId]);
            $connection->commit();
            $notice = 'Your email address is verified. You can now sign in.';
            $token = '';
        } catch (RuntimeException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            show_database_error($exception);
            $error = 'We could not verify your email right now. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254) {
            try {
                $statement = $connection->prepare(
                    'SELECT u.id, u.full_name, u.email, u.email_verified_at,
                           t.created_at AS token_created_at
                     FROM users u
                     LEFT JOIN email_verification_tokens t ON t.user_id = u.id
                     WHERE u.email = :email AND u.role = \'customer\'
                     LIMIT 1'
                );
                $statement->execute(['email' => $email]);
                $user = $statement->fetch();
                if (
                    $user
                    && $user['email_verified_at'] === null
                    && (
                        $user['token_created_at'] === null
                        || strtotime((string) $user['token_created_at']) <= time() - 120
                    )
                ) {
                    issue_email_verification(
                        $connection,
                        (int) $user['id'],
                        (string) $user['email'],
                        (string) $user['full_name']
                    );
                }
            } catch (PDOException $exception) {
                show_database_error($exception);
                $error = 'We could not process the request right now. Please try again.';
            }
        }

        if ($error === '') {
            $notice = 'If that address belongs to an unverified customer account and is eligible for resend, a verification email will be sent. Please check your inbox and spam folder.';
        }
    }
}

$tokenIsAvailable = false;
if ($notice === '' && preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    try {
        $statement = $connection->prepare(
            'SELECT 1 FROM email_verification_tokens
             WHERE token_hash = :token_hash AND expires_at > CURRENT_TIMESTAMP'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $tokenIsAvailable = (bool) $statement->fetchColumn();
    } catch (PDOException $exception) {
        show_database_error($exception);
        $error = 'We could not check this verification link right now. Please try again.';
    }
}
if ($token !== '' && !$tokenIsAvailable && $error === '' && $notice === '') {
    $error = 'This verification link is invalid or expired. Request a new link below.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email verification | Angels Bridal Rental Shop</title>
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
    <main class="auth-layout verification-layout">
        <section class="form-panel" aria-labelledby="resend-heading">
            <div class="auth-card">
                <?php if ($error !== ''): ?>
                    <p class="form-error" role="alert"><?= escape_html($error) ?></p>
                <?php endif; ?>
                <?php if ($notice !== '' && !$verificationRequired): ?>
                    <p class="store-notice" role="status"><?= escape_html($notice) ?></p>
                <?php endif; ?>
                <?php if ($tokenIsAvailable): ?>
                    <p class="form-intro">Confirm that you want to verify this email address.</p>
                    <form method="post" action="verify.php">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="token" value="<?= escape_html($token) ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <button class="submit-button" type="submit">Verify email address</button>
                    </form>
                <?php endif; ?>
                <?php if (!$tokenIsAvailable && ($notice === '' || $error !== '' || $verificationRequired)): ?>
                    <h2 id="resend-heading">Resend verification email</h2>
                    <p class="form-intro">Enter the email address used when creating your customer account. Resend requests are limited to one every two minutes.</p>
                    <form method="post" action="verify.php">
                        <input type="hidden" name="action" value="resend">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <div class="field">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= escape_html($emailPrefill) ?>" required>
                        </div>
                        <button class="submit-button" type="submit">Send verification email</button>
                    </form>
                <?php endif; ?>
                <?php if (!$tokenIsAvailable): ?>
                    <div class="form-links"><a href="login.php">Return to sign in</a></div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
