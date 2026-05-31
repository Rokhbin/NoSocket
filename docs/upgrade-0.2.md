# Upgrade To 0.2

`0.2.0` intentionally changes the polling wire contract before a stable public release.

1. Apply [`database/mysql/upgrade-0.2.sql`](../database/mysql/upgrade-0.2.sql), [`database/postgresql/upgrade-0.2.sql`](../database/postgresql/upgrade-0.2.sql), or the published Laravel migration to add `nosocket_channel_watermarks`.
2. Change poll routes from `GET` to `POST`.
3. Replace the global cursor request with `subscriptions`, keyed by channel.
4. Publish the updated browser SDK. Existing global cursor storage is ignored; channels bootstrap live by default.
5. Configure `tokenProvider` for production so leader tabs can authorize changing channel unions.
6. Handle `onResync` by fetching an authoritative snapshot for the affected channel.

The new watermark table stores the last removed event in its `event_id` column. This lets clients detect events missed after TTL cleanup instead of silently continuing from an incomplete history. Do not rename that database column to `cursor`; `cursor` is reserved on MariaDB.
