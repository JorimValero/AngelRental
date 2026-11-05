<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/paypal_helpers.php';
require_authenticated_role('customer');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function payment_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    payment_response(405, ['success' => false, 'message' => 'This payment action is not supported.']);
}
if (!valid_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    payment_response(403, ['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

$request = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($request)) {
    payment_response(400, ['success' => false, 'message' => 'Invalid payment request.']);
}
$action = is_string($request['action'] ?? null) ? $request['action'] : '';
$purpose = is_string($request['purpose'] ?? null) ? $request['purpose'] : '';
$reservationId = filter_var($request['reservation_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!in_array($purpose, ['rental', 'fees'], true) || $reservationId === false) {
    payment_response(400, ['success' => false, 'message' => 'Select a valid reservation payment.']);
}
if (!paypal_is_configured()) {
    payment_response(503, ['success' => false, 'message' => 'PayPal sandbox is not configured. Please contact the shop.']);
}

$userId = (int) $_SESSION['user_id'];

try {
    $connection = get_database_connection();

    if ($action === 'create_order') {
        $connection->beginTransaction();
        try {
            $reservationStatement = $connection->prepare(
                'SELECT status, payment_method, rental_total, refundable_deposit,
                        damage_charge, late_charge
                 FROM reservations
                 WHERE reservation_id = :reservation_id AND user_id = :user_id
                 FOR UPDATE'
            );
            $reservationStatement->execute([
                'reservation_id' => $reservationId,
                'user_id' => $userId,
            ]);
            $reservation = $reservationStatement->fetch();
            if (!$reservation) {
                throw new RuntimeException('That reservation could not be found.');
            }

            if ($purpose === 'rental') {
                if ($reservation['status'] !== 'accepted' || $reservation['payment_method'] !== 'online') {
                    throw new RuntimeException('PayPal rental payment is available after staff accepts an online reservation.');
                }
                $amount = number_format(
                    (float) $reservation['rental_total'] + (float) $reservation['refundable_deposit'],
                    2,
                    '.',
                    ''
                );
            } else {
                if ($reservation['status'] !== 'completed') {
                    throw new RuntimeException('Return fees can be paid online after staff completes the return inspection.');
                }
                $paidFeesStatement = $connection->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM payment_transactions
                     WHERE reservation_id = :reservation_id
                       AND purpose = 'fees' AND status = 'paid'"
                );
                $paidFeesStatement->execute(['reservation_id' => $reservationId]);
                $amount = number_format(
                    max(
                        (float) $reservation['damage_charge'] + (float) $reservation['late_charge']
                            - (float) $paidFeesStatement->fetchColumn(),
                        0
                    ),
                    2,
                    '.',
                    ''
                );
                if ((float) $amount <= 0) {
                    throw new RuntimeException('There are no outstanding return fees for this reservation.');
                }
            }
            if ((float) $amount > 99999999.99) {
                throw new RuntimeException('This payment exceeds the supported transaction limit. Please contact the shop.');
            }

            if ($purpose === 'rental') {
                $paidStatement = $connection->prepare(
                    "SELECT 1 FROM payment_transactions
                     WHERE reservation_id = :reservation_id
                       AND purpose = 'rental' AND status = 'paid'
                     LIMIT 1"
                );
                $paidStatement->execute(['reservation_id' => $reservationId]);
                if ($paidStatement->fetchColumn()) {
                    throw new RuntimeException('This payment has already been received.');
                }
            }

            $pendingStatement = $connection->prepare(
                "SELECT payment_id, paypal_order_id,
                        (created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 170 MINUTE)) AS is_recent
                 FROM payment_transactions
                 WHERE reservation_id = :reservation_id
                   AND purpose = :purpose AND method = 'paypal' AND status = 'pending'
                 ORDER BY payment_id DESC LIMIT 1 FOR UPDATE"
            );
            $pendingStatement->execute([
                'reservation_id' => $reservationId,
                'purpose' => $purpose,
            ]);
            $pendingPayment = $pendingStatement->fetch();
            if (
                $pendingPayment
                && is_string($pendingPayment['paypal_order_id'])
                && $pendingPayment['paypal_order_id'] !== ''
                && (bool) $pendingPayment['is_recent']
            ) {
                $connection->commit();
                payment_response(200, ['success' => true, 'order_id' => $pendingPayment['paypal_order_id']]);
            }
            if ($pendingPayment) {
                $expirePendingStatement = $connection->prepare(
                    "UPDATE payment_transactions SET status = 'failed'
                     WHERE payment_id = :payment_id AND status = 'pending'"
                );
                $expirePendingStatement->execute(['payment_id' => $pendingPayment['payment_id']]);
            }

            $insertStatement = $connection->prepare(
                "INSERT INTO payment_transactions
                    (reservation_id, user_id, purpose, method, amount, currency, status)
                 VALUES
                    (:reservation_id, :user_id, :purpose, 'paypal', :amount, 'PHP', 'pending')"
            );
            $insertStatement->execute([
                'reservation_id' => $reservationId,
                'user_id' => $userId,
                'purpose' => $purpose,
                'amount' => $amount,
            ]);
            $paymentId = (int) $connection->lastInsertId();

            $description = $purpose === 'rental'
                ? 'Rental and refundable deposit for reservation #' . $reservationId
                : 'Return fees for reservation #' . $reservationId;
            $paypalOrder = paypal_api_request('POST', '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $paymentId,
                    'custom_id' => (string) $reservationId,
                    'description' => $description,
                    'amount' => [
                        'currency_code' => 'PHP',
                        'value' => $amount,
                    ],
                ]],
            ], 'rental-create-' . $paymentId);
            $orderId = $paypalOrder['id'] ?? null;
            if (!is_string($orderId) || $orderId === '') {
                throw new RuntimeException('PayPal did not create a valid payment order. Please try again.');
            }

            $updateStatement = $connection->prepare(
                'UPDATE payment_transactions SET paypal_order_id = :order_id WHERE payment_id = :payment_id'
            );
            $updateStatement->execute(['order_id' => $orderId, 'payment_id' => $paymentId]);
            $connection->commit();
            payment_response(200, ['success' => true, 'order_id' => $orderId]);
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    if ($action === 'capture_order') {
        $orderId = is_string($request['order_id'] ?? null) ? trim($request['order_id']) : '';
        if ($orderId === '' || strlen($orderId) > 100 || !preg_match('/\A[A-Za-z0-9-]+\z/', $orderId)) {
            payment_response(400, ['success' => false, 'message' => 'Invalid PayPal order.']);
        }

        $connection->beginTransaction();
        try {
            $paymentLookupStatement = $connection->prepare(
                'SELECT payment_id, reservation_id, purpose, amount, currency, status
                 FROM payment_transactions
                 WHERE paypal_order_id = :order_id AND user_id = :user_id AND method = \'paypal\''
            );
            $paymentLookupStatement->execute(['order_id' => $orderId, 'user_id' => $userId]);
            $paymentLookup = $paymentLookupStatement->fetch();
            if (!$paymentLookup) {
                throw new RuntimeException('This PayPal order is not associated with your account.');
            }
            if ((int) $paymentLookup['reservation_id'] !== (int) $reservationId
                || $paymentLookup['purpose'] !== $purpose) {
                throw new RuntimeException('This PayPal order is no longer payable.');
            }

            $reservationStatement = $connection->prepare(
                'SELECT status, payment_method, rental_total, refundable_deposit,
                        damage_charge, late_charge
                 FROM reservations
                 WHERE reservation_id = :reservation_id AND user_id = :user_id
                 FOR UPDATE'
            );
            $reservationStatement->execute([
                'reservation_id' => $reservationId,
                'user_id' => $userId,
            ]);
            $reservation = $reservationStatement->fetch();
            if (!$reservation) {
                throw new RuntimeException('That reservation could not be found.');
            }

            $paymentStatement = $connection->prepare(
                'SELECT payment_id, reservation_id, purpose, amount, currency, status
                 FROM payment_transactions
                 WHERE paypal_order_id = :order_id AND user_id = :user_id AND method = \'paypal\'
                 FOR UPDATE'
            );
            $paymentStatement->execute(['order_id' => $orderId, 'user_id' => $userId]);
            $payment = $paymentStatement->fetch();
            if (!$payment) {
                throw new RuntimeException('This PayPal order is no longer associated with your account.');
            }
            if ($payment['status'] === 'paid') {
                $connection->commit();
                payment_response(200, ['success' => true, 'message' => 'Payment has already been received.']);
            }
            if ($payment['status'] !== 'pending') {
                throw new RuntimeException('This PayPal order is no longer payable.');
            }

            if ($purpose === 'rental') {
                if ($reservation['status'] !== 'accepted' || $reservation['payment_method'] !== 'online') {
                    throw new RuntimeException('This rental is no longer eligible for online payment.');
                }
                $expectedAmount = number_format(
                    (float) $reservation['rental_total'] + (float) $reservation['refundable_deposit'],
                    2,
                    '.',
                    ''
                );
            } else {
                if ($reservation['status'] !== 'completed') {
                    throw new RuntimeException('This return fee is no longer eligible for online payment.');
                }
                $paidFeesStatement = $connection->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM payment_transactions
                     WHERE reservation_id = :reservation_id
                       AND purpose = 'fees' AND status = 'paid'"
                );
                $paidFeesStatement->execute(['reservation_id' => $reservationId]);
                $expectedAmount = number_format(
                    max(
                        (float) $reservation['damage_charge'] + (float) $reservation['late_charge']
                            - (float) $paidFeesStatement->fetchColumn(),
                        0
                    ),
                    2,
                    '.',
                    ''
                );
            }
            if ((float) $expectedAmount > 99999999.99) {
                throw new RuntimeException('This payment exceeds the supported transaction limit. Please contact the shop.');
            }
            if ($expectedAmount !== number_format((float) $payment['amount'], 2, '.', '')
                || (float) $expectedAmount <= 0) {
                throw new RuntimeException('The amount due has changed. Refresh the page before paying.');
            }

            if ($purpose === 'rental') {
                $otherPaidStatement = $connection->prepare(
                    "SELECT 1 FROM payment_transactions
                     WHERE reservation_id = :reservation_id
                       AND purpose = 'rental' AND status = 'paid'
                       AND payment_id <> :payment_id
                     LIMIT 1"
                );
                $otherPaidStatement->execute([
                    'reservation_id' => $reservationId,
                    'payment_id' => $payment['payment_id'],
                ]);
                if ($otherPaidStatement->fetchColumn()) {
                    throw new RuntimeException('This payment has already been received.');
                }
            }

            $paypalCapture = paypal_api_request(
                'POST',
                '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
                null,
                'rental-capture-' . (int) $payment['payment_id']
            );
            $capture = $paypalCapture['purchase_units'][0]['payments']['captures'][0] ?? null;
            $capturedAmount = is_array($capture) ? ($capture['amount']['value'] ?? null) : null;
            $capturedCurrency = is_array($capture) ? ($capture['amount']['currency_code'] ?? null) : null;
            if (
                ($paypalCapture['id'] ?? null) !== $orderId
                || !is_array($capture)
                || ($capture['status'] ?? null) !== 'COMPLETED'
                || !is_string($capturedAmount)
                || number_format((float) $capturedAmount, 2, '.', '') !== number_format((float) $payment['amount'], 2, '.', '')
                || $capturedCurrency !== $payment['currency']
            ) {
                error_log('PayPal capture did not match the expected order amount or status for payment #' . (int) $payment['payment_id']);
                throw new RuntimeException('PayPal could not confirm the expected payment. Contact the shop before retrying.');
            }

            $captureId = $capture['id'] ?? null;
            if (!is_string($captureId) || $captureId === '') {
                throw new RuntimeException('PayPal returned an invalid capture reference. Contact the shop.');
            }
            $markPaidStatement = $connection->prepare(
                "UPDATE payment_transactions
                 SET status = 'paid', paypal_capture_id = :capture_id, paid_at = CURRENT_TIMESTAMP
                 WHERE payment_id = :payment_id AND status = 'pending'"
            );
            $markPaidStatement->execute([
                'capture_id' => $captureId,
                'payment_id' => $payment['payment_id'],
            ]);
            if ($markPaidStatement->rowCount() !== 1) {
                throw new RuntimeException('The payment status could not be updated. Contact the shop.');
            }
            $connection->commit();
            $paymentDescription = $purpose === 'rental'
                ? 'Your rental payment, including the refundable deposit, was received.'
                : 'Your return-fee payment was received.';
            send_customer_transaction_email(
                $connection,
                (int) $reservationId,
                $purpose === 'rental' ? 'Rental payment received' : 'Return-fee payment received',
                $paymentDescription . ' Amount: ₱' . number_format((float) $payment['amount'], 2)
                    . '. PayPal capture reference: ' . $captureId . '.'
            );
            payment_response(200, ['success' => true]);
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    payment_response(400, ['success' => false, 'message' => 'That payment action is not supported.']);
} catch (RuntimeException $exception) {
    payment_response(400, ['success' => false, 'message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    show_database_error($exception);
    payment_response(503, ['success' => false, 'message' => 'Payment services are temporarily unavailable. Please try again.']);
} catch (Throwable $exception) {
    error_log('RentalSystem payment error: ' . $exception->getMessage());
    payment_response(500, ['success' => false, 'message' => 'The payment could not be completed. Please try again.']);
}
