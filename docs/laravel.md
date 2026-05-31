# Laravel Integration

Install the adapter and publish its config and migration:

```bash
composer require nosocket/laravel
php artisan vendor:publish --tag=nosocket-config
php artisan vendor:publish --tag=nosocket-migrations
php artisan migrate
```

Set `NOSOCKET_SECRET` to a random secret. Emit through the facade:

```php
use NoSocket\Laravel\Facades\NoSocket;

NoSocket::emit('orders', 'order.created', ['id' => $order->id]);
```

The provider registers `POST /nosocket/poll` and authenticated `POST /nosocket/token`. Define a Gate before requesting tokens:

```php
Gate::define('nosocket-subscribe', function (User $user, string $channel): bool {
    return in_array($channel, ['orders', "user:{$user->id}"], true);
});
```

Schedule cleanup:

```php
Schedule::call(fn () => app(\NoSocket\NoSocket::class)->cleanup())->hourly();
Schedule::call(fn () => app(\NoSocket\RateLimit\PdoRateLimiter::class)->deleteExpired())->hourly();
```
