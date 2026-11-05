<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_authenticated_role('admin');

$summary = [
    'rental_count' => 0,
    'ongoing_count' => 0,
    'returned_count' => 0,
    'revenue' => 0,
    'deposits_collected' => 0,
    'deposits_refunded' => 0,
    'return_charges' => 0,
];
$records = [];
$error = '';

try {
    $connection = get_database_connection();
    expire_stale_reservations($connection);

    $reservationRows = $connection->query(
        "SELECT r.reservation_id, r.reservation_session_id, r.pickup_date, r.return_date,
                r.rental_total, r.refundable_deposit, r.status, r.created_at,
                r.damage_charge, r.late_charge,
                p.product_name,
                u.full_name, u.username,
                EXISTS(
                    SELECT 1 FROM payment_transactions pt
                    WHERE (pt.reservation_id = r.reservation_id
                           OR (r.reservation_session_id IS NOT NULL
                               AND pt.reservation_session_id = r.reservation_session_id))
                      AND pt.purpose = 'rental' AND pt.status = 'paid'
                ) AS rental_payment_received
         FROM reservations r
         INNER JOIN products p ON p.product_id = r.product_id
         INNER JOIN users u ON u.id = r.user_id
         WHERE r.status IN ('accepted', 'paid', 'checked_out', 'completed')
         ORDER BY r.created_at DESC, r.reservation_id DESC"
    )->fetchAll();

    $rentalGroups = [];
    foreach ($reservationRows as $row) {
        $groupKey = $row['reservation_session_id'] === null
            ? 'reservation-' . (int) $row['reservation_id']
            : 'session-' . (int) $row['reservation_session_id'];
        if (!isset($rentalGroups[$groupKey])) {
            $rentalGroups[$groupKey] = [
                'reservation_number' => 'R-' . str_pad((string) $row['reservation_id'], 6, '0', STR_PAD_LEFT),
                'customer_name' => (string) $row['full_name'],
                'username' => (string) $row['username'],
                'pickup_date' => (string) $row['pickup_date'],
                'return_date' => (string) $row['return_date'],
                'created_at' => (string) $row['created_at'],
                'items' => [],
                'rental_total' => 0.0,
                'deposit_total' => 0.0,
                'damage_charge' => 0.0,
                'late_charge' => 0.0,
                'statuses' => [],
                'payment_received' => true,
                'overdue' => false,
            ];
        }
        $group = &$rentalGroups[$groupKey];
        $group['items'][] = (string) $row['product_name'];
        $group['rental_total'] += (float) $row['rental_total'];
        $group['deposit_total'] += (float) $row['refundable_deposit'];
        $group['damage_charge'] += (float) $row['damage_charge'];
        $group['late_charge'] += (float) $row['late_charge'];
        $group['statuses'][] = (string) $row['status'];
        $group['payment_received'] = $group['payment_received'] && (bool) $row['rental_payment_received'];
        if (
            in_array($row['status'], ['checked_out', 'paid'], true)
            && (string) $row['return_date'] < date('Y-m-d')
        ) {
            $group['overdue'] = true;
        }
        unset($group);
    }
    $records = array_values($rentalGroups);
    $summary['rental_count'] = count($records);

    $paidRentalStatement = $connection->query(
        "SELECT COALESCE(SUM(r.rental_total), 0) AS revenue,
                COALESCE(SUM(r.refundable_deposit), 0) AS deposits_collected,
                COALESCE(SUM(r.damage_charge + r.late_charge), 0) AS return_charges
         FROM reservations r
         WHERE r.status IN ('accepted', 'paid', 'checked_out', 'completed')
           AND EXISTS (
               SELECT 1 FROM payment_transactions pt
               WHERE (pt.reservation_id = r.reservation_id
                      OR (r.reservation_session_id IS NOT NULL
                          AND pt.reservation_session_id = r.reservation_session_id))
                 AND pt.purpose = 'rental' AND pt.status = 'paid'
           )"
    )->fetch();
    $summary['revenue'] = (float) $paidRentalStatement['revenue'];
    $summary['deposits_collected'] = (float) $paidRentalStatement['deposits_collected'];
    $summary['return_charges'] = array_sum(array_map(
        static fn (array $record): float => $record['damage_charge'] + $record['late_charge'],
        $records
    ));
    $summary['deposits_refunded'] = (float) $connection->query(
        "SELECT COALESCE(SUM(pt.amount), 0)
         FROM payment_transactions pt
         INNER JOIN reservations r ON r.reservation_id = pt.reservation_id
         WHERE pt.purpose = 'deposit_refund' AND pt.status = 'paid'
           AND r.status IN ('accepted', 'paid', 'checked_out', 'completed')"
    )->fetchColumn();

    foreach ($records as &$record) {
        if ($record['overdue']) {
            $record['display_status'] = 'Overdue';
            $record['status_filter'] = 'overdue';
        } elseif (count(array_unique($record['statuses'])) === 1
            && $record['statuses'][0] === 'completed') {
            $record['display_status'] = 'Returned';
            $record['status_filter'] = 'returned';
            $summary['returned_count']++;
        } else {
            $record['display_status'] = 'Ongoing';
            $record['status_filter'] = 'ongoing';
            $summary['ongoing_count']++;
        }
    }
    unset($record);
    $records = array_values(array_filter(
        $records,
        static fn (array $record): bool => $record['rental_total'] > 0
    ));
    usort(
        $records,
        static fn (array $left, array $right): int => strcmp($right['created_at'], $left['created_at'])
    );
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'Rental records could not be loaded. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rental records | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page rental-records-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory rental-records-dashboard">
        <div class="rental-records-heading">
            <div>
                <p class="shop-kicker">Administration</p>
                <h1>Rental records</h1>
            </div>
            <div class="rental-record-filters" aria-label="Filter rental records">
                <label class="rental-record-search">
                    <span class="visually-hidden">Search rental records</span>
                    <input id="rental-record-search" type="search" placeholder="Search reservation no., customer, or item..." autocomplete="off">
                </label>
                <label>
                    <span class="visually-hidden">Filter by status</span>
                    <select id="rental-record-status">
                        <option value="all">All status</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="returned">Returned</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </label>
                <label>
                    <span class="visually-hidden">Filter by rental date</span>
                    <select id="rental-record-date">
                        <option value="all">All dates</option>
                        <option value="30">Last 30 days</option>
                        <option value="90">Last 90 days</option>
                        <option value="year">This year</option>
                    </select>
                </label>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <div class="rental-summary-board">
            <section class="rental-record-summary" aria-label="Rental summary">
                <article class="rental-summary-total"><span>Total rentals</span><strong><?= (int) $summary['rental_count'] ?></strong></article>
                <article class="rental-summary-ongoing"><span>Ongoing rentals</span><strong><?= (int) $summary['ongoing_count'] ?></strong></article>
                <article class="rental-summary-returned"><span>Returned</span><strong><?= (int) $summary['returned_count'] ?></strong></article>
            </section>

            <section class="rental-financial-summary" aria-label="Rental financial summary">
                <article><span>Total rental revenue</span><strong>₱<?= number_format((float) $summary['revenue'], 2) ?></strong></article>
                <article><span>Total deposits collected</span><strong>₱<?= number_format((float) $summary['deposits_collected'], 2) ?></strong></article>
                <article><span>Total deposits refunded</span><strong>₱<?= number_format((float) $summary['deposits_refunded'], 2) ?></strong></article>
                <article><span>Total damage/late charges</span><strong>₱<?= number_format((float) $summary['return_charges'], 2) ?></strong></article>
            </section>
        </div>

        <section class="admin-product-list rental-history-panel" aria-labelledby="rental-record-list-heading">
            <div class="rental-history-heading">
                <h2 id="rental-record-list-heading">Rental history</h2>
                <p id="rental-record-result-count" aria-live="polite"><?= count($records) ?> <?= count($records) === 1 ? 'reservation' : 'reservations' ?></p>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-product-table rental-record-table">
                    <thead>
                        <tr>
                            <th scope="col">Reservation no.</th>
                            <th scope="col">Customer name</th>
                            <th scope="col">Item(s)</th>
                            <th scope="col">Rental date</th>
                            <th scope="col">Return date</th>
                            <th scope="col">Rental</th>
                            <th scope="col">Deposit</th>
                            <th scope="col">Damage charge</th>
                            <th scope="col">Late charge</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <?php
                                $searchText = strtolower(implode(' ', [
                                    $record['reservation_number'],
                                    $record['customer_name'],
                                    $record['username'],
                                    implode(' ', $record['items']),
                                ]));
                                $itemCounts = array_count_values($record['items']);
                                $itemSummary = [];
                                foreach ($itemCounts as $itemName => $itemCount) {
                                    $itemSummary[] = $itemName . ' (' . $itemCount . ')';
                                }
                            ?>
                            <tr
                                data-rental-row
                                data-search="<?= escape_html($searchText) ?>"
                                data-status="<?= escape_html($record['status_filter']) ?>"
                                data-pickup-date="<?= escape_html($record['pickup_date']) ?>"
                            >
                                <td data-label="Reservation no."><?= escape_html($record['reservation_number']) ?></td>
                                <td data-label="Customer name">
                                    <?= escape_html($record['customer_name']) ?>
                                    <span class="reservation-customer-username">@<?= escape_html($record['username']) ?></span>
                                </td>
                                <td data-label="Item(s)"><?= escape_html(implode(', ', $itemSummary)) ?></td>
                                <td data-label="Rental date"><?= escape_html(date('M j, Y', strtotime($record['pickup_date']))) ?></td>
                                <td data-label="Return date"><?= escape_html(date('M j, Y', strtotime($record['return_date']))) ?></td>
                                <td data-label="Rental">₱<?= number_format((float) $record['rental_total'], 2) ?></td>
                                <td data-label="Deposit">₱<?= number_format((float) $record['deposit_total'], 2) ?></td>
                                <td data-label="Damage charge">₱<?= number_format((float) $record['damage_charge'], 2) ?></td>
                                <td data-label="Late charge">₱<?= number_format((float) $record['late_charge'], 2) ?></td>
                                <td data-label="Status"><span class="rental-record-status rental-record-status-<?= escape_html($record['status_filter']) ?>"><?= escape_html($record['display_status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="empty-state rental-record-empty"<?= $records === [] ? '' : ' hidden' ?>>No rental records have been created yet.</p>
            <p class="empty-state rental-record-empty" data-filter-empty hidden>No reservations match these filters.</p>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
    <script>
        const rentalRows = Array.from(document.querySelectorAll('[data-rental-row]'));
        const rentalSearch = document.getElementById('rental-record-search');
        const rentalStatus = document.getElementById('rental-record-status');
        const rentalDate = document.getElementById('rental-record-date');
        const rentalResultCount = document.getElementById('rental-record-result-count');
        const noRentalResults = document.querySelector('[data-filter-empty]');
        const today = new Date('<?= escape_html(date('Y-m-d')) ?>T00:00:00');

        function filterRentalRecords() {
            const query = rentalSearch.value.trim().toLocaleLowerCase();
            const status = rentalStatus.value;
            const dateRange = rentalDate.value;
            let visibleCount = 0;

            rentalRows.forEach((row) => {
                const matchesSearch = row.dataset.search.includes(query);
                const matchesStatus = status === 'all' || row.dataset.status === status;
                const pickupDate = new Date(`${row.dataset.pickupDate}T00:00:00`);
                let matchesDate = true;

                if (dateRange === 'year') {
                    matchesDate = pickupDate.getFullYear() === today.getFullYear();
                } else if (dateRange !== 'all') {
                    const dayCount = Number(dateRange);
                    const earliestDate = new Date(today);
                    earliestDate.setDate(earliestDate.getDate() - dayCount);
                    matchesDate = pickupDate >= earliestDate && pickupDate <= today;
                }

                const isVisible = matchesSearch && matchesStatus && matchesDate;
                row.hidden = !isVisible;
                if (isVisible) {
                    visibleCount++;
                }
            });

            const countLabel = visibleCount === 1 ? 'reservation' : 'reservations';
            rentalResultCount.textContent = `${visibleCount} ${countLabel}`;
            noRentalResults.hidden = visibleCount !== 0 || rentalRows.length === 0;
        }

        rentalSearch.addEventListener('input', filterRentalRecords);
        rentalStatus.addEventListener('change', filterRentalRecords);
        rentalDate.addEventListener('change', filterRentalRecords);
    </script>
</body>
</html>
