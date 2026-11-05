<?php

require_once __DIR__ . '/../auth/helpers.php';
require_authenticated_role('customer');

$paymentId = filter_var($_GET['payment_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$payment = null;
$items = [];
$error = '';

try {
    if ($paymentId === false) {
        throw new RuntimeException('Select a valid payment receipt.');
    }

    $connection = get_database_connection();
    $paymentStatement = $connection->prepare(
        'SELECT pt.payment_id, pt.reservation_id, pt.reservation_session_id, pt.purpose,
                pt.method, pt.amount, pt.currency, pt.status, pt.paypal_capture_id,
                pt.paypal_refund_id, pt.paid_at, pt.created_at, r.user_id,
                u.full_name, r.reservation_session_id AS booking_session_id
         FROM payment_transactions pt
         INNER JOIN reservations r ON r.reservation_id = pt.reservation_id
         INNER JOIN users u ON u.id = pt.user_id
         WHERE pt.payment_id = :payment_id
           AND pt.user_id = :payment_user_id
           AND r.user_id = :reservation_user_id
           AND pt.status = \'paid\'
         LIMIT 1'
    );
    $paymentStatement->execute([
        'payment_id' => $paymentId,
        'payment_user_id' => (int) $_SESSION['user_id'],
        'reservation_user_id' => (int) $_SESSION['user_id'],
    ]);
    $payment = $paymentStatement->fetch();
    if (!$payment) {
        throw new RuntimeException('That paid receipt could not be found.');
    }

    if ($payment['booking_session_id'] !== null) {
        $itemsStatement = $connection->prepare(
            'SELECT p.product_name, r.rental_total, r.refundable_deposit
             FROM reservations r
             INNER JOIN products p ON p.product_id = r.product_id
             WHERE r.user_id = :user_id
               AND r.reservation_session_id = :session_id
             ORDER BY r.reservation_id'
        );
        $itemsStatement->execute([
            'user_id' => (int) $_SESSION['user_id'],
            'session_id' => (int) $payment['booking_session_id'],
        ]);
    } else {
        $itemsStatement = $connection->prepare(
            'SELECT p.product_name, r.rental_total, r.refundable_deposit
             FROM reservations r
             INNER JOIN products p ON p.product_id = r.product_id
             WHERE r.user_id = :user_id AND r.reservation_id = :reservation_id'
        );
        $itemsStatement->execute([
            'user_id' => (int) $_SESSION['user_id'],
            'reservation_id' => (int) $payment['reservation_id'],
        ]);
    }
    $items = $itemsStatement->fetchAll();
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'The payment receipt could not be loaded. Please try again.';
}

$paidAt = $payment
    ? (string) ($payment['paid_at'] ?: $payment['created_at'])
    : '';
$ticketDateTime = $paidAt !== ''
    ? (new DateTimeImmutable($paidAt, new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A')
    : '';
$bookingReference = $payment
    ? ($payment['booking_session_id'] !== null
        ? 'Booking session #' . (int) $payment['booking_session_id']
        : 'Reservation #' . (int) $payment['reservation_id'])
    : '';
$purposeLabel = $payment
    ? match ($payment['purpose']) {
        'rental' => 'Rental payment',
        'deposit_refund' => 'Security deposit refund',
        'reservation_refund' => 'Reservation cancellation refund',
        default => 'Return fees',
    }
    : '';
$methodLabel = $payment
    ? ($payment['method'] === 'deposit' ? 'Deducted from deposit' : ucfirst((string) $payment['method']))
    : '';
$receiptReference = $payment
    ? ($payment['method'] === 'paypal'
        ? (in_array($payment['purpose'], ['deposit_refund', 'reservation_refund'], true)
            ? ($payment['paypal_refund_id'] ?: 'PayPal refund')
            : ($payment['paypal_capture_id'] ?: 'PayPal'))
        : ($payment['method'] === 'deposit'
            ? 'Deducted from deposit'
            : ($payment['purpose'] === 'deposit_refund' ? 'Returned in shop' : 'Received in shop')))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment receipt | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory paypal-receipt-page">
        <section class="admin-form-card paypal-receipt-card">
            <?php if ($error !== ''): ?>
                <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>
            <?php if ($payment): ?>
                <div class="paypal-receipt-content" id="paypal-receipt-content">
                    <header class="paypal-receipt-header">
                        <p class="shop-kicker paypal-receipt-kicker">Ticket #<?= (int) $payment['payment_id'] ?></p>
                        <p class="paypal-receipt-company">Angels Bridal Rental Shop</p>
                        <div class="paypal-receipt-meta">
                            <p><span>Date and time</span><strong><?= escape_html($ticketDateTime) ?></strong></p>
                            <p><span>Customer</span><strong><?= escape_html((string) $payment['full_name']) ?></strong></p>
                        </div>
                    </header>
                    <h1>Payment receipt</h1>
                    <dl class="paypal-receipt-summary">
                        <div>
                            <dt>Payment received</dt>
                            <dd class="paypal-receipt-summary-amount"><?= escape_html((string) $payment['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?></dd>
                        </div>
                        <div>
                            <dt>Transaction type</dt>
                            <dd><?= escape_html($purposeLabel) ?> via <?= escape_html($methodLabel) ?></dd>
                        </div>
                        <div>
                            <dt>Booking</dt>
                            <dd><?= escape_html($bookingReference) ?></dd>
                        </div>
                        <div>
                            <dt>Receipt / reference</dt>
                            <dd><code><?= escape_html((string) $receiptReference) ?></code></dd>
                        </div>
                    </dl>
                    <h2>Items in this booking</h2>
                    <table class="paypal-receipt-items">
                        <thead>
                            <tr><th scope="col">Item</th><th scope="col">Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= escape_html((string) $item['product_name']) ?></strong>
                                        <?php if ($payment['purpose'] === 'rental'): ?>
                                            <span class="paypal-receipt-item-detail">Rental: <?= escape_html((string) $payment['currency']) ?> <?= number_format((float) $item['rental_total'], 2) ?> · Refundable deposit: <?= escape_html((string) $payment['currency']) ?> <?= number_format((float) $item['refundable_deposit'], 2) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="paypal-receipt-item-total"><?= $payment['purpose'] === 'rental'
                                        ? escape_html((string) $payment['currency']) . ' ' . number_format((float) $item['rental_total'] + (float) $item['refundable_deposit'], 2)
                                        : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="paypal-receipt-paid-total"><span>Total paid:</span><strong><?= escape_html((string) $payment['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?></strong></p>
                </div>
                <div class="paypal-receipt-actions">
                    <button class="shop-button" type="button" data-receipt-download data-filename="Ticket-<?= (int) $payment['payment_id'] ?>.png">Download receipt as image</button>
                    <a class="text-link" href="payments.php">Back to payment history</a>
                    <p class="paypal-receipt-download-status" data-receipt-download-status role="status" hidden></p>
                </div>
                <script src="../javascript/receipt-download.bundle.js" defer></script>
            <?php else: ?>
                <a class="shop-button" href="payments.php">Back to payment history</a>
            <?php endif; ?>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
