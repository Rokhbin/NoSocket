<?php

declare(strict_types=1);

namespace NoSocket\Symfony\Http;

use InvalidArgumentException;
use NoSocket\Http\PollService;
use NoSocket\Http\RateLimitExceeded;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PollController
{
    public function __construct(private readonly PollService $poller)
    {
    }

    #[Route('/nosocket/poll', name: 'nosocket_poll', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $token = preg_replace('/^Bearer\s+/i', '', (string) $request->headers->get('Authorization'));
            $key = (string) $request->getClientIp() . ':' . hash('sha256', $token);
            $body = $request->toArray();
            return new JsonResponse($this->poller->poll((array) ($body['subscriptions'] ?? []), $token, $key));
        } catch (RateLimitExceeded $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 429, ['Retry-After' => '120']);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 403);
        }
    }
}
