# WordPress Integration

Copy [`packages/wordpress/nosocket`](../packages/wordpress/nosocket) into `wp-content/plugins`, install its Composer dependencies, and activate **NoSocket**:

```bash
cd wp-content/plugins/nosocket
composer install --no-dev
```

Activation creates prefixed event, rate-limit, and watermark tables.

Emit from WordPress code:

```php
do_action('nosocket_emit', 'orders', 'order.created', ['id' => 123], 3600);
```

The plugin exposes:

| Route | Purpose |
| --- | --- |
| `POST /wp-json/nosocket/v1/poll` | Poll retained events |
| `POST /wp-json/nosocket/v1/token` | Issue a grant for an authenticated user |

Authorize channels with a filter:

```php
add_filter('nosocket_authorized_channels', function (array $requested, int $userId): array {
    return array_values(array_intersect($requested, ['notifications', "user:{$userId}"]));
}, 10, 2);
```

WooCommerce integrations can emit from hooks such as `woocommerce_new_order`. The plugin schedules hourly cleanup with WP-Cron.
