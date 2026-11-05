<?php

require_once __DIR__ . '/auth/helpers.php';
require_once __DIR__ . '/includes/mailer.php';

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$isStaff = ($_SESSION['user_role'] ?? '') === 'staff';
$isCustomer = ($_SESSION['user_role'] ?? '') === 'customer';
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
$productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$reservationError = '';

if ($productId === false) {
    http_response_code(404);
    exit('Product not found.');
}

try {
    $connection = get_database_connection();
    $productStatement = $connection->prepare(
        "SELECT p.*,
                EXISTS(
                    SELECT 1 FROM reservations active_hold
                    WHERE active_hold.product_id = p.product_id
                      AND (
                          (active_hold.status = 'pending' AND active_hold.expires_at > CURRENT_TIMESTAMP)
                          OR active_hold.status IN ('accepted', 'checked_out', 'paid')
                      )
                )
                OR EXISTS(
                    SELECT 1 FROM reservation_cart_holds cart_hold
                    WHERE cart_hold.product_id = p.product_id
                      AND cart_hold.expires_at > CURRENT_TIMESTAMP
                ) AS is_reserved
         FROM products p
         WHERE p.product_id = :product_id"
    );
    $productStatement->execute(['product_id' => $productId]);
    $product = $productStatement->fetch();

    if (!$product) {
        http_response_code(404);
        exit('Product not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isCustomer) {
            http_response_code(403);
            exit('Only customer accounts can make reservations.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'add_to_reservation') {
            http_response_code(400);
            exit('Unsupported product action.');
        }

        if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
            $reservationError = 'Your session expired. Refresh the page and try again.';
        } else {
            try {
                $connection->beginTransaction();
                $expiredReservationStatement = $connection->query(
                    "SELECT reservation_id FROM reservations
                     WHERE status = 'pending' AND expires_at <= CURRENT_TIMESTAMP
                     ORDER BY reservation_id FOR UPDATE"
                );
                $expiredReservationIds = array_map(
                    'intval',
                    $expiredReservationStatement->fetchAll(PDO::FETCH_COLUMN)
                );
                $connection->exec(
                    "UPDATE reservations
                     SET status = 'expired'
                     WHERE status = 'pending' AND expires_at <= CURRENT_TIMESTAMP"
                );
                $expiredHoldStatement = $connection->prepare(
                    'DELETE FROM reservation_cart_holds
                     WHERE product_id = :product_id AND expires_at <= CURRENT_TIMESTAMP'
                );
                $expiredHoldStatement->execute(['product_id' => $productId]);
                $lockedProductStatement = $connection->prepare(
                    'SELECT status FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $lockedProductStatement->execute(['product_id' => $productId]);
                if ($lockedProductStatement->fetchColumn() !== 'available') {
                    throw new RuntimeException('This product is not currently available for reservations.');
                }

                $activeHoldStatement = $connection->prepare(
                    "SELECT 1 FROM reservations
                     WHERE product_id = :product_id
                       AND (
                           (status = 'pending' AND expires_at > CURRENT_TIMESTAMP)
                           OR status IN ('accepted', 'checked_out', 'paid')
                       )
                     LIMIT 1"
                );
                $activeHoldStatement->execute(['product_id' => $productId]);
                if ($activeHoldStatement->fetchColumn()) {
                    throw new RuntimeException('This product is on hold for another customer and is currently unavailable.');
                }

                $cartHoldStatement = $connection->prepare(
                    'SELECT user_id FROM reservation_cart_holds
                     WHERE product_id = :product_id AND expires_at > CURRENT_TIMESTAMP
                     FOR UPDATE'
                );
                $cartHoldStatement->execute(['product_id' => $productId]);
                $cartHoldOwner = $cartHoldStatement->fetchColumn();
                if ($cartHoldOwner !== false) {
                    $message = (int) $cartHoldOwner === (int) $userId
                        ? 'This product is already in your reservation list.'
                        : 'This product is already in another customer’s reservation list.';
                    throw new RuntimeException($message);
                }

                $reservationCart = $_SESSION['customer_reservation_cart'] ?? [];
                foreach ($reservationCart as $cartItem) {
                    if ((int) ($cartItem['product_id'] ?? 0) === $productId) {
                        throw new RuntimeException('This product is already in your reservation list.');
                    }
                }
                $insertHoldStatement = $connection->prepare(
                    'INSERT INTO reservation_cart_holds (product_id, user_id, expires_at)
                     VALUES (:product_id, :user_id, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 3 DAY))'
                );
                $insertHoldStatement->execute([
                    'product_id' => $productId,
                    'user_id' => (int) $userId,
                ]);
                $reservationCart[] = ['product_id' => $productId];
                $_SESSION['customer_reservation_cart'] = $reservationCart;
                $connection->commit();
                foreach ($expiredReservationIds as $expiredReservationId) {
                    send_customer_transaction_email(
                        $connection,
                        $expiredReservationId,
                        'Your reservation request expired',
                        'Your pending reservation expired before it was accepted. You can create a new reservation from the shop.'
                    );
                }
                $_SESSION['store_message'] = [
                    'type' => 'success',
                    'text' => 'Product added to your reservation list. Visit My reservations to choose dates and confirm.',
                ];
                header('Location: index.php#collections');
                exit;
            } catch (RuntimeException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $reservationError = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                show_database_error($exception);
                $reservationError = 'Your product could not be added. Please try again.';
            }
        }
    }

    $imageStatement = $connection->prepare(
        'SELECT image_id, image_path, is_thumbnail
         FROM product_images
         WHERE product_id = :product_id
         ORDER BY is_thumbnail DESC, sort_order, image_id'
    );
    $imageStatement->execute(['product_id' => $productId]);
    $productImages = $imageStatement->fetchAll();

    $favouriteStatement = $connection->prepare(
        'SELECT COUNT(*) AS favourites_count,
                EXISTS(
                    SELECT 1 FROM favorites
                    WHERE product_id = :product_id AND user_id = :user_id
                ) AS is_favourite
         FROM favorites
         WHERE product_id = :count_product_id'
    );
    $favouriteStatement->execute([
        'product_id' => $productId,
        'user_id' => $userId ?? 0,
        'count_product_id' => $productId,
    ]);
    $favouriteData = $favouriteStatement->fetch();
} catch (PDOException $exception) {
    show_database_error($exception);
    http_response_code(503);
    exit('The product details are temporarily unavailable.');
}

$selectedImageId = filter_var($_GET['image'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$selectedImage = null;
foreach ($productImages as $image) {
    if ($selectedImageId !== false && (int) $image['image_id'] === $selectedImageId) {
        $selectedImage = $image;
        break;
    }
}
$selectedImage ??= $productImages[0] ?? null;
$productName = (string) $product['product_name'];
$isReserved = (bool) $product['is_reserved'];
$isAvailable = $product['status'] === 'available' && !$isReserved;
$favouritesCount = (int) $favouriteData['favourites_count'];
$isFavourite = (bool) $favouriteData['is_favourite'];
$favouriteLabel = $favouritesCount . ($favouritesCount === 1 ? ' favourite' : ' favourites');
$csrfToken = $canFavourite ? csrf_token() : '';
$storeMessage = $_SESSION['store_message'] ?? null;
unset($_SESSION['store_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= escape_html($productName) ?> from Angels Bridal Rental Shop.">
    <title><?= escape_html($productName) ?> | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="storefront product-page">
    <header class="shop-header">
        <a class="shop-brand" href="index.php"><img class="shop-brand-logo" src="image/Angel%20Logo.png" alt="">Angels Bridal Rental Shop</a>
        <nav class="shop-nav" aria-label="Main navigation">
            <a href="index.php#home">Home</a>
            <a href="index.php#collections">Collections</a>
            <a href="faqs.php">FAQs</a>
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
                    <a href="faqs.php">FAQs</a>
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
    <main class="product-detail-section">
        <a class="text-link product-back-link" href="index.php#collections">← Back to collections</a>
        <?php if (is_array($storeMessage) && ($storeMessage['type'] ?? '') === 'error'): ?>
            <div class="store-notice store-notice-<?= escape_html((string) ($storeMessage['type'] ?? 'success')) ?>" role="status"><?= escape_html((string) ($storeMessage['text'] ?? '')) ?></div>
        <?php endif; ?>
        <article class="product-detail">
            <div class="product-gallery">
                <?php if ($canFavourite): ?>
                    <form class="product-favourite" method="post" action="index.php">
                        <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_favourite">
                        <input type="hidden" name="product_id" value="<?= (int) $productId ?>">
                        <input type="hidden" name="return_to" value="<?= escape_html('product.php?id=' . $productId) ?>">
                        <button
                            class="favourite-button<?= $isFavourite ? ' is-favourite' : '' ?>"
                            type="submit"
                            aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>; <?= escape_html($favouriteLabel) ?>"
                            aria-pressed="<?= $isFavourite ? 'true' : 'false' ?>"
                            title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"
                        >
                            <svg class="favourite-heart" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M20.8 4.7a5.5 5.5 0 0 0-7.8 0L12 5.8l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.4 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path>
                            </svg>
                            <span><?= $favouritesCount ?></span>
                        </button>
                    </form>
                <?php else: ?>
                    <a class="product-favourite favourite-button favourite-login" href="auth/login.php" aria-label="<?= escape_html($favouriteLabel) ?>. Sign in to add to favourites" title="Sign in to add to favourites">
                        <svg class="favourite-heart" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M20.8 4.7a5.5 5.5 0 0 0-7.8 0L12 5.8l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.4 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path>
                        </svg>
                        <span><?= $favouritesCount ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($selectedImage): ?>
                    <img
                        class="product-gallery-main"
                        id="product-gallery-main"
                        src="<?= escape_html($selectedImage['image_path']) ?>"
                        alt="<?= escape_html($productName) ?>"
                    >
                    <?php if (count($productImages) > 1): ?>
                        <div class="product-gallery-thumbnails" aria-label="More product images">
                            <?php foreach ($productImages as $imageIndex => $image): ?>
                                <button
                                    class="product-gallery-thumbnail<?= (int) $image['image_id'] === (int) $selectedImage['image_id'] ? ' is-selected' : '' ?>"
                                    type="button"
                                    data-gallery-src="<?= escape_html($image['image_path']) ?>"
                                    aria-label="Show product image <?= $imageIndex + 1 ?> of <?= count($productImages) ?>"
                                    aria-pressed="<?= (int) $image['image_id'] === (int) $selectedImage['image_id'] ? 'true' : 'false' ?>"
                                >
                                    <img src="<?= escape_html($image['image_path']) ?>" alt="">
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="style-art product-detail-placeholder" aria-hidden="true">
                        <span class="style-art-shape"></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="product-detail-copy">
                <p class="style-category"><?= escape_html($product['category']) ?></p>
                <h1><?= escape_html($productName) ?></h1>
                <div class="product-detail-price-panel">
                    <p class="product-detail-price">₱<?= number_format((float) $product['rental_price'], 2) ?> <span>/ day</span></p>
                </div>
                <div class="product-detail-facts">
                    <div class="product-detail-meta">
                        <span class="product-detail-meta-label">Availability</span>
                        <span class="product-detail-status <?= $isReserved ? 'status-reserved' : ($isAvailable ? 'status-available' : 'status-unavailable') ?>">
                            <?= $isReserved ? 'Reserved' : ($isAvailable ? 'Available' : escape_html(ucfirst($product['status']))) ?>
                        </span>
                    </div>
                    <div class="product-detail-meta product-detail-size">
                        <span class="product-detail-meta-label">Size</span>
                        <strong><?= escape_html($product['size']) ?></strong>
                    </div>
                </div>
                <section class="product-detail-description" aria-labelledby="product-description-heading">
                    <h2 id="product-description-heading">About this piece</h2>
                    <p><?= nl2br(escape_html($product['description'])) ?></p>
                </section>
                <?php if ($isCustomer): ?>
                    <section class="reservation-form-panel" id="reserve" aria-labelledby="reservation-heading">
                        <h2 id="reservation-heading">Reserve this piece</h2>
                        <p>Add this item to your reservation list, then choose pickup and return dates before confirming.</p>
                        <?php if ($reservationError !== ''): ?>
                            <p class="store-notice store-notice-error" role="alert"><?= escape_html($reservationError) ?></p>
                        <?php endif; ?>
                        <form method="post" action="product.php?id=<?= (int) $productId ?>#reserve">
                            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                            <input type="hidden" name="action" value="add_to_reservation">
                            <p class="reservation-terms">
                                You can add multiple items, then review their dates and choose a payment method before final confirmation.
                                A refundable security deposit of <strong>₱<?= number_format((float) $product['refundable_deposit'], 2) ?></strong> applies.
                                Late returns or damage may incur additional charges.
                            </p>
                            <button class="product-reserve-button" type="submit" <?= $isAvailable ? '' : 'disabled' ?>>Add to reservation</button>
                        </form>
                    </section>
                <?php elseif (!$canFavourite): ?>
                    <section class="reservation-form-panel" id="reserve" aria-labelledby="reservation-heading">
                        <h2 id="reservation-heading">Reserve this piece</h2>
                        <p>Log in to add this item to your reservation list.</p>
                        <a class="product-reserve-button" href="auth/login.php">Add to reservation</a>
                    </section>
                <?php endif; ?>
            </div>
        </article>
    </main>
    <?php require __DIR__ . '/includes/footer.php'; ?>
    <script>
        const galleryMain = document.getElementById('product-gallery-main');
        document.querySelectorAll('[data-gallery-src]').forEach((thumbnail) => {
            thumbnail.addEventListener('click', () => {
                galleryMain.src = thumbnail.dataset.gallerySrc;
                document.querySelectorAll('[data-gallery-src]').forEach((item) => {
                    const selected = item === thumbnail;
                    item.classList.toggle('is-selected', selected);
                    item.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
            });
        });
    </script>
</body>
</html>
