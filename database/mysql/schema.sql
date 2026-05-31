CREATE TABLE nosocket_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(128) NOT NULL,
    event VARCHAR(128) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX nosocket_events_channel_id (channel, id),
    INDEX nosocket_events_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nosocket_rate_limits (
    key_hash CHAR(64) NOT NULL,
    bucket BIGINT NOT NULL,
    hits INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (key_hash, bucket),
    INDEX nosocket_rate_limits_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nosocket_channel_watermarks (
    channel VARCHAR(128) NOT NULL PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
