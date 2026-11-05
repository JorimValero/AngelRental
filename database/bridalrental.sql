-- Complete database setup. Historical migrations are folded into this file.
CREATE DATABASE IF NOT EXISTS rental_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE rental_system;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(254) DEFAULT NULL,
    contact_number VARCHAR(30) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'admin', 'staff') NOT NULL DEFAULT 'customer',
    email_verified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_username_unique (username),
    UNIQUE KEY users_email_unique (email),
    KEY users_role_idx (role)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS username VARCHAR(30) NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME DEFAULT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS notifications_last_read_at DATETIME(6) DEFAULT NULL AFTER email_verified_at,
    MODIFY COLUMN email VARCHAR(254) NULL,
    MODIFY COLUMN role ENUM('customer', 'admin', 'staff') NOT NULL DEFAULT 'customer';

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY email_verification_token_hash_unique (token_hash),
    CONSTRAINT email_verification_tokens_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY password_reset_token_hash_unique (token_hash),
    CONSTRAINT password_reset_tokens_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP PROCEDURE IF EXISTS migrate_usernames//
CREATE PROCEDURE migrate_usernames()
BEGIN
    DECLARE v_done BOOLEAN DEFAULT FALSE;
    DECLARE v_id INT UNSIGNED;
    DECLARE v_email VARCHAR(254);
    DECLARE v_base VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_candidate VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_suffix INT UNSIGNED;
    DECLARE v_suffix_text VARCHAR(10);
    DECLARE v_matches INT UNSIGNED;
    DECLARE user_cursor CURSOR FOR
        SELECT id, email
        FROM users
        WHERE username IS NULL OR username = ''
        ORDER BY id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;

    OPEN user_cursor;

    username_loop: LOOP
        FETCH user_cursor INTO v_id, v_email;
        IF v_done THEN
            LEAVE username_loop;
        END IF;

        SET v_base = LEFT(
            REGEXP_REPLACE(LOWER(SUBSTRING_INDEX(v_email, '@', 1)), '[^a-z0-9_.-]', ''),
            30
        );

        IF CHAR_LENGTH(v_base) < 3 OR v_base REGEXP '^[^a-z0-9]' THEN
            SET v_base = CONCAT('user', v_id);
        END IF;

        SET v_candidate = v_base;
        SET v_suffix = 1;

        candidate_loop: LOOP
            SELECT COUNT(*) INTO v_matches
            FROM users
            WHERE username = v_candidate;

            IF v_matches = 0 THEN
                LEAVE candidate_loop;
            END IF;

            SET v_suffix_text = CAST(v_suffix AS CHAR);
            SET v_candidate = CONCAT(
                LEFT(v_base, 30 - CHAR_LENGTH(v_suffix_text)),
                v_suffix_text
            );
            SET v_suffix = v_suffix + 1;
        END LOOP;

        UPDATE users
        SET username = v_candidate
        WHERE id = v_id;
    END LOOP;

    CLOSE user_cursor;
END//

CALL migrate_usernames()//
DROP PROCEDURE migrate_usernames//

DELIMITER ;

ALTER TABLE users
    MODIFY COLUMN username VARCHAR(30) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS users_username_unique (username);

CREATE TABLE IF NOT EXISTS products (
    product_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    size VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    rental_price DECIMAL(10, 2) NOT NULL,
    refundable_deposit DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    qr_code VARCHAR(100) NOT NULL,
    status ENUM('available', 'rented', 'unavailable') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    KEY products_category_idx (category),
    KEY products_status_idx (status),
    KEY products_created_at_idx (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS qr_code VARCHAR(100) NULL AFTER rental_price,
    ADD COLUMN IF NOT EXISTS refundable_deposit DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER rental_price;

UPDATE products
SET qr_code = REPLACE(UUID(), '-', '')
WHERE qr_code IS NULL OR qr_code = '';

ALTER TABLE products
    MODIFY COLUMN qr_code VARCHAR(100) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS products_qr_code_unique (qr_code);

ALTER TABLE products
    DROP COLUMN IF EXISTS is_new_arrival,
    DROP COLUMN IF EXISTS new_arrival_since;

CREATE TABLE IF NOT EXISTS product_images (
    image_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_thumbnail TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    KEY product_images_product_idx (product_id, sort_order),
    CONSTRAINT product_images_product_fk
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, product_id),
    KEY favorites_product_idx (product_id),
    CONSTRAINT favorites_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT favorites_product_fk
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservation_cart_holds (
    product_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    KEY reservation_cart_holds_user_idx (user_id, expires_at),
    KEY reservation_cart_holds_expiry_idx (expires_at),
    CONSTRAINT reservation_cart_holds_product_fk
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE CASCADE,
    CONSTRAINT reservation_cart_holds_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservation_sessions (
    reservation_session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    pickup_date DATE NOT NULL,
    return_date DATE NOT NULL,
    payment_method ENUM('cash', 'online') NOT NULL,
    payment_provider ENUM('paypal') DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reservation_session_id),
    KEY reservation_sessions_user_idx (user_id, created_at),
    CONSTRAINT reservation_sessions_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
    reservation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_session_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    pickup_date DATE NOT NULL,
    return_date DATE NOT NULL,
    rental_days SMALLINT UNSIGNED NOT NULL,
    daily_rate DECIMAL(10, 2) NOT NULL,
    rental_total DECIMAL(10, 2) NOT NULL,
    refundable_deposit DECIMAL(10, 2) NOT NULL,
    damage_notes TEXT DEFAULT NULL,
    damage_charge DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    late_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    late_notes TEXT DEFAULT NULL,
    late_charge DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    deposit_deduction_authorized TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'accepted', 'checked_out', 'paid', 'expired', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
    payment_method ENUM('cash', 'online') NOT NULL DEFAULT 'cash',
    payment_provider ENUM('paypal') DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reservation_id),
    KEY reservations_session_idx (reservation_session_id),
    KEY reservations_user_idx (user_id, created_at),
    KEY reservations_product_dates_idx (product_id, status, pickup_date, return_date),
    KEY reservations_expiry_idx (status, expires_at),
    CONSTRAINT reservations_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT reservations_product_fk
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reservations
    MODIFY COLUMN status ENUM('pending', 'accepted', 'checked_out', 'paid', 'expired', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
    MODIFY COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    ADD COLUMN IF NOT EXISTS damage_notes TEXT DEFAULT NULL AFTER refundable_deposit,
    ADD COLUMN IF NOT EXISTS damage_charge DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER damage_notes,
    ADD COLUMN IF NOT EXISTS late_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER damage_charge,
    ADD COLUMN IF NOT EXISTS late_notes TEXT DEFAULT NULL AFTER late_days,
    ADD COLUMN IF NOT EXISTS late_charge DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER late_notes,
    ADD COLUMN IF NOT EXISTS reservation_session_id INT UNSIGNED DEFAULT NULL AFTER reservation_id,
    ADD COLUMN IF NOT EXISTS deposit_deduction_authorized TINYINT(1) NOT NULL DEFAULT 0 AFTER late_charge,
    ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'online') NOT NULL DEFAULT 'cash' AFTER status,
    ADD COLUMN IF NOT EXISTS payment_provider ENUM('paypal') DEFAULT NULL AFTER payment_method,
    ADD INDEX IF NOT EXISTS reservations_session_idx (reservation_session_id),
    ADD INDEX IF NOT EXISTS reservations_user_updated_idx (user_id, updated_at);

CREATE TABLE IF NOT EXISTS payment_transactions (
    payment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    reservation_session_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED NOT NULL,
    purpose ENUM('rental', 'fees', 'deposit_refund', 'reservation_refund') NOT NULL,
    method ENUM('paypal', 'cash', 'deposit') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    paypal_order_id VARCHAR(100) DEFAULT NULL,
    paypal_capture_id VARCHAR(100) DEFAULT NULL,
    paypal_refund_id VARCHAR(100) DEFAULT NULL,
    status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (payment_id),
    UNIQUE KEY payment_transactions_paypal_order_unique (paypal_order_id),
    KEY payment_transactions_session_idx (reservation_session_id, purpose, status),
    KEY payment_transactions_reservation_idx (reservation_id, purpose, status),
    KEY payment_transactions_user_idx (user_id, created_at),
    CONSTRAINT payment_transactions_reservation_fk
        FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
        ON DELETE CASCADE,
    CONSTRAINT payment_transactions_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_transactions
    MODIFY COLUMN purpose ENUM('rental', 'fees', 'deposit_refund', 'reservation_refund') NOT NULL,
    MODIFY COLUMN method ENUM('paypal', 'cash', 'deposit') NOT NULL,
    ADD COLUMN IF NOT EXISTS paypal_refund_id VARCHAR(100) DEFAULT NULL AFTER paypal_capture_id,
    ADD COLUMN IF NOT EXISTS reservation_session_id INT UNSIGNED DEFAULT NULL AFTER reservation_id,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at,
    ADD INDEX IF NOT EXISTS payment_transactions_session_idx (reservation_session_id, purpose, status),
    ADD INDEX IF NOT EXISTS payment_transactions_user_updated_idx (user_id, updated_at);
