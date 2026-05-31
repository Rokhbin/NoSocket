<?php

declare(strict_types=1);

namespace NoSocket\Auth;

use InvalidArgumentException;
use JsonException;
use NoSocket\Channel;

final class SubscriptionSigner
{
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('NoSocket signing secret must be at least 32 characters.');
        }
    }

    /**
     * @param list<string> $channels
     */
    public function issue(array $channels, string $subject, int $ttlSeconds, ?int $now = null): string
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Subscription token TTL must be greater than zero.');
        }
        $channels = $this->normalizeChannels($channels);
        $now ??= time();
        $claims = [
            'channels' => $channels,
            'sub' => $subject,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'nonce' => bin2hex(random_bytes(12)),
        ];
        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $payload . '.' . $this->encode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    /**
     * @return array{channels: list<string>, sub: string, iat: int, exp: int, nonce: string}
     */
    public function verify(string $token, ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Malformed subscription token.');
        }

        [$payload, $providedSignature] = $parts;
        $expectedSignature = $this->encode(hash_hmac('sha256', $payload, $this->secret, true));
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new InvalidArgumentException('Invalid subscription token signature.');
        }

        try {
            $claims = json_decode($this->decode($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Malformed subscription token payload.', 0, $exception);
        }

        $now ??= time();
        if (!is_array($claims) || !isset($claims['channels'], $claims['sub'], $claims['iat'], $claims['exp'], $claims['nonce'])) {
            throw new InvalidArgumentException('Subscription token is missing required claims.');
        }
        if (!is_int($claims['exp']) || !is_int($claims['iat']) || $claims['exp'] <= $now || $claims['iat'] > $now + 60) {
            throw new InvalidArgumentException('Subscription token is expired or not active.');
        }
        if (!is_string($claims['sub']) || !is_string($claims['nonce']) || !is_array($claims['channels'])) {
            throw new InvalidArgumentException('Subscription token has invalid claims.');
        }

        /** @var list<string> $channels */
        $channels = $this->normalizeChannels($claims['channels']);
        $claims['channels'] = $channels;

        /** @var array{channels: list<string>, sub: string, iat: int, exp: int, nonce: string} $claims */
        return $claims;
    }

    /**
     * @param list<string> $channels
     * @return list<string>
     */
    private function normalizeChannels(array $channels): array
    {
        $channels = array_values(array_unique(array_map(static function (mixed $channel): string {
            if (!is_string($channel)) {
                throw new InvalidArgumentException('Subscription channels must be strings.');
            }

            return Channel::validate($channel);
        }, $channels)));
        sort($channels);

        return $channels;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Malformed subscription token encoding.');
        }

        return $decoded;
    }
}
