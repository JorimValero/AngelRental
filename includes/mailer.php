<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

function mail_delivery_is_configured(): bool
{
    foreach (['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'] as $variable) {
        $value = getenv($variable);
        if ($value === false || trim($value) === '') {
            return false;
        }
    }

    return true;
}

function password_reset_email_is_configured(): bool
{
    $baseUrl = rtrim((string) (getenv('RENTAL_APP_URL') ?: ''), '/');

    return mail_delivery_is_configured()
        && preg_match('/\Ahttps?:\/\/[a-zA-Z0-9.-]+(?::\d+)?(?:\/[^\s]*)?\z/', $baseUrl) === 1;
}

function create_mailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = getenv('MAIL_HOST') ?: '';
    $mail->SMTPAuth = true;
    $mail->Username = getenv('MAIL_USERNAME') ?: '';
    $mail->Password = getenv('MAIL_PASSWORD') ?: '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) (getenv('MAIL_PORT') ?: 587);
    $mail->setFrom(
        getenv('MAIL_FROM_ADDRESS') ?: '',
        getenv('MAIL_FROM_NAME') ?: 'Angels Bridal Rental Shop'
    );
    $mail->isHTML(true);

    return $mail;
}

function render_app_email(
    string $subject,
    string $message,
    string $eyebrow,
    ?string $heading,
    ?string $actionLabel,
    ?string $actionUrl,
    bool $showActionLinkFallback
): string {
    $paragraphs = preg_split('/\R{2,}/', trim($message)) ?: [];
    $bodyHtml = '';
    foreach ($paragraphs as $paragraph) {
        $bodyHtml .= '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.7;">'
            . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . '</p>';
    }

    $buttonHtml = '';
    if ($actionLabel !== null && $actionUrl !== null) {
        $safeActionLabel = htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeActionUrl = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $buttonHtml = '<tr><td align="center" style="padding:12px 0 24px;">'
            . '<a class="email-button" href="' . $safeActionUrl . '" '
            . 'style="display:inline-block;padding:16px 30px;background:#b97893;border:1px solid #87536a;'
            . 'border-radius:12px;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;">'
            . $safeActionLabel . '</a></td></tr>';
        if ($showActionLinkFallback) {
            $buttonHtml .= '<tr><td class="email-link-fallback" style="padding:0 34px 24px;color:#6b7280;'
            . 'font-size:13px;line-height:1.6;">'
            . 'If the button does not work, copy and paste this link into your browser:<br>'
            . '<a href="' . $safeActionUrl . '" style="color:#87536a;word-break:break-all;">'
            . $safeActionUrl . '</a></td></tr>';
        }
    }

    $safeEyebrow = htmlspecialchars($eyebrow, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeHeading = htmlspecialchars($heading ?? $subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<meta name="supported-color-schemes" content="light dark">'
        . '<title>' . $safeHeading . '</title><style>'
        . ':root{color-scheme:light dark;}'
        . '@media (prefers-color-scheme:dark){'
        . 'body.email-outer,table.email-outer{background:#17191d!important;background-color:#17191d!important;}'
        . 'td.email-card{background:#111315!important;background-color:#111315!important;'
        . 'border-color:#292d32!important;}'
        . '.email-body{color:#e5e7eb!important;}'
        . '.email-copy,.email-copy p{color:#c4cbd4!important;}'
        . '.email-eyebrow{color:#d8a9bb!important;}'
        . '.email-footer{color:#9aa3af!important;}'
        . '.email-link-fallback{color:#aab3bf!important;}'
        . '.email-link-fallback a{color:#d8a9bb!important;}'
        . '}'
        . '@media (prefers-color-scheme:light){'
        . 'body.email-outer,table.email-outer{background:#f1f3f5!important;background-color:#f1f3f5!important;}'
        . 'td.email-card{background:#ffffff!important;background-color:#ffffff!important;'
        . 'border-color:#e4e7eb!important;}'
        . '.email-body{color:#374151!important;}'
        . '.email-copy,.email-copy p{color:#4b5563!important;}'
        . '.email-eyebrow{color:#87536a!important;}'
        . '.email-footer{color:#6b7280!important;}'
        . '.email-link-fallback{color:#6b7280!important;}'
        . '.email-link-fallback a{color:#87536a!important;}'
        . '}'
        . '@media only screen and (max-width:600px){.email-shell{padding:16px!important;}'
        . '.email-card{border-radius:16px!important;}.email-content{padding:28px 22px!important;}'
        . '.email-link-fallback{padding-left:22px!important;padding-right:22px!important;}}'
        . '</style></head><body class="email-outer" style="margin:0;padding:0;background:#f1f3f5;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" '
        . 'class="email-outer" style="background:#f1f3f5;"><tr><td align="center" class="email-shell" '
        . 'style="padding:32px 16px;"><table role="presentation" width="600" cellspacing="0" '
        . 'cellpadding="0" border="0" style="width:100%;max-width:600px;">'
        . '<tr><td><table role="presentation" width="100%" cellspacing="0" '
        . 'cellpadding="0" border="0" style="background:#e8d7df;border-radius:16px 16px 0 0;">'
        . '<tr><td style="padding:24px 28px;color:#3e3439;font-size:16px;font-weight:700;">'
        . 'Angels Bridal Rental Shop</td></tr></table></td></tr>'
        . '<tr><td class="email-card" style="background:#ffffff;border:1px solid #e4e7eb;'
        . 'border-radius:0 0 16px 16px;"><table role="presentation" width="100%" cellspacing="0" '
        . 'cellpadding="0" border="0"><tr><td class="email-content" style="padding:36px 34px 20px;">'
        . '<p class="email-eyebrow" style="margin:0 0 12px;color:#87536a;'
        . 'font-size:12px;font-weight:700;letter-spacing:2px;">'
        . $safeEyebrow . '</p><h1 class="email-body" style="margin:0 0 18px;color:#20242a;'
        . 'font-size:27px;line-height:1.3;">' . $safeHeading . '</h1>'
        . '<div class="email-copy" style="color:#4b5563;">' . $bodyHtml . '</div></td></tr>'
        . $buttonHtml
        . '<tr><td class="email-content" style="padding:0 34px 26px;"><p class="email-footer" '
        . 'style="margin:0;color:#6b7280;font-size:13px;line-height:1.6;">'
        . 'This is an automated message from Angels Bridal Rental Shop.</p></td></tr></table></td></tr>'
        . '<tr><td align="center" class="email-footer" style="padding:18px 12px;color:#6b7280;'
        . 'font-size:12px;">Angels Bridal Rental Shop</td></tr></table></td></tr></table></body></html>';
}

function send_app_email(
    string $address,
    string $name,
    string $subject,
    string $message,
    ?string $actionLabel = null,
    ?string $actionUrl = null,
    string $eyebrow = 'SHOP UPDATE',
    ?string $heading = null,
    bool $showActionLinkFallback = true
): bool
{
    $actionScheme = $actionUrl === null ? false : parse_url($actionUrl, PHP_URL_SCHEME);
    if (
        ($actionLabel !== null || $actionUrl !== null)
        && (
            $actionLabel === null
            || trim($actionLabel) === ''
            || $actionUrl === null
            || !filter_var($actionUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) $actionScheme), ['http', 'https'], true)
        )
    ) {
        error_log('RentalSystem email was not sent: action link is invalid.');
        return false;
    }

    if (
        !filter_var($address, FILTER_VALIDATE_EMAIL)
        || !mail_delivery_is_configured()
    ) {
        error_log('RentalSystem email was not sent: recipient or SMTP configuration is invalid.');
        return false;
    }

    try {
        $mail = create_mailer();
        $mail->addAddress($address, $name);
        $mail->Subject = $subject;
        $mail->Body = render_app_email(
            $subject,
            $message,
            $eyebrow,
            $heading,
            $actionLabel,
            $actionUrl,
            $showActionLinkFallback
        );
        $mail->AltBody = $message;
        if ($showActionLinkFallback && $actionLabel !== null && $actionUrl !== null) {
            $mail->AltBody .= "\n\n{$actionLabel}: {$actionUrl}";
        }
        $mail->send();
        return true;
    } catch (MailerException $exception) {
        error_log('RentalSystem email delivery failed: ' . $exception->getMessage());
        return false;
    }
}

function send_customer_transaction_email(
    PDO $connection,
    int $reservationId,
    string $subject,
    string $message
): bool {
    try {
        $statement = $connection->prepare(
            'SELECT u.full_name, u.email, p.product_name, r.pickup_date, r.return_date,
                    r.reservation_session_id
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             INNER JOIN products p ON p.product_id = r.product_id
             WHERE r.reservation_id = :reservation_id'
        );
        $statement->execute(['reservation_id' => $reservationId]);
        $reservation = $statement->fetch();
        if (!$reservation || !is_string($reservation['email']) || $reservation['email'] === '') {
            error_log('RentalSystem notification not sent: reservation customer or email was not found.');
            return false;
        }

        $details = "Booking details:\n"
            . "Product: {$reservation['product_name']}\n"
            . "Pickup: {$reservation['pickup_date']}\n"
            . "Return: {$reservation['return_date']}\n";
        if ($reservation['reservation_session_id'] !== null) {
            $itemsStatement = $connection->prepare(
                'SELECT p.product_name, r.pickup_date, r.return_date
                 FROM reservations r
                 INNER JOIN products p ON p.product_id = r.product_id
                 WHERE r.reservation_session_id = :session_id
                 ORDER BY r.reservation_id'
            );
            $itemsStatement->execute(['session_id' => (int) $reservation['reservation_session_id']]);
            $items = $itemsStatement->fetchAll();
            $details = 'Booking session: #' . (int) $reservation['reservation_session_id'] . "\n";
            foreach ($items as $item) {
                $details .= "Product: {$item['product_name']}\n"
                    . "Pickup: {$item['pickup_date']}; Return: {$item['return_date']}\n";
            }
        }

        return send_app_email(
            $reservation['email'],
            $reservation['full_name'],
            $subject,
            "Hello {$reservation['full_name']},\n\n{$message}\n\n{$details}"
        );
    } catch (PDOException $exception) {
        error_log('RentalSystem notification lookup failed: ' . $exception->getMessage());
        return false;
    }
}

function send_admin_transaction_email(
    PDO $connection,
    int $reservationId,
    string $subject,
    string $message
): void {
    try {
        $statement = $connection->prepare(
            'SELECT u.full_name, u.email, p.product_name, r.pickup_date, r.return_date,
                    r.reservation_session_id
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             INNER JOIN products p ON p.product_id = r.product_id
             WHERE r.reservation_id = :reservation_id'
        );
        $statement->execute(['reservation_id' => $reservationId]);
        $reservation = $statement->fetch();
        if (!$reservation) {
            error_log('RentalSystem admin notification not sent: reservation was not found.');
            return;
        }

        $adminStatement = $connection->query(
            "SELECT full_name, email FROM users
             WHERE role IN ('admin', 'staff') AND email IS NOT NULL AND email <> ''"
        );
        $recipients = $adminStatement->fetchAll();
        $configuredAdmin = getenv('RENTAL_ADMIN_EMAIL');
        if (is_string($configuredAdmin) && filter_var($configuredAdmin, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = ['full_name' => 'Shop administrator', 'email' => $configuredAdmin];
        }
        if ($recipients === []) {
            error_log('RentalSystem admin notification not sent: no admin or staff email recipients are configured.');
            return;
        }

        $details = "Customer: {$reservation['full_name']}\n";
        if ($reservation['reservation_session_id'] !== null) {
            $itemsStatement = $connection->prepare(
                'SELECT p.product_name, r.pickup_date, r.return_date
                 FROM reservations r
                 INNER JOIN products p ON p.product_id = r.product_id
                 WHERE r.reservation_session_id = :session_id
                 ORDER BY r.reservation_id'
            );
            $itemsStatement->execute(['session_id' => (int) $reservation['reservation_session_id']]);
            $details .= 'Booking session: #' . (int) $reservation['reservation_session_id'] . "\n";
            foreach ($itemsStatement->fetchAll() as $item) {
                $details .= "Product: {$item['product_name']}\n"
                    . "Pickup: {$item['pickup_date']}; Return: {$item['return_date']}\n";
            }
        } else {
            $details .= "Product: {$reservation['product_name']}\n"
                . "Pickup: {$reservation['pickup_date']}\n"
                . "Return: {$reservation['return_date']}\n";
        }

        $sent = [];
        foreach ($recipients as $recipient) {
            $address = (string) $recipient['email'];
            if (!isset($sent[$address])) {
                send_app_email(
                    $address,
                    (string) $recipient['full_name'],
                    $subject,
                    "{$message}\n\n{$details}"
                );
                $sent[$address] = true;
            }
        }
    } catch (PDOException $exception) {
        error_log('RentalSystem admin notification lookup failed: ' . $exception->getMessage());
    }
}

function issue_email_verification(PDO $connection, int $userId, string $email, string $name): bool
{
    $token = bin2hex(random_bytes(32));
    $statement = $connection->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (:user_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR), CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                                 expires_at = VALUES(expires_at),
                                 created_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'user_id' => $userId,
        'token_hash' => hash('sha256', $token),
    ]);

    $baseUrl = rtrim((string) (getenv('RENTAL_APP_URL') ?: ''), '/');
    if ($baseUrl === '' || !preg_match('/\Ahttps?:\/\/[a-zA-Z0-9.-]+(?::\d+)?(?:\/[^\s]*)?\z/', $baseUrl)) {
        error_log('RentalSystem verification email not sent: RENTAL_APP_URL is missing or invalid.');
        return false;
    }

    $link = $baseUrl . '/auth/verify.php?token=' . rawurlencode($token);
    return send_app_email(
        $email,
        $name,
        'Verify your email address',
        "Hello {$name},\n\n"
            . "Please verify your email address to activate your Angels Bridal Rental Shop customer account.\n\n"
            . "This link expires in 24 hours. If you did not create this account, you can ignore this email.",
        'Verify email address',
        $link,
        'ACCOUNT SECURITY',
        'Verify your email',
        false
    );
}

function issue_customer_password_reset(PDO $connection, int $userId, string $email, string $name): bool
{
    $token = bin2hex(random_bytes(32));
    $statement = $connection->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (:user_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR), CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                                 expires_at = VALUES(expires_at),
                                 created_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'user_id' => $userId,
        'token_hash' => hash('sha256', $token),
    ]);

    $baseUrl = rtrim((string) (getenv('RENTAL_APP_URL') ?: ''), '/');
    if ($baseUrl === '' || !preg_match('/\Ahttps?:\/\/[a-zA-Z0-9.-]+(?::\d+)?(?:\/[^\s]*)?\z/', $baseUrl)) {
        error_log('RentalSystem password reset email not sent: RENTAL_APP_URL is missing or invalid.');
        return false;
    }

    $link = $baseUrl . '/auth/reset_password.php?token=' . rawurlencode($token);
    return send_app_email(
        $email,
        $name,
        'Reset your password',
        "Hello {$name},\n\n"
            . "We received a request to reset the password for your Angels Bridal Rental Shop account. "
            . "Use the button below to choose a new password.\n\n"
            . "This link expires in one hour and can only be used once. If you did not request a password reset, "
            . "you can ignore this email.",
        'Reset my password',
        $link,
        'ACCOUNT SECURITY',
        'Reset your password',
        false
    );
}

function expire_stale_reservations(PDO $connection): void
{
    $connection->beginTransaction();
    try {
        $statement = $connection->query(
            "SELECT reservation_id FROM reservations
             WHERE status = 'pending' AND expires_at <= CURRENT_TIMESTAMP
             ORDER BY reservation_id FOR UPDATE"
        );
        $reservationIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $expiredIds = [];
        if ($reservationIds !== []) {
            $updateStatement = $connection->prepare(
                "UPDATE reservations SET status = 'expired'
                 WHERE reservation_id = :reservation_id AND status = 'pending'
                   AND expires_at <= CURRENT_TIMESTAMP"
            );
            foreach ($reservationIds as $reservationId) {
                $updateStatement->execute(['reservation_id' => $reservationId]);
                if ($updateStatement->rowCount() === 1) {
                    $expiredIds[] = $reservationId;
                }
            }
        }
        $connection->commit();
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    foreach ($expiredIds as $reservationId) {
        send_customer_transaction_email(
            $connection,
            $reservationId,
            'Your reservation request expired',
            'Your pending reservation expired before it was accepted. You can create a new reservation from the shop.'
        );
    }
}