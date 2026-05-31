<?php

declare(strict_types=1);

namespace NoSocket\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use NoSocket\Auth\SubscriptionSigner;
use NoSocket\Config;

final class TokenController
{
    public function __invoke(Request $request, SubscriptionSigner $signer, Config $config): JsonResponse
    {
        $channels = array_values(array_filter(
            (array) $request->input('channels', []),
            static fn (mixed $channel): bool => is_string($channel) && Gate::allows('nosocket-subscribe', $channel)
        ));
        $ttl = $config->tokenTtlSeconds;

        return response()->json([
            'token' => $signer->issue($channels, 'laravel-user:' . $request->user()->getAuthIdentifier(), $ttl),
            'channels' => $channels,
            'expires_at' => gmdate(DATE_ATOM, time() + $ttl),
        ], 201);
    }
}
