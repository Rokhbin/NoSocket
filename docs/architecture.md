# Architecture

## Data Flow

1. Application code emits an event into `nosocket_events`.
2. A leader browser tab sends `POST /nosocket/poll` with an independent cursor for each channel.
3. The server verifies a signed channel grant, checks the database-backed rate limiter, and selects retained rows with `id > cursor`.
4. The leader dispatches events locally and broadcasts them to follower tabs.
5. Every tab persists the newest cursor for each channel in local storage for offline recovery.

## Server Components

| Component | Responsibility |
| --- | --- |
| `NoSocket\NoSocket` | Validates and emits events |
| `PdoEventStore` | Appends, queries, and expires event rows |
| `SubscriptionSigner` | Issues and verifies HMAC-SHA256 channel grants |
| `PollService` | Enforces grants, limits result pages, and advances cursors |
| `PdoRateLimiter` | Counts fixed-window requests without external infrastructure |

`nosocket_events` is indexed by `(channel, id)` and `expires_at`. Polls are cursor seeks, never full-table scans. Before cleanup deletes expired rows, it records the highest removed ID per channel in `nosocket_channel_watermarks`. A client cursor behind that watermark receives `resync_required`.

## Browser Components

`NoSocketClient` prefers the Web Locks API and falls back to a renewable local-storage lease. The lease lasts eight seconds and is renewed every three seconds. Followers broadcast channels, visibility, and activity state on the same cadence, letting the leader poll the union of live subscriptions. A hidden leader yields when a visible peer is available.

`BroadcastChannel` is preferred. Browsers without it use storage events. Cooldowns are shared across tabs, so leadership changes do not bypass server backoff. If local storage is unavailable, event delivery still works in a tab but single-leader coordination cannot be guaranteed.

## Delivery Semantics

Delivery is at-least-once across network retries and best-effort within event TTL. Event IDs are monotonic revision cursors. Consumers that perform non-idempotent work should deduplicate by event ID. When retention creates a gap, applications refresh their authoritative snapshot in `onResync`. NoSocket is intended for UI synchronization; authoritative writes remain normal application requests.

## Scaling Shape

The design trades sub-second idle latency for predictable shared-hosting cost. With 100 users, 10 tabs each, and the default idle interval:

| Design | Poll requests per minute |
| --- | ---: |
| Per-tab polling | 2,000 |
| NoSocket leader polling | 200 |

Actual database capacity depends on host limits, indexes, TTL, payload size, active-user ratio, and event volume.
