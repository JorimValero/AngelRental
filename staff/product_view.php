<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../billing/paypal_helpers.php';
require_authenticated_role('staff');

$qrCode = trim((string) ($_GET['qr'] ?? ''));
if (!preg_match('/\A[a-f0-9]{32}\z/', $qrCode)) {
    http_response_code(404);
    exit('Product not found.');
}

$workflowError = '';
$pickupDateNotReached = false;
$workflowNotice = (string) ($_SESSION['staff_product_notice'] ?? '');
unset($_SESSION['staff_product_notice']);

try {
    $connection = get_database_connection();
    $statement = $connection->prepare(
        "SELECT p.product_id, p.product_name, p.category, p.size, p.description,
                p.rental_price, p.refundable_deposit, p.status, p.qr_code,
                (
                    EXISTS(
                    SELECT 1 FROM reservations active_hold
                    WHERE active_hold.product_id = p.product_id
                      AND (
                          (active_hold.status = 'pending' AND active_hold.expires_at > CURRENT_TIMESTAMP)
                          OR active_hold.status IN ('accepted', 'checked_out', 'paid')
                      )
                    )
                    OR EXISTS(
                        SELECT 1 FROM reservation_cart_holds cart_hold
                        WHERE cart_hold.product_id = p.product_id
                          AND cart_hold.expires_at > CURRENT_TIMESTAMP
                    )
                ) AS is_reserved
         FROM products p
         WHERE p.qr_code = :qr_code"
    );
    $statement->execute(['qr_code' => $qrCode]);
    $product = $statement->fetch();
    if (!$product) {
        http_response_code(404);
        exit('Product not found.');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $action = (string) ($_POST['action'] ?? '');

        if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
            $workflowError = 'Your session expired. Refresh the page and try again.';
        } elseif ($reservationId === false) {
            $workflowError = 'Select a valid reservation.';
        } elseif ($action === 'confirm_handoff') {
            try {
                $connection->beginTransaction();
                $reservationLock = $connection->prepare(
                    'SELECT reservation_session_id, status, payment_method, rental_total, refundable_deposit,
                            (pickup_date <= CURRENT_DATE) AS pickup_due FROM reservations
                     WHERE reservation_id = :reservation_id AND product_id = :product_id
                     FOR UPDATE'
                );
                $reservationLock->execute([
                    'reservation_id' => $reservationId,
                    'product_id' => $product['product_id'],
                ]);
                $lockedReservation = $reservationLock->fetch();
                if (!$lockedReservation || $lockedReservation['status'] !== 'accepted') {
                    throw new RuntimeException('This reservation is no longer awaiting pickup.');
                }
                if (!(bool) $lockedReservation['pickup_due']) {
                    $pickupDateNotReached = true;
                    throw new RuntimeException('This reservation cannot be handed over before its pickup date.');
                }
                if (($_POST['customer_verified'] ?? '') !== '1') {
                    throw new RuntimeException('Confirm that you verified the customer before handing over the product.');
                }
                if ($lockedReservation['payment_method'] === 'online') {
                    $paidStatement = $connection->prepare(
                        "SELECT 1 FROM payment_transactions
                         WHERE (reservation_id = :reservation_id
                                OR reservation_session_id = :reservation_session_id)
                           AND purpose = 'rental' AND status = 'paid'
                         LIMIT 1"
                    );
                    $paidStatement->execute([
                        'reservation_id' => $reservationId,
                        'reservation_session_id' => $lockedReservation['reservation_session_id'],
                    ]);
                    if (!$paidStatement->fetchColumn()) {
                        throw new RuntimeException('Confirm the customer’s PayPal payment before handing over the product.');
                    }
                } else {
                    if (($_POST['cash_received'] ?? '') !== '1') {
                        throw new RuntimeException('Confirm that cash payment was received before handing over the product.');
                    }
                    if ((float) $lockedReservation['rental_total'] + (float) $lockedReservation['refundable_deposit'] > 99999999.99) {
                        throw new RuntimeException('This payment exceeds the supported transaction limit. Contact the administrator.');
                    }
                    $cashPaymentStatement = $connection->prepare(
                        "INSERT INTO payment_transactions
                            (reservation_id, user_id, purpose, method, amount, currency, status, paid_at)
                         SELECT reservation_id, user_id, 'rental', 'cash',
                                rental_total + refundable_deposit, 'PHP', 'paid', CURRENT_TIMESTAMP
                         FROM reservations
                         WHERE reservation_id = :reservation_id
                           AND NOT EXISTS (
                               SELECT 1 FROM payment_transactions
                               WHERE reservation_id = :reservation_id_check
                                 AND purpose = 'rental' AND status = 'paid'
                           )"
                    );
                    $cashPaymentStatement->execute([
                        'reservation_id' => $reservationId,
                        'reservation_id_check' => $reservationId,
                    ]);
                    if ($cashPaymentStatement->rowCount() !== 1) {
                        throw new RuntimeException('A rental payment has already been recorded for this reservation.');
                    }
                }
                $handoffStatement = $connection->prepare(
                    "UPDATE reservations SET status = 'checked_out'
                     WHERE reservation_id = :reservation_id AND status = 'accepted'"
                );
                $handoffStatement->execute(['reservation_id' => $reservationId]);
                if ($handoffStatement->rowCount() !== 1) {
                    throw new RuntimeException('The product handoff could not be confirmed. Refresh and try again.');
                }
                $connection->commit();
                send_admin_transaction_email(
                    $connection,
                    (int) $reservationId,
                    'Rental pickup confirmed',
                    'Staff confirmed the product handoff and rental payment of ₱'
                        . number_format(
                            (float) $lockedReservation['rental_total']
                                + (float) $lockedReservation['refundable_deposit'],
                            2
                        )
                        . '. The rental is now checked out.'
                );
                send_customer_transaction_email(
                    $connection,
                    (int) $reservationId,
                    'Your rental pickup is confirmed',
                    'Staff confirmed the product handoff and rental payment of ₱'
                        . number_format(
                            (float) $lockedReservation['rental_total']
                                + (float) $lockedReservation['refundable_deposit'],
                            2
                        )
                        . '. Your rental is now checked out.'
                );
                $_SESSION['staff_reservation_notice'] = 'Pickup confirmed. The reservation is now checked out.';
                header('Location: reservations.php');
                exit;
            } catch (RuntimeException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $workflowError = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                show_database_error($exception);
                $workflowError = 'The pickup could not be confirmed. Please try again.';
            }
        } elseif ($action === 'confirm_return') {
            $damageNotes = trim((string) ($_POST['damage_notes'] ?? ''));
            $damageCharge = trim((string) ($_POST['damage_charge'] ?? '0'));
            $lateNotes = trim((string) ($_POST['late_notes'] ?? ''));
            $lateCharge = trim((string) ($_POST['late_charge'] ?? '0'));
            $chargePattern = '/\A\d{1,8}(?:\.\d{1,2})?\z/';
            $chargesAreValid = preg_match($chargePattern, $damageCharge) === 1
                && preg_match($chargePattern, $lateCharge) === 1
                && (float) $damageCharge <= 99999999.99
                && (float) $lateCharge <= 99999999.99;

            if (($_POST['inspection_confirmed'] ?? '') !== '1') {
                $workflowError = 'Confirm that you checked the return timing and inspected the product before completing the return.';
            } elseif (
                strlen($damageNotes) > 2000
                || strlen($lateNotes) > 2000
                || !$chargesAreValid
                || (float) $damageCharge + (float) $lateCharge > 99999999.99
                || ((float) $damageCharge > 0 && $damageNotes === '')
            ) {
                $workflowError = 'Enter valid non-negative charges and damage notes when applying a damage charge.';
            } else {
                try {
                    $connection->beginTransaction();
                    $reservationLock = $connection->prepare(
                        'SELECT reservation_session_id, status, return_date, user_id, payment_method, refundable_deposit
                         FROM reservations
                         WHERE reservation_id = :reservation_id AND product_id = :product_id
                         FOR UPDATE'
                    );
                    $reservationLock->execute([
                        'reservation_id' => $reservationId,
                        'product_id' => $product['product_id'],
                    ]);
                    $lockedReservation = $reservationLock->fetch();
                    if (!$lockedReservation || !in_array($lockedReservation['status'], ['checked_out', 'paid'], true)) {
                        throw new RuntimeException('This reservation is not awaiting a return.');
                    }
                    $feePaymentMethod = $lockedReservation['payment_method'] === 'online' ? 'online' : 'cash';
                    $lateDaysStatement = $connection->prepare(
                        'SELECT GREATEST(DATEDIFF(CURRENT_DATE, return_date), 0)
                         FROM reservations WHERE reservation_id = :reservation_id'
                    );
                    $lateDaysStatement->execute(['reservation_id' => $reservationId]);
                    $lateDays = (int) $lateDaysStatement->fetchColumn();
                    if ($lateDays > 0 && $lateNotes === '') {
                        throw new RuntimeException('Add a note explaining the late return.');
                    }
                    $feeTotal = number_format((float) $damageCharge + (float) $lateCharge, 2, '.', '');
                    $deductedFees = min((float) $feeTotal, (float) $lockedReservation['refundable_deposit']);
                    $remainingFees = number_format(max((float) $feeTotal - $deductedFees, 0), 2, '.', '');

                    $rentalPaymentSql = "SELECT payment_id, method, paypal_capture_id
                                         FROM payment_transactions
                                         WHERE (reservation_id = :reservation_id
                                                OR reservation_session_id = :reservation_session_id)
                                           AND purpose = 'rental' AND status = 'paid'
                                         ORDER BY payment_id DESC LIMIT 1 FOR UPDATE";
                    $rentalPaymentStatement = $connection->prepare($rentalPaymentSql);
                    $rentalPaymentStatement->execute([
                        'reservation_id' => $reservationId,
                        'reservation_session_id' => $lockedReservation['reservation_session_id'],
                    ]);
                    $rentalPayment = $rentalPaymentStatement->fetch();
                    if (
                        !$rentalPayment
                        && $lockedReservation['payment_method'] === 'cash'
                        && (float) $lockedReservation['refundable_deposit'] > 0
                    ) {
                        $rentalPayment = ['method' => 'cash', 'paypal_capture_id' => null];
                    }
                    if (!$rentalPayment && $deductedFees > 0) {
                        throw new RuntimeException('A deposit payment is not recorded, so fees cannot be deducted from it.');
                    }
                    $refundAmount = number_format(
                        $rentalPayment
                            ? max((float) $lockedReservation['refundable_deposit'] - $deductedFees, 0)
                            : 0,
                        2,
                        '.',
                        ''
                    );
                    if ((float) $refundAmount > 0 && $rentalPayment['method'] === 'paypal'
                        && empty($rentalPayment['paypal_capture_id'])) {
                        throw new RuntimeException('The original PayPal capture is missing. Contact the administrator before completing this return.');
                    }
                    if ((float) $refundAmount > 0 && $rentalPayment['method'] === 'cash'
                        && ($_POST['cash_deposit_refund_received'] ?? '') !== '1') {
                        throw new RuntimeException('Confirm that the refundable deposit was returned to the customer in cash.');
                    }
                    $returnStatement = $connection->prepare(
                        "UPDATE reservations
                         SET status = 'completed',
                             damage_notes = :damage_notes,
                             damage_charge = :damage_charge,
                             late_days = GREATEST(DATEDIFF(CURRENT_DATE, return_date), 0),
                             late_notes = :late_notes,
                             late_charge = :late_charge,
                             deposit_deduction_authorized = 0
                         WHERE reservation_id = :reservation_id
                           AND status IN ('checked_out', 'paid')"
                    );
                    $returnStatement->execute([
                        'damage_notes' => $damageNotes === '' ? null : $damageNotes,
                        'damage_charge' => $damageCharge,
                        'late_notes' => $lateNotes === '' ? null : $lateNotes,
                        'late_charge' => $lateCharge,
                        'reservation_id' => $reservationId,
                    ]);
                    if ($returnStatement->rowCount() !== 1) {
                        throw new RuntimeException('The return could not be confirmed. Refresh and try again.');
                    }
                    if ($deductedFees > 0) {
                        $depositFeeStatement = $connection->prepare(
                            "INSERT INTO payment_transactions
                                (reservation_id, user_id, purpose, method, amount, currency, status, paid_at)
                             VALUES
                                (:reservation_id, :user_id, 'fees', 'deposit', :amount, 'PHP', 'paid', CURRENT_TIMESTAMP)"
                        );
                        $depositFeeStatement->execute([
                            'reservation_id' => $reservationId,
                            'user_id' => (int) $lockedReservation['user_id'],
                            'amount' => number_format($deductedFees, 2, '.', ''),
                        ]);
                    }
                    $refundPaymentId = null;
                    if ((float) $refundAmount > 0) {
                        $refundStatement = $connection->prepare(
                            "INSERT INTO payment_transactions
                                (reservation_id, user_id, purpose, method, amount, currency,
                                 paypal_capture_id, status, paid_at)
                             VALUES
                                (:reservation_id, :user_id, 'deposit_refund', :method, :amount, 'PHP',
                                 :capture_id, :status, :paid_at)"
                        );
                        $isPaypalRefund = $rentalPayment['method'] === 'paypal';
                        $refundStatement->execute([
                            'reservation_id' => $reservationId,
                            'user_id' => (int) $lockedReservation['user_id'],
                            'method' => $isPaypalRefund ? 'paypal' : 'cash',
                            'amount' => $refundAmount,
                            'capture_id' => $isPaypalRefund ? $rentalPayment['paypal_capture_id'] : null,
                            'status' => $isPaypalRefund ? 'pending' : 'paid',
                            'paid_at' => $isPaypalRefund ? null : date('Y-m-d H:i:s'),
                        ]);
                        if ($isPaypalRefund) {
                            $refundPaymentId = (int) $connection->lastInsertId();
                        }
                    }
                    $connection->commit();
                    $refundNotice = !$rentalPayment && (float) $lockedReservation['refundable_deposit'] > 0
                        ? ' No rental payment was recorded, so no deposit refund was issued.'
                        : '';
                    if ($refundPaymentId !== null) {
                        try {
                            $refundNotice = process_paypal_deposit_refund($connection, $refundPaymentId)
                                ? ' The refundable deposit was refunded through PayPal.'
                                : ' The PayPal deposit refund is processing and will appear in payment history.';
                        } catch (RuntimeException $exception) {
                            error_log('PayPal deposit refund #' . $refundPaymentId . ' remains pending: ' . $exception->getMessage());
                            $refundNotice = ' The PayPal deposit refund is pending and can be retried from Payments.';
                        } catch (PDOException $exception) {
                            show_database_error($exception);
                            $refundNotice = ' The PayPal deposit refund is pending and can be retried from Payments.';
                        }
                    } elseif ((float) $refundAmount > 0) {
                        $refundNotice = ' The refundable deposit was returned in cash.';
                    }
                    $deductionNotice = $deductedFees > 0
                        ? ' ₱' . number_format($deductedFees, 2) . ' in return fees were deducted from the refundable deposit.'
                        : '';
                    $feeNotice = (float) $remainingFees > 0
                        ? ' Remaining return fees are due by ' . ($feePaymentMethod === 'cash' ? 'cash in the shop.' : 'PayPal.')
                        : '';
                    send_admin_transaction_email(
                        $connection,
                        (int) $reservationId,
                        'Rental return confirmed',
                        'Staff completed the return inspection. Damage notes: '
                            . ($damageNotes === '' ? 'None.' : $damageNotes)
                            . ' Damage charges: ₱' . number_format((float) $damageCharge, 2)
                            . '; late-return notes: '
                            . ($lateNotes === '' ? 'None.' : $lateNotes)
                            . ' Late charges: ₱' . number_format((float) $lateCharge, 2) . '.'
                            . $deductionNotice . $feeNotice . $refundNotice
                    );
                    send_customer_transaction_email(
                        $connection,
                        (int) $reservationId,
                        'Your rental return is confirmed',
                        'Staff completed the return inspection. Damage charges: ₱'
                            . number_format((float) $damageCharge, 2)
                            . '; late charges: ₱' . number_format((float) $lateCharge, 2) . '.'
                            . $deductionNotice . $feeNotice . $refundNotice
                    );
                    $_SESSION['staff_reservation_notice'] = 'Return confirmed. Damage and late-return details were recorded.'
                        . $deductionNotice . $feeNotice . $refundNotice;
                    header('Location: reservations.php');
                    exit;
                } catch (RuntimeException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    $workflowError = $exception->getMessage();
                } catch (PDOException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    show_database_error($exception);
                    $workflowError = 'The return could not be confirmed. Please try again.';
                }
            }
        } else {
            $workflowError = 'That product action is not supported.';
        }
    }

    $reservationStatement = $connection->prepare(
        "SELECT r.reservation_id, r.reservation_session_id, r.pickup_date, r.return_date, r.expires_at, r.status,
                r.payment_method, r.rental_total, r.refundable_deposit,
                r.damage_notes, r.damage_charge, r.late_days, r.late_notes, r.late_charge,
                GREATEST(DATEDIFF(CURRENT_DATE, r.return_date), 0) AS current_late_days,
                u.full_name, u.username, u.contact_number, u.email,
                EXISTS(
                    SELECT 1 FROM payment_transactions pt
                    WHERE (pt.reservation_id = r.reservation_id
                           OR pt.reservation_session_id = r.reservation_session_id)
                      AND pt.purpose = 'rental' AND pt.status = 'paid'
                ) AS rental_payment_received,
                (
                    SELECT pt.amount FROM payment_transactions pt
                    WHERE (pt.reservation_id = r.reservation_id
                           OR pt.reservation_session_id = r.reservation_session_id)
                      AND pt.purpose = 'rental' AND pt.status = 'paid'
                    ORDER BY pt.payment_id DESC LIMIT 1
                ) AS rental_payment_amount,
                EXISTS(
                    SELECT 1 FROM payment_transactions pt
                    WHERE pt.reservation_id = r.reservation_id
                      AND pt.purpose = 'fees' AND pt.status = 'paid'
                ) AS fees_payment_received
         FROM reservations r
         INNER JOIN users u ON u.id = r.user_id
         WHERE r.product_id = :product_id
           AND (
               r.status IN ('accepted', 'checked_out', 'paid')
               OR (r.status = 'pending' AND r.expires_at > CURRENT_TIMESTAMP)
           )
         ORDER BY FIELD(r.status, 'checked_out', 'paid', 'accepted', 'pending'),
                  r.created_at DESC
         LIMIT 1"
    );
    $reservationStatement->execute(['product_id' => $product['product_id']]);
    $activeReservations = $reservationStatement->fetchAll();
} catch (PDOException $exception) {
    show_database_error($exception);
    http_response_code(503);
    exit('The product details are temporarily unavailable.');
}

$isReserved = (bool) $product['is_reserved'];
$availability = $isReserved ? 'Reserved' : ucfirst((string) $product['status']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape_html($product['product_name']) ?> | Staff product view</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page staff-product-view-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory staff-product-view">
        <a class="text-link" href="inventory.php">← Back to products</a>
        <?php if ($workflowError !== ''): ?>
            <p class="store-notice store-notice-error staff-workflow-error<?= $pickupDateNotReached ? ' staff-workflow-early-pickup' : '' ?>" role="alert"><?= escape_html($workflowError) ?></p>
        <?php endif; ?>
        <?php if ($workflowNotice !== ''): ?>
            <p class="store-notice" role="status"><?= escape_html($workflowNotice) ?></p>
        <?php endif; ?>
        <article class="admin-form-card">
            <p class="shop-kicker">Staff product view</p>
            <h1><?= escape_html($product['product_name']) ?></h1>
            <dl class="staff-product-details">
                <div><dt>Category</dt><dd><?= escape_html($product['category']) ?></dd></div>
                <div><dt>Size</dt><dd><?= escape_html($product['size']) ?></dd></div>
                <div><dt>Availability</dt><dd><?= escape_html($availability) ?></dd></div>
                <div class="staff-product-tag"><dt>Product tag</dt><dd><code><?= escape_html($product['qr_code']) ?></code></dd></div>
                <div><dt>Rental price per day</dt><dd>₱<?= number_format((float) $product['rental_price'], 2) ?></dd></div>
                <div><dt>Refundable deposit</dt><dd>₱<?= number_format((float) $product['refundable_deposit'], 2) ?></dd></div>
            </dl>
            <section class="staff-product-description" aria-labelledby="staff-product-description">
                <h2 id="staff-product-description">Description</h2>
                <p><?= nl2br(escape_html($product['description'])) ?></p>
            </section>
            <?php if ($activeReservations !== []): ?>
                <section class="staff-active-reservation" aria-labelledby="active-reservation-heading">
                    <h2 id="active-reservation-heading">Reservations for this product</h2>
                    <?php foreach ($activeReservations as $activeReservation): ?>
                        <article class="staff-reservation-workflow">
                            <h3>
                                <?= $activeReservation['status'] === 'accepted' ? 'Accepted reservation awaiting pickup' : (in_array($activeReservation['status'], ['checked_out', 'paid'], true) ? 'Product checked out' : 'Pending customer reservation') ?>
                            </h3>
                            <dl class="staff-reservation-details">
                                <div><dt>Reservation #</dt><dd><?= (int) $activeReservation['reservation_id'] ?></dd></div>
                                <div><dt>Customer</dt><dd><?= escape_html($activeReservation['full_name']) ?> (@<?= escape_html($activeReservation['username']) ?>)</dd></div>
                                <?php if (!empty($activeReservation['contact_number'])): ?>
                                    <div><dt>Contact</dt><dd><?= escape_html($activeReservation['contact_number']) ?></dd></div>
                                <?php endif; ?>
                                <?php if (!empty($activeReservation['email'])): ?>
                                    <div><dt>Email</dt><dd><?= escape_html($activeReservation['email']) ?></dd></div>
                                <?php endif; ?>
                                <div><dt>Rental period</dt><dd><?= escape_html(date('M j, Y', strtotime($activeReservation['pickup_date']))) ?> – <?= escape_html(date('M j, Y', strtotime($activeReservation['return_date']))) ?></dd></div>
                                <?php if ($activeReservation['status'] === 'pending'): ?>
                                    <div><dt>Payment</dt><dd>Hold expires <?= escape_html(date('M j, Y g:i A', strtotime($activeReservation['expires_at']))) ?>. Accept it before confirming pickup.</dd></div>
                                <?php elseif ($activeReservation['status'] === 'accepted'): ?>
                                    <div><dt>Payment</dt><dd>
                                        <?php if ($activeReservation['payment_method'] === 'online'): ?>
                                            <?php if (!empty($activeReservation['rental_payment_received'])): ?>
                                                Paid through PayPal: ₱<?= number_format((float) $activeReservation['rental_payment_amount'], 2) ?>.
                                            <?php else: ?>
                                                PayPal payment is not recorded yet. Do not hand over the product.
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Amount due at pickup: ₱<?= number_format((float) $activeReservation['rental_total'] + (float) $activeReservation['refundable_deposit'], 2) ?> (rental and refundable deposit), payable in cash.
                                        <?php endif; ?>
                                    </dd></div>
                                <?php elseif (in_array($activeReservation['status'], ['checked_out', 'paid'], true)): ?>
                                    <div>
                                        <dt>Return</dt>
                                        <dd>
                                            <?= (int) $activeReservation['current_late_days'] > 0
                                                ? 'Late return: ' . (int) $activeReservation['current_late_days'] . ' ' . ((int) $activeReservation['current_late_days'] === 1 ? 'day' : 'days') . '.'
                                                : 'Due ' . date('M j, Y', strtotime($activeReservation['return_date'])) . '.' ?>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Refundable deposit</dt>
                                        <dd>
                                            ₱<?= number_format((float) $activeReservation['refundable_deposit'], 2) ?>.
                                            <?php if ($activeReservation['payment_method'] === 'online' && empty($activeReservation['rental_payment_received'])): ?>
                                                No rental payment is recorded, so the app cannot issue a PayPal deposit refund.
                                            <?php elseif ($activeReservation['payment_method'] === 'online'): ?>
                                                PayPal deposits are refunded after return.
                                            <?php else: ?>
                                                Cash deposits are returned in the shop.
                                            <?php endif; ?>
                                        </dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                            <?php if ($activeReservation['status'] === 'accepted'): ?>
                                <form class="staff-workflow-form" method="post" action="product_view.php?qr=<?= rawurlencode($qrCode) ?>" novalidate data-workflow-validation="pickup">
                                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="confirm_handoff">
                                    <input type="hidden" name="reservation_id" value="<?= (int) $activeReservation['reservation_id'] ?>">
                                    <?php if ($activeReservation['payment_method'] === 'cash'): ?>
                                        <label class="staff-inspection-confirmation">
                                            <input type="checkbox" name="cash_received" value="1" required>
                                            I received the rental payment and refundable deposit in cash.
                                        </label>
                                    <?php endif; ?>
                                    <label class="staff-inspection-confirmation">
                                        <input type="checkbox" name="customer_verified" value="1" required>
                                        I verified the customer and reservation before handing over the product.
                                    </label>
                                    <button class="shop-button" type="submit" <?= $activeReservation['payment_method'] === 'online' && empty($activeReservation['rental_payment_received']) ? 'disabled' : '' ?>>Confirm pickup</button>
                                </form>
                            <?php elseif (in_array($activeReservation['status'], ['checked_out', 'paid'], true)): ?>
                                <form class="staff-workflow-form staff-return-workflow-form" method="post" action="product_view.php?qr=<?= rawurlencode($qrCode) ?>" novalidate data-workflow-validation="return">
                                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="confirm_return">
                                    <input type="hidden" name="reservation_id" value="<?= (int) $activeReservation['reservation_id'] ?>">
                                    <div class="staff-return-inspection">
                                        <label>
                                            Damage notes
                                            <textarea name="damage_notes" rows="2" maxlength="2000"><?= escape_html((string) ($activeReservation['damage_notes'] ?? '')) ?></textarea>
                                        </label>
                                        <label>
                                            Damage charge (₱)
                                            <input name="damage_charge" type="number" min="0" max="99999999.99" step="0.01" value="<?= number_format((float) $activeReservation['damage_charge'], 2, '.', '') ?>">
                                        </label>
                                        <label>
                                            Late-return notes
                                            <textarea name="late_notes" rows="2" maxlength="2000"><?= escape_html((string) ($activeReservation['late_notes'] ?? '')) ?></textarea>
                                        </label>
                                        <label>
                                            Late-return charge (₱)
                                            <input name="late_charge" type="number" min="0" max="99999999.99" step="0.01" value="<?= number_format((float) $activeReservation['late_charge'], 2, '.', '') ?>">
                                        </label>
                                    </div>
                                    <div class="staff-return-guidance">
                                        <p class="field-hint staff-return-policy-note">You can accept the return before the scheduled return date. Rental payment stays based on the reserved dates and is not prorated.</p>
                                        <p class="field-hint">
                                            Remaining return fees use the original payment method:
                                            <?= $activeReservation['payment_method'] === 'online' ? 'online with PayPal.' : 'cash in the shop.' ?>
                                        </p>
                                    </div>
                                    <?php if ($activeReservation['payment_method'] === 'cash' && (float) $activeReservation['refundable_deposit'] > 0): ?>
                                        <label class="staff-inspection-confirmation">
                                            <input type="checkbox" name="cash_deposit_refund_received" value="1">
                                            I returned the remaining refundable deposit to the customer in cash.
                                        </label>
                                    <?php endif; ?>
                                    <label class="staff-inspection-confirmation">
                                        <input type="checkbox" name="inspection_confirmed" value="1" required>
                                        I confirmed the return timing and inspected the product for damage.
                                    </label>
                                    <button class="shop-button staff-confirm-return-button" type="submit">Confirm return</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <a class="text-link" href="reservations.php">View all reservations</a>
                </section>
            <?php endif; ?>
        </article>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
