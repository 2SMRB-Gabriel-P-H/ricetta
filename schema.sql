-- Esquema de Ricetta para MariaDB/MySQL.
-- Se aplica automáticamente al primer arranque. También puedes importarlo a mano:
--   mariadb -u ricetta -p ricetta < schema.sql

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(24) NOT NULL,
    email VARCHAR(254) NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- La unicidad del correo sólo se aplica a direcciones verificadas. Así
    -- varias cuentas pueden solicitar el mismo correo sin bloquearse entre sí.
    email_verified_key VARCHAR(254)
        GENERATED ALWAYS AS (CASE WHEN email_verified = 1 THEN LOWER(email) ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_username (username),
    UNIQUE KEY uniq_users_email_verified (email_verified_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_state (
    user_id INT UNSIGNED NOT NULL,
    value LONGTEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_state_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (token_hash),
    KEY idx_sessions_expiry (expires_at),
    KEY idx_sessions_user (user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shares (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    kind ENUM('recipe', 'planner') NOT NULL,
    title VARCHAR(100) NOT NULL,
    payload LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    copied_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_shares_recipient (recipient_id, dismissed_at, id),
    KEY idx_shares_sender (sender_id),
    CONSTRAINT fk_shares_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_shares_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pending_emails (
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uniq_pending_emails_token (token_hash),
    CONSTRAINT fk_pending_emails_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uniq_password_resets_token (token_hash),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_action_limits (
    action VARCHAR(32) NOT NULL,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_at DATETIME NOT NULL,
    PRIMARY KEY (action, email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Límites por IP. En PHP cada petición es un proceso distinto, así que no se
-- puede usar un diccionario en memoria como en la versión de un solo proceso.
CREATE TABLE IF NOT EXISTS request_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(32) NOT NULL,
    client_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_request_limits_lookup (action, client_hash, requested_at),
    KEY idx_request_limits_time (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
