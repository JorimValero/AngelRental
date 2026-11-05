<?php

require_once __DIR__ . '/../auth/helpers.php';
require_authenticated_role('staff');

$productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$showProductList = !array_key_exists('id', $_GET);
$products = [];
$error = '';
$product = null;

if (!$showProductList && $productId === false) {
    http_response_code(404);
    exit('Product not found.');
}

try {
    $connection = get_database_connection();
    if ($showProductList) {
        $statement = $connection->query(
            'SELECT product_id, product_name, category, size
             FROM products ORDER BY product_name, product_id'
        );
        $products = $statement->fetchAll();
    } else {
        $statement = $connection->prepare(
            'SELECT product_id, product_name, category, size, qr_code
             FROM products WHERE product_id = :product_id'
        );
        $statement->execute(['product_id' => $productId]);
        $product = $statement->fetch();
    }
} catch (PDOException $exception) {
    show_database_error($exception);
    if ($showProductList) {
        $error = 'The product list could not be loaded. Please try again later.';
    } else {
        http_response_code(503);
        exit('The product tag is temporarily unavailable.');
    }
}

if (!$showProductList && !$product) {
    http_response_code(404);
    exit('Product not found.');
}

$staffProductUrl = '';
if (!$showProductList) {
    $applicationUrl = trim((string) (getenv('RENTAL_APP_URL') ?: ''));
    $configuredUrlParts = $applicationUrl !== '' ? parse_url($applicationUrl) : false;
    $configuredHost = is_array($configuredUrlParts)
        ? strtolower((string) ($configuredUrlParts['host'] ?? ''))
        : '';
    $configuredForLocalhost = in_array($configuredHost, ['localhost', '::1'], true)
        || preg_match('/\A127(?:\.\d{1,3}){3}\z/', $configuredHost) === 1;
    if ($applicationUrl === '' || $configuredForLocalhost) {
        $applicationUrl = 'https://fanfare-wow-harmonics.ngrok-free.dev/RentalSystem';
    }
    $applicationUrl = rtrim($applicationUrl, '/');
    if ($applicationUrl !== '') {
        $urlParts = parse_url($applicationUrl);
        if (
            !is_array($urlParts)
            || !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($urlParts['host'])
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
            || isset($urlParts['query'])
            || isset($urlParts['fragment'])
        ) {
            http_response_code(500);
            exit('The configured application URL cannot be used to create a QR tag.');
        }

        $applicationPath = rtrim((string) ($urlParts['path'] ?? ''), '/');
        if (strtolower(basename($applicationPath)) === 'index.php') {
            $applicationPath = rtrim(dirname($applicationPath), '/.');
        }
        $applicationHost = $urlParts['host']
            . (isset($urlParts['port']) ? ':' . $urlParts['port'] : '');
        $applicationUrl = $urlParts['scheme'] . '://' . $applicationHost . $applicationPath;
    } else {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/\A(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?\z/', $host)) {
            http_response_code(400);
            exit('The current site address cannot be used to create a QR tag.');
        }
        $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/staff/product_tag.php'));
        $projectPath = rtrim(dirname(dirname($scriptPath)), '/');
        if ($projectPath === '.' || $projectPath === '/') {
            $projectPath = '';
        }
        $forwardedProto = strtolower(trim(explode(
            ',',
            (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
        )[0]));
        $scheme = in_array($forwardedProto, ['http', 'https'], true)
            ? $forwardedProto
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $applicationUrl = $scheme . '://' . $host . $projectPath;
    }

    $staffProductUrl = $applicationUrl . '/staff/product_view.php?qr=' . rawurlencode((string) $product['qr_code']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $showProductList ? 'Print QR tags' : 'Product QR tag' ?> | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
    <?php if (!$showProductList): ?>
        <script src="../javascript/qr.bundle.js" defer></script>
    <?php endif; ?>
</head>
<body class="storefront account-page<?= $showProductList ? ' inventory-page staff-qr-tags-page' : ' qr-print-page' ?>">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <?php if ($showProductList): ?>
        <main class="admin-inventory">
            <div class="admin-page-heading">
                <div>
                    <p class="shop-kicker">Staff tools</p>
                    <h1>Print QR tags</h1>
                </div>
                <p><?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?></p>
            </div>
            <?php if ($error !== ''): ?>
                <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>
            <section class="admin-product-list" aria-labelledby="qr-tag-list-heading">
                <h2 id="qr-tag-list-heading">Choose a product</h2>
                <?php if ($products !== []): ?>
                    <div class="admin-table-wrap">
                        <table class="admin-product-table">
                            <thead>
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Size</th>
                                    <th scope="col">QR tag</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $listedProduct): ?>
                                    <tr>
                                        <td data-label="Product"><?= escape_html($listedProduct['product_name']) ?></td>
                                        <td data-label="Category"><?= escape_html($listedProduct['category']) ?></td>
                                        <td data-label="Size"><?= escape_html($listedProduct['size']) ?></td>
                                        <td data-label="QR tag">
                                            <a class="text-link" href="product_tag.php?id=<?= (int) $listedProduct['product_id'] ?>">Print QR tag</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($error === ''): ?>
                    <p class="empty-state">There are no products to print QR tags for.</p>
                <?php endif; ?>
            </section>
        </main>
    <?php else: ?>
        <main class="product-tag-main">
            <article class="product-tag-card">
                <p class="shop-kicker">Angels Bridal Rental Shop · Staff product tag</p>
                <h1><?= escape_html($product['product_name']) ?></h1>
                <p class="product-tag-meta"><?= escape_html($product['category']) ?> · Size <?= escape_html($product['size']) ?></p>
                <div class="product-qr-code" data-product-qr="<?= escape_html($staffProductUrl) ?>" role="img" aria-label="QR code for <?= escape_html($product['product_name']) ?>">
                    Preparing QR tag…
                </div>
                <p class="product-tag-instructions">Scan while signed in to a staff account to open this product’s staff view.</p>
                <code class="product-tag-code"><?= escape_html($product['qr_code']) ?></code>
            </article>
            <div class="product-tag-actions qr-screen-only">
                <button class="shop-button" type="button" onclick="window.print()">Print QR tag</button>
                <a class="text-link" href="inventory.php">Back to view products</a>
            </div>
        </main>
    <?php endif; ?>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>
