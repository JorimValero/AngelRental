<?php

$notificationRole = (string) ($_SESSION['user_role'] ?? '');
$notificationBasePath = (string) ($notificationBasePath ?? '');
$notificationTarget = match ($notificationRole) {
    'customer' => 'costumer/reservations.php',
    'staff' => 'staff/reservations.php',
    'admin' => 'admin/rental_records.php',
    default => '',
};
$notificationTargetLabel = match ($notificationRole) {
    'customer' => 'My reservations',
    'staff' => 'Reservations',
    'admin' => 'Rental records',
    default => 'Updates',
};
?>
<?php if ($notificationTarget !== ''): ?>
    <details class="notification-dropdown" data-notification-menu data-endpoint="<?= escape_html($notificationBasePath . 'notifications.php') ?>" data-csrf-token="<?= escape_html(csrf_token()) ?>">
        <summary class="notification-button" aria-label="Notifications" title="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                <path d="M10 21h4"></path>
            </svg>
            <span class="notification-count" data-notification-count hidden></span>
        </summary>
        <section class="notification-menu-panel" aria-label="Notifications">
            <div class="notification-menu-heading">
                <h2>Notifications</h2>
                <a href="<?= escape_html($notificationBasePath . $notificationTarget) ?>"><?= escape_html($notificationTargetLabel) ?></a>
            </div>
            <div class="notification-list" data-notification-list aria-live="polite" aria-relevant="additions text">
                <p class="notification-state">Loading notifications…</p>
            </div>
        </section>
    </details>
<?php endif; ?>
