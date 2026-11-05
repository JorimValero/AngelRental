<?php

const PRODUCT_IMAGE_MAX_COUNT = 10;
const PRODUCT_IMAGE_MAX_SIZE = 5242880;

function read_product_image_uploads(?array $fileData): array
{
    if ($fileData === null || !isset($fileData['error']) || !is_array($fileData['error'])) {
        return [];
    }

    $uploads = [];
    $mimeExtensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($fileData['error'] as $index => $uploadError) {
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the product images could not be uploaded. Please try again.');
        }

        $temporaryPath = $fileData['tmp_name'][$index] ?? '';
        $fileSize = $fileData['size'][$index] ?? 0;
        if (
            !is_string($temporaryPath)
            || $temporaryPath === ''
            || !is_uploaded_file($temporaryPath)
            || !is_int($fileSize)
            || $fileSize < 1
            || $fileSize > PRODUCT_IMAGE_MAX_SIZE
        ) {
            throw new RuntimeException('Each product image must be no larger than 5 MB.');
        }

        $mimeType = $fileInfo->file($temporaryPath);
        if (!is_string($mimeType) || !isset($mimeExtensions[$mimeType])) {
            throw new RuntimeException('Product images must be JPG, PNG, or WebP files.');
        }

        $uploads[] = [
            'tmp_name' => $temporaryPath,
            'extension' => $mimeExtensions[$mimeType],
        ];
    }

    if (count($uploads) > PRODUCT_IMAGE_MAX_COUNT) {
        throw new RuntimeException('You can add up to 10 images per product.');
    }

    return $uploads;
}

function store_product_image_uploads(array $uploads): array
{
    if ($uploads === []) {
        return [];
    }

    $directory = dirname(__DIR__) . '/image/products';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The product image folder could not be created.');
    }

    $storedPaths = [];
    try {
        foreach ($uploads as $upload) {
            $filename = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
            $absolutePath = $directory . '/' . $filename;
            if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
                throw new RuntimeException('A product image could not be saved. Please try again.');
            }
            $storedPaths[] = 'image/products/' . $filename;
        }
    } catch (Throwable $exception) {
        delete_product_image_files($storedPaths);
        throw $exception;
    }

    return $storedPaths;
}

function delete_product_image_files(array $imagePaths): void
{
    $imageDirectory = realpath(dirname(__DIR__) . '/image/products');
    if ($imageDirectory === false) {
        return;
    }

    foreach ($imagePaths as $imagePath) {
        if (!is_string($imagePath) || preg_match('/\Aimage\/products\/[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $imagePath) !== 1) {
            continue;
        }

        $absolutePath = $imageDirectory . DIRECTORY_SEPARATOR . basename($imagePath);
        if (is_file($absolutePath) && !unlink($absolutePath)) {
            error_log('RentalSystem product image cleanup failed: ' . $absolutePath);
        }
    }
}
