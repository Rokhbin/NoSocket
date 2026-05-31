CREATE TABLE nosocket_channel_watermarks (
    channel VARCHAR(128) PRIMARY KEY,
    event_id BIGINT NOT NULL,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
);
