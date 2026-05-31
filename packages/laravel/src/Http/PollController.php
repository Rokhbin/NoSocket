<?php

declare(strict_types=1);

namespace NoSocket\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use NoSocket\Http\PollService;
use NoSocket\Http\RateLimitExceeded;

final class PollController
{
    public function __invoke(Request $request, PollService $service): JsonResponse
    {
        try {
            $token = (string) $request->bearerToken();
            $clientKey = (string) $request->ip() . ':' . hash('sha256', $token);

            return response()->json($service->poll((array) $request->input('subscriptions', []), $token, $clientKey));
        } catch (RateLimitExceeded $exception) {
            return response()->json(['error' => $exception->getMessage()], 429, ['Retry-After' => '120']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 403);
        }
    }
}
