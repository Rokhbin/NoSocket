<?php

declare(strict_types=1);

namespace NoSocket\Laravel;

use Illuminate\Support\ServiceProvider;
use NoSocket\Auth\SubscriptionSigner;
use NoSocket\Config;
use NoSocket\Http\PollService;
use NoSocket\NoSocket;
use NoSocket\RateLimit\PdoRateLimiter;
use NoSocket\Store\PdoEventStore;

final class NoSocketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/nosocket.php', 'nosocket');
        $this->app->singleton(Config::class, fn (): Config => new Config(
            eventTtlSeconds: (int) config('nosocket.event_ttl_seconds'),
            pollLimit: (int) config('nosocket.poll_limit'),
            rateLimit: (int) config('nosocket.rate_limit'),
            rateWindowSeconds: (int) config('nosocket.rate_window_seconds'),
            tokenTtlSeconds: (int) config('nosocket.token_ttl_seconds'),
        ));
        $this->app->singleton(PdoEventStore::class, fn (): PdoEventStore => new PdoEventStore($this->pdo()));
        $this->app->singleton(PdoRateLimiter::class, fn (): PdoRateLimiter => new PdoRateLimiter($this->pdo()));
        $this->app->singleton(SubscriptionSigner::class, fn (): SubscriptionSigner => new SubscriptionSigner(
            (string) config('nosocket.secret')
        ));
        $this->app->singleton(NoSocket::class, fn ($app): NoSocket => new NoSocket($app->make(PdoEventStore::class), $app->make(Config::class)));
        $this->app->singleton(PollService::class, fn ($app): PollService => new PollService(
            $app->make(PdoEventStore::class),
            $app->make(SubscriptionSigner::class),
            $app->make(PdoRateLimiter::class),
            $app->make(Config::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([dirname(__DIR__) . '/config/nosocket.php' => config_path('nosocket.php')], 'nosocket-config');
        $this->publishes([dirname(__DIR__) . '/database/migrations' => database_path('migrations')], 'nosocket-migrations');
        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/nosocket.php');
    }

    private function pdo(): \PDO
    {
        return $this->app->make('db')->connection()->getPdo();
    }
}
