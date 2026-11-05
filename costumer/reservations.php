<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../billing/paypal_helpers.php';
require_once __DIR__ . '/../billing/reservation_cancellation.php';
require_authenticated_role('customer');

$reservations = [];
$reservationSessions = [];
$reservationCart = $_SESSION['customer_reservation_cart'] ?? [];
$selectedCartProductIds = null;
if (($_POST['action'] ?? '') === 'confirm_cart') {
    $selectedCartProductIds = [];
    foreach ((array) ($_POST['selected_products'] ?? []) as $selectedProduct) {
        $selectedId = filter_var($selectedProduct, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($selectedId !== false) {
            $selectedCartProductIds[] = $selectedId;
        }
    }
}
$error = '';
$notice = (string) ($_SESSION['reservation_notice'] ?? '');
$postedPaymentMethod = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : 'cash';
$postedPickupDate = is_string($_POST['pickup_date'] ?? null) ? $_POST['pickup_date'] : '';
$postedReturnDate = is_string($_POST['return_date'] ?? null) ? $_POST['return_date'] : '';
$paypalClientId = paypal_client_id();
$paypalReady = paypal_is_configured();
unset($_SESSION['reservation_notice']);

try {
    $connection = get_database_connection();
    expire_stale_reservations($connection);

    if ($reservationCart !== []) {
        $connection->beginTransaction();
        try {
            foreach ($reservationCart as $cartItem) {
                $productId = (int) ($cartItem['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $lockProductStatement = $connection->prepare(
                    'SELECT status FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $lockProductStatement->execute(['product_id' => $productId]);
                if ($lockProductStatement->fetchColumn() !== 'available') {
                    continue;
                }

                $connection->prepare(
                    'DELETE FROM reservation_cart_holds
                     WHERE product_id = :product_id AND expires_at <= CURRENT_TIMESTAMP'
                )->execute(['product_id' => $productId]);

                $activeReservationStatement = $connection->prepare(
                    "SELECT 1 FROM reservations
                     WHERE product_id = :product_id
                       AND (
                           (status = 'pending' AND expires_at > CURRENT_TIMESTAMP)
                           OR status IN ('accepted', 'checked_out', 'paid')
                       )
                     LIMIT 1"
                );
                $activeReservationStatement->execute(['product_id' => $productId]);
                if ($activeReservationStatement->fetchColumn()) {
                    continue;
                }

                $adoptHoldStatement = $connection->prepare(
                    'INSERT IGNORE INTO reservation_cart_holds (product_id, user_id, expires_at)
                     VALUES (:product_id, :user_id, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 3 DAY))'
                );
                $adoptHoldStatement->execute([
                    'product_id' => $productId,
                    'user_id' => (int) $_SESSION['user_id'],
                ]);
            }
            $connection->commit();
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    $activeCartHoldsStatement = $connection->prepare(
        'SELECT product_id
         FROM reservation_cart_holds
         WHERE user_id = :user_id AND expires_at > CURRENT_TIMESTAMP
         ORDER BY created_at, product_id'
    );
    $activeCartHoldsStatement->execute(['user_id' => (int) $_SESSION['user_id']]);
    $activeCartProductIds = array_map('intval', $activeCartHoldsStatement->fetchAll(PDO::FETCH_COLUMN));
    $activeCartProductLookup = array_fill_keys($activeCartProductIds, true);
    $reservationCart = array_values(array_filter(
        $reservationCart,
        static function ($cartItem) use ($activeCartProductLookup): bool {
            return is_array($cartItem)
                && isset($cartItem['product_id'])
                && isset($activeCartProductLookup[(int) $cartItem['product_id']]);
        }
    ));
    $sessionCartProductIds = array_fill_keys(array_map(
        static fn ($cartItem): int => (int) $cartItem['product_id'],
        $reservationCart
    ), true);
    foreach ($activeCartProductIds as $productId) {
        if (!isset($sessionCartProductIds[$productId])) {
            $reservationCart[] = ['product_id' => $productId];
        }
    }
    $_SESSION['customer_reservation_cart'] = $reservationCart;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
            $error = 'Your session expired. Refresh the page and try again.';
        } elseif (isset($_POST['remove_cart_index'])) {
            $cartIndex = filter_var($_POST['remove_cart_index'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if ($cartIndex === false || !isset($reservationCart[$cartIndex])) {
                $error = 'Select a valid item to remove from your reservation list.';
            } else {
                $productId = (int) ($reservationCart[$cartIndex]['product_id'] ?? 0);
                $removeHoldStatement = $connection->prepare(
                    'DELETE FROM reservation_cart_holds
                     WHERE product_id = :product_id AND user_id = :user_id'
                );
                $removeHoldStatement->execute([
                    'product_id' => $productId,
                    'user_id' => (int) $_SESSION['user_id'],
                ]);
                unset($reservationCart[$cartIndex]);
                $_SESSION['customer_reservation_cart'] = array_values($reservationCart);
                $_SESSION['reservation_notice'] = 'Item removed from your reservation list.';
                header('Location: reservations.php');
                exit;
            }
        } elseif ($action === 'confirm_cart') {
            $selectedProducts = is_array($_POST['selected_products'] ?? null) ? $_POST['selected_products'] : [];
            $pickupDate = is_string($_POST['pickup_date'] ?? null) ? trim($_POST['pickup_date']) : '';
            $returnDate = is_string($_POST['return_date'] ?? null) ? trim($_POST['return_date']) : '';
            $paymentMethod = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
            $paymentProvider = is_string($_POST['payment_provider'] ?? null) ? $_POST['payment_provider'] : '';

            if (empty($selectedProducts)) {
                $error = 'Select at least one item before confirming your reservation.';
            } elseif (!in_array($paymentMethod, ['cash', 'online'], true)) {
                $error = 'Choose a valid payment method before confirming your reservation.';
            } elseif ($paymentMethod === 'online' && $paymentProvider !== 'paypal') {
                $error = 'Choose PayPal as your online payment provider.';
            } else {
                $pickup = DateTimeImmutable::createFromFormat('!Y-m-d', $pickupDate);
                $return = DateTimeImmutable::createFromFormat('!Y-m-d', $returnDate);
                if (
                    $pickup === false
                    || $pickup->format('Y-m-d') !== $pickupDate
                    || $return === false
                    || $return->format('Y-m-d') !== $returnDate
                    || $pickup < new DateTimeImmutable('today')
                    || $return <= $pickup
                ) {
                    $error = 'Choose a valid pickup date and a return date after pickup.';
                }

                if ($error === '') {
                    $selectedProductIds = [];
                    foreach ($selectedProducts as $selectedProduct) {
                        $selectedId = filter_var($selectedProduct, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($selectedId !== false) {
                            $selectedProductIds[] = $selectedId;
                        }
                    }

                    if ($selectedProductIds === []) {
                        $error = 'Select valid products to include in your final reservation.';
                    } else {
                        $selectedCartEntries = [];
                        foreach ($reservationCart as $cartIndex => $cartItem) {
                            $productId = (int) ($cartItem['product_id'] ?? 0);
                            if (in_array($productId, $selectedProductIds, true)) {
                                $selectedCartEntries[] = ['index' => $cartIndex, 'item' => $cartItem];
                            }
                        }

                        if ($selectedCartEntries === []) {
                            $error = 'The selected products were not found in your reservation list.';
                        } else {
                            $connection->beginTransaction();
                            try {
                                $sessionStatement = $connection->prepare(
                                    "INSERT INTO reservation_sessions
                                        (user_id, pickup_date, return_date, payment_method, payment_provider, expires_at)
                                     VALUES
                                        (:user_id, :pickup_date, :return_date, :payment_method, :payment_provider,
                                         DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 3 DAY))"
                                );
                                $sessionStatement->execute([
                                    'user_id' => (int) $_SESSION['user_id'],
                                    'pickup_date' => $pickupDate,
                                    'return_date' => $returnDate,
                                    'payment_method' => $paymentMethod,
                                    'payment_provider' => $paymentMethod === 'online' ? $paymentProvider : null,
                                ]);
                                $reservationSessionId = (int) $connection->lastInsertId();
                                $firstReservationId = 0;

                                foreach ($selectedCartEntries as $entry) {
                                    $productId = (int) ($entry['item']['product_id'] ?? 0);
                                    $rentalDays = (int) $pickup->diff($return)->format('%a');
                                    if ($rentalDays > 65535) {
                                        throw new RuntimeException('The selected rental period is too long.');
                                    }

                                    $productStatement = $connection->prepare(
                                        'SELECT status, rental_price, refundable_deposit
                                         FROM products WHERE product_id = :product_id FOR UPDATE'
                                    );
                                    $productStatement->execute(['product_id' => $productId]);
                                    $product = $productStatement->fetch();
                                    if (!$product || $product['status'] !== 'available') {
                                        throw new RuntimeException('One of the selected products is no longer available.');
                                    }

                                    $cartHoldStatement = $connection->prepare(
                                        'SELECT user_id FROM reservation_cart_holds
                                         WHERE product_id = :product_id
                                           AND expires_at > CURRENT_TIMESTAMP
                                         FOR UPDATE'
                                    );
                                    $cartHoldStatement->execute(['product_id' => $productId]);
                                    $cartHoldOwner = $cartHoldStatement->fetchColumn();
                                    if ($cartHoldOwner === false || (int) $cartHoldOwner !== (int) $_SESSION['user_id']) {
                                        throw new RuntimeException('Your hold for one of the selected products expired or was released. Add it again to continue.');
                                    }

                                    $activeHoldStatement = $connection->prepare(
                                        "SELECT 1 FROM reservations
                                         WHERE product_id = :product_id
                                           AND status = 'pending'
                                           AND expires_at > CURRENT_TIMESTAMP
                                         LIMIT 1"
                                    );
                                    $activeHoldStatement->execute(['product_id' => $productId]);
                                    if ($activeHoldStatement->fetchColumn()) {
                                        throw new RuntimeException('One of the selected products is currently held by another reservation.');
                                    }

                                    $overlapStatement = $connection->prepare(
                                        "SELECT 1 FROM reservations
                                         WHERE product_id = :product_id
                                           AND status IN ('accepted', 'checked_out', 'paid')
                                           AND pickup_date < :return_date
                                           AND return_date > :pickup_date
                                         LIMIT 1"
                                    );
                                    $overlapStatement->execute([
                                        'product_id' => $productId,
                                        'return_date' => $returnDate,
                                        'pickup_date' => $pickupDate,
                                    ]);
                                    if ($overlapStatement->fetchColumn()) {
                                        throw new RuntimeException('One or more selected products overlap with an existing reservation.');
                                    }

                                    $rentalTotal = number_format(((float) $product['rental_price'] * $rentalDays), 2, '.', '');
                                    $insertStatement = $connection->prepare(
                                        "INSERT INTO reservations
                                            (reservation_session_id, user_id, product_id, pickup_date, return_date, rental_days,
                                             daily_rate, rental_total, refundable_deposit, status,
                                             payment_method, payment_provider, expires_at)
                                         VALUES
                                            (:reservation_session_id, :user_id, :product_id, :pickup_date, :return_date, :rental_days,
                                             :daily_rate, :rental_total, :refundable_deposit, 'pending',
                                             :payment_method, :payment_provider,
                                             DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 3 DAY))"
                                    );
                                    $insertStatement->execute([
                                        'reservation_session_id' => $reservationSessionId,
                                        'user_id' => (int) $_SESSION['user_id'],
                                        'product_id' => $productId,
                                        'pickup_date' => $pickupDate,
                                        'return_date' => $returnDate,
                                        'rental_days' => $rentalDays,
                                        'daily_rate' => $product['rental_price'],
                                        'rental_total' => $rentalTotal,
                                        'refundable_deposit' => $product['refundable_deposit'],
                                        'payment_method' => $paymentMethod,
                                        'payment_provider' => $paymentMethod === 'online' ? $paymentProvider : null,
                                    ]);
                                    if ($firstReservationId === 0) {
                                        $firstReservationId = (int) $connection->lastInsertId();
                                    }
                                    $releaseHoldStatement = $connection->prepare(
                                        'DELETE FROM reservation_cart_holds
                                         WHERE product_id = :product_id AND user_id = :user_id'
                                    );
                                    $releaseHoldStatement->execute([
                                        'product_id' => $productId,
                                        'user_id' => (int) $_SESSION['user_id'],
                                    ]);
                                }

                                $remainingCart = [];
                                foreach ($reservationCart as $cartIndex => $cartItem) {
                                    $productId = (int) ($cartItem['product_id'] ?? 0);
                                    if (!in_array($productId, $selectedProductIds, true)) {
                                        $remainingCart[] = $cartItem;
                                    }
                                }
                                $_SESSION['customer_reservation_cart'] = $remainingCart;
                                $connection->commit();
                                if ($firstReservationId > 0) {
                                    send_customer_transaction_email(
                                        $connection,
                                        $firstReservationId,
                                        'We received your reservation request',
                                        'Your booking request has been received and is awaiting staff review. You can check its status from your reservations page.'
                                    );
                                    send_admin_transaction_email(
                                        $connection,
                                        $firstReservationId,
                                        'New customer reservation request',
                                        'A customer submitted a new booking request that is awaiting staff review.'
                                    );
                                }
                                if ($paymentMethod === 'online') {
                                    header('Location: ../billing/paypal_checkout.php?session_id=' . $reservationSessionId);
                                    exit;
                                }
                                $_SESSION['reservation_notice'] = 'Your selected items were added to your reservation list. Staff will review the booking before pickup.';
                                header('Location: reservations.php');
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
                                $error = 'Your reservation could not be saved. Please try again.';
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'cancel' && $reservationId === false) {
            $error = 'Select a valid reservation to cancel.';
        } elseif ($action === 'cancel') {
            try {
                $cancellation = cancel_reservation_booking(
                    $connection,
                    (int) $reservationId,
                    (int) $_SESSION['user_id']
                );
                $refundMessage = $cancellation['refund_payment_id'] === null
                    ? ''
                    : ($cancellation['refund_completed']
                        ? ' The PayPal refund was issued.'
                        : ' Your PayPal refund is pending staff review and processing.');
                $_SESSION['reservation_notice'] = ($cancellation['session_id'] === null
                    ? 'Your reservation was cancelled.'
                    : 'Your booking session was cancelled.') . $refundMessage;
                header('Location: reservations.php');
                exit;
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (PDOException $exception) {
                show_database_error($exception);
                $error = 'Your booking could not be cancelled. Please try again.';
            }
        } else {
            $error = 'That reservation action is not supported.';
        }
    }

    $statement = $connection->prepare(
        "SELECT r.reservation_id, r.reservation_session_id, r.product_id, r.pickup_date, r.return_date, r.rental_days,
                r.daily_rate, r.rental_total, r.refundable_deposit, r.status, r.payment_method,
                r.payment_provider,
                r.expires_at, r.created_at, r.damage_notes, r.damage_charge, r.late_days,
                r.late_notes, r.late_charge, r.deposit_deduction_authorized, p.product_name,
                EXISTS(
                    SELECT 1 FROM payment_transactions pt
                    WHERE (pt.reservation_id = r.reservation_id
                           OR pt.reservation_session_id = r.reservation_session_id)
                      AND pt.purpose = 'rental' AND pt.status = 'paid'
                ) AS rental_payment_received,
                (
                    SELECT pt.paypal_capture_id FROM payment_transactions pt
                    WHERE (pt.reservation_id = r.reservation_id
                           OR pt.reservation_session_id = r.reservation_session_id)
                      AND pt.purpose = 'rental' AND pt.status = 'paid'
                    ORDER BY pt.payment_id DESC LIMIT 1
                ) AS rental_capture_id,
                (
                    SELECT pt.amount FROM payment_transactions pt
                    WHERE (pt.reservation_id = r.reservation_id
                           OR pt.reservation_session_id = r.reservation_session_id)
                      AND pt.purpose = 'rental' AND pt.status = 'paid'
                    ORDER BY pt.payment_id DESC LIMIT 1
                ) AS rental_payment_amount,
                COALESCE((
                    SELECT SUM(pt.amount) FROM payment_transactions pt
                    WHERE pt.reservation_id = r.reservation_id
                      AND pt.purpose = 'fees' AND pt.status = 'paid'
                ), 0) AS fees_paid_amount,
                COALESCE((
                    SELECT SUM(pt.amount) FROM payment_transactions pt
                    WHERE pt.reservation_id = r.reservation_id
                      AND pt.purpose = 'deposit_refund' AND pt.status = 'paid'
                ), 0) AS deposit_refunded_amount,
                COALESCE((
                    SELECT SUM(pt.amount) FROM payment_transactions pt
                    WHERE pt.reservation_id = r.reservation_id
                      AND pt.purpose = 'fees' AND pt.method = 'deposit' AND pt.status = 'paid'
                ), 0) AS deposit_deduction_amount,
                EXISTS(
                    SELECT 1 FROM payment_transactions pt
                    WHERE pt.reservation_id = r.reservation_id
                      AND pt.purpose = 'deposit_refund' AND pt.status = 'pending'
                ) AS deposit_refund_pending
         FROM reservations r
         INNER JOIN products p ON p.product_id = r.product_id
         WHERE r.user_id = :user_id
         ORDER BY r.created_at DESC, r.reservation_id DESC"
    );
    $statement->execute(['user_id' => (int) $_SESSION['user_id']]);
    $reservations = $statement->fetchAll();
    $reservationSessions = [];
    foreach ($reservations as $reservation) {
        $sessionId = $reservation['reservation_session_id'] === null
            ? null
            : (int) $reservation['reservation_session_id'];
        $groupKey = $sessionId === null
            ? 'reservation-' . (int) $reservation['reservation_id']
            : 'session-' . $sessionId;
        if (!isset($reservationSessions[$groupKey])) {
            $reservationSessions[$groupKey] = [
                'session_id' => $sessionId,
                'reservations' => [],
                'combined_total' => 0.0,
                'pending_reservation_id' => null,
                'pending_count' => 0,
                'cancellable_reservation_id' => null,
                'cancellable_count' => 0,
                'needs_paypal_checkout' => false,
            ];
        }
        $reservationSessions[$groupKey]['reservations'][] = $reservation;
        $reservationSessions[$groupKey]['combined_total'] +=
            (float) $reservation['rental_total'] + (float) $reservation['refundable_deposit'];
        if ($reservation['status'] === 'pending') {
            $reservationSessions[$groupKey]['pending_count']++;
            $reservationSessions[$groupKey]['pending_reservation_id'] ??= (int) $reservation['reservation_id'];
        }
        if (in_array($reservation['status'], ['pending', 'accepted'], true)) {
            $reservationSessions[$groupKey]['cancellable_count']++;
            $reservationSessions[$groupKey]['cancellable_reservation_id'] ??= (int) $reservation['reservation_id'];
        }
        if (
            $sessionId !== null
            && $reservation['payment_method'] === 'online'
            && empty($reservation['rental_payment_received'])
            && in_array($reservation['status'], ['pending', 'accepted'], true)
        ) {
            $reservationSessions[$groupKey]['needs_paypal_checkout'] = true;
        }
    }

    $reservationCartProductIds = [];
    foreach ($reservationCart as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        if ($productId > 0) {
            $reservationCartProductIds[] = $productId;
        }
    }
    $reservationCartProductIds = array_values(array_unique($reservationCartProductIds));
    $reservationCartLookup = [];
    if ($reservationCartProductIds !== []) {
        $placeholders = implode(',', array_fill(0, count($reservationCartProductIds), '?'));
        $reservationCartStatement = $connection->prepare(
            'SELECT p.product_id, p.product_name, p.size, p.rental_price, p.refundable_deposit,
                    cart_hold.expires_at AS hold_expires_at,
                    cart_hold.user_id AS hold_user_id,
                    (
                        SELECT pi.image_path
                        FROM product_images pi
                        WHERE pi.product_id = p.product_id
                        ORDER BY pi.is_thumbnail DESC, pi.sort_order, pi.image_id
                        LIMIT 1
                    ) AS image_path
             FROM products p
             LEFT JOIN reservation_cart_holds cart_hold
               ON cart_hold.product_id = p.product_id
              AND cart_hold.expires_at > CURRENT_TIMESTAMP
             WHERE p.product_id IN (' . $placeholders . ')'
        );
        $reservationCartStatement->execute($reservationCartProductIds);
        foreach ($reservationCartStatement->fetchAll() as $cartProduct) {
            $reservationCartLookup[(int) $cartProduct['product_id']] = $cartProduct;
        }
    }
} catch (PDOException $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    show_database_error($exception);
    $error = 'Your reservations could not be loaded. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My reservations | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page reservation-history-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory reservations-page">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Customer account</p>
                <h1>My reservations</h1>
            </div>
            <div>
                <a class="text-link" href="payments.php">Payment history</a>
                <a class="text-link" href="../index.php#collections">Browse collections</a>
            </div>
        </div>

        <?php if ($notice !== ''): ?>
            <p class="store-notice" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>
        <?php if ($reservationCart !== []): ?>
            <section class="admin-form-card reservation-cart-panel">
                <div class="inventory-list-heading">
                    <h2>Finalize your reservation</h2>
                </div>
                <form id="reservation-confirm-form" method="post" action="reservations.php" novalidate data-workflow-validation="reservation">
                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                    <input type="hidden" name="action" value="confirm_cart">
                    <div class="reservation-cart-dates">
                        <p class="field-hint">Choose one pickup and return date for all selected items.</p>
                        <div class="reservation-date-fields">
                            <div class="field">
                                <label for="reservation-pickup-date">Pickup date</label>
                                <input id="reservation-pickup-date" name="pickup_date" type="date" min="<?= escape_html(date('Y-m-d')) ?>" value="<?= escape_html($postedPickupDate) ?>" required>
                            </div>
                            <div class="field">
                                <label for="reservation-return-date">Return date</label>
                                <input id="reservation-return-date" name="return_date" type="date" min="<?= escape_html(date('Y-m-d', strtotime('+1 day'))) ?>" value="<?= escape_html($postedReturnDate) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="reservation-cart-items">
                        <?php foreach ($reservationCart as $index => $item): ?>
                            <?php $cartProductId = (int) ($item['product_id'] ?? 0); ?>
                            <?php if ($cartProductId <= 0) { continue; } ?>
                            <?php $cartProduct = $reservationCartLookup[$cartProductId] ?? null; ?>
                            <?php $heldByAnotherCustomer = $cartProduct && !empty($cartProduct['hold_user_id']) && (int) $cartProduct['hold_user_id'] !== (int) $_SESSION['user_id']; ?>
                            <div class="reservation-cart-item">
                                <input id="reservation-product-<?= $cartProductId ?>" class="reservation-cart-checkbox" type="checkbox" name="selected_products[]" value="<?= $cartProductId ?>" <?= $heldByAnotherCustomer ? 'disabled' : '' ?> <?= $selectedCartProductIds === null || in_array($cartProductId, $selectedCartProductIds, true) ? 'checked' : '' ?>>
                                <a class="reservation-cart-product-link" href="../product.php?id=<?= $cartProductId ?>">
                                    <?php if (!empty($cartProduct['image_path'])): ?>
                                        <img class="reservation-cart-image" src="../<?= escape_html((string) $cartProduct['image_path']) ?>" alt="<?= escape_html((string) ($cartProduct['product_name'] ?? 'Reserved product')) ?>">
                                    <?php else: ?>
                                        <span class="reservation-cart-image reservation-cart-image-placeholder" aria-hidden="true">No image</span>
                                    <?php endif; ?>
                                </a>
                                <label class="reservation-cart-product-copy" for="reservation-product-<?= $cartProductId ?>">
                                    <strong><?= escape_html((string) ($cartProduct['product_name'] ?? ('Product #' . $cartProductId))) ?></strong>
                                    <?php if ($cartProduct): ?>
                                        <span>Size <?= escape_html((string) $cartProduct['size']) ?></span>
                                        <span>₱<?= number_format((float) $cartProduct['rental_price'], 2) ?> / day</span>
                                        <span>Refundable deposit: ₱<?= number_format((float) $cartProduct['refundable_deposit'], 2) ?></span>
                                        <?php if (!empty($cartProduct['hold_expires_at'])): ?>
                                            <span>Held until <?= escape_html(date('M j, Y g:i A', strtotime($cartProduct['hold_expires_at']))) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($heldByAnotherCustomer): ?>
                                        <span>Held by another customer. Remove this item to continue.</span>
                                    <?php endif; ?>
                                </label>
                                <button class="reservation-cart-remove" type="submit" name="remove_cart_index" value="<?= $index ?>" formnovalidate>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="reservation-payment-choice">
                        <span>Payment method</span>
                        <label><input type="radio" name="payment_method" value="cash" <?= $postedPaymentMethod === 'cash' ? 'checked' : '' ?>> Cash at pickup</label>
                        <label><input type="radio" name="payment_method" value="online" <?= $postedPaymentMethod === 'online' ? 'checked' : '' ?>> Online payment</label>
                    </div>
                    <div class="reservation-provider-choice" <?= $postedPaymentMethod === 'online' ? '' : 'hidden' ?>>
                        <label for="payment-provider">Online payment provider</label>
                        <select id="payment-provider" name="payment_provider" <?= $postedPaymentMethod === 'online' ? 'required' : '' ?>>
                            <option value="paypal" selected>PayPal</option>
                        </select>
                        <p class="field-hint">PayPal checkout opens after you confirm; payment automatically accepts the booking session.</p>
                    </div>
                    <button id="confirm-reservation-button" class="shop-button" type="submit">Confirm selected reservation(s)</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($reservations === [] && $reservationCart === []): ?>
            <section class="admin-form-card reservation-empty-state">
                <h2>No reservations yet</h2>
                <p>When you reserve a piece, its dates and rental details will appear here.</p>
                <a class="shop-button" href="../index.php#collections">Browse collections</a>
            </section>
        <?php elseif ($reservations !== []): ?>
            <div class="reservation-list">
                <?php foreach ($reservationSessions as $reservationSession): ?>
                    <section class="reservation-session">
                        <div class="reservation-session-heading">
                            <div>
                                <p class="shop-kicker"><?= $reservationSession['session_id'] === null ? 'Reservation' : 'Booking session #' . $reservationSession['session_id'] ?></p>
                                <h2><?= count($reservationSession['reservations']) ?> <?= count($reservationSession['reservations']) === 1 ? 'item' : 'items' ?></h2>
                            </div>
                            <p>Combined total due (rental + refundable deposits): ₱<?= number_format($reservationSession['combined_total'], 2) ?></p>
                        </div>
                        <div class="reservation-session-items">
                <?php foreach ($reservationSession['reservations'] as $reservation): ?>
                    <article class="reservation-card" id="reservation-<?= (int) $reservation['reservation_id'] ?>">
                        <div class="reservation-card-heading">
                            <div>
                                <p class="shop-kicker">Reservation #<?= (int) $reservation['reservation_id'] ?></p>
                                <h2><a href="../product.php?id=<?= (int) $reservation['product_id'] ?>"><?= escape_html($reservation['product_name']) ?></a></h2>
                            </div>
                            <span class="reservation-status reservation-status-<?= escape_html($reservation['status']) ?>"><?= escape_html(ucfirst($reservation['status'])) ?></span>
                        </div>
                        <dl class="reservation-details">
                            <div><dt>Pickup</dt><dd><?= escape_html(date('M j, Y', strtotime($reservation['pickup_date']))) ?></dd></div>
                            <div><dt>Return</dt><dd><?= escape_html(date('M j, Y', strtotime($reservation['return_date']))) ?></dd></div>
                            <div><dt>Rental period</dt><dd><?= (int) $reservation['rental_days'] ?> <?= (int) $reservation['rental_days'] === 1 ? 'day' : 'days' ?></dd></div>
                            <div><dt>Rental total</dt><dd>₱<?= number_format((float) $reservation['rental_total'], 2) ?></dd></div>
                            <div><dt>Refundable deposit</dt><dd>₱<?= number_format((float) $reservation['refundable_deposit'], 2) ?></dd></div>
                        </dl>
                        <?php if ($reservation['status'] === 'pending'): ?>
                            <div class="reservation-payment-panel">
                                <p>
                                    This request is held until <strong><?= escape_html(date('M j, Y g:i A', strtotime($reservation['expires_at']))) ?></strong>.
                                    Payment method:
                                    <strong><?= ($reservation['payment_method'] ?? 'cash') === 'online'
                                        ? 'Online payment — ' . escape_html(ucfirst((string) ($reservation['payment_provider'] ?? 'PayPal')))
                                        : 'Cash at pickup' ?></strong>.
                                </p>
                            </div>
                        <?php elseif ($reservation['status'] === 'expired'): ?>
                            <p class="field-hint">This unpaid request expired and these dates are no longer held.</p>
                        <?php elseif ($reservation['status'] === 'accepted'): ?>
                            <p class="field-hint">Your reservation was accepted. Staff will scan the product tag and verify your details before handoff.</p>
                            <?php if ($reservation['payment_method'] === 'online'): ?>
                                <?php if (!empty($reservation['rental_payment_received'])): ?>
                                    <p class="field-hint">PayPal payment received for the booking: ₱<?= number_format((float) $reservation['rental_payment_amount'], 2) ?>. Receipt <?= escape_html((string) ($reservation['rental_capture_id'] ?? 'recorded')) ?>. Bring your reservation to the shop for pickup.</p>
                                <?php elseif ($reservation['reservation_session_id'] === null && $paypalReady): ?>
                                    <p>Rental and refundable deposit due: ₱<?= number_format((float) $reservation['rental_total'] + (float) $reservation['refundable_deposit'], 2) ?>.</p>
                                    <div class="paypal-checkout" data-paypal-checkout data-reservation-id="<?= (int) $reservation['reservation_id'] ?>" data-payment-purpose="rental" data-csrf-token="<?= escape_html(csrf_token()) ?>">
                                        <div data-paypal-buttons></div>
                                        <p data-payment-message class="field-hint" role="status"></p>
                                    </div>
                                <?php elseif ($reservation['reservation_session_id'] === null): ?>
                                    <p class="store-notice store-notice-error" role="alert">PayPal sandbox is not configured. Please contact the shop to arrange payment.</p>
                                <?php else: ?>
                                    <p class="field-hint">Complete the session PayPal checkout using the button below the booking items.</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="field-hint">Cash payment and refundable deposit are due at pickup.</p>
                            <?php endif; ?>
                        <?php elseif (in_array($reservation['status'], ['checked_out', 'paid'], true)): ?>
                            <p class="field-hint">This rental is checked out. Please return the product by <?= escape_html(date('M j, Y', strtotime($reservation['return_date']))) ?>.</p>
                        <?php elseif ($reservation['status'] === 'completed'): ?>
                            <?php $returnFees = (float) $reservation['late_charge'] + (float) $reservation['damage_charge']; ?>
                            <?php $feesDue = max($returnFees - (float) $reservation['fees_paid_amount'], 0); ?>
                            <p class="field-hint">Return inspection complete. Late <?= (int) $reservation['late_days'] ?> <?= (int) $reservation['late_days'] === 1 ? 'day' : 'days' ?> · late fee ₱<?= number_format((float) $reservation['late_charge'], 2) ?> · damage charge ₱<?= number_format((float) $reservation['damage_charge'], 2) ?>.</p>
                            <?php if ((float) $reservation['deposit_refunded_amount'] > 0): ?>
                                <p class="field-hint">Refundable deposit returned: ₱<?= number_format((float) $reservation['deposit_refunded_amount'], 2) ?>.</p>
                            <?php elseif (!empty($reservation['deposit_refund_pending'])): ?>
                                <p class="field-hint">Your refundable deposit refund is processing.</p>
                            <?php endif; ?>
                            <?php if ((float) $reservation['deposit_deduction_amount'] > 0): ?>
                                <p class="field-hint">₱<?= number_format((float) $reservation['deposit_deduction_amount'], 2) ?> in return fees were deducted from your refundable deposit.</p>
                            <?php endif; ?>
                            <?php if ($returnFees > 0 && $feesDue <= 0): ?>
                                <p class="field-hint">Return fees paid. Thank you.</p>
                            <?php elseif ($feesDue > 0 && $paypalReady): ?>
                                <p>Outstanding return fees: ₱<?= number_format($feesDue, 2) ?>.</p>
                                <div class="paypal-checkout" data-paypal-checkout data-reservation-id="<?= (int) $reservation['reservation_id'] ?>" data-payment-purpose="fees" data-csrf-token="<?= escape_html(csrf_token()) ?>">
                                    <div data-paypal-buttons></div>
                                    <p data-payment-message class="field-hint" role="status"></p>
                                </div>
                            <?php elseif ($feesDue > 0): ?>
                                <p class="store-notice store-notice-error" role="alert">PayPal sandbox is not configured. Return fees can be paid in the shop.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                        </div>
                        <?php if ($reservationSession['needs_paypal_checkout'] || (
                            $reservationSession['cancellable_reservation_id'] !== null
                            && $reservationSession['cancellable_count'] === count($reservationSession['reservations'])
                        )): ?>
                            <?php
                                $cancelLabel = $reservationSession['session_id'] === null
                                    ? 'Cancel reservation'
                                    : 'Cancel booking session';
                            ?>
                            <div class="reservation-session-actions">
                                <?php if ($reservationSession['needs_paypal_checkout']): ?>
                                    <a class="shop-button" href="../billing/paypal_checkout.php?session_id=<?= (int) $reservationSession['session_id'] ?>" target="_blank" rel="noopener">Continue PayPal checkout</a>
                                <?php endif; ?>
                                <?php if (
                                    $reservationSession['cancellable_reservation_id'] !== null
                                    && $reservationSession['cancellable_count'] === count($reservationSession['reservations'])
                                ): ?>
                                    <form method="post" action="reservations.php" data-confirm-message="<?= escape_html('Cancel this ' . ($reservationSession['session_id'] === null ? 'reservation' : 'booking session') . '? Any PayPal payment will be refunded.') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $reservationSession['cancellable_reservation_id'] ?>">
                                        <button class="reservation-cancel-button" type="submit"><?= escape_html($cancelLabel) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
    <script>
        const paymentMethodInputs = document.querySelectorAll('input[name="payment_method"]');
        const providerChoice = document.querySelector('.reservation-provider-choice');
        const providerSelect = document.getElementById('payment-provider');
        const syncPaymentProvider = () => {
            if (providerChoice === null || providerSelect === null) {
                return;
            }
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            const onlineSelected = selectedMethod !== null && selectedMethod.value === 'online';
            providerChoice.hidden = !onlineSelected;
            providerSelect.required = onlineSelected;
        };
        paymentMethodInputs.forEach((input) => input.addEventListener('change', syncPaymentProvider));
        syncPaymentProvider();

        const reservationForm = document.getElementById('reservation-confirm-form');
        reservationForm?.addEventListener('submit', (event) => {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            const isConfirmingOnline = event.submitter?.id === 'confirm-reservation-button'
                && selectedMethod?.value === 'online';
            if (isConfirmingOnline) {
                reservationForm.target = '_blank';
            } else {
                reservationForm.removeAttribute('target');
            }
        });
    </script>
    <?php if ($paypalReady): ?>
        <script src="https://www.paypal.com/sdk/js?client-id=<?= rawurlencode($paypalClientId) ?>&currency=PHP&intent=capture" defer></script>
        <script src="../javascript/paypal-checkout.js" defer></script>
    <?php endif; ?>
</body>
</html>