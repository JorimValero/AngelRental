<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../billing/paypal_helpers.php';
require_authenticated_role('staff');

$payments = [];
$outstandingFees = [];
$pendingRefunds = [];
$error = '';
$notice = (string) ($_SESSION['staff_payment_notice'] ?? '');
unset($_SESSION['staff_payment_notice']);

try {
    $connection = get_database_connection();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
            $error = 'Your session expired. Refresh the page and try again.';
        } elseif ($action === 'retry_deposit_refund') {
            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($paymentId === false) {
                $error = 'Select a valid PayPal refund.';
            } else {
                try {
                    $refundCompleted = process_paypal_deposit_refund($connection, $paymentId);
                    $_SESSION['staff_payment_notice'] = $refundCompleted
                        ? 'PayPal refund confirmed.'
                        : 'PayPal accepted the refund request, but it is still processing. Check its status again shortly.';
                    header('Location: payments.php');
                    exit;
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                } catch (PDOException $exception) {
                    show_database_error($exception);
                    $error = 'The PayPal refund could not be checked. Please try again.';
                }
            }
        } elseif ($action !== 'record_cash_fees' || $reservationId === false) {
            $error = 'Select a valid return-fee payment.';
        } else {
            try {
                $connection->beginTransaction();
                $reservationStatement = $connection->prepare(
                    'SELECT user_id, status, damage_charge, late_charge
                     FROM reservations WHERE reservation_id = :reservation_id FOR UPDATE'
                );
                $reservationStatement->execute(['reservation_id' => $reservationId]);
                $reservation = $reservationStatement->fetch();
                if (!$reservation || $reservation['status'] !== 'completed') {
                    throw new RuntimeException('Return fees can only be recorded for a completed return.');
                }
                $feeTotal = number_format(
                    (float) $reservation['damage_charge'] + (float) $reservation['late_charge'],
                    2,
                    '.',
                    ''
                );
                $paidFeesStatement = $connection->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM payment_transactions
                     WHERE reservation_id = :reservation_id
                       AND purpose = 'fees' AND status = 'paid'"
                );
                $paidFeesStatement->execute(['reservation_id' => $reservationId]);
                $feeTotal = number_format(
                    max((float) $feeTotal - (float) $paidFeesStatement->fetchColumn(), 0),
                    2,
                    '.',
                    ''
                );
                if ((float) $feeTotal <= 0 || (float) $feeTotal > 99999999.99) {
                    throw new RuntimeException('This reservation has no valid outstanding return-fee balance.');
                }
                $connection->prepare(
                    "UPDATE payment_transactions SET status = 'failed'
                     WHERE reservation_id = :reservation_id
                       AND purpose = 'fees' AND method = 'paypal' AND status = 'pending'"
                )->execute(['reservation_id' => $reservationId]);
                $insertStatement = $connection->prepare(
                    "INSERT INTO payment_transactions
                        (reservation_id, user_id, purpose, method, amount, currency, status, paid_at)
                     VALUES
                        (:reservation_id, :user_id, 'fees', 'cash', :amount, 'PHP', 'paid', CURRENT_TIMESTAMP)"
                );
                $insertStatement->execute([
                    'reservation_id' => $reservationId,
                    'user_id' => (int) $reservation['user_id'],
                    'amount' => $feeTotal,
                ]);
                $connection->commit();
                send_customer_transaction_email(
                    $connection,
                    (int) $reservationId,
                    'Return-fee payment received',
                    'We recorded your cash payment of ₱' . number_format((float) $feeTotal, 2)
                        . ' for the return fees.'
                );
                $_SESSION['staff_payment_notice'] = 'Cash return-fee payment recorded.';
                header('Location: payments.php');
                exit;
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
                $error = 'The cash payment could not be recorded. Please try again.';
            }
        }
    }

    $payments = $connection->query(
        'SELECT pt.payment_id, pt.reservation_id, pt.reservation_session_id, pt.purpose, pt.method, pt.amount,
                pt.currency, pt.status, pt.paypal_refund_id,
                pt.paid_at, pt.created_at,
                p.product_name, u.full_name, u.username
         FROM payment_transactions pt
         INNER JOIN reservations r ON r.reservation_id = pt.reservation_id
         INNER JOIN products p ON p.product_id = r.product_id
         INNER JOIN users u ON u.id = pt.user_id
         ORDER BY pt.created_at DESC, pt.payment_id DESC'
    )->fetchAll();
    $outstandingFees = $connection->query(
        "SELECT r.reservation_id, r.damage_charge, r.late_charge,
                COALESCE((
                    SELECT SUM(pt.amount) FROM payment_transactions pt
                    WHERE pt.reservation_id = r.reservation_id
                      AND pt.purpose = 'fees' AND pt.status = 'paid'
                ), 0) AS fees_paid_amount,
                p.product_name, u.full_name, u.username
         FROM reservations r
         INNER JOIN products p ON p.product_id = r.product_id
         INNER JOIN users u ON u.id = r.user_id
         WHERE r.status = 'completed'
           AND r.damage_charge + r.late_charge > COALESCE((
               SELECT SUM(pt.amount) FROM payment_transactions pt
               WHERE pt.reservation_id = r.reservation_id
                 AND pt.purpose = 'fees' AND pt.status = 'paid'
           ), 0)
         ORDER BY r.updated_at DESC, r.reservation_id DESC"
    )->fetchAll();
    $pendingRefunds = $connection->query(
        "SELECT pt.payment_id, pt.reservation_id, pt.amount, pt.paypal_refund_id,
                p.product_name, u.full_name, u.username
         FROM payment_transactions pt
         INNER JOIN reservations r ON r.reservation_id = pt.reservation_id
         INNER JOIN products p ON p.product_id = r.product_id
         INNER JOIN users u ON u.id = pt.user_id
         WHERE pt.purpose IN ('deposit_refund', 'reservation_refund')
           AND pt.method = 'paypal' AND pt.status = 'pending'
         ORDER BY pt.created_at DESC, pt.payment_id DESC"
    )->fetchAll();
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'Payment records could not be loaded. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payments | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Shop account</p>
                <h1>Payments</h1>
            </div>
            <a class="text-link" href="reservations.php">View reservations</a>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <p class="store-notice" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if ($pendingRefunds !== []): ?>
            <section class="admin-product-list" aria-labelledby="pending-refunds-heading">
                <h2 id="pending-refunds-heading">Pending PayPal refunds</h2>
                <div class="admin-table-wrap">
                    <table class="admin-product-table">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col">Product</th>
                                <th scope="col">Reservation</th>
                                <th scope="col">Refund</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRefunds as $refund): ?>
                                <tr>
                                    <td data-label="Customer"><?= escape_html($refund['full_name']) ?> <span class="reservation-customer-username">@<?= escape_html($refund['username']) ?></span></td>
                                    <td data-label="Product"><?= escape_html($refund['product_name']) ?></td>
                                    <td data-label="Reservation">#<?= (int) $refund['reservation_id'] ?></td>
                                    <td data-label="Refund">₱<?= number_format((float) $refund['amount'], 2) ?></td>
                                    <td data-label="Action">
                                        <form method="post" action="payments.php">
                                            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="retry_deposit_refund">
                                            <input type="hidden" name="payment_id" value="<?= (int) $refund['payment_id'] ?>">
                                            <button class="shop-button" type="submit"><?= empty($refund['paypal_refund_id']) ? 'Process refund' : 'Check refund status' ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($outstandingFees !== []): ?>
            <section class="admin-product-list" aria-labelledby="outstanding-fees-heading">
                <h2 id="outstanding-fees-heading">Outstanding return fees</h2>
                <div class="admin-table-wrap">
                    <table class="admin-product-table">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col">Product</th>
                                <th scope="col">Reservation</th>
                                <th scope="col">Balance</th>
                                <th scope="col">Shop payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outstandingFees as $fee): ?>
                                <tr>
                                    <td data-label="Customer"><?= escape_html($fee['full_name']) ?> <span class="reservation-customer-username">@<?= escape_html($fee['username']) ?></span></td>
                                    <td data-label="Product"><?= escape_html($fee['product_name']) ?></td>
                                    <td data-label="Reservation">#<?= (int) $fee['reservation_id'] ?></td>
                                    <td data-label="Balance">₱<?= number_format(max((float) $fee['damage_charge'] + (float) $fee['late_charge'] - (float) $fee['fees_paid_amount'], 0), 2) ?></td>
                                    <td data-label="Shop payment">
                                        <form method="post" action="payments.php" onsubmit="return confirm('Confirm that this customer paid these return fees in cash?');">
                                            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="record_cash_fees">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $fee['reservation_id'] ?>">
                                            <button class="shop-button" type="submit">Record cash received</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($payments !== []): ?>
            <div class="admin-table-wrap">
                <table class="admin-product-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Product</th>
                            <th scope="col">Reservation</th>
                            <th scope="col">Payment</th>
                            <th scope="col">Method</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td data-label="Date"><?= escape_html(date('M j, Y g:i A', strtotime($payment['created_at']))) ?></td>
                                <td data-label="Customer">
                                    <?= escape_html($payment['full_name']) ?>
                                    <span class="reservation-customer-username">@<?= escape_html($payment['username']) ?></span>
                                </td>
                                <td data-label="Product"><?= escape_html($payment['product_name']) ?></td>
                                <td data-label="Reservation">#<?= (int) $payment['reservation_id'] ?></td>
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
                                <td data-label="Reference">
                                    <?php if ($payment['status'] === 'paid'): ?>
                                        <?= $payment['method'] === 'paypal'
                                            ? escape_html((string) (in_array($payment['purpose'], ['deposit_refund', 'reservation_refund'], true) ? ($payment['paypal_refund_id'] ?? 'PayPal refund') : 'PayPal'))
                                            : escape_html($payment['method'] === 'deposit' ? 'Deducted from deposit' : ($payment['purpose'] === 'deposit_refund' ? 'Returned in shop' : 'Received in shop')) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($outstandingFees === [] && $pendingRefunds === []): ?>
            <section class="admin-form-card reservation-empty-state">
                <h2>No payments recorded</h2>
                <p>Rental and return-fee payments will appear here when received.</p>
            </section>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
