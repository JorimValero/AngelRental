<?php

require_once __DIR__ . '/auth/helpers.php';

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$isStaff = ($_SESSION['user_role'] ?? '') === 'staff';
$canFavourite = $userId !== null;
$profileName = trim((string) ($_SESSION['user_name'] ?? ''));
$profileRole = ucfirst((string) ($_SESSION['user_role'] ?? 'Guest'));
$profileInitials = '';
foreach (preg_split('/\s+/', $profileName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $namePart) {
    $profileInitials .= strtoupper(substr($namePart, 0, 1));
    if (strlen($profileInitials) >= 2) {
        break;
    }
}
if ($profileInitials === '') {
    $profileInitials = 'G';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Answers to common questions about renting from Angels Bridal Rental Shop.">
    <title>FAQs | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="storefront faq-page">
    <header class="shop-header">
        <a class="shop-brand" href="index.php"><img class="shop-brand-logo" src="image/Angel%20Logo.png" alt="">Angels Bridal Rental Shop</a>
        <nav class="shop-nav" aria-label="Main navigation">
            <a href="index.php#home">Home</a>
            <a href="index.php#collections">Collections</a>
            <a href="faqs.php" aria-current="page">FAQs</a>
        </nav>
        <div class="shop-header-actions">
            <details class="navigation-dropdown">
                <summary aria-label="Open site navigation" title="Site navigation">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="5" r="1.8"></circle>
                        <circle cx="12" cy="12" r="1.8"></circle>
                        <circle cx="12" cy="19" r="1.8"></circle>
                    </svg>
                </summary>
                <nav class="navigation-menu-panel" aria-label="Main navigation">
                    <a href="index.php#home">Home</a>
                    <a href="index.php#collections">Collections</a>
                    <a href="faqs.php" aria-current="page">FAQs</a>
                </nav>
            </details>
            <?php if ($canFavourite): ?>
                <details class="profile-dropdown">
                    <summary aria-label="<?= 'Account for ' . escape_html($profileName) . ', ' . escape_html($profileRole) ?>">
                        <span class="profile-avatar" aria-hidden="true"><?= escape_html($profileInitials) ?></span>
                        <span class="profile-summary-copy">
                            <span class="profile-name"><?= $profileName !== '' ? escape_html($profileName) : escape_html($profileRole) ?></span>
                            <span class="profile-role"><?= escape_html($profileRole) ?></span>
                        </span>
                    </summary>
                    <div class="profile-menu-panel">
                        <p>Signed in as <strong><?= escape_html($profileRole) ?></strong></p>
                        <?php if ($isAdmin): ?>
                            <a href="admin/inventory.php">Add products</a>
                            <a href="admin/rental_records.php">Rental records</a>
                            <a href="admin/manage_users.php">Manage accounts</a>
                        <?php elseif ($isStaff): ?>
                            <a href="staff/inventory.php">View products</a>
                            <a href="staff/reservations.php">Reservations</a>
                        <?php else: ?>
                            <a href="costumer/profile.php">My account</a>
                            <a href="costumer/reservations.php">My reservations</a>
                        <?php endif; ?>
                        <a href="auth/logout.php">Sign out</a>
                    </div>
                </details>
            <?php else: ?>
                <a class="header-login-link" href="auth/login.php">Log In</a>
            <?php endif; ?>
        </div>
    </header>
    <main class="faq-content">
        <p class="shop-kicker">Need a little help?</p>
        <h1>Frequently Asked Questions</h1>
        <p class="faq-intro">Everything you need to know before renting your perfect look.</p>
        <section class="faq-list" aria-label="Frequently asked questions">
            <details class="faq-item" open>
                <summary>How do I reserve an item?</summary>
                <p>Browse our collections, select an item, choose your pickup and return dates, and submit the reservation request. You can review your reservation from your account.</p>
            </details>
            <details class="faq-item">
                <summary>Is the rental deposit refundable?</summary>
                <p>The security deposit is refundable after the item is returned and checked, less any applicable damage or other charges.</p>
            </details>
            <details class="faq-item">
                <summary>What happens if an item is damaged?</summary>
                <p>Staff will assess and record damage when the item is returned. Any applicable damage charge will be recorded with your rental.</p>
            </details>
        </section>
    </main>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
