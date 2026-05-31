CREATE TABLE nosocket_channel_watermarks (
    channel VARCHAR(128) NOT NULL PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
