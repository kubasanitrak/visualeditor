-- 001: initial schema (MySQL 5.7+ / MariaDB 10.4+)

CREATE TABLE IF NOT EXISTS media (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename      VARCHAR(255) NOT NULL,           -- path relative to /uploads
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    mime          VARCHAR(100) NOT NULL DEFAULT '',
    kind          ENUM('image','video','file') NOT NULL DEFAULT 'image',
    width         INT UNSIGNED NULL,
    height        INT UNSIGNED NULL,
    size          INT UNSIGNED NOT NULL DEFAULT 0,
    alt           VARCHAR(255) NOT NULL DEFAULT '',
    thumb         VARCHAR(255) NULL,               -- thumbnail path relative to /uploads
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug             VARCHAR(191) NOT NULL UNIQUE,
    title            VARCHAR(255) NOT NULL DEFAULT '',
    meta_description VARCHAR(500) NOT NULL DEFAULT '',
    og_image_id      INT UNSIGNED NULL,
    settings         LONGTEXT NULL,                -- JSON: bg, color, full_width, grid, classes
    status           ENUM('draft','published') NOT NULL DEFAULT 'published',
    sort             INT NOT NULL DEFAULT 0,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pages_og FOREIGN KEY (og_image_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable component masters (content tree stored as JSON).
CREATE TABLE IF NOT EXISTS components (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(191) NOT NULL,
    type       VARCHAR(50)  NOT NULL,
    tree       LONGTEXT NULL,                       -- JSON
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything placed on a page: containers (section/row/col) and content (heading/text/image/video).
CREATE TABLE IF NOT EXISTS items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id      INT UNSIGNED NOT NULL,
    parent_id    INT UNSIGNED NULL,
    sort         INT NOT NULL DEFAULT 0,
    type         VARCHAR(50) NOT NULL,
    content      LONGTEXT NULL,                     -- JSON, shape depends on type
    classes      VARCHAR(500) NOT NULL DEFAULT '',  -- from styles.manifest.json
    settings     LONGTEXT NULL,                     -- JSON: bg, color, width
    component_id INT UNSIGNED NULL,                 -- set when this is a linked component instance
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_items_page (page_id, parent_id, sort),
    CONSTRAINT fk_items_page   FOREIGN KEY (page_id)      REFERENCES pages(id)      ON DELETE CASCADE,
    CONSTRAINT fk_items_parent FOREIGN KEY (parent_id)    REFERENCES items(id)      ON DELETE CASCADE,
    CONSTRAINT fk_items_comp   FOREIGN KEY (component_id) REFERENCES components(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global settings, key -> JSON value.
CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`    LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    google_id     VARCHAR(191) NULL UNIQUE,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
