<?php

$accountRole = (string) ($_SESSION['user_role'] ?? '');
$accountName = trim((string) ($_SESSION['user_name'] ?? ''));
$accountPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$accountInitials = '';
foreach (preg_split('/\s+/', $accountName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $namePart) {
    $accountInitials .= strtoupper(substr($namePart, 0, 1));
    if (strlen($accountInitials) >= 2) {
        break;
    }
}
if ($accountInitials === '') {
    $accountInitials = strtoupper(substr($accountRole, 0, 1));
}

$accountNavigation = match ($accountRole) {
    'admin' => [
        ['label' => 'Add products', 'href' => 'inventory.php', 'icon' => 'inventory', 'page' => 'inventory.php'],
        ['label' => 'Rental records', 'href' => 'rental_records.php', 'icon' => 'records', 'page' => 'rental_records.php'],
        ['label' => 'Manage accounts', 'href' => 'manage_users.php', 'icon' => 'accounts', 'page' => 'manage_users.php'],
    ],
    'staff' => [
        ['label' => 'View products', 'href' => 'inventory.php', 'icon' => 'inventory', 'page' => ['inventory.php', 'product_view.php']],
        ['label' => 'Reservations', 'href' => 'reservations.php', 'icon' => 'records', 'page' => 'reservations.php'],
        ['label' => 'Payments', 'href' => 'payments.php', 'icon' => 'records', 'page' => 'payments.php'],
    ],
    'customer' => [
        ['label' => 'Edit account', 'href' => 'profile.php', 'icon' => 'accounts', 'page' => 'profile.php'],
        ['label' => 'My reservations', 'href' => 'reservations.php', 'icon' => 'records', 'page' => 'reservations.php'],
        ['label' => 'Payments', 'href' => 'payments.php', 'icon' => 'records', 'page' => 'payments.php'],
    ],
    default => [],
};

$accountSection = $accountRole === 'customer' ? 'costumer' : $accountRole;
$accountPrefix = '../' . $accountSection . '/';
$isQrTagPage = $accountPage === 'product_tag.php' && array_key_exists('id', $_GET);
?>
<header class="shop-header account-topbar<?= $isQrTagPage ? ' qr-screen-only' : '' ?>">
    <a class="shop-brand" href="../index.php"><img class="shop-brand-logo" src="../image/Angel%20Logo.png" alt="">Angels Bridal Rental Shop</a>
    <nav class="shop-nav" aria-label="Main navigation">
        <a href="../index.php#home">Home</a>
        <a href="../index.php#collections">Collections</a>
        <a href="../faqs.php">FAQs</a>
    </nav>
    <div class="shop-header-actions">
        <?php $notificationBasePath = '../'; require __DIR__ . '/notification_bell.php'; ?>
        <details class="navigation-dropdown">
            <summary aria-label="Open site navigation" title="Site navigation">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <circle cx="12" cy="5" r="1.8"></circle>
                    <circle cx="12" cy="12" r="1.8"></circle>
                    <circle cx="12" cy="19" r="1.8"></circle>
                </svg>
            </summary>
            <nav class="navigation-menu-panel" aria-label="Main navigation">
                <a href="../index.php#home">Home</a>
                <a href="../index.php#collections">Collections</a>
                <a href="../faqs.php">FAQs</a>
            </nav>
        </details>
        <details class="profile-dropdown">
            <summary aria-label="<?= 'Account for ' . escape_html($accountName !== '' ? $accountName : ucfirst($accountRole)) . ', ' . escape_html(ucfirst($accountRole)) ?>">
                <span class="profile-avatar" aria-hidden="true"><?= escape_html($accountInitials) ?></span>
                <span class="profile-summary-copy">
                    <span class="profile-name"><?= $accountName !== '' ? escape_html($accountName) : escape_html(ucfirst($accountRole)) ?></span>
                    <span class="profile-role"><?= escape_html(ucfirst($accountRole)) ?></span>
                </span>
            </summary>
            <div class="profile-menu-panel">
                <p>Signed in as <strong><?= escape_html(ucfirst($accountRole)) ?></strong></p>
                <a href="../auth/logout.php">Sign out</a>
            </div>
        </details>
    </div>
</header>
<?php
$accountFlash = $_SESSION['store_message'] ?? null;
unset($_SESSION['store_message']);
$accountFlashType = is_array($accountFlash) ? (string) ($accountFlash['type'] ?? 'info') : 'info';
if (!in_array($accountFlashType, ['success', 'error', 'warning', 'info'], true)) {
    $accountFlashType = 'info';
}
?>
<?php if (is_array($accountFlash) && is_string($accountFlash['text'] ?? null) && trim($accountFlash['text']) !== ''): ?>
    <div class="store-notice store-notice-<?= escape_html($accountFlashType) ?>" role="<?= $accountFlashType === 'error' ? 'alert' : 'status' ?>"><?= escape_html($accountFlash['text']) ?></div>
<?php endif; ?>
<div class="account-layout">
    <aside class="account-sidebar<?= $isQrTagPage ? ' qr-screen-only' : '' ?>">
        <div class="account-sidebar-header">
            <button class="account-sidebar-toggle" type="button" aria-label="Expand account sidebar" aria-expanded="false" aria-controls="account-sidebar-navigation">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
        </div>
        <p class="account-sidebar-heading"><?= escape_html(ucfirst($accountRole)) ?> menu</p>
        <nav id="account-sidebar-navigation" aria-label="<?= escape_html(ucfirst($accountRole)) ?> navigation">
            <?php foreach ($accountNavigation as $item): ?>
                <?php $isActiveAccountPage = in_array($accountPage, (array) $item['page'], true); ?>
                <a class="account-nav-link" href="<?= escape_html($accountPrefix . $item['href']) ?>" aria-label="<?= escape_html($item['label']) ?>"<?= $isActiveAccountPage ? ' aria-current="page"' : '' ?>>
                    <svg class="account-nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <?php if ($item['icon'] === 'inventory'): ?>
                            <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"></path>
                            <path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9M8 5.3l8 4.5"></path>
                        <?php elseif ($item['icon'] === 'records'): ?>
                            <path d="M7 3h8l4 4v14H5V3h2Z"></path>
                            <path d="M14 3v5h5M8 12h8M8 16h8"></path>
                        <?php else: ?>
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M4 21a8 8 0 0 1 16 0"></path>
                        <?php endif; ?>
                    </svg>
                    <span><?= escape_html($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="account-main">
