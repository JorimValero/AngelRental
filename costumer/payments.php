<?php

require_once __DIR__ . '/../auth/helpers.php';
require_authenticated_role('customer');

$payments = [];
$error = '';

try {
    $statement = get_database_connection()->prepare(
        "SELECT pt.payment_id, pt.reservation_id, pt.reservation_session_id, pt.purpose, pt.method, pt.amount,
                pt.currency, pt.status, pt.paypal_capture_id, pt.paypal_refund_id,
                pt.paid_at, pt.created_at,
                p.product_name,
                CASE WHEN pt.reservation_session_id IS NULL THEN p.product_name ELSE (
                    SELECT GROUP_CONCAT(p2.product_name ORDER BY r2.reservation_id SEPARATOR ', ')
                    FROM reservations r2
                    INNER JOIN products p2 ON p2.product_id = r2.product_id
                    WHERE r2.reservation_session_id = pt.reservation_session_id
                      AND r2.user_id = pt.user_id
                ) END AS receipt_items
         FROM payment_transactions pt
         INNER JOIN reservations r ON r.reservation_id = pt.reservation_id
         INNER JOIN products p ON p.product_id = r.product_id
         WHERE pt.user_id = :user_id
         ORDER BY pt.created_at DESC, pt.payment_id DESC"
    );
    $statement->execute(['user_id' => (int) $_SESSION['user_id']]);
    $payments = $statement->fetchAll();
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'Your payment history could not be loaded. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment history | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Customer account</p>
                <h1>Payment history</h1>
            </div>
            <a class="text-link" href="reservations.php">Back to reservations</a>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($payments !== []): ?>
            <div class="admin-table-wrap">
                <table class="admin-product-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Product</th>
                            <th scope="col">Reservation</th>
                            <th scope="col">Payment</th>
                            <th scope="col">Method</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td data-label="Date"><?= escape_html(date('M j, Y g:i A', strtotime($payment['created_at']))) ?></td>
                                <td data-label="Product"><?= escape_html($payment['product_name']) ?></td>
                                <td data-label="Reservation"><?= $payment['reservation_session_id'] !== null
                                    ? 'Booking session #' . (int) $payment['reservation_session_id']
                                    : '#' . (int) $payment['reservation_id'] ?></td>
                                <td data-label="Payment">
                                    <?= $payment['purpose'] === 'rental'
                                        ? ($payment['reservation_session_id'] !== null ? 'Booking session payment' : 'Rental and deposit')
                                        : ($payment['purpose'] === 'deposit_refund'
                                            ? 'Security deposit refund'
                                            : ($payment['purpose'] === 'reservation_refund' ? 'Reservation cancellation refund' : 'Return fees')) ?>
                                </td>
                                <td data-label="Method"><?= $payment['method'] === 'deposit' ? 'Deducted from deposit' : escape_html(ucfirst($payment['method'])) ?></td>
                                <td data-label="Amount"><?= escape_html($payment['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?></td>
                                <td data-label="Status">
                                    <span class="reservation-status reservation-status-<?= escape_html($payment['status']) ?>"><?= escape_html(ucfirst($payment['status'])) ?></span>
                                </td>
                                <td data-label="Receipt">
                                    <?php if ($payment['status'] === 'paid'): ?>
                                        <a class="shop-button payment-receipt-link" href="receipt.php?payment_id=<?= (int) $payment['payment_id'] ?>">View receipt</a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <section class="admin-form-card reservation-empty-state">
                <h2>No payments yet</h2>
                <p>Rental payments and return-fee payments will appear here.</p>
                <a class="shop-button" href="reservations.php">View my reservations</a>
            </section>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
