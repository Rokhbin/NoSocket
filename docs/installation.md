# Installation

## Vanilla PHP

1. Install `composer require nosocket/nosocket`.
2. Import the MySQL/MariaDB, PostgreSQL, or SQLite schema from [`database`](../database).
3. Configure a PDO connection and construct `NoSocket\NoSocket` with `PdoEventStore`.
4. Mount [`public/poll.php`](../public/poll.php) as `POST /nosocket/poll` or adapt its small controller to your router.
5. Generate a random signing secret with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
6. Adapt [`examples/vanilla-php/token.php`](../examples/vanilla-php/token.php) to issue channel grants only after your existing authentication and authorization checks.
7. Run cleanup hourly: invoke `$nosocket->cleanup()` and `$rateLimiter->deleteExpired()` from cron or your scheduler.

SQLite is supported for small installations where the PHP runtime has `pdo_sqlite`. Use `sqlite:/absolute/path/to/nosocket.sqlite` and import [`database/sqlite/schema.sql`](../database/sqlite/schema.sql). MySQL, MariaDB, and PostgreSQL remain better choices when writes are concurrent.

For a basic emitter, see [`examples/vanilla-php`](../examples/vanilla-php).

## Browser

Publish [`assets/js/nosocket.js`](../assets/js/nosocket.js), import `createNoSocket`, configure `tokenProvider`, subscribe to channels, attach listeners, then call `start()`. Use a private namespace such as `shop:user-42` to prevent cursor sharing between user sessions.

## Production Checklist

- Require HTTPS.
- Keep token TTL short and scope grants to the minimum channels required.
- Keep private data out of public channels.
- Protect token-issuing POST routes with your framework's CSRF middleware.
- Validate payloads before emitting and keep payloads compact.
- Schedule expiry cleanup for events and `nosocket_rate_limits`.
- Confirm host request quotas with burst mode enabled.
- Handle `onResync` by refreshing the relevant application snapshot.
- Keep the diagnostics endpoint disabled unless it is protected with a strong `NOSOCKET_DIAGNOSTICS_TOKEN`.
