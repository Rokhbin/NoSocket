CREATE TABLE nosocket_events (
    id BIGSERIAL PRIMARY KEY,
    channel VARCHAR(128) NOT NULL,
    event VARCHAR(128) NOT NULL,
    payload_json JSONB NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    expires_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
);

CREATE INDEX nosocket_events_channel_id ON nosocket_events (channel, id);
CREATE INDEX nosocket_events_expires_at ON nosocket_events (expires_at);

CREATE TABLE nosocket_rate_limits (
    key_hash CHAR(64) NOT NULL,
    bucket BIGINT NOT NULL,
    hits INTEGER NOT NULL,
    expires_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (key_hash, bucket)
);

CREATE INDEX nosocket_rate_limits_expires_at ON nosocket_rate_limits (expires_at);

CREATE TABLE nosocket_channel_watermarks (
    channel VARCHAR(128) PRIMARY KEY,
    event_id BIGINT NOT NULL,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
);
