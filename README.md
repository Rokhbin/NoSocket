# NoSocket

**Realtime for Shared Hosting.**

[فارسی](README.fa.md) | English

NoSocket is a framework-agnostic event delivery layer for sites that cannot run WebSockets, SSE workers, Redis, brokers, Node.js, daemons, or persistent processes. It gives browser code a small event API while remaining friendly to ordinary PHP hosting.

```php
$nosocket->emit('orders', 'order.created', ['id' => 123]);
```

```js
import { createNoSocket } from "/assets/js/nosocket.js";

const NoSocket = createNoSocket({
  namespace: `shop:user-${currentUser.id}`,
  tokenProvider: ({ channels }) => fetchToken(channels),
  onResync: ({ channel }) => refreshSnapshot(channel),
});
NoSocket.subscribe("orders");
NoSocket.on("order.created", (order) => console.log(order.id));
NoSocket.start();
```

## Why It Works

The server appends short-lived rows to an indexed event log. A browser polls for events after its per-channel revision cursors. When several tabs are open, Web Locks or a local lease elects one leader tab. Only that tab polls; events are fanned out with `BroadcastChannel`, with storage events as a fallback. If retention expires before recovery, the SDK requests an application snapshot resync instead of silently skipping data.

Default cadence:

| State | Poll interval |
| --- | ---: |
| Normal | 30 seconds |
| Recently active user | 10 seconds |
| Events arriving | 2 seconds for 30 seconds |
| HTTP 403 | wait at least 60 seconds |
| HTTP 429 | wait at least 120 seconds |
| HTTP 504 | wait at least 300 seconds |

Repeated failures use exponential backoff capped at five minutes.

## Packages

| Package | Purpose |
| --- | --- |
| `nosocket/nosocket` | Vanilla PHP core |
| `@nosocket/client` | Browser SDK |
| `nosocket/laravel` | Laravel provider, facade, route, migration |
| `nosocket/symfony` | Symfony controller and service wiring |
| `nosocket/codeigniter4` | CodeIgniter 4 services and controller |
| `packages/wordpress/nosocket` | WordPress plugin |

## Install

```bash
composer require nosocket/nosocket
mysql -u app -p app_db < database/mysql/schema.sql
```

Set `NOSOCKET_DSN`, `NOSOCKET_DB_USER`, `NOSOCKET_DB_PASSWORD`, and a random `NOSOCKET_SECRET` of at least 32 characters. Point a route at [`public/poll.php`](public/poll.php), issue scoped subscription tokens after your application authorizes channels, and load [`assets/js/nosocket.js`](assets/js/nosocket.js).

See [Installation](docs/installation.md), [Architecture](docs/architecture.md), [API](docs/api.md), and the [0.2 upgrade guide](docs/upgrade-0.2.md).

## Guarantees And Boundaries

- NoSocket uses short HTTP requests only. It is not a low-latency replacement for WebSockets.
- Under normal browser conditions, tabs sharing an origin and namespace elect one polling leader.
- If browser storage is disabled, cross-tab election cannot be guaranteed and tabs may each poll.
- Replay covers retained events. Retention gaps trigger `nosocket.resync_required`.
- Bearer subscription grants must be delivered over HTTPS and kept short-lived.

## Verify

```bash
composer test
npm test
npm run test:e2e
php benchmarks/run.php
```

## License

MIT. See [LICENSE](LICENSE).
