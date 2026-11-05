<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$error = '';
$notice = '';
$tokenValue = $_POST['token'] ?? $_GET['token'] ?? '';
$token = is_string($tokenValue) ? trim($tokenValue) : '';
$tokenIsValid = false;

if (preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    try {
        $statement = get_database_connection()->prepare(
            'SELECT 1 FROM password_reset_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
               AND t.expires_at > CURRENT_TIMESTAMP
               AND u.role = \'customer\'
             LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $tokenIsValid = (bool) $statement->fetchColumn();
    } catch (PDOException $exception) {
        show_database_error($exception);
        $error = 'We could not validate this reset link right now. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!$tokenIsValid) {
        $error = 'This password reset link is invalid or expired. Request a new link.';
    } elseif (strlen($password) < 8 || strlen($password) > 256) {
        $error = 'Your password must be between 8 and 256 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The passwords do not match.';
    } else {
        $connection = get_database_connection();
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'SELECT u.id, u.full_name, u.email
                 FROM password_reset_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = :token_hash
                   AND t.expires_at > CURRENT_TIMESTAMP
                   AND u.role = \'customer\'
                 FOR UPDATE'
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $user = $statement->fetch();
            if (!$user) {
                throw new RuntimeException('This password reset link is invalid or expired. Request a new link.');
            }

            $connection->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :user_id'
            )->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'user_id' => (int) $user['id'],
            ]);
            $connection->prepare(
                'DELETE FROM password_reset_tokens WHERE user_id = :user_id'
            )->execute(['user_id' => (int) $user['id']]);
            $connection->commit();
            send_app_email(
                (string) $user['email'],
                (string) $user['full_name'],
                'Your password was changed',
                "Hello {$user['full_name']},\n\n"
                    . "The password for your Angels Bridal Rental Shop customer account was changed. "
                    . "If you did not make this change, contact the shop immediately."
            );
            $tokenIsValid = false;
            $token = '';
            $notice = 'Your password has been reset. You can now sign in with your new password.';
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
            $error = 'We could not reset your password right now. Please try again.';
        }
    }
}

if ($token !== '' && !$tokenIsValid && $error === '' && $notice === '') {
    $error = 'This password reset link is invalid or expired. Request a new link.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password | Angels Bridal Rental Shop</title>
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
    </header>
    <main class="auth-layout auth-layout-centered">
        <section class="form-panel" aria-labelledby="reset-heading">
            <div class="auth-card">
                <h1 id="reset-heading">Reset your password</h1>
                <?php if ($error !== ''): ?>
                    <p class="form-error" role="alert"><?= escape_html($error) ?></p>
                <?php endif; ?>
                <?php if ($notice !== ''): ?>
                    <p class="store-notice" role="status"><?= escape_html($notice) ?></p>
                <?php endif; ?>
                <?php if ($tokenIsValid): ?>
                    <p class="form-intro">Choose a new password for your customer account.</p>
                    <form method="post" action="reset_password.php">
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="token" value="<?= escape_html($token) ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <div class="form-fields">
                            <div class="field">
                                <label for="password">New password</label>
                                <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="256" required>
                            </div>
                            <div class="field">
                                <label for="password_confirmation">Confirm new password</label>
                                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="256" required>
                            </div>
                        </div>
                        <button class="submit-button" type="submit">Reset password</button>
                    </form>
                <?php endif; ?>
                <?php if ($notice !== ''): ?>
                    <div class="form-links"><a href="login.php">Sign in</a></div>
                <?php elseif (!$tokenIsValid): ?>
                    <div class="form-links"><a href="forgot.php">Request a new reset link</a></div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
