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

Use [`database/mysql/schema.sql`](../database/mysql/schema.sql) for MySQL/MariaDB or [`database/postgresql/schema.sql`](../database/postgresql/schema.sql) for PostgreSQL. SQLite remains a local testing convenience and is not officially supported in `0.2.0`.
