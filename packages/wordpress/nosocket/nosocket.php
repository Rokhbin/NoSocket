<?php
/**
 * Plugin Name: NoSocket
 * Description: Realtime for shared-hosted WordPress sites.
 * Version: 0.2.0
 * License: MIT
 */

declare(strict_types=1);

use NoSocket\Auth\SubscriptionSigner;
use NoSocket\Config;
use NoSocket\Http\PollService;
use NoSocket\Http\RateLimitExceeded;
use NoSocket\NoSocket;
use NoSocket\RateLimit\PdoRateLimiter;
use NoSocket\Store\PdoEventStore;

defined('ABSPATH') || exit;

$autoloaders = [__DIR__ . '/vendor/autoload.php', dirname(__DIR__, 3) . '/vendor/autoload.php'];
foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require_once $autoloader;
        break;
    }
}

final class NoSocketWordPress
{
    public static function boot(): void
    {
        add_action('nosocket_emit', [self::class, 'emit'], 10, 4);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('nosocket_cleanup', [self::class, 'cleanup']);
        add_action('init', [self::class, 'scheduleCleanup']);
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}nosocket_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(128) NOT NULL,
            event VARCHAR(128) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY channel_id (channel, id),
            KEY expires_at (expires_at)
        ) {$charset};");
        dbDelta("CREATE TABLE {$wpdb->prefix}nosocket_rate_limits (
            key_hash CHAR(64) NOT NULL,
            bucket BIGINT NOT NULL,
            hits INT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (key_hash, bucket),
            KEY expires_at (expires_at)
        ) {$charset};");
        dbDelta("CREATE TABLE {$wpdb->prefix}nosocket_channel_watermarks (
            channel VARCHAR(128) NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (channel)
        ) {$charset};");
    }

    public static function routes(): void
    {
        register_rest_route('nosocket/v1', '/poll', [
            'methods' => 'POST',
            'callback' => [self::class, 'poll'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('nosocket/v1', '/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'token'],
            'permission_callback' => static fn (): bool => is_user_logged_in(),
        ]);
    }

    public static function emit(string $channel, string $event, array $payload = [], ?int $ttlSeconds = null): void
    {
        self::client()->emit($channel, $event, $payload, $ttlSeconds);
    }

    public static function poll(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $token = preg_replace('/^Bearer\s+/i', '', (string) $request->get_header('authorization'));
            $key = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . hash('sha256', $token);
            $result = self::poller()->poll((array) $request->get_param('subscriptions'), $token, $key);
            return new WP_REST_Response($result, 200, ['Cache-Control' => 'no-store']);
        } catch (RateLimitExceeded $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 429, ['Retry-After' => '120']);
        } catch (InvalidArgumentException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 403);
        }
    }

    public static function token(WP_REST_Request $request): WP_REST_Response
    {
        $requested = array_values(array_filter((array) $request->get_param('channels'), 'is_string'));
        /** @var list<string> $granted */
        $granted = apply_filters('nosocket_authorized_channels', $requested, get_current_user_id());
        $token = self::signer()->issue($granted, 'wp-user:' . get_current_user_id(), 3600);
        return new WP_REST_Response(['token' => $token, 'channels' => $granted], 201);
    }

    public static function cleanup(): void
    {
        self::client()->cleanup();
        self::limiter()->deleteExpired();
    }

    public static function scheduleCleanup(): void
    {
        if (!wp_next_scheduled('nosocket_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'nosocket_cleanup');
        }
    }

    private static function client(): NoSocket
    {
        return new NoSocket(self::store(), new Config());
    }

    private static function poller(): PollService
    {
        return new PollService(self::store(), self::signer(), self::limiter());
    }

    private static function signer(): SubscriptionSigner
    {
        return new SubscriptionSigner(wp_salt('auth'));
    }

    private static function store(): PdoEventStore
    {
        global $wpdb;
        return new PdoEventStore(
            self::pdo(),
            $wpdb->prefix . 'nosocket_events',
            $wpdb->prefix . 'nosocket_channel_watermarks',
        );
    }

    private static function limiter(): PdoRateLimiter
    {
        global $wpdb;
        return new PdoRateLimiter(self::pdo(), $wpdb->prefix . 'nosocket_rate_limits');
    }

    private static function pdo(): PDO
    {
        static $pdo;
        return $pdo ??= new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}

register_activation_hook(__FILE__, [NoSocketWordPress::class, 'activate']);
NoSocketWordPress::boot();
