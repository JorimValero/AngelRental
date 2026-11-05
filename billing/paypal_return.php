<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/paypal_helpers.php';
require_authenticated_role('customer');

$sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$result = is_string($_GET['result'] ?? null) ? $_GET['result'] : '';
$orderId = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
$error = '';
$receipt = null;
$reservations = [];
$recoveryNotice = null;
$bookingReady = false;
$paymentCapturedNow = false;

try {
    if ($sessionId === false) {
        throw new RuntimeException('The booking session could not be verified.');
    }
    $connection = get_database_connection();
    $connection->beginTransaction();
    $sessionStatement = $connection->prepare(
        'SELECT rs.reservation_session_id, rs.created_at, u.full_name
         FROM reservation_sessions rs
         INNER JOIN users u ON u.id = rs.user_id
         WHERE rs.reservation_session_id = :session_id AND rs.user_id = :user_id
         FOR UPDATE'
    );
    $sessionStatement->execute([
        'session_id' => $sessionId,
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    $bookingSession = $sessionStatement->fetch();
    if (!$bookingSession) {
        throw new RuntimeException('The booking session could not be verified.');
    }
    $reservationsStatement = $connection->prepare(
        'SELECT reservation_id, product_id, rental_total, refundable_deposit, status, expires_at
         FROM reservations
         WHERE reservation_session_id = :session_id AND user_id = :user_id
         ORDER BY reservation_id
         FOR UPDATE'
    );
    $reservationsStatement->execute([
        'session_id' => $sessionId,
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    $reservations = $reservationsStatement->fetchAll();
    if ($reservations === []) {
        throw new RuntimeException('The booking session has no reservations.');
    }

    $paymentStatement = $connection->prepare(
        "SELECT payment_id, reservation_id, paypal_order_id, paypal_capture_id, amount, currency, status, paid_at
         FROM payment_transactions
         WHERE reservation_session_id = :session_id AND user_id = :user_id
           AND purpose = 'rental' AND method = 'paypal'
         ORDER BY payment_id DESC LIMIT 1 FOR UPDATE"
    );
    $paymentStatement->execute([
        'session_id' => $sessionId,
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    $payment = $paymentStatement->fetch();
    $ticketDateTime = (new DateTimeImmutable(
        is_string($payment['paid_at'] ?? null) && $payment['paid_at'] !== ''
            ? $payment['paid_at']
            : (string) $bookingSession['created_at'],
        new DateTimeZone('Asia/Manila')
    ))->format('M j, Y g:i A');

    if ($result === 'cancel') {
        if ($payment && $payment['status'] === 'pending') {
            $connection->prepare(
                "UPDATE payment_transactions SET status = 'failed'
                 WHERE payment_id = :payment_id AND status = 'pending'"
            )->execute(['payment_id' => (int) $payment['payment_id']]);
        }
        $connection->commit();
        $_SESSION['reservation_notice'] = 'PayPal checkout was cancelled. Your booking is still awaiting payment and can be retried.';
        header('Location: ../costumer/reservations.php');
        exit;
    }
    if ($result !== 'success' || $orderId === '' || strlen($orderId) > 100
        || !preg_match('/\A[A-Za-z0-9-]+\z/', $orderId)) {
        throw new RuntimeException('PayPal did not return a valid payment confirmation.');
    }
    if (!$payment || !hash_equals((string) $payment['paypal_order_id'], $orderId)) {
        throw new RuntimeException('This PayPal order does not match the booking session.');
    }
    if ($payment['status'] === 'paid') {
        $captureId = (string) $payment['paypal_capture_id'];
        $receipt = [
            'amount' => (string) $payment['amount'],
            'currency' => (string) $payment['currency'],
            'capture_id' => $captureId,
        ];
    } elseif ($payment['status'] !== 'pending') {
        throw new RuntimeException('This PayPal payment is no longer payable.');
    } else {
        $existingOrder = paypal_api_request('GET', '/v2/checkout/orders/' . rawurlencode($orderId));
        $captureAlreadyCompleted = ($existingOrder['status'] ?? '') === 'COMPLETED';
        if (!$captureAlreadyCompleted) {
            foreach ($reservations as $reservation) {
                if (
                    !in_array($reservation['status'], ['pending', 'accepted'], true)
                    || ($reservation['status'] === 'pending' && strtotime((string) $reservation['expires_at']) <= time())
                ) {
                    throw new RuntimeException('This booking session expired or was cancelled before payment completed.');
                }
            }
        }
        $captureResponse = $captureAlreadyCompleted
            ? $existingOrder
            : paypal_api_request(
                'POST',
                '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
                null,
                'rental-session-capture-' . (int) $payment['payment_id']
            );
        $capture = $captureResponse['purchase_units'][0]['payments']['captures'][0] ?? null;
        $capturedAmount = is_array($capture) ? ($capture['amount']['value'] ?? null) : null;
        $captureId = is_array($capture) ? ($capture['id'] ?? null) : null;
        if (
            ($captureResponse['id'] ?? null) !== $orderId
            || !is_array($capture)
            || ($capture['status'] ?? null) !== 'COMPLETED'
            || !is_string($capturedAmount)
            || number_format((float) $capturedAmount, 2, '.', '') !== number_format((float) $payment['amount'], 2, '.', '')
            || ($capture['amount']['currency_code'] ?? null) !== $payment['currency']
            || !is_string($captureId)
            || $captureId === ''
        ) {
            error_log('PayPal session capture did not match expected booking #' . $sessionId);
            throw new RuntimeException('PayPal could not confirm the expected booking total. Please contact the shop before retrying.');
        }

        $connection->prepare(
            "UPDATE payment_transactions
             SET status = 'paid', paypal_capture_id = :capture_id, paid_at = CURRENT_TIMESTAMP
             WHERE payment_id = :payment_id AND status = 'pending'"
        )->execute([
            'capture_id' => $captureId,
            'payment_id' => (int) $payment['payment_id'],
        ]);
        $paymentCapturedNow = true;
        $pendingReservationCount = count(array_filter(
            $reservations,
            static fn (array $reservation): bool => $reservation['status'] === 'pending'
        ));
        $bookingCanBeAccepted = true;
        foreach ($reservations as $reservation) {
            if (
                !in_array($reservation['status'], ['pending', 'accepted'], true)
                || ($reservation['status'] === 'pending' && strtotime((string) $reservation['expires_at']) <= time())
            ) {
                $bookingCanBeAccepted = false;
                break;
            }
        }
        $acceptedCount = 0;
        if ($bookingCanBeAccepted) {
            $acceptStatement = $connection->prepare(
                "UPDATE reservations SET status = 'accepted'
                 WHERE reservation_session_id = :session_id
                   AND user_id = :user_id
                   AND status = 'pending'
                   AND expires_at > CURRENT_TIMESTAMP"
            );
            $acceptStatement->execute([
                'session_id' => $sessionId,
                'user_id' => (int) $_SESSION['user_id'],
            ]);
            $acceptedCount = $acceptStatement->rowCount();
        }
        if (!$bookingCanBeAccepted || $acceptedCount !== $pendingReservationCount) {
            $connection->prepare(
                "UPDATE reservations SET status = 'cancelled'
                 WHERE reservation_session_id = :session_id
                   AND user_id = :user_id
                   AND status IN ('pending', 'accepted', 'expired')"
            )->execute([
                'session_id' => $sessionId,
                'user_id' => (int) $_SESSION['user_id'],
            ]);
            $refundStatement = $connection->prepare(
                "INSERT INTO payment_transactions
                    (reservation_id, reservation_session_id, user_id, purpose, method, amount,
                     currency, paypal_capture_id, status)
                 VALUES
                    (:reservation_id, :session_id, :user_id, 'reservation_refund', 'paypal',
                     :amount, :currency, :capture_id, 'pending')"
            );
            $refundStatement->execute([
                'reservation_id' => (int) $payment['reservation_id'],
                'session_id' => $sessionId,
                'user_id' => (int) $_SESSION['user_id'],
                'amount' => $payment['amount'],
                'currency' => $payment['currency'],
                'capture_id' => $captureId,
            ]);
            $refundPaymentId = (int) $connection->lastInsertId();
            $connection->commit();
            $receipt = [
                'amount' => (string) $payment['amount'],
                'currency' => (string) $payment['currency'],
                'capture_id' => $captureId,
            ];
            $recoveryNotice = 'PayPal captured your payment, but this booking could not be confirmed. A full refund has been started.';
            try {
                if (!process_paypal_deposit_refund($connection, $refundPaymentId)) {
                    $recoveryNotice .= ' The refund is still processing; please check your payment history.';
                }
            } catch (RuntimeException $exception) {
                error_log('Automatic booking refund #' . $refundPaymentId . ' remains pending: ' . $exception->getMessage());
                $recoveryNotice .= ' The refund is still processing; please check your payment history.';
            } catch (PDOException $exception) {
                show_database_error($exception);
                $recoveryNotice .= ' The refund is still processing; please contact the shop if it does not appear in your payment history.';
            }
        } else {
            $connection->commit();
            $bookingReady = true;
            $receipt = [
                'amount' => (string) $payment['amount'],
                'currency' => (string) $payment['currency'],
                'capture_id' => $captureId,
            ];
        }
    }

    if ($paymentCapturedNow && $payment) {
        $paymentMessage = $recoveryNotice !== null
            ? 'PayPal captured your booking payment, but the booking could not be confirmed. A refund has been started and may still be processing.'
            : 'PayPal confirmed your booking payment and your reservation is ready. Amount: '
                . $payment['currency'] . ' ' . number_format((float) $payment['amount'], 2)
                . '. Capture reference: ' . $captureId . '.';
        send_customer_transaction_email(
            $connection,
            (int) $payment['reservation_id'],
            $recoveryNotice !== null ? 'Booking payment captured; refund started' : 'Booking payment received',
            $paymentMessage
        );
        if ($recoveryNotice !== null) {
            send_admin_transaction_email(
                $connection,
                (int) $payment['reservation_id'],
                'A paid booking could not be confirmed',
                'PayPal captured the customer payment, but the booking was cancelled and a refund was started. Please monitor the refund status.'
            );
        }
    }

    $productStatement = $connection->prepare(
        'SELECT p.product_name, r.rental_total, r.refundable_deposit, r.status
         FROM reservations r
         INNER JOIN products p ON p.product_id = r.product_id
         WHERE r.reservation_session_id = :session_id AND r.user_id = :user_id
         ORDER BY r.reservation_id'
    );
    $productStatement->execute([
        'session_id' => $sessionId,
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    $reservations = $productStatement->fetchAll();
    if (!$bookingReady && $recoveryNotice === null) {
        $bookingReady = $reservations !== [];
        foreach ($reservations as $reservation) {
            if ($reservation['status'] !== 'accepted') {
                $bookingReady = false;
                break;
            }
        }
    }
} catch (RuntimeException $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    show_database_error($exception);
    $error = 'The PayPal receipt could not be loaded. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $receipt !== null ? 'Payment receipt' : 'PayPal payment' ?> | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory paypal-receipt-page">
        <section class="admin-form-card paypal-receipt-card">
            <?php if ($error !== ''): ?>
                <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>
            <?php if ($recoveryNotice !== null): ?>
                <p class="store-notice store-notice-error" role="alert"><?= escape_html($recoveryNotice) ?></p>
            <?php endif; ?>
            <?php if ($receipt !== null): ?>
                <div class="paypal-receipt-content" id="paypal-receipt-content">
                    <header class="paypal-receipt-header">
                        <p class="shop-kicker paypal-receipt-kicker">Ticket #<?= (int) $sessionId ?></p>
                        <p class="paypal-receipt-company">Angels Bridal Rental Shop</p>
                        <div class="paypal-receipt-meta">
                            <p><span>Date and time</span><strong><?= escape_html($ticketDateTime) ?></strong></p>
                            <p><span>Customer</span><strong><?= escape_html((string) $bookingSession['full_name']) ?></strong></p>
                        </div>
                    </header>
                    <h1>Payment receipt</h1>
                    <dl class="paypal-receipt-summary">
                        <div>
                            <dt>Payment received</dt>
                            <dd class="paypal-receipt-summary-amount"><?= escape_html($receipt['currency']) ?> <?= number_format((float) $receipt['amount'], 2) ?></dd>
                        </div>
                        <div>
                            <dt>Transaction type</dt>
                            <dd>Rental payment via PayPal</dd>
                        </div>
                        <div>
                            <dt>Booking</dt>
                            <dd>Booking session #<?= (int) $sessionId ?></dd>
                        </div>
                        <div>
                            <dt>Receipt / reference</dt>
                            <dd><code><?= escape_html($receipt['capture_id']) ?></code></dd>
                        </div>
                    </dl>
                    <h2>Items in this booking</h2>
                    <table class="paypal-receipt-items">
                        <thead>
                            <tr><th scope="col">Item</th><th scope="col">Amount</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reservations as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= escape_html($item['product_name']) ?></strong>
                                    <span class="paypal-receipt-item-detail">Rental: <?= escape_html($receipt['currency']) ?> <?= number_format((float) $item['rental_total'], 2) ?> · Refundable deposit: <?= escape_html($receipt['currency']) ?> <?= number_format((float) $item['refundable_deposit'], 2) ?></span>
                                </td>
                                <td class="paypal-receipt-item-total"><?= escape_html($receipt['currency']) ?> <?= number_format((float) $item['rental_total'] + (float) $item['refundable_deposit'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="paypal-receipt-paid-total"><span>Total paid:</span><strong><?= escape_html($receipt['currency']) ?> <?= number_format((float) $receipt['amount'], 2) ?></strong></p>
                    <?php if ($bookingReady || $recoveryNotice === null): ?>
                        <p class="paypal-receipt-message"><?= $bookingReady
                            ? 'Your booking is paid and ready for pickup on its scheduled date. Staff will verify the payment when scanning each product.'
                            : 'Payment was received. Please check your reservation status or contact the shop before pickup.' ?></p>
                    <?php endif; ?>
                </div>
                <div class="paypal-receipt-actions">
                    <button class="shop-button" type="button" data-receipt-download data-filename="Ticket-<?= (int) $sessionId ?>.png">Download receipt as image</button>
                    <a class="text-link" href="../costumer/reservations.php">View my reservations</a>
                    <p class="paypal-receipt-download-status" data-receipt-download-status role="status" hidden></p>
                </div>
                <script src="../javascript/receipt-download.bundle.js" defer></script>
            <?php else: ?>
                <a class="shop-button paypal-receipt-button" href="../costumer/reservations.php">View my reservations</a>
            <?php endif; ?>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
