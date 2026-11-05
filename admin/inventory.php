<?php

require_once __DIR__ . '/../auth/helpers.php';
require_once __DIR__ . '/../functions/product_image_function.php';
require_authenticated_role('admin');
$connection = get_database_connection();
$productSearch = substr(trim((string) ($_GET['q'] ?? '')), 0, 150);
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$statuses = ['available', 'rented', 'unavailable'];
$categories = [];
$categoryChoice = '';
$error = '';
$form = [
    'product_name' => '',
    'category' => '',
    'size' => '',
    'description' => '',
    'rental_price' => '',
    'refundable_deposit' => '0.00',
    'status' => 'available',
];

try {
    $categories = $connection->query(
        'SELECT DISTINCT category FROM products ORDER BY category'
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $exception) {
    show_database_error($exception);
    $error = 'The product categories could not be loaded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($action === 'update') {
        $editId = $productId;
    }

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'delete') {
        if ($productId === false) {
            $error = 'Select a valid product to remove.';
        } else {
            try {
                $connection->beginTransaction();
                $productStatement = $connection->prepare(
                    'SELECT product_id FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $productStatement->execute(['product_id' => $productId]);
                if (!$productStatement->fetchColumn()) {
                    $connection->rollBack();
                    $error = 'That product no longer exists.';
                } else {
                    $reservationStatement = $connection->prepare(
                        'SELECT COUNT(*) FROM reservations WHERE product_id = :product_id'
                    );
                    $reservationStatement->execute(['product_id' => $productId]);
                    if ((int) $reservationStatement->fetchColumn() > 0) {
                        $connection->rollBack();
                        $error = 'Products with reservation history cannot be deleted. Set the product status to unavailable instead.';
                    } else {
                        $imageStatement = $connection->prepare(
                            'SELECT image_path FROM product_images WHERE product_id = :product_id'
                        );
                        $imageStatement->execute(['product_id' => $productId]);
                        $imagePaths = array_column($imageStatement->fetchAll(), 'image_path');
                        $statement = $connection->prepare('DELETE FROM products WHERE product_id = :product_id');
                        $statement->execute(['product_id' => $productId]);
                        $connection->commit();
                        delete_product_image_files($imagePaths);
                        header('Location: inventory.php');
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                show_database_error($exception);
                $error = 'The product could not be removed. Please try again.';
            }
        }
    } elseif (in_array($action, ['save', 'update'], true)) {
        foreach ($form as $field => $default) {
            if ($field !== 'category') {
                $form[$field] = trim((string) ($_POST[$field] ?? $default));
            }
        }
        $categoryChoice = trim((string) ($_POST['category_choice'] ?? ''));
        $form['category'] = $categoryChoice === '__new__'
            ? trim((string) ($_POST['new_category'] ?? ''))
            : $categoryChoice;
        $form['status'] = strtolower($form['status']);
        $priceIsValid = preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', (string) $form['rental_price']) === 1;
        $depositIsValid = preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', (string) $form['refundable_deposit']) === 1;
        $categoryChoiceIsValid = $categoryChoice === '__new__' || in_array($categoryChoice, $categories, true);
        $existingImages = [];
        $newUploads = [];

        if (
            $form['product_name'] === ''
            || strlen($form['product_name']) > 150
            || $form['category'] === ''
            || strlen($form['category']) > 80
            || !$categoryChoiceIsValid
            || $form['size'] === ''
            || strlen($form['size']) > 50
            || $form['description'] === ''
            || strlen($form['description']) > 5000
            || !$priceIsValid
            || !$depositIsValid
            || !in_array($form['status'], $statuses, true)
        ) {
            $error = 'Check each field. Product name, category, size, description, valid rental price, security deposit, and status are required.';
        } elseif (($action === 'update' && $productId === false) || !in_array($action, ['save', 'update'], true)) {
            $error = 'Select a valid product to update.';
        } else {
            try {
                $newUploads = read_product_image_uploads($_FILES['product_images'] ?? null);
                if ($action === 'update') {
                    $existingStatement = $connection->prepare(
                        'SELECT image_id, image_path, is_thumbnail FROM product_images
                         WHERE product_id = :product_id ORDER BY sort_order, image_id'
                    );
                    $existingStatement->execute(['product_id' => $productId]);
                    $existingImages = $existingStatement->fetchAll();
                }

                if (count($existingImages) + count($newUploads) > PRODUCT_IMAGE_MAX_COUNT) {
                    throw new RuntimeException('You can add up to 10 images per product.');
                }

                $thumbnailSelection = (string) ($_POST['thumbnail_selection'] ?? '');
                $validThumbnailSelections = [];
                foreach ($existingImages as $image) {
                    $validThumbnailSelections[] = 'existing-' . $image['image_id'];
                }
                foreach ($newUploads as $index => $upload) {
                    $validThumbnailSelections[] = 'new-' . $index;
                }
                if ($validThumbnailSelections !== [] && !in_array($thumbnailSelection, $validThumbnailSelections, true)) {
                    $thumbnailSelection = $validThumbnailSelections[0];
                }

                $storedImagePaths = store_product_image_uploads($newUploads);
                try {
                    $connection->beginTransaction();
                    if ($action === 'update') {
                        $statement = $connection->prepare(
                            'UPDATE products
                             SET product_name = :product_name,
                                 category = :category,
                                 size = :size,
                                 description = :description,
                                 rental_price = :rental_price,
                                 refundable_deposit = :refundable_deposit,
                                 status = :status
                             WHERE product_id = :product_id'
                        );
                        $statement->execute([
                            'product_name' => $form['product_name'],
                            'category' => $form['category'],
                            'size' => $form['size'],
                            'description' => $form['description'],
                            'rental_price' => $form['rental_price'],
                            'refundable_deposit' => $form['refundable_deposit'],
                            'status' => $form['status'],
                            'product_id' => $productId,
                        ]);
                        $existsStatement = $connection->prepare('SELECT 1 FROM products WHERE product_id = :product_id');
                        $existsStatement->execute(['product_id' => $productId]);
                        if (!$existsStatement->fetchColumn()) {
                            throw new RuntimeException('That product no longer exists.');
                        }
                        $savedProductId = $productId;
                    } else {
                        $qrCode = bin2hex(random_bytes(16));
                        $statement = $connection->prepare(
                            'INSERT INTO products
                                (product_name, category, size, description, rental_price, refundable_deposit, qr_code, status)
                             VALUES
                                (:product_name, :category, :size, :description, :rental_price, :refundable_deposit, :qr_code, :status)'
                        );
                        $statement->execute([
                            'product_name' => $form['product_name'],
                            'category' => $form['category'],
                            'size' => $form['size'],
                            'description' => $form['description'],
                            'rental_price' => $form['rental_price'],
                            'refundable_deposit' => $form['refundable_deposit'],
                            'qr_code' => $qrCode,
                            'status' => $form['status'],
                        ]);
                        $savedProductId = (int) $connection->lastInsertId();
                    }

                    $thumbnailUpdate = $connection->prepare(
                        'UPDATE product_images SET is_thumbnail = 0 WHERE product_id = :product_id'
                    );
                    $thumbnailUpdate->execute(['product_id' => $savedProductId]);
                    $insertImage = $connection->prepare(
                        'INSERT INTO product_images (product_id, image_path, is_thumbnail, sort_order)
                         VALUES (:product_id, :image_path, :is_thumbnail, :sort_order)'
                    );
                    foreach ($storedImagePaths as $index => $imagePath) {
                        $isThumbnail = $thumbnailSelection === 'new-' . $index;
                        $insertImage->execute([
                            'product_id' => $savedProductId,
                            'image_path' => $imagePath,
                            'is_thumbnail' => $isThumbnail ? 1 : 0,
                            'sort_order' => count($existingImages) + $index,
                        ]);
                    }
                    if (strncmp($thumbnailSelection, 'existing-', 9) === 0) {
                        $thumbnailId = (int) substr($thumbnailSelection, strlen('existing-'));
                        $selectThumbnail = $connection->prepare(
                            'UPDATE product_images SET is_thumbnail = 1
                             WHERE image_id = :image_id AND product_id = :product_id'
                        );
                        $selectThumbnail->execute([
                            'image_id' => $thumbnailId,
                            'product_id' => $savedProductId,
                        ]);
                    }
                    $connection->commit();
                    header('Location: inventory.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    delete_product_image_files($storedImagePaths);
                    throw $exception;
                }
            } catch (Throwable $exception) {
                if ($exception instanceof PDOException) {
                    show_database_error($exception);
                    $error = 'The product could not be saved. Please check the values and try again.';
                } elseif ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log('RentalSystem product image save error: ' . $exception->getMessage());
                    $error = 'The product could not be saved. Please try again.';
                }
            }
        }
    } else {
        $error = 'That product action is not supported.';
    }
}

$editingProduct = null;
$productImages = [];

try {
    if (($_SERVER['REQUEST_METHOD'] !== 'POST' || ($action ?? '') === 'update') && $editId !== false) {
        $editStatement = $connection->prepare('SELECT * FROM products WHERE product_id = :product_id');
        $editStatement->execute(['product_id' => $editId]);
        $editingProduct = $editStatement->fetch();
        if (!$editingProduct) {
            $error = 'That product no longer exists.';
        } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            foreach ($form as $field => $default) {
                $form[$field] = (string) $editingProduct[$field];
            }
            $categoryChoice = $form['category'];
        }
    }
    if ($editingProduct) {
        $imagesStatement = $connection->prepare(
            'SELECT image_id, image_path, is_thumbnail FROM product_images
             WHERE product_id = :product_id ORDER BY sort_order, image_id'
        );
        $imagesStatement->execute(['product_id' => $editId]);
        $productImages = $imagesStatement->fetchAll();
    }

    $productSql = 'SELECT p.*,
            (SELECT pi.image_path FROM product_images pi
             WHERE pi.product_id = p.product_id
             ORDER BY pi.is_thumbnail DESC, pi.sort_order, pi.image_id LIMIT 1) AS thumbnail_image,
            (SELECT COUNT(*) FROM favorites f WHERE f.product_id = p.product_id) AS favourites_count,
            (
                EXISTS(
                SELECT 1 FROM reservations active_hold
                WHERE active_hold.product_id = p.product_id
                  AND active_hold.status = \'pending\'
                  AND active_hold.expires_at > CURRENT_TIMESTAMP
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
    $productStatement = $connection->prepare($productSql);
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
    $products = [];
    $error = $error !== '' ? $error : 'The product list could not be loaded.';
}

$isEditing = is_array($editingProduct);
$formAction = $isEditing ? 'update' : 'save';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add products | Angels Bridal Rental Shop</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="storefront account-page inventory-page">
    <?php require __DIR__ . '/../includes/account_shell_start.php'; ?>
    <main class="admin-inventory">
        <div class="admin-page-heading">
            <div>
                <p class="shop-kicker">Store inventory</p>
                <h1><?= $isEditing ? 'Edit product' : 'Add products' ?></h1>
            </div>
            <p><?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?></p>
        </div>

        <?php if ($error !== ''): ?>
            <p class="store-notice store-notice-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <section class="admin-form-card" aria-labelledby="product-form-heading">
            <form method="post" enctype="multipart/form-data" action="inventory.php<?= $isEditing ? '?edit=' . (int) $editingProduct['product_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= escape_html($formAction) ?>">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="product_id" value="<?= (int) $editingProduct['product_id'] ?>">
                <?php endif; ?>
                <div class="admin-form-heading">
                    <h2 id="product-form-heading"><?= $isEditing ? 'Edit product' : 'Add a product' ?></h2>
                </div>
                <div class="admin-product-form">
                    <div class="field">
                        <label for="product_name">Product name</label>
                        <input id="product_name" name="product_name" type="text" maxlength="150" value="<?= escape_html($form['product_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="category">Category</label>
                        <select id="category" name="category_choice" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= escape_html((string) $category) ?>" <?= $categoryChoice === (string) $category ? 'selected' : '' ?>><?= escape_html((string) $category) ?></option>
                            <?php endforeach; ?>
                            <option value="__new__" <?= $categoryChoice === '__new__' || ($categoryChoice === '' && $form['category'] !== '') ? 'selected' : '' ?>>Add a new category...</option>
                        </select>
                        <input
                            id="new_category"
                            name="new_category"
                            type="text"
                            maxlength="80"
                            placeholder="Enter a new category"
                            value="<?= escape_html($categoryChoice === '__new__' ? $form['category'] : '') ?>"
                            <?= $categoryChoice === '__new__' || ($categoryChoice === '' && $form['category'] !== '') ? '' : 'hidden' ?>
                        >
                    </div>
                    <div class="field">
                        <label for="size">Size</label>
                        <input id="size" name="size" type="text" maxlength="50" placeholder="e.g. XS–XL" value="<?= escape_html($form['size']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="rental_price">Rental price per day (₱)</label>
                        <input id="rental_price" name="rental_price" type="number" min="0" max="99999999.99" step="0.01" value="<?= escape_html($form['rental_price']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="refundable_deposit">Refundable security deposit (₱)</label>
                        <input id="refundable_deposit" name="refundable_deposit" type="number" min="0" max="99999999.99" step="0.01" value="<?= escape_html($form['refundable_deposit']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= escape_html($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= escape_html(ucfirst($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field admin-description-field">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3" maxlength="5000" required><?= escape_html($form['description']) ?></textarea>
                    </div>
                    <div class="field admin-description-field">
                        <label for="product_images">Product images</label>
                        <input id="product_images" name="product_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                        <small class="field-hint">Add up to 10 JPG, PNG, or WebP images (5 MB each). Choose the thumbnail below.</small>
                        <p class="product-image-error" id="product-image-error" role="alert" hidden></p>
                        <div class="product-image-options" id="product-image-options" aria-label="Choose product thumbnail">
                            <?php foreach ($productImages as $image): ?>
                                <div class="product-image-option">
                                    <input
                                        type="radio"
                                        name="thumbnail_selection"
                                        value="existing-<?= (int) $image['image_id'] ?>"
                                        <?= (bool) $image['is_thumbnail'] ? 'checked' : '' ?>
                                    >
                                    <img src="../<?= escape_html($image['image_path']) ?>" alt="Current product image">
                                    <span><?= (bool) $image['is_thumbnail'] ? 'Selected thumbnail' : 'Use as thumbnail' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="admin-form-actions">
                    <button class="shop-button" type="submit"><?= $isEditing ? 'Save changes' : 'Add product' ?></button>
                    <?php if ($isEditing): ?>
                        <a class="text-link" href="inventory.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
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
                                <th scope="col">Hearts</th>
                                <th scope="col">Actions</th>
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
                                    <td data-label="Hearts"><?= (int) $product['favourites_count'] ?></td>
                                    <td class="admin-row-actions" data-label="Actions">
                                        <div class="admin-row-action-links">
                                            <a class="text-link" href="../product.php?id=<?= (int) $product['product_id'] ?>">View product</a>
                                            <a class="text-link" href="inventory.php?edit=<?= (int) $product['product_id'] ?>">Edit</a>
                                            <form method="post" action="inventory.php" onsubmit="return confirm('Delete this product? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                                                <button class="admin-delete-button" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state"><?= $productSearch !== '' ? 'No products match your search. Try a different term.' : 'No products yet. Add the first product above.' ?></p>
            <?php endif; ?>
        </section>
    </main>
    <?php require __DIR__ . '/../includes/account_shell_end.php'; ?>
    <script>
        const categorySelect = document.getElementById('category');
        const newCategoryInput = document.getElementById('new_category');

        function updateCategoryInput() {
            const isNewCategory = categorySelect.value === '__new__';
            newCategoryInput.hidden = !isNewCategory;
            newCategoryInput.required = isNewCategory;
        }

        categorySelect.addEventListener('change', updateCategoryInput);
        updateCategoryInput();

        const imageInput = document.getElementById('product_images');
        const imageOptions = document.getElementById('product-image-options');
        const imageError = document.getElementById('product-image-error');
        const existingImageOptions = Array.from(imageOptions.querySelectorAll('input[name="thumbnail_selection"]'));
        let selectedFiles = [];

        function updateThumbnailLabels() {
            imageOptions.querySelectorAll('.product-image-option').forEach((option) => {
                const radio = option.querySelector('input[name="thumbnail_selection"]');
                const label = option.querySelector('span');
                label.textContent = radio.checked ? 'Selected thumbnail' : 'Use as thumbnail';
            });
        }

        function setUploadFiles(files) {
            const transfer = new DataTransfer();
            files.forEach((file) => transfer.items.add(file));
            imageInput.files = transfer.files;
        }

        function renderNewImageOptions(selectedValue = imageOptions.querySelector('input[name="thumbnail_selection"]:checked')?.value) {
            imageOptions.querySelectorAll('[data-new-product-image]').forEach((option) => {
                URL.revokeObjectURL(option.dataset.previewUrl);
                option.remove();
            });

            selectedFiles.forEach((file, index) => {
                const option = document.createElement('div');
                const radio = document.createElement('input');
                const image = document.createElement('img');
                const label = document.createElement('span');
                const removeButton = document.createElement('button');
                const previewUrl = URL.createObjectURL(file);

                option.className = 'product-image-option';
                option.dataset.newProductImage = '';
                option.dataset.previewUrl = previewUrl;
                radio.type = 'radio';
                radio.name = 'thumbnail_selection';
                radio.value = `new-${index}`;
                radio.required = true;
                radio.addEventListener('change', updateThumbnailLabels);
                radio.checked = selectedValue === radio.value;
                image.src = previewUrl;
                image.alt = file.name;
                label.textContent = 'Use as thumbnail';
                removeButton.className = 'product-image-remove';
                removeButton.type = 'button';
                removeButton.dataset.removeProductImage = String(index);
                removeButton.setAttribute('aria-label', `Remove ${file.name}`);
                removeButton.textContent = '×';
                option.append(radio, image, label, removeButton);
                imageOptions.append(option);
            });

            const anyImageSelected = imageOptions.querySelector('input[name="thumbnail_selection"]:checked');
            if (!anyImageSelected) {
                const firstOption = imageOptions.querySelector('input[name="thumbnail_selection"]');
                if (firstOption) {
                    firstOption.checked = true;
                }
            }

            const allOptions = Array.from(imageOptions.querySelectorAll('input[name="thumbnail_selection"]'));
            allOptions.forEach((radio) => {
                radio.required = allOptions.length > 0;
            });
            updateThumbnailLabels();
        }

        existingImageOptions.forEach((radio) => {
            radio.required = existingImageOptions.length > 0;
            radio.addEventListener('change', updateThumbnailLabels);
        });

        imageInput.addEventListener('change', () => {
            const addedFiles = Array.from(imageInput.files);
            const currentImageCount = existingImageOptions.length + selectedFiles.length;
            if (currentImageCount + addedFiles.length > 10) {
                imageError.textContent = 'You can add up to 10 images per product.';
                imageError.hidden = false;
                setUploadFiles(selectedFiles);
                return;
            }

            selectedFiles = selectedFiles.concat(addedFiles);
            imageError.textContent = '';
            imageError.hidden = true;
            setUploadFiles(selectedFiles);
            renderNewImageOptions();
        });

        imageOptions.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            const removeButton = event.target.closest('[data-remove-product-image]');
            if (!removeButton) {
                return;
            }

            const removeIndex = Number(removeButton.dataset.removeProductImage);
            const selectedRadio = imageOptions.querySelector('input[name="thumbnail_selection"]:checked');
            let selectedValue = selectedRadio?.value;
            const removedSelection = selectedValue === `new-${removeIndex}`;
            if (selectedValue?.startsWith('new-') && !removedSelection) {
                const selectedIndex = Number(selectedValue.slice(4));
                if (selectedIndex > removeIndex) {
                    selectedValue = `new-${selectedIndex - 1}`;
                }
            }
            selectedFiles.splice(removeIndex, 1);
            setUploadFiles(selectedFiles);
            renderNewImageOptions(removedSelection ? undefined : selectedValue);

            if (removedSelection) {
                const fallbackRadio = imageOptions.querySelector('input[name="thumbnail_selection"]');
                if (fallbackRadio) {
                    fallbackRadio.checked = true;
                    updateThumbnailLabels();
                }
            }
        });

        if (!imageOptions.querySelector('input[name="thumbnail_selection"]:checked') && existingImageOptions[0]) {
            existingImageOptions[0].checked = true;
        }
        if (existingImageOptions.length > 0) {
            updateThumbnailLabels();
        }
    </script>
</body>
</html>
