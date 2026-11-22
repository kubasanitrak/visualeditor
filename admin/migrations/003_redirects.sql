-- 003: old page addresses keep working after a slug change (301 to the page's current slug).

CREATE TABLE IF NOT EXISTS redirects (
    old_slug   VARCHAR(191) NOT NULL PRIMARY KEY,
    page_id    INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_redirects_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
