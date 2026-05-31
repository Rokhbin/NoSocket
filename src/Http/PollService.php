<?php

declare(strict_types=1);

namespace NoSocket\Http;

use InvalidArgumentException;
use NoSocket\Auth\SubscriptionSigner;
use NoSocket\Config;
use NoSocket\RateLimit\RateLimiter;
use NoSocket\Store\EventStore;

final class PollService
{
    public function __construct(
        private readonly EventStore $store,
        private readonly SubscriptionSigner $signer,
        private readonly RateLimiter $rateLimiter,
        private readonly Config $config = new Config(),
    ) {
    }

    /**
     * @param array<string, array{cursor: int|null, replay?: string}> $subscriptions
     */
    public function poll(array $subscriptions, string $token, string $clientKey): PollResult
    {
        if (!$this->rateLimiter->hit($clientKey, $this->config->rateLimit, $this->config->rateWindowSeconds)) {
            throw new RateLimitExceeded('NoSocket polling rate limit exceeded.');
        }

        $claims = $this->signer->verify($token);
        $channels = array_keys($subscriptions);
        if ($channels === [] || array_diff($channels, $claims['channels']) !== []) {
            throw new InvalidArgumentException('Requested channels are not granted by the subscription token.');
        }

        $latest = $this->store->latestCursors($channels);
        $watermarks = $this->store->watermarks($channels);
        $cursors = [];
        $resyncRequired = [];
        foreach ($subscriptions as $channel => $subscription) {
            if (!is_string($channel) || !is_array($subscription)) {
                throw new InvalidArgumentException('Subscriptions must be keyed by channel.');
            }
            $cursor = $subscription['cursor'] ?? null;
            $replay = $subscription['replay'] ?? 'live';
            \NoSocket\Channel::validate($channel);
            if ($cursor !== null && (!is_int($cursor) || $cursor < 0)) {
                throw new InvalidArgumentException('Subscription cursors must be non-negative integers or null.');
            }
            if (!in_array($replay, ['live', 'retained'], true)) {
                throw new InvalidArgumentException('Subscription replay must be live or retained.');
            }
            if ($cursor === null) {
                $cursors[$channel] = $replay === 'retained' ? $watermarks[$channel] : $latest[$channel];
                continue;
            }
            if ($cursor < $watermarks[$channel]) {
                $resyncRequired[] = $channel;
                $cursors[$channel] = $cursor;
                continue;
            }
            $cursors[$channel] = $cursor;
        }

        $queryCursors = array_diff_key($cursors, array_flip($resyncRequired));
        $events = $this->store->after($queryCursors, $this->config->pollLimit + 1);
        $hasMore = count($events) > $this->config->pollLimit;
        if ($hasMore) {
            array_pop($events);
        }
        foreach ($events as $event) {
            $cursors[$event->channel] = max($cursors[$event->channel], $event->id);
        }

        return new PollResult($events, $cursors, $hasMore, $resyncRequired);
    }
}
