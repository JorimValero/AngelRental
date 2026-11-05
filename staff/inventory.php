<?php

require_once __DIR__ . '/../auth/helpers.php';
require_authenticated_role('staff');

$products = [];
$error = '';
$productSearch = substr(trim((string) ($_GET['q'] ?? '')), 0, 150);

try {
    $productSql = 'SELECT p.product_id, p.product_name, p.category, p.size,
                          p.rental_price, p.status,
                          (SELECT pi.image_path FROM product_images pi
                           WHERE pi.product_id = p.product_id
                           ORDER BY pi.is_thumbnail DESC, pi.sort_order, pi.image_id LIMIT 1) AS thumbnail_image,
                          (SELECT COUNT(*) FROM favorites f WHERE f.product_id = p.product_id) AS favourites_count,
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
                          ) AS is_reserved
                   FROM products p';
    if ($productSearch !== '') {
        $productSql .= ' WHERE p.product_name LIKE :name_search
                         OR p.category LIKE :category_search
                         OR p.size LIKE :size_search
                         OR p.description LIKE :description_search
                         OR p.qr_code LIKE :qr_search
                         OR p.status LIKE :status_search';
    }
    $productSql .= ' ORDER BY p.created_at DESC, p.product_id DESC';
    $productStatement = get_database_connection()->prepare($productSql);
    if ($productSearch !== '') {
        $searchTerm = '%' . $productSearch . '%';
        $productStatement->execute([
            'name_search' => $searchTerm,
            'category_search' => $searchTerm,
            'size_search' => $searchTerm,
            'description_search' => $searchTerm,
            'qr_search' => $searchTerm,
            'status_search' => $searchTerm,
        ]);
    } else {
        $productStatement->execute();
    }
    $products = $productStatement->fetchAll();
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'The product list could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View products | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page staff-inventory-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Staff inventory</p>
                <h1>View products</h1>
            </div>
            <p><?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?></p>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <section class="admin-product-list" aria-labelledby="product-list-heading">
            <div class="inventory-list-heading">
                <h2 id="product-list-heading">Products</h2>
                <form class="shop-search inventory-search" method="get" action="inventory.php#products" role="search">
                    <label class="visually-hidden" for="product-search">Search products</label>
                    <input id="product-search" name="q" type="search" placeholder="Search products" value="<?= escape_html($productSearch) ?>">
                    <button type="submit">Search</button>
                    <?php if ($productSearch !== ''): ?>
                        <a class="inventory-search-clear" href="inventory.php#products">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <span id="products"></span>
            <?php if ($products !== []): ?>
                <div class="admin-table-wrap">
                    <table class="admin-product-table">
                        <thead>
                            <tr>
                                <th scope="col">Image</th>
                                <th scope="col">Product</th>
                                <th scope="col">Category</th>
                                <th scope="col">Size</th>
                                <th scope="col">Rental / day</th>
                                <th scope="col">Status</th>
                                <th scope="col">QR tag</th>
                                <th scope="col">Hearts</th>
                                <th scope="col">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td data-label="Image">
                                        <?php if ($product['thumbnail_image'] !== null): ?>
                                            <img class="admin-product-thumbnail" src="../<?= escape_html($product['thumbnail_image']) ?>" alt="">
                                        <?php else: ?>
                                            <span class="admin-no-thumbnail">No image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Product"><?= escape_html($product['product_name']) ?></td>
                                    <td data-label="Category"><?= escape_html($product['category']) ?></td>
                                    <td data-label="Size"><?= escape_html($product['size']) ?></td>
                                    <td data-label="Rental / day">₱<?= number_format((float) $product['rental_price'], 2) ?></td>
                                    <td data-label="Status"><?= $product['is_reserved'] ? 'Reserved' : escape_html(ucfirst($product['status'])) ?></td>
                                    <td data-label="QR tag"><a class="text-link" href="product_tag.php?id=<?= (int) $product['product_id'] ?>">Print QR tag</a></td>
                                    <td data-label="Hearts"><?= (int) $product['favourites_count'] ?></td>
                                    <td class="admin-row-actions" data-label="Details">
                                        <a class="text-link" href="../product.php?id=<?= (int) $product['product_id'] ?>">View product</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state"><?= $productSearch !== '' ? 'No products match your search. Try a different term.' : 'No products are available to view.' ?></p>
            <?php endif; ?>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
</body>
</html>