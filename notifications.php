<?php

require_once __DIR__ . '/auth/helpers.php';
require_authenticated_roles(['customer', 'staff', 'admin']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function notification_response(int $status, array $response): void
{
    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = (string) $_SESSION['user_role'];
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        notification_response(403, ['error' => 'Your session expired. Refresh the page and try again.']);
    }

    try {
        $statement = get_database_connection()->prepare(
            'UPDATE users
             SET notifications_last_read_at = CURRENT_TIMESTAMP(6)
             WHERE id = :user_id AND role = :role'
        );
        $statement->execute(['user_id' => $userId, 'role' => $role]);
        notification_response(200, ['success' => true]);
    } catch (PDOException $exception) {
        show_database_error($exception);
        notification_response(500, ['error' => 'Notifications could not be marked as read.']);
    }
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    notification_response(405, ['error' => 'This notification request is not supported.']);
}

try {
    $connection = get_database_connection();
    $readAtStatement = $connection->prepare(
        'SELECT notifications_last_read_at FROM users WHERE id = :user_id AND role = :role'
    );
    $readAtStatement->execute(['user_id' => $userId, 'role' => $role]);
    $lastReadAt = $readAtStatement->fetchColumn();
    if ($lastReadAt === false) {
        notification_response(403, ['error' => 'Your account could not be verified.']);
    }

    if ($role === 'customer') {
        $reservationFilter = 'r.user_id = :reservation_user_id';
        $paymentFilter = 'pt.user_id = :payment_user_id AND pt.status IN (\'paid\', \'failed\')';
        $reservationParameters = ['reservation_user_id' => $userId];
        $paymentParameters = ['payment_user_id' => $userId];
    } elseif ($role === 'staff') {
        $reservationFilter = "r.status = 'pending'";
        $paymentFilter = "pt.status = 'failed'";
        $reservationParameters = [];
        $paymentParameters = [];
    } else {
        $reservationFilter = "r.status IN ('completed', 'cancelled')";
        $paymentFilter = "pt.status IN ('paid', 'failed')";
        $reservationParameters = [];
        $paymentParameters = [];
    }

    $unreadStatement = $connection->prepare(
        "SELECT
             (SELECT COUNT(*) FROM reservations r
              WHERE $reservationFilter
                AND r.updated_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
                AND (:reservation_read_at_null IS NULL OR r.updated_at > :reservation_read_at))
             +
             (SELECT COUNT(*) FROM payment_transactions pt
              WHERE $paymentFilter
                AND pt.updated_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
                AND (:payment_read_at_null IS NULL OR pt.updated_at > :payment_read_at)"
            . ($role === 'customer' ? '' : " AND pt.purpose IN ('rental', 'fees', 'deposit_refund', 'reservation_refund')") . ')'
    );
    $unreadStatement->execute($reservationParameters + $paymentParameters + [
        'reservation_read_at_null' => $lastReadAt,
        'reservation_read_at' => $lastReadAt,
        'payment_read_at_null' => $lastReadAt,
        'payment_read_at' => $lastReadAt,
    ]);
    $unreadCount = (int) $unreadStatement->fetchColumn();

    $notificationsStatement = $connection->prepare(
        "SELECT notification_type, notification_id, status, purpose, updated_at, product_name
         FROM (
             SELECT 'reservation' AS notification_type, r.reservation_id AS notification_id,
                    r.status, NULL AS purpose, r.updated_at, p.product_name
             FROM reservations r
             INNER JOIN products p ON p.product_id = r.product_id
             WHERE $reservationFilter
               AND r.updated_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
             UNION ALL
             SELECT 'payment' AS notification_type, pt.payment_id AS notification_id,
                    pt.status, pt.purpose, pt.updated_at, p.product_name
             FROM payment_transactions pt
             INNER JOIN reservations r ON r.reservation_id = pt.reservation_id
             INNER JOIN products p ON p.product_id = r.product_id
             WHERE $paymentFilter
               AND pt.updated_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
         ) AS recent_notifications
         ORDER BY updated_at DESC, notification_id DESC
         LIMIT 10"
    );
    $notificationsStatement->execute($reservationParameters + $paymentParameters);
    $statusMessages = [
        'pending' => $role === 'staff'
            ? 'A new reservation request is waiting for review.'
            : 'Your reservation request is awaiting review.',
        'accepted' => 'Your reservation has been accepted.',
        'checked_out' => 'Your rental pickup has been confirmed.',
        'paid' => 'Your reservation payment has been recorded.',
        'completed' => 'A rental has been marked as returned.',
        'cancelled' => 'A reservation has been cancelled.',
        'expired' => 'Your reservation request has expired.',
    ];
    $notifications = [];
    foreach ($notificationsStatement->fetchAll() as $row) {
        $updatedAt = new DateTimeImmutable((string) $row['updated_at']);
        $isUnread = $lastReadAt === null
            || $updatedAt > new DateTimeImmutable((string) $lastReadAt);
        $isPayment = $row['notification_type'] === 'payment';
        if ($isPayment) {
            $isRefund = in_array($row['purpose'], ['deposit_refund', 'reservation_refund'], true);
            $message = match ([$row['status'], $isRefund]) {
                ['paid', true] => 'A customer refund has been completed.',
                ['failed', true] => 'A customer refund failed and needs review.',
                ['paid', false] => 'A customer payment has been received.',
                default => 'A customer payment failed and may need review.',
            };
        } else {
            $message = $statusMessages[(string) $row['status']] ?? 'A reservation has been updated.';
        }

        $href = match ($role) {
            'customer' => $isPayment
                ? 'costumer/payments.php'
                : 'costumer/reservations.php#reservation-' . (int) $row['notification_id'],
            'staff' => 'staff/reservations.php',
            default => 'admin/rental_records.php',
        };
        $notifications[] = [
            'type' => $isPayment ? 'payment' : 'reservation',
            'id' => (int) $row['notification_id'],
            'product_name' => (string) $row['product_name'],
            'message' => $message,
            'updated_at' => $updatedAt->format(DATE_ATOM),
            'is_unread' => $isUnread,
            'href' => $href,
        ];
    }

    notification_response(200, [
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
    ]);
} catch (PDOException $exception) {
    show_database_error($exception);
    $errorCode = (string) $exception->getCode();
    $message = in_array($errorCode, ['42S02', '42S22'], true)
        ? 'The notification database update is missing. Import database/bridalrental.sql in your hosting database, then reload the page.'
        : 'Notifications could not be loaded. Please try again later.';
    notification_response(500, ['error' => $message]);
}
