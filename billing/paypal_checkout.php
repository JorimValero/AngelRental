<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/paypal_helpers.php';
require_authenticated_role('customer');

$sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$error = '';
$approvalUrl = null;

try {
    if ($sessionId === false) {
        throw new RuntimeException('Select a valid booking session.');
    }
    if (!paypal_is_configured()) {
        throw new RuntimeException('PayPal sandbox is not configured. Please contact the shop.');
    }

    $connection = get_database_connection();
    $connection->beginTransaction();
    $sessionStatement = $connection->prepare(
        'SELECT reservation_session_id
         FROM reservation_sessions
         WHERE reservation_session_id = :session_id AND user_id = :user_id
         FOR UPDATE'
    );
    $sessionStatement->execute([
        'session_id' => $sessionId,
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    if ($sessionStatement->fetchColumn() === false) {
        throw new RuntimeException('That booking session could not be found.');
    }

    $reservationsStatement = $connection->prepare(
        "SELECT reservation_id, rental_total, refundable_deposit, status, expires_at
         FROM reservations
         WHERE reservation_session_id = :session_id AND user_id = :user_id
         ORDER BY reservation_id
         FOR UPDATE"
    );
    $reservationsStatement->execute([
        'session_id' => $sessionId,
        'user_id' => (int) $_SESSION['user_id'],
    ]);
    $reservations = $reservationsStatement->fetchAll();
    if ($reservations === []) {
        throw new RuntimeException('This booking session has no reservations.');
    }
    foreach ($reservations as $reservation) {
        if (
            !in_array($reservation['status'], ['pending', 'accepted'], true)
            || ($reservation['status'] === 'pending' && strtotime((string) $reservation['expires_at']) <= time())
        ) {
            throw new RuntimeException('This booking session is no longer eligible for payment.');
        }
    }

    $paymentStatement = $connection->prepare(
        "SELECT payment_id, paypal_order_id, status, amount,
                (created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 170 MINUTE)) AS is_recent
         FROM payment_transactions
         WHERE reservation_session_id = :session_id
           AND purpose = 'rental' AND method = 'paypal'
         ORDER BY payment_id DESC LIMIT 1 FOR UPDATE"
    );
    $paymentStatement->execute(['session_id' => $sessionId]);
    $payment = $paymentStatement->fetch();
    if ($payment && $payment['status'] === 'paid') {
        $connection->commit();
        header('Location: paypal_return.php?session_id=' . $sessionId . '&result=success');
        exit;
    }

    $baseUrl = paypal_checkout_base_url();
    $baseParts = parse_url($baseUrl);
    if (
        !is_array($baseParts)
        || !in_array($baseParts['scheme'] ?? '', ['http', 'https'], true)
        || empty($baseParts['host'])
        || isset($baseParts['user'])
        || isset($baseParts['pass'])
    ) {
        throw new RuntimeException('RENTAL_APP_URL must be an absolute HTTP or HTTPS application URL.');
    }
    $baseUrl = rtrim($baseUrl, '/');
    $returnUrl = $baseUrl . '/billing/paypal_return.php?session_id=' . $sessionId . '&result=success';
    $cancelUrl = $baseUrl . '/billing/paypal_return.php?session_id=' . $sessionId . '&result=cancel';

    if (
        $payment
        && $payment['status'] === 'pending'
        && is_string($payment['paypal_order_id'])
        && $payment['paypal_order_id'] !== ''
        && (bool) $payment['is_recent']
    ) {
        $order = paypal_api_request('GET', '/v2/checkout/orders/' . rawurlencode($payment['paypal_order_id']));
    } else {
        if ($payment && $payment['status'] === 'pending') {
            $connection->prepare(
                "UPDATE payment_transactions SET status = 'failed'
                 WHERE payment_id = :payment_id AND status = 'pending'"
            )->execute(['payment_id' => (int) $payment['payment_id']]);
        }
        $amount = 0.0;
        foreach ($reservations as $reservation) {
            $amount += (float) $reservation['rental_total'] + (float) $reservation['refundable_deposit'];
        }
        $amount = number_format($amount, 2, '.', '');
        if ((float) $amount <= 0 || (float) $amount > 99999999.99) {
            throw new RuntimeException('This booking total exceeds the supported payment limit.');
        }
        $insertStatement = $connection->prepare(
            "INSERT INTO payment_transactions
                (reservation_id, reservation_session_id, user_id, purpose, method, amount, currency, status)
             VALUES
                (:reservation_id, :session_id, :user_id, 'rental', 'paypal', :amount, 'PHP', 'pending')"
        );
        $insertStatement->execute([
            'reservation_id' => (int) $reservations[0]['reservation_id'],
            'session_id' => $sessionId,
            'user_id' => (int) $_SESSION['user_id'],
            'amount' => $amount,
        ]);
        $paymentId = (int) $connection->lastInsertId();
        $order = paypal_api_request('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $paymentId,
                'custom_id' => (string) $sessionId,
                'description' => 'Rental and refundable deposits for booking session #' . $sessionId,
                'amount' => [
                    'currency_code' => 'PHP',
                    'value' => $amount,
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
                'brand_name' => 'Angels Bridal Rental Shop',
            ],
        ], 'rental-session-create-' . $paymentId);
        $orderId = $order['id'] ?? null;
        if (!is_string($orderId) || $orderId === '') {
            throw new RuntimeException('PayPal did not create a valid payment order. Please try again.');
        }
        $connection->prepare(
            'UPDATE payment_transactions SET paypal_order_id = :order_id WHERE payment_id = :payment_id'
        )->execute([
            'order_id' => $orderId,
            'payment_id' => $paymentId,
        ]);
    }

    foreach (($order['links'] ?? []) as $link) {
        if (in_array(($link['rel'] ?? ''), ['approve', 'payer-action'], true)
            && is_string($link['href'] ?? null)) {
            $approvalUrl = $link['href'];
            break;
        }
    }
    if ($approvalUrl === null || !str_starts_with($approvalUrl, 'https://www.sandbox.paypal.com/')) {
        throw new RuntimeException('PayPal did not provide a valid sandbox approval link.');
    }
    $connection->commit();
    header('Location: ' . $approvalUrl);
    exit;
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
    $error = 'PayPal checkout could not be started. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PayPal checkout | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <a class="shop-button" href="../costumer/reservations.php">Return to my reservations</a>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
