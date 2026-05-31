<?php

declare(strict_types=1);

namespace NoSocket\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use NoSocket\CodeIgniter\Config\Services;
use NoSocket\Http\RateLimitExceeded;

final class PollController extends \CodeIgniter\Controller
{
    public function index(): ResponseInterface
    {
        try {
            $token = preg_replace('/^Bearer\s+/i', '', (string) $this->request->getHeaderLine('Authorization'));
            $key = (string) $this->request->getIPAddress() . ':' . hash('sha256', $token);
            $body = (array) $this->request->getJSON(true);
            $result = Services::nosocketPoller()->poll((array) ($body['subscriptions'] ?? []), $token, $key);
            return $this->response->setJSON($result);
        } catch (RateLimitExceeded $exception) {
            return $this->response->setStatusCode(429)->setHeader('Retry-After', '120')->setJSON(['error' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $exception->getMessage()]);
        }
    }
}
