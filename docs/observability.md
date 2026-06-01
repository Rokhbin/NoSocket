# Observability

## Diagnostics

[`public/diagnostics.php`](../public/diagnostics.php) is an optional, read-only HTML snapshot for operators. It reports retained events, already-expired events awaiting cleanup, active channels, and channel watermarks.

Do not expose it publicly. Set a strong `NOSOCKET_DIAGNOSTICS_TOKEN` and send it as a bearer token:

```bash
curl -H "Authorization: Bearer $NOSOCKET_DIAGNOSTICS_TOKEN" https://example.com/nosocket/diagnostics
```

## Metrics Hooks

NoSocket does not require a telemetry SDK. Provide a `MetricsHook` when constructing the core client or poll service:

```php
use NoSocket\Observability\CallableMetricsHook;

$metrics = new CallableMetricsHook(
    static fn (string $name, int|float $value, array $attributes) =>
        $meter->counter($name)->add($value, $attributes),
);

$nosocket = new NoSocket\NoSocket($store, $config, $metrics);
$poller = new NoSocket\Http\PollService($store, $signer, $limiter, $config, $metrics);
```

The callable adapter can bridge OpenTelemetry, StatsD, Prometheus clients, framework metrics APIs, or application logging without coupling the core package to one vendor.

Built-in metric names:

| Metric | Meaning |
| --- | --- |
| `nosocket.events.emitted` | Emitted events |
| `nosocket.events.cleaned` | Expired events deleted |
| `nosocket.cleanup.failures` | Best-effort probabilistic cleanup failures |
| `nosocket.poll.requests` | Successful polls |
| `nosocket.poll.events_returned` | Events returned to clients |
| `nosocket.poll.rate_limited` | Rejected polls |
| `nosocket.poll.resync_required` | Channels requiring snapshot refresh |

Metrics hooks are fail-open: an instrumentation failure does not interrupt event delivery or polling.
