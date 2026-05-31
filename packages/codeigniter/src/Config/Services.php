<?php

declare(strict_types=1);

namespace NoSocket\CodeIgniter\Config;

use Config\Database;
use NoSocket\Auth\SubscriptionSigner;
use NoSocket\Config;
use NoSocket\Http\PollService;
use NoSocket\NoSocket;
use NoSocket\RateLimit\PdoRateLimiter;
use NoSocket\Store\PdoEventStore;

final class Services extends \CodeIgniter\Config\BaseService
{
    public static function nosocket(bool $getShared = true): NoSocket
    {
        if ($getShared) {
            return static::getSharedInstance('nosocket');
        }
        return new NoSocket(new PdoEventStore(Database::connect()->connID), new Config());
    }

    public static function nosocketPoller(bool $getShared = true): PollService
    {
        if ($getShared) {
            return static::getSharedInstance('nosocketPoller');
        }
        $pdo = Database::connect()->connID;
        return new PollService(
            new PdoEventStore($pdo),
            new SubscriptionSigner((string) env('NOSOCKET_SECRET')),
            new PdoRateLimiter($pdo),
            new Config(),
        );
    }
}
