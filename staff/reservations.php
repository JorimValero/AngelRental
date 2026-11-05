<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../billing/reservation_cancellation.php';
require_authenticated_role('staff');

$reservations = [];
$error = '';
$search = substr(trim((string) ($_GET['q'] ?? '')), 0, 150);
$notice = (string) ($_SESSION['staff_reservation_notice'] ?? '');
unset($_SESSION['staff_reservation_notice']);

try {
    $connection = get_database_connection();
    expire_stale_reservations($connection);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
            $error = 'Your session expired. Refresh the page and try again.';
        } elseif ($reservationId === false) {
            $error = 'Select a valid reservation to update.';
        } elseif ($action === 'cancel') {
            try {
                $cancellation = cancel_reservation_booking($connection, (int) $reservationId);
                $refundMessage = $cancellation['refund_payment_id'] === null
                    ? ''
                    : ($cancellation['refund_completed']
                        ? ' PayPal refund issued.'
                        : ' PayPal refund pending staff processing; process it from Payments.');
                $_SESSION['staff_reservation_notice'] = $cancellation['session_id'] === null
                    ? 'Reservation cancelled.' . $refundMessage
                    : 'Booking session cancelled.' . $refundMessage;
                header('Location: reservations.php');
                exit;
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (PDOException $exception) {
                show_database_error($exception);
                $error = 'The booking could not be cancelled. Please try again.';
            }
        } elseif ($action === 'accept') {
            try {
                $connection->beginTransaction();
                $sessionLookupStatement = $connection->prepare(
                    'SELECT reservation_session_id
                     FROM reservations WHERE reservation_id = :reservation_id'
                );
                $sessionLookupStatement->execute(['reservation_id' => $reservationId]);
                $reservationSessionId = $sessionLookupStatement->fetchColumn();
                if ($reservationSessionId === false) {
                    throw new RuntimeException('That reservation is no longer pending or has expired.');
                }

                if ($reservationSessionId === null) {
                    $reservationStatement = $connection->prepare(
                        'SELECT reservation_session_id, product_id, pickup_date, return_date, status,
                                (expires_at > CURRENT_TIMESTAMP) AS hold_active
                         FROM reservations WHERE reservation_id = :reservation_id FOR UPDATE'
                    );
                    $reservationStatement->execute(['reservation_id' => $reservationId]);
                    $reservation = $reservationStatement->fetch();
                    $sessionReservations = [$reservation];
                } else {
                    $sessionLockStatement = $connection->prepare(
                        'SELECT reservation_session_id FROM reservation_sessions
                         WHERE reservation_session_id = :reservation_session_id FOR UPDATE'
                    );
                    $sessionLockStatement->execute(['reservation_session_id' => (int) $reservationSessionId]);
                    if ($sessionLockStatement->fetchColumn() === false) {
                        throw new RuntimeException('This booking session could not be found. Refresh and try again.');
                    }
                    $sessionReservationsStatement = $connection->prepare(
                        'SELECT reservation_id, product_id, pickup_date, return_date, status,
                                (expires_at > CURRENT_TIMESTAMP) AS hold_active
                         FROM reservations
                         WHERE reservation_session_id = :reservation_session_id
                         ORDER BY reservation_id
                         FOR UPDATE'
                    );
                    $sessionReservationsStatement->execute([
                        'reservation_session_id' => (int) $reservationSessionId,
                    ]);
                    $sessionReservations = $sessionReservationsStatement->fetchAll();
                    $reservation = $sessionReservations[0] ?? null;
                }

                if (!$reservation || $reservation['status'] !== 'pending' || !(bool) $reservation['hold_active']) {
                    throw new RuntimeException('That reservation is no longer pending or has expired.');
                }

                foreach ($sessionReservations as $sessionReservation) {
                    if ($sessionReservation['status'] !== 'pending' || !(bool) $sessionReservation['hold_active']) {
                        throw new RuntimeException('Every item in this booking session must still be pending and active.');
                    }

                    $productStatement = $connection->prepare(
                        'SELECT status FROM products WHERE product_id = :product_id FOR UPDATE'
                    );
                    $productStatement->execute(['product_id' => $sessionReservation['product_id']]);
                    if ($productStatement->fetchColumn() !== 'available') {
                        throw new RuntimeException('A product in this booking session is not currently available to accept.');
                    }

                    $overlapSql = "SELECT 1 FROM reservations
                                   WHERE product_id = :product_id
                                     AND (
                                         (
                                             status IN ('accepted', 'checked_out', 'paid')
                                             AND pickup_date < :return_date
                                             AND return_date > :pickup_date
                                         )
                                         OR (status = 'pending' AND expires_at > CURRENT_TIMESTAMP)
                                     )";
                    $overlapParameters = [
                        'product_id' => $sessionReservation['product_id'],
                        'return_date' => $sessionReservation['return_date'],
                        'pickup_date' => $sessionReservation['pickup_date'],
                    ];
                    if ($reservationSessionId === null) {
                        $overlapSql .= ' AND reservation_id <> :reservation_id';
                        $overlapParameters['reservation_id'] = $reservationId;
                    } else {
                        $overlapSql .= ' AND (reservation_session_id IS NULL OR reservation_session_id <> :own_session_id)';
                        $overlapParameters['own_session_id'] = (int) $reservationSessionId;
                    }
                    $overlapSql .= ' LIMIT 1';
                    $overlapStatement = $connection->prepare($overlapSql);
                    $overlapStatement->execute($overlapParameters);
                    if ($overlapStatement->fetchColumn()) {
                        throw new RuntimeException('A product in this booking session overlaps another accepted or active rental.');
                    }

                    $cartHoldStatement = $connection->prepare(
                        'SELECT 1 FROM reservation_cart_holds
                         WHERE product_id = :product_id AND expires_at > CURRENT_TIMESTAMP
                         LIMIT 1'
                    );
                    $cartHoldStatement->execute(['product_id' => $sessionReservation['product_id']]);
                    if ($cartHoldStatement->fetchColumn()) {
                        throw new RuntimeException('A product in this booking session is currently in a customer’s unconfirmed reservation list.');
                    }
                }

                $acceptSql = "UPDATE reservations SET status = 'accepted'
                              WHERE status = 'pending' AND expires_at > CURRENT_TIMESTAMP";
                $acceptParameters = [];
                if ($reservationSessionId === null) {
                    $acceptSql .= ' AND reservation_id = :reservation_id';
                    $acceptParameters['reservation_id'] = $reservationId;
                } else {
                    $acceptSql .= ' AND reservation_session_id = :reservation_session_id';
                    $acceptParameters['reservation_session_id'] = (int) $reservationSessionId;
                }
                $acceptStatement = $connection->prepare($acceptSql);
                $acceptStatement->execute($acceptParameters);
                if ($acceptStatement->rowCount() !== count($sessionReservations)) {
                    throw new RuntimeException('That reservation could not be accepted. Refresh and try again.');
                }
                $connection->commit();
                send_customer_transaction_email(
                    $connection,
                    (int) $reservationId,
                    'Your reservation was accepted',
                    'Staff accepted your booking. Please follow the pickup and payment instructions shown in your reservation details.'
                );
                $_SESSION['staff_reservation_notice'] = count($sessionReservations) > 1
                    ? 'Booking session accepted. Scan each product tag at pickup to confirm handoff.'
                    : 'Reservation accepted. Scan the product tag at pickup to confirm handoff.';
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
                $error = 'The reservation could not be accepted. Please try again.';
            }
        } else {
            $error = 'That reservation action is not supported.';
        }
    }

    $sql = 'SELECT r.reservation_id, r.reservation_session_id, r.product_id, r.pickup_date, r.return_date,
                   r.rental_days, r.rental_total, r.refundable_deposit, r.status,
                   r.expires_at, r.created_at, r.damage_charge,
                   r.late_days, r.late_notes, r.late_charge, p.product_name,
                   u.full_name, u.username, u.contact_number
            FROM reservations r
            INNER JOIN products p ON p.product_id = r.product_id
            INNER JOIN users u ON u.id = r.user_id';
    if ($search !== '') {
        $sql .= ' WHERE p.product_name LIKE :product_search
                  OR u.full_name LIKE :name_search
                  OR u.username LIKE :username_search
                  OR r.status LIKE :status_search';
    }
    $sql .= ' ORDER BY r.created_at DESC, r.reservation_id DESC';
    $statement = $connection->prepare($sql);
    if ($search !== '') {
        $term = '%' . $search . '%';
        $statement->execute([
            'product_search' => $term,
            'name_search' => $term,
            'username_search' => $term,
            'status_search' => $term,
        ]);
    } else {
        $statement->execute();
    }
    $reservations = $statement->fetchAll();
    $reservationGroups = [];
    foreach ($reservations as $reservation) {
        $sessionId = $reservation['reservation_session_id'] === null
            ? null
            : (int) $reservation['reservation_session_id'];
        $groupKey = $sessionId === null
            ? 'reservation-' . (int) $reservation['reservation_id']
            : 'session-' . $sessionId;
        if (!isset($reservationGroups[$groupKey])) {
            $reservationGroups[$groupKey] = [
                'session_id' => $sessionId,
                'reservations' => [],
            ];
        }
        $reservationGroups[$groupKey]['reservations'][] = $reservation;
    }
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'Reservations could not be loaded. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservations | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page staff-reservations-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Customer bookings</p>
                <h1>Reservations</h1>
            </div>
            <p><?= count($reservations) ?> <?= count($reservations) === 1 ? 'reservation' : 'reservations' ?></p>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <p class="store-notice" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <section class="admin-product-list" aria-labelledby="reservation-list-heading">
            <div class="inventory-list-heading">
                <h2 id="reservation-list-heading">Reservation requests</h2>
                <form class="shop-search inventory-search" method="get" action="reservations.php" role="search">
                    <label class="visually-hidden" for="reservation-search">Search reservations</label>
                    <input id="reservation-search" name="q" type="search" placeholder="Search product or customer" value="<?= escape_html($search) ?>">
                    <button type="submit">Search</button>
                    <?php if ($search !== ''): ?>
                        <a class="inventory-search-clear" href="reservations.php">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <?php if ($reservations !== []): ?>
                <div class="admin-table-wrap">
                    <table class="admin-product-table reservation-table">
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Rental dates</th>
                                <th scope="col">Total</th>
                                <th scope="col">Deposit</th>
                                <th scope="col">QR workflow</th>
                                <th scope="col">Return record</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservationGroups as $reservationGroup): ?>
                                <?php if ($reservationGroup['session_id'] !== null): ?>
                                    <?php
                                        $sessionCanBeAccepted = count(array_filter(
                                            $reservationGroup['reservations'],
                                            static fn (array $reservation): bool => $reservation['status'] === 'pending'
                                        )) === count($reservationGroup['reservations']);
                                        $sessionHasPending = count(array_filter(
                                            $reservationGroup['reservations'],
                                            static fn (array $reservation): bool => $reservation['status'] === 'pending'
                                        )) > 0;
                                        $sessionCanBeCancelled = count(array_filter(
                                            $reservationGroup['reservations'],
                                            static fn (array $reservation): bool => in_array($reservation['status'], ['pending', 'accepted'], true)
                                        )) === count($reservationGroup['reservations']);
                                        $sessionReservationId = (int) $reservationGroup['reservations'][0]['reservation_id'];
                                    ?>
                                    <tr class="reservation-session-group-row">
                                        <th colspan="7" scope="rowgroup">
                                            <div class="reservation-session-group-content">
                                                <span>Booking session #<?= $reservationGroup['session_id'] ?> · <?= count($reservationGroup['reservations']) ?> <?= count($reservationGroup['reservations']) === 1 ? 'item' : 'items' ?></span>
                                                <?php if ($sessionHasPending || $sessionCanBeCancelled): ?>
                                                    <div class="reservation-session-group-actions">
                                                        <?php if ($sessionHasPending): ?>
                                                            <span class="reservation-status reservation-status-pending">Pending</span>
                                                        <?php endif; ?>
                                                        <?php if ($sessionCanBeAccepted): ?>
                                                            <form method="post" action="reservations.php">
                                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                                <input type="hidden" name="action" value="accept">
                                                                <input type="hidden" name="reservation_id" value="<?= $sessionReservationId ?>">
                                                                <button class="shop-button reservation-workflow-button" type="submit">Accept request</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($sessionCanBeCancelled): ?>
                                                            <form method="post" action="reservations.php" data-confirm-message="Cancel this booking session? Any PayPal payment will be refunded.">
                                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                                <input type="hidden" name="action" value="cancel">
                                                                <input type="hidden" name="reservation_id" value="<?= $sessionReservationId ?>">
                                                                <button class="reservation-cancel-button" type="submit">Cancel</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </th>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($reservationGroup['reservations'] as $reservation): ?>
                                <tr>
                                    <td data-label="Product">
                                        <?= escape_html($reservation['product_name']) ?>
                                        <?php if ($reservation['status'] !== 'pending' || $reservation['reservation_session_id'] === null): ?>
                                            <span class="reservation-status reservation-status-<?= escape_html($reservation['status']) ?>"><?= escape_html(ucfirst(str_replace('_', ' ', $reservation['status']))) ?></span>
                                        <?php endif; ?>
                                        <?php if ($reservation['status'] === 'pending' && $reservation['reservation_session_id'] === null): ?>
                                            <form method="post" action="reservations.php">
                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="accept">
                                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                                                <button class="shop-button reservation-workflow-button" type="submit">Accept reservation</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($reservation['status'], ['pending', 'accepted'], true) && $reservation['reservation_session_id'] === null): ?>
                                            <form method="post" action="reservations.php" data-confirm-message="Cancel this reservation? Any PayPal payment will be refunded.">
                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                                                <button class="reservation-cancel-button" type="submit">Cancel reservation</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Customer">
                                        <?= escape_html($reservation['full_name']) ?>
                                        <span class="reservation-customer-username">@<?= escape_html($reservation['username']) ?></span>
                                        <?php if ($reservation['contact_number'] !== null && $reservation['contact_number'] !== ''): ?>
                                            <span class="reservation-customer-username"><?= escape_html($reservation['contact_number']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Rental dates">
                                        <?= escape_html(date('M j, Y', strtotime($reservation['pickup_date']))) ?>
                                        – <?= escape_html(date('M j, Y', strtotime($reservation['return_date']))) ?>
                                        <span class="reservation-customer-username"><?= (int) $reservation['rental_days'] ?> <?= (int) $reservation['rental_days'] === 1 ? 'day' : 'days' ?></span>
                                        <?php if ($reservation['status'] === 'pending'): ?>
                                            <span class="reservation-customer-username">Held until <?= escape_html(date('M j, g:i A', strtotime($reservation['expires_at']))) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Total">₱<?= number_format((float) $reservation['rental_total'], 2) ?></td>
                                    <td data-label="Deposit">₱<?= number_format((float) $reservation['refundable_deposit'], 2) ?></td>
                                    <td data-label="QR workflow">
                                        <a class="text-link" href="product_tag.php?id=<?= (int) $reservation['product_id'] ?>">Print QR tag</a>
                                        <?php if (in_array($reservation['status'], ['accepted', 'checked_out', 'paid'], true)): ?>
                                            <span class="reservation-customer-username"><?= $reservation['status'] === 'accepted' ? 'Scan product tag at pickup' : 'Scan product tag on return' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Return record">
                                        <?php if ($reservation['status'] === 'completed'): ?>
                                            <span class="reservation-customer-username">Damage charge: ₱<?= number_format((float) $reservation['damage_charge'], 2) ?></span>
                                            <span class="reservation-customer-username">
                                                Late <?= (int) $reservation['late_days'] ?> <?= (int) $reservation['late_days'] === 1 ? 'day' : 'days' ?> fee: ₱<?= number_format((float) $reservation['late_charge'], 2) ?>
                                            </span>
                                            <?php if ($reservation['late_notes'] !== null && $reservation['late_notes'] !== ''): ?>
                                                <span class="reservation-customer-username"><?= nl2br(escape_html($reservation['late_notes'])) ?></span>
                                            <?php endif; ?>
                                        <?php elseif (in_array($reservation['status'], ['paid', 'checked_out'], true)): ?>
                                            <span class="field-hint">Return charges are recorded during the QR return inspection.</span>
                                        <?php elseif ($reservation['status'] === 'accepted'): ?>
                                            <span class="field-hint">Available after pickup and return inspection.</span>
                                        <?php elseif ($reservation['status'] === 'cancelled'): ?>
                                            <span class="field-hint">Reservation cancelled.</span>
                                        <?php elseif ($reservation['status'] === 'pending'): ?>
                                            <span class="field-hint">Available after the rental is checked out.</span>
                                        <?php else: ?>
                                            <span class="field-hint">No return record yet.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state"><?= $search !== '' ? 'No reservations match your search.' : 'There are no reservation requests yet.' ?></p>
            <?php endif; ?>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
