# API Reference

## PHP

### Emit

```php
$event = $nosocket->emit(
    channel: 'orders',
    event: 'order.created',
    payload: ['id' => 123],
    ttlSeconds: 3600,
);
```

Channel and event names accept 1-128 letters, numbers, dots, underscores, colons, and dashes.

### Issue A Subscription Grant

Authorize channels in your application before issuing a token:

```php
$signer = new NoSocket\Auth\SubscriptionSigner($_ENV['NOSOCKET_SECRET']);
$token = $signer->issue(['orders', 'notifications'], 'user:42', 3600);
```

### Emit A Batch

`emitBatch()` validates every item first. `PdoEventStore` writes the batch in a transaction. Custom stores that only implement `EventStore` use a sequential fallback.

```php
$events = $nosocket->emitBatch([
    ['channel' => 'orders', 'event' => 'order.updated', 'payload' => ['id' => 123]],
    ['channel' => 'dashboard', 'event' => 'metrics.updated', 'payload' => ['online' => 7], 'ttl_seconds' => 300],
]);
```

### Cleanup

Run `$nosocket->cleanup()` from cron or a framework scheduler. As a fallback for hosting plans without reliable cron, configure `cleanupProbability` between `0.0` and `1.0`. The default is `0.0`, so event writes do not run cleanup queries unless explicitly enabled.

### Poll

```http
POST /nosocket/poll
Authorization: Bearer <signed-grant>
Content-Type: application/json
```

```json
{
  "subscriptions": {
    "orders": { "cursor": 1523, "replay": "live" },
    "notifications": { "cursor": null, "replay": "retained" }
  }
}
```

`cursor: null` bootstraps a new subscription. `live` starts after the newest existing event. `retained` replays events still available within TTL.

```json
{
  "events": [
    {
      "id": 1524,
      "channel": "orders",
      "event": "order.created",
      "payload": { "id": 123 },
      "created_at": "2026-05-31T12:00:00+00:00"
    }
  ],
  "cursors": {
    "orders": 1524,
    "notifications": 81
  },
  "has_more": false,
  "resync_required": []
}
```

`has_more` tells the browser to fetch the next bounded page immediately. A channel listed in `resync_required` has fallen behind retention and needs an application snapshot refresh.

## JavaScript

For production, use `tokenProvider` so a leader tab can authorize the union of all tab subscriptions and refresh expiring grants:

```js
const NoSocket = createNoSocket({
  endpoint: "/nosocket/poll",
  namespace: `shop:user-${currentUser.id}`,
  tokenProvider: async ({ channels, reason }) => {
    const response = await fetch("/nosocket/token", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
      body: JSON.stringify({ channels }),
    });
    return response.json();
  },
  onResync: async ({ channel }) => refreshSnapshot(channel),
});

const unsubscribe = NoSocket.subscribe("orders");
NoSocket.subscribe("notifications", { replay: "retained" });
const off = NoSocket.on("order.created", (payload, event) => {});
NoSocket.on("nosocket.resync_required", ({ channel }) => {});
NoSocket.start();
NoSocket.resync("orders");
NoSocket.stop();
```

A fixed `token` remains supported for small examples. Supported options: `endpoint`, `namespace`, `token`, `tokenProvider`, `onResync`, `csrfToken`, polling intervals, lease settings, `requestTimeout`, `tokenRefreshWindow`, and `jitterRatio`.

## Database

Use [`database/mysql/schema.sql`](../database/mysql/schema.sql) for MySQL/MariaDB, [`database/postgresql/schema.sql`](../database/postgresql/schema.sql) for PostgreSQL, or [`database/sqlite/schema.sql`](../database/sqlite/schema.sql) for SQLite.
