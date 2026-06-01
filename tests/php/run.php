<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'NoSocket\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use NoSocket\Auth\SubscriptionSigner;
use NoSocket\Config;
use NoSocket\Diagnostics\DiagnosticsService;
use NoSocket\Event;
use NoSocket\Http\PollService;
use NoSocket\Http\RateLimitExceeded;
use NoSocket\NoSocket;
use NoSocket\Observability\CallableMetricsHook;
use NoSocket\RateLimit\NullRateLimiter;
use NoSocket\RateLimit\PdoRateLimiter;
use NoSocket\Store\EventStore;
use NoSocket\Store\PdoEventStore;

final class MemoryStore implements EventStore
{
    /** @var list<Event> */
    public array $events = [];

    /** @var array<string, int> */
    public array $deletedThrough = [];

    public function append(string $channel, string $event, array $payload, int $ttlSeconds): Event
    {
        return $this->events[] = new Event(count($this->events) + 1, $channel, $event, $payload, gmdate(DATE_ATOM));
    }

    public function after(array $cursors, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->events,
            static fn (Event $event): bool => isset($cursors[$event->channel]) && $event->id > $cursors[$event->channel]
        )), 0, $limit);
    }

    public function latestCursors(array $channels): array
    {
        $result = array_fill_keys($channels, 0);
        foreach ($this->events as $event) {
            if (array_key_exists($event->channel, $result)) {
                $result[$event->channel] = max($result[$event->channel], $event->id);
            }
        }
        return $result;
    }

    public function watermarks(array $channels): array
    {
        return array_intersect_key($this->deletedThrough + array_fill_keys($channels, 0), array_flip($channels));
    }

    public function deleteExpired(): int { return 0; }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE sample_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(128) NOT NULL,
        event VARCHAR(128) NOT NULL,
        payload_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL
    )');
    $pdo->exec('CREATE TABLE sample_watermarks (
        channel VARCHAR(128) PRIMARY KEY,
        event_id INTEGER NOT NULL,
        updated_at DATETIME NOT NULL
    )');
    $pdo->exec('CREATE TABLE sample_limits (
        key_hash CHAR(64) NOT NULL,
        bucket INTEGER NOT NULL,
        hits INTEGER NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (key_hash, bucket)
    )');
    return $pdo;
}

$store = new MemoryStore();
$client = new NoSocket($store);
$client->emit('orders', 'order.created', ['id' => 123]);
$client->emit('dashboard', 'metrics.updated', ['online' => 7]);
$client->emit('orders', 'order.created', ['id' => 456]);
check(count($store->events) === 3, 'emit appends events');

$signer = new SubscriptionSigner(str_repeat('s', 32));
$fixedToken = $signer->issue(['orders', 'dashboard'], 'user:1', 300, 1000);
check($signer->verify($fixedToken, 1001)['channels'] === ['dashboard', 'orders'], 'signed subscriptions round trip');
try {
    $signer->issue(['orders'], 'user:1', 0);
    check(false, 'token issuance rejects invalid TTL');
} catch (InvalidArgumentException) {
    check(true, 'token issuance rejects invalid TTL');
}

$poller = new PollService($store, $signer, new NullRateLimiter(), new Config(pollLimit: 10));
$result = $poller->poll(['orders' => ['cursor' => null]], $signer->issue(['orders'], 'user:1', 300), 'browser:1');
check($result->events === [] && $result->cursors === ['orders' => 3], 'live bootstrap starts after existing channel events');

$result = $poller->poll(['orders' => ['cursor' => null, 'replay' => 'retained']], $signer->issue(['orders'], 'user:1', 300), 'browser:1');
check(count($result->events) === 2 && $result->cursors === ['orders' => 3], 'retained bootstrap replays channel events');

$result = $poller->poll([
    'orders' => ['cursor' => 1],
    'dashboard' => ['cursor' => 0],
], $signer->issue(['orders', 'dashboard'], 'user:1', 300), 'browser:1');
check(count($result->events) === 2 && $result->cursors === ['orders' => 3, 'dashboard' => 2], 'poll advances independent channel cursors');

$paged = new PollService($store, $signer, new NullRateLimiter(), new Config(pollLimit: 1));
$result = $paged->poll(['orders' => ['cursor' => 0]], $signer->issue(['orders'], 'user:1', 300), 'browser:1');
check(count($result->events) === 1 && $result->hasMore, 'poll reports bounded pagination');

$store->deletedThrough['orders'] = 2;
$result = $poller->poll(['orders' => ['cursor' => 1]], $signer->issue(['orders'], 'user:1', 300), 'browser:1');
check($result->resyncRequired === ['orders'] && $result->events === [], 'poll reports retention gaps');

try {
    $poller->poll(['dashboard' => ['cursor' => 0]], $signer->issue(['orders'], 'user:1', 300), 'browser:1');
    check(false, 'poll rejects ungranted channels');
} catch (InvalidArgumentException) {
    check(true, 'poll rejects ungranted channels');
}

$batched = $client->emitBatch([
    ['channel' => 'orders', 'event' => 'order.updated', 'payload' => ['id' => 123]],
    ['channel' => 'dashboard', 'event' => 'metrics.updated', 'payload' => ['online' => 8]],
]);
check(count($batched) === 2 && count($store->events) === 5, 'batch emit falls back for custom event stores');

$pdo = sqlite();
$pdoStore = new PdoEventStore($pdo, 'sample_events', 'sample_watermarks');
$pdoStore->append('orders', 'order.created', ['id' => 789], 60);
check($pdoStore->latestCursors(['orders']) === ['orders' => 1], 'PDO store exposes per-channel latest cursors');
check($pdoStore->after(['orders' => 0], 10)[0]->payload['id'] === 789, 'PDO store reads per-channel cursor pages');
$pdoBatch = $pdoStore->appendBatch([
    ['channel' => 'orders', 'event' => 'order.updated', 'payload' => ['id' => 789], 'ttl_seconds' => 60],
    ['channel' => 'dashboard', 'event' => 'metrics.updated', 'payload' => ['online' => 9], 'ttl_seconds' => 60],
]);
check(count($pdoBatch) === 2 && $pdoBatch[1]->id === 3, 'PDO store appends a batch');
check($pdoStore->diagnostics() === ['events' => 3, 'expired_events' => 0, 'channels' => 2, 'watermarks' => 0], 'PDO store reports diagnostics');
check((new DiagnosticsService($pdoStore))->snapshot()['channels'] === 2, 'diagnostics service exposes store snapshot');
try {
    $pdoStore->appendBatch([
        ['channel' => 'orders', 'event' => 'order.updated', 'payload' => ['id' => 790], 'ttl_seconds' => 60],
        ['channel' => 'orders', 'event' => 'order.updated', 'payload' => ['invalid' => NAN], 'ttl_seconds' => 60],
    ]);
    check(false, 'PDO batch rolls back on failure');
} catch (JsonException) {
    check($pdoStore->diagnostics()['events'] === 3, 'PDO batch rolls back on failure');
}
$pdo->exec("UPDATE sample_events SET expires_at = '2000-01-01 00:00:00' WHERE id = 1");
check($pdoStore->deleteExpired() === 1, 'PDO cleanup deletes expired events');
check($pdoStore->watermarks(['orders']) === ['orders' => 1], 'PDO cleanup records channel watermark');
check($pdoStore->diagnostics()['watermarks'] === 1, 'PDO diagnostics count watermarks');

$metrics = [];
$instrumented = new NoSocket(
    $pdoStore,
    new Config(cleanupProbability: 1.0),
    new CallableMetricsHook(static function (string $metric, int|float $value, array $attributes) use (&$metrics): void {
        $metrics[] = [$metric, $value, $attributes];
    }),
);
$pdo->exec("UPDATE sample_events SET expires_at = '2000-01-01 00:00:00' WHERE id = 2");
$instrumented->emit('orders', 'order.created', ['id' => 999], 60);
check(count($metrics) === 2 && $metrics[0][0] === 'nosocket.events.emitted' && $metrics[1] === ['nosocket.events.cleaned', 1, []], 'emit can run probabilistic cleanup and metrics hooks');
$failingMetrics = new CallableMetricsHook(static function (): void {
    throw new RuntimeException('metrics unavailable');
});
check((new NoSocket($store, metrics: $failingMetrics))->emit('orders', 'order.created', ['id' => 1000])->id === 6, 'metrics failures do not interrupt emit');

$failingCleanupStore = new class implements EventStore {
    public function append(string $channel, string $event, array $payload, int $ttlSeconds): Event
    {
        return new Event(1, $channel, $event, $payload, gmdate(DATE_ATOM));
    }
    public function after(array $cursors, int $limit): array { return []; }
    public function latestCursors(array $channels): array { return array_fill_keys($channels, 0); }
    public function watermarks(array $channels): array { return array_fill_keys($channels, 0); }
    public function deleteExpired(): int { throw new RuntimeException('cleanup unavailable'); }
};
check((new NoSocket($failingCleanupStore, new Config(cleanupProbability: 1.0)))->emit('orders', 'order.created', [])->id === 1, 'probabilistic cleanup failures do not interrupt emit');

$limiter = new PdoRateLimiter($pdo, 'sample_limits');
check($limiter->hit('browser:1', 1, 60), 'rate limiter allows request within limit');
check(!$limiter->hit('browser:1', 1, 60), 'rate limiter rejects request above limit');

try {
    $limitedPoller = new PollService($store, $signer, new class implements \NoSocket\RateLimit\RateLimiter {
        public function hit(string $key, int $limit, int $windowSeconds): bool { return false; }
    });
    $limitedPoller->poll(['orders' => ['cursor' => 0]], $signer->issue(['orders'], 'user:1', 300), 'browser:1');
    check(false, 'poll exposes rate limit failure');
} catch (RateLimitExceeded) {
    check(true, 'poll exposes rate limit failure');
}

try {
    $client->emit('orders', 'order.created', [], 0);
    check(false, 'emit rejects invalid TTL');
} catch (InvalidArgumentException) {
    check(true, 'emit rejects invalid TTL');
}

fwrite(STDOUT, "PHP tests passed.\n");
