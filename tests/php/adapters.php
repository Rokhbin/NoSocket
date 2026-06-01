<?php

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$fixtures = [
    'laravel' => [
        'manifest' => 'packages/laravel/composer.json',
        'files' => [
            'packages/laravel/src/NoSocketServiceProvider.php',
            'packages/laravel/src/Http/PollController.php',
            'packages/laravel/src/Http/TokenController.php',
            'packages/laravel/database/migrations/2026_01_01_000000_create_nosocket_tables.php',
        ],
    ],
    'wordpress' => [
        'manifest' => 'packages/wordpress/nosocket/composer.json',
        'files' => ['packages/wordpress/nosocket/nosocket.php'],
    ],
    'symfony' => [
        'manifest' => 'packages/symfony/composer.json',
        'files' => ['packages/symfony/config/services.yaml', 'packages/symfony/src/Http/PollController.php'],
    ],
    'codeigniter' => [
        'manifest' => 'packages/codeigniter/composer.json',
        'files' => [
            'packages/codeigniter/src/Config/Services.php',
            'packages/codeigniter/src/Controllers/PollController.php',
            'packages/codeigniter/src/Database/Migrations/2026-06-01-000000_CreateNoSocketTables.php',
        ],
    ],
];

foreach ($fixtures as $adapter => $fixture) {
    $manifest = json_decode((string) file_get_contents($root . '/' . $fixture['manifest']), true, 512, JSON_THROW_ON_ERROR);
    check(($manifest['require']['nosocket/nosocket'] ?? null) === '^0.2', "{$adapter} adapter requires compatible core");
    foreach ($fixture['files'] as $file) {
        check(is_file($root . '/' . $file), "{$adapter} fixture includes {$file}");
    }
}

foreach ([
    'packages/laravel/src/Http/PollController.php',
    'packages/wordpress/nosocket/nosocket.php',
    'packages/symfony/src/Http/PollController.php',
    'packages/codeigniter/src/Controllers/PollController.php',
] as $controller) {
    check(str_contains((string) file_get_contents($root . '/' . $controller), "'subscriptions'"), "{$controller} accepts subscription map");
}

fwrite(STDOUT, "Adapter fixtures passed.\n");
