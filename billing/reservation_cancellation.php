<?php

require_once __DIR__ . '/paypal_helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

function cancel_reservation_booking(PDO $connection, int $reservationId, ?int $customerId = null): array
{
    $connection->beginTransaction();
    try {
        $lookupSql = 'SELECT reservation_session_id, user_id
                      FROM reservations
                      WHERE reservation_id = :reservation_id';
        $lookupParameters = ['reservation_id' => $reservationId];
        if ($customerId !== null) {
            $lookupSql .= ' AND user_id = :user_id';
            $lookupParameters['user_id'] = $customerId;
        }
        $lookupStatement = $connection->prepare($lookupSql);
        $lookupStatement->execute($lookupParameters);
        $lookup = $lookupStatement->fetch();
        if (!$lookup) {
            throw new RuntimeException('That reservation could not be found or is not available to cancel.');
        }

        $sessionId = $lookup['reservation_session_id'] === null
            ? null
            : (int) $lookup['reservation_session_id'];
        if ($sessionId !== null) {
            $sessionLock = $connection->prepare(
                'SELECT reservation_session_id FROM reservation_sessions
                 WHERE reservation_session_id = :session_id FOR UPDATE'
            );
            $sessionLock->execute(['session_id' => $sessionId]);
            if ($sessionLock->fetchColumn() === false) {
                throw new RuntimeException('This booking session could not be found.');
            }
        }

        $reservationsSql = 'SELECT reservation_id, user_id, status
                            FROM reservations
                            WHERE ' . ($sessionId === null
                ? 'reservation_id = :reservation_id'
                : 'reservation_session_id = :session_id') . '
                            ORDER BY reservation_id
                            FOR UPDATE';
        $reservationsStatement = $connection->prepare($reservationsSql);
        $reservationsStatement->execute($sessionId === null
            ? ['reservation_id' => $reservationId]
            : ['session_id' => $sessionId]);
        $reservations = $reservationsStatement->fetchAll();
        if ($reservations === []) {
            throw new RuntimeException('This booking has no reservations to cancel.');
        }
        foreach ($reservations as $reservation) {
            if ($customerId !== null && (int) $reservation['user_id'] !== $customerId) {
                throw new RuntimeException('This booking is not available to cancel.');
            }
            if (!in_array($reservation['status'], ['pending', 'accepted'], true)) {
                throw new RuntimeException('A booking can only be cancelled before pickup is confirmed.');
            }
        }

        $paymentSql = "SELECT payment_id, reservation_id, method, amount, currency,
                              paypal_capture_id, status
                       FROM payment_transactions
                       WHERE purpose = 'rental' AND status = 'paid'
                         AND " . ($sessionId === null
                ? 'reservation_id = :reservation_id'
                : 'reservation_session_id = :session_id') . '
                       ORDER BY payment_id DESC LIMIT 1 FOR UPDATE';
        $paymentStatement = $connection->prepare($paymentSql);
        $paymentStatement->execute($sessionId === null
            ? ['reservation_id' => $reservationId]
            : ['session_id' => $sessionId]);
        $payment = $paymentStatement->fetch();
        if ($payment && $payment['method'] !== 'paypal') {
            throw new RuntimeException('A cash payment was already recorded. Staff must issue that refund in the shop.');
        }

        $refundPaymentId = null;
        if ($payment) {
            if (empty($payment['paypal_capture_id'])) {
                throw new RuntimeException('The PayPal capture reference is missing. Contact the shop before cancelling.');
            }
            $existingRefundSql = "SELECT payment_id
                                  FROM payment_transactions
                                  WHERE purpose = 'reservation_refund'
                                    AND method = 'paypal'
                                    AND status IN ('pending', 'paid')
                                    AND " . ($sessionId === null
                ? 'reservation_id = :reservation_id'
                : 'reservation_session_id = :session_id') . '
                                  ORDER BY payment_id DESC LIMIT 1 FOR UPDATE';
            $existingRefund = $connection->prepare($existingRefundSql);
            $existingRefund->execute($sessionId === null
                ? ['reservation_id' => $reservationId]
                : ['session_id' => $sessionId]);
            $existingRefundPaymentId = $existingRefund->fetchColumn();
            if ($existingRefundPaymentId !== false) {
                throw new RuntimeException('A refund for this booking has already been initiated.');
            }

            $refundInsert = $connection->prepare(
                "INSERT INTO payment_transactions
                    (reservation_id, reservation_session_id, user_id, purpose, method, amount,
                     currency, paypal_capture_id, status)
                 VALUES
                    (:reservation_id, :session_id, :user_id, 'reservation_refund', 'paypal',
                     :amount, :currency, :capture_id, 'pending')"
            );
            $refundInsert->execute([
                'reservation_id' => (int) $payment['reservation_id'],
                'session_id' => $sessionId,
                'user_id' => (int) $lookup['user_id'],
                'amount' => $payment['amount'],
                'currency' => $payment['currency'],
                'capture_id' => $payment['paypal_capture_id'],
            ]);
            $refundPaymentId = (int) $connection->lastInsertId();
        }

        if ($sessionId === null) {
            $cancelStatement = $connection->prepare(
                "UPDATE reservations SET status = 'cancelled'
                 WHERE reservation_id = :reservation_id
                   AND status IN ('pending', 'accepted')"
            );
            $cancelStatement->execute(['reservation_id' => $reservationId]);
        } else {
            $cancelStatement = $connection->prepare(
                "UPDATE reservations SET status = 'cancelled'
                 WHERE reservation_session_id = :session_id
                   AND status IN ('pending', 'accepted')"
            );
            $cancelStatement->execute(['session_id' => $sessionId]);
        }
        if ($cancelStatement->rowCount() !== count($reservations)) {
            throw new RuntimeException('The booking could not be cancelled. Refresh and try again.');
        }
        if ($sessionId !== null) {
            $connection->prepare(
                "UPDATE payment_transactions SET status = 'failed'
                 WHERE reservation_session_id = :session_id
                   AND purpose = 'rental' AND status = 'pending'"
            )->execute(['session_id' => $sessionId]);
        } else {
            $connection->prepare(
                "UPDATE payment_transactions SET status = 'failed'
                 WHERE reservation_id = :reservation_id
                   AND purpose = 'rental' AND status = 'pending'"
            )->execute(['reservation_id' => $reservationId]);
        }
        $connection->commit();

        $refundCompleted = $refundPaymentId !== null ? false : null;

        $cancellationMessage = 'Your booking was cancelled.';
        if ($refundPaymentId !== null) {
            $cancellationMessage .= ' Your PayPal refund is pending staff review and processing.';
        }
        send_customer_transaction_email(
            $connection,
            $reservationId,
            'Your reservation was cancelled',
            $cancellationMessage
        );
        send_admin_transaction_email(
            $connection,
            $reservationId,
            'A reservation was cancelled',
            $cancellationMessage
        );

        return [
            'session_id' => $sessionId,
            'reservation_count' => count($reservations),
            'refund_payment_id' => $refundPaymentId,
            'refund_completed' => $refundCompleted,
        ];
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}
