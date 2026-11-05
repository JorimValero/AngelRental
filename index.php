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
$search = trim((string) ($_GET['q'] ?? ''));
$selectedCollection = trim((string) ($_GET['collection'] ?? ''));

function render_product_card(array $product, bool $canFavourite, string $csrfToken, string $returnUrl): void
{
    $escape = 'escape_html';
    $categoryClass = preg_replace('/[^a-z0-9]+/', '-', strtolower($product['category']));
    $isFavourite = (bool) $product['is_favourite'];
    $isReserved = (bool) ($product['is_reserved'] ?? false);
    $isAvailable = $product['status'] === 'available' && !$isReserved;
    $count = (int) $product['favourites_count'];
    $countLabel = $count . ($count === 1 ? ' favourite' : ' favourites');
    $thumbnailImage = (string) ($product['thumbnail_image'] ?? '');
    ?>
    <article class="style-card">
        <a class="style-card-link" href="product.php?id=<?= (int) $product['product_id'] ?>">
            <div class="style-art style-art-<?= $escape($categoryClass) ?><?= $thumbnailImage !== '' ? ' has-product-thumbnail' : '' ?>" aria-hidden="true">
                <?php if ($thumbnailImage !== ''): ?>
                    <img class="product-thumbnail" src="<?= $escape($thumbnailImage) ?>" alt="">
                <?php else: ?>
                    <span class="style-art-shape"></span>
                <?php endif; ?>
                <span class="style-art-label <?= $isReserved ? 'status-reserved' : ($isAvailable ? 'status-available' : 'status-unavailable') ?>"><?= $isReserved ? 'Reserved' : ($isAvailable ? 'Available' : 'Not available') ?></span>
            </div>
            <div class="style-card-copy">
                <p class="style-category"><?= $escape($product['category']) ?></p>
                <h3><?= $escape($product['product_name']) ?></h3>
                <p class="style-description"><?= $escape($product['description']) ?></p>
                <div class="product-card-meta">
                    <span>Size <?= $escape($product['size']) ?></span>
                    <span>₱<?= number_format((float) $product['rental_price'], 2) ?> / day</span>
                </div>
            </div>
        </a>
        <?php if ($canFavourite): ?>
            <form class="product-favourite" method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="toggle_favourite">
                <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                <input type="hidden" name="return_to" value="<?= $escape($returnUrl) ?>">
                <input type="hidden" name="scroll_position" value="0">
                <button
                    class="favourite-button<?= $isFavourite ? ' is-favourite' : '' ?>"
                    type="submit"
                    aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>; <?= $escape($countLabel) ?>"
                    aria-pressed="<?= $isFavourite ? 'true' : 'false' ?>"
                    title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"
                >
                    <svg class="favourite-heart" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M20.8 4.7a5.5 5.5 0 0 0-7.8 0L12 5.8l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.4 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path>
                    </svg>
                    <span><?= $count ?></span>
                </button>
            </form>
        <?php else: ?>
            <a class="product-favourite favourite-button favourite-login" href="auth/login.php" aria-label="<?= $escape($countLabel) ?>. Sign in to add to favourites" title="Sign in to add to favourites">
                <svg class="favourite-heart" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M20.8 4.7a5.5 5.5 0 0 0-7.8 0L12 5.8l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.4 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path>
                </svg>
                <span><?= $count ?></span>
            </a>
        <?php endif; ?>
    </article>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canFavourite || ($_POST['action'] ?? '') !== 'toggle_favourite') {
        http_response_code($canFavourite ? 400 : 403);
        exit('You must be signed in to favourite a product.');
    }

    unset($_SESSION['store_message']);
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['store_message'] = ['type' => 'error', 'text' => 'Your session expired. Refresh the page and try again.'];
    } else {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($productId === false) {
            $_SESSION['store_message'] = ['type' => 'error', 'text' => 'That product could not be found.'];
        } else {
            $connection = get_database_connection();
            try {
                $connection->beginTransaction();
                $productStatement = $connection->prepare(
                    'SELECT product_id FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $productStatement->execute(['product_id' => $productId]);
                if (!$productStatement->fetch()) {
                    throw new RuntimeException('That product is no longer available.');
                }

                $deleteStatement = $connection->prepare(
                    'DELETE FROM favorites WHERE user_id = :user_id AND product_id = :product_id'
                );
                $deleteStatement->execute([
                    'user_id' => $userId,
                    'product_id' => $productId,
                ]);
                if ($deleteStatement->rowCount() === 0) {
                    $insertStatement = $connection->prepare(
                        'INSERT INTO favorites (user_id, product_id) VALUES (:user_id, :product_id)'
                    );
                    $insertStatement->execute([
                        'user_id' => $userId,
                        'product_id' => $productId,
                    ]);
                }
                $connection->commit();
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                show_database_error($exception);
                $_SESSION['store_message'] = ['type' => 'error', 'text' => 'We could not update your favourites. Please try again.'];
            } catch (RuntimeException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $_SESSION['store_message'] = ['type' => 'error', 'text' => $exception->getMessage()];
            }
        }
    }

    $submittedReturnTo = (string) ($_POST['return_to'] ?? '');
    $submittedReturnPath = parse_url($submittedReturnTo, PHP_URL_PATH);
    $submittedReturnQuery = parse_url($submittedReturnTo, PHP_URL_QUERY);
    $returnParameters = [];
    if (is_string($submittedReturnQuery)) {
        parse_str($submittedReturnQuery, $returnParameters);
    }
    if ($submittedReturnPath === 'product.php') {
        $returnProductId = filter_var($returnParameters['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($returnProductId !== false) {
            header('Location: product.php?id=' . $returnProductId);
            exit;
        }
    }

    $returnQuery = [];
    if (isset($returnParameters['collection']) && is_string($returnParameters['collection'])) {
        $returnQuery['collection'] = substr($returnParameters['collection'], 0, 80);
    }
    if (isset($returnParameters['q']) && is_string($returnParameters['q'])) {
        $returnQuery['q'] = substr($returnParameters['q'], 0, 254);
    }
    $scrollPosition = filter_var($_POST['scroll_position'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0],
    ]);
    if ($scrollPosition !== false) {
        $returnQuery['scroll'] = $scrollPosition;
    }
    $returnUrl = 'index.php' . ($returnQuery !== [] ? '?' . http_build_query($returnQuery) : '') . '#collections';
    header('Location: ' . $returnUrl);
    exit;
}

$storeMessage = $_SESSION['store_message'] ?? null;
unset($_SESSION['store_message']);

$products = [];
$categories = [];
$storeError = '';
$connection = null;

try {
    $connection = get_database_connection();
    $categories = $connection->query(
        'SELECT DISTINCT category FROM products ORDER BY category'
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($selectedCollection !== '' && !in_array($selectedCollection, $categories, true)) {
        $selectedCollection = '';
    }

    $conditions = [];
    $parameters = ['user_id' => $userId ?? 0];
    if ($selectedCollection !== '') {
        $conditions[] = 'p.category = :category';
        $parameters['category'] = $selectedCollection;
    }
    if ($search !== '') {
        $conditions[] = '(p.product_name LIKE :name_search OR p.category LIKE :category_search OR p.description LIKE :description_search OR p.size LIKE :size_search)';
        $searchTerm = '%' . $search . '%';
        $parameters['name_search'] = $searchTerm;
        $parameters['category_search'] = $searchTerm;
        $parameters['description_search'] = $searchTerm;
        $parameters['size_search'] = $searchTerm;
    }

    $selectSql = 'SELECT p.*,
                (SELECT COUNT(*) FROM favorites f WHERE f.product_id = p.product_id) AS favourites_count,
                (SELECT pi.image_path FROM product_images pi
                 WHERE pi.product_id = p.product_id
                 ORDER BY pi.is_thumbnail DESC, pi.sort_order, pi.image_id LIMIT 1) AS thumbnail_image,
                (
                    EXISTS(
                    SELECT 1 FROM reservations active_hold
                    WHERE active_hold.product_id = p.product_id
                      AND (
                          (active_hold.status = \'pending\' AND active_hold.expires_at > CURRENT_TIMESTAMP)
                          OR active_hold.status IN (\'accepted\', \'checked_out\', \'paid\')
                      )
                    )
                    OR EXISTS(
                        SELECT 1 FROM reservation_cart_holds cart_hold
                        WHERE cart_hold.product_id = p.product_id
                          AND cart_hold.expires_at > CURRENT_TIMESTAMP
                    )
                ) AS is_reserved,
                EXISTS(
                    SELECT 1 FROM favorites mine
                    WHERE mine.product_id = p.product_id AND mine.user_id = :user_id
                ) AS is_favourite
            FROM products p';
    $whereSql = '';
    if ($conditions !== []) {
        $whereSql = ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql = $selectSql . $whereSql . ' ORDER BY p.created_at DESC, p.product_id DESC';

    $productStatement = $connection->prepare($sql);
    $productStatement->execute($parameters);
    $products = $productStatement->fetchAll();

} catch (PDOException $exception) {
    show_database_error($exception);
    $storeError = 'The product catalog is temporarily unavailable. Please try again later.';
}

$returnQuery = [];
if ($selectedCollection !== '') {
    $returnQuery['collection'] = $selectedCollection;
}
if ($search !== '') {
    $returnQuery['q'] = $search;
}
$returnUrl = 'index.php' . ($returnQuery !== [] ? '?' . http_build_query($returnQuery) : '') . '#collections';
$csrfToken = $canFavourite ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Discover wedding gowns, bridesmaid styles and special-occasion accessories at Angels Bridal Rental Shop.">
    <title>Angels Bridal Rental Shop | Find your special-day look</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="storefront<?= $search !== '' || $selectedCollection !== '' ? ' has-catalog-filter' : '' ?>">
    <header class="shop-header">
        <a class="shop-brand" href="index.php"><img class="shop-brand-logo" src="image/Angel%20Logo.png" alt="">Angels Bridal Rental Shop</a>
        <nav class="shop-nav" aria-label="Main navigation">
            <a href="#home">Home</a>
            <a href="#collections">Collections</a>
            <a href="faqs.php">FAQs</a>
        </nav>
        <div class="shop-header-actions">
            <?php $notificationBasePath = ''; require __DIR__ . '/includes/notification_bell.php'; ?>
            <details class="navigation-dropdown">
                <summary aria-label="Open site navigation" title="Site navigation">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="5" r="1.8"></circle>
                        <circle cx="12" cy="12" r="1.8"></circle>
                        <circle cx="12" cy="19" r="1.8"></circle>
                    </svg>
                </summary>
                <nav class="navigation-menu-panel" aria-label="Main navigation">
                    <a href="#home">Home</a>
                    <a href="#collections">Collections</a>
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

    <main>
        <section class="shop-hero" id="home">
            <div class="hero-copy">
                <h1 class="hero-story-heading">Here for your once-in-a-lifetime moments.</h1>
                <p>Whether it’s your wedding day, a special celebration, or a moment you’ve been looking forward to, we’re here to help you find the outfit that feels just right.</p>
                <a class="shop-button" href="#collections">Explore collections</a>
            </div>
            <div class="hero-art" aria-label="A curated bridalwear collection preview" role="img">
                <div class="hero-art-card hero-art-card-back">
                    <span>THE DETAILS</span>
                    <img class="hero-art-photo" src="image/details.png" alt="">
                </div>
                <div class="hero-art-card hero-art-card-front">
                    <span>MADE FOR YOUR DAY</span>
                    <img class="hero-art-photo" src="image/ring.jpg" alt="">
                </div>
                <div class="hero-art-card hero-art-card-flowers">
                    <span>A TOUCH OF BLOOM</span>
                    <img class="hero-art-photo" src="image/flower.jpg" alt="">
                </div>
            </div>
        </section>

        <section class="how-it-works" aria-labelledby="how-heading">
            <h2 id="how-heading">How it works</h2>
            <ol class="steps-list">
                <li><span class="step-number">01</span><strong>Browse Collections</strong><p>Explore gowns and accessories.</p></li>
                <li><span class="step-number">02</span><strong>Reserve Your Outfit</strong><p>Choose your favorite and reserve it.</p></li>
                <li><span class="step-number">03</span><strong>Pick Up and Enjoy</strong><p>Collect your outfit and enjoy your event.</p></li>
                <li><span class="step-number">04</span><strong>Return Your Outfit</strong><p>Return your outfit on time.</p></li>
            </ol>
        </section>

        <?php if (is_array($storeMessage)): ?>
            <div class="store-notice store-notice-<?= escape_html((string) ($storeMessage['type'] ?? 'success')) ?>" role="<?= ($storeMessage['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"><?= escape_html((string) ($storeMessage['text'] ?? '')) ?></div>
        <?php endif; ?>
        <?php if ($storeError !== ''): ?>
            <div class="store-notice store-notice-error" role="alert"><?= escape_html($storeError) ?></div>
        <?php endif; ?>

        <section class="catalog-section collections-section" id="collections" aria-labelledby="collections-heading">
            <div class="section-heading collections-heading">
                <div>
                    <p class="shop-kicker">Find something you love</p>
                    <h2 id="collections-heading">All collections (<span class="result-count" aria-label="<?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?>"><?= count($products) ?></span>)</h2>
                </div>
                <form class="shop-search collection-search" method="get" action="index.php#collections" role="search">
                    <?php if ($selectedCollection !== ''): ?>
                        <input type="hidden" name="collection" value="<?= escape_html($selectedCollection) ?>">
                    <?php endif; ?>
                    <label class="visually-hidden" for="shop-search">Search products and collections</label>
                    <input id="shop-search" name="q" type="search" placeholder="Search products" value="<?= escape_html($search) ?>">
                    <button type="submit">Search</button>
                </form>
            </div>
            <div class="collection-toolbar">
                <?php if ($categories !== []): ?>
                    <nav class="collection-filters" aria-label="Filter collections">
                        <a class="<?= $selectedCollection === '' ? 'is-active' : '' ?>" href="index.php<?= $search !== '' ? '?q=' . rawurlencode($search) : '' ?>#collections" <?= $selectedCollection === '' ? 'aria-current="true"' : '' ?>>All</a>
                        <?php foreach ($categories as $category): ?>
                            <?php $filterQuery = ['collection' => $category]; if ($search !== '') { $filterQuery['q'] = $search; } ?>
                            <a class="<?= $selectedCollection === $category ? 'is-active' : '' ?>" href="index.php?<?= escape_html(http_build_query($filterQuery)) ?>#collections" <?= $selectedCollection === $category ? 'aria-current="true"' : '' ?>><?= escape_html($category) ?></a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>
            <?php if ($products !== []): ?>
                <div class="style-grid collection-grid">
                    <?php foreach ($products as $product): ?>
                        <?php render_product_card($product, $canFavourite, $csrfToken, $returnUrl); ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-state"><?= $storeError !== '' ? 'The catalog could not be loaded.' : (($search !== '' || $selectedCollection !== '') ? 'No products matched your search or collection filter. Try a different term or choose All.' : 'No products are available yet. Please check back soon.') ?><?php if ($isAdmin && $search === '' && $selectedCollection === ''): ?> <a href="admin/inventory.php">Add products</a>.<?php endif; ?></p>
            <?php endif; ?>
        </section>
    </main>

    <?php
    $showLandingContactDetails = true;
    require __DIR__ . '/includes/footer.php';
    ?>
    <script>
        document.querySelectorAll('form.product-favourite input[name="scroll_position"]').forEach((scrollInput) => {
            scrollInput.form.addEventListener('submit', () => {
                scrollInput.value = String(window.scrollY);
            });
        });

        const pageUrl = new URL(window.location.href);
        const savedScrollPosition = pageUrl.searchParams.get('scroll');
        if (savedScrollPosition !== null && /^\d+$/.test(savedScrollPosition)) {
            const scrollPosition = Number(savedScrollPosition);
            if (Number.isSafeInteger(scrollPosition)) {
                window.history.scrollRestoration = 'manual';
                requestAnimationFrame(() => {
                    window.scrollTo(0, scrollPosition);
                    pageUrl.searchParams.delete('scroll');
                    window.history.replaceState(null, '', pageUrl.pathname + pageUrl.search + pageUrl.hash);
                });
            }
        }
    </script>
</body>
</html>
