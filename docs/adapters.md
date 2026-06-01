# Framework Adapters

## Symfony

Copy or package [`packages/symfony`](../packages/symfony), import `config/services.yaml`, apply the core SQL schema, and enable attribute routing for `NoSocket\Symfony\Http\PollController`. The adapter expects a PDO-backed Doctrine connection.

## CodeIgniter 4

Copy or package [`packages/codeigniter`](../packages/codeigniter), apply the core SQL schema, and register:

```php
$routes->post('nosocket/poll', '\NoSocket\CodeIgniter\Controllers\PollController::index');
```

The adapter expects CodeIgniter's PDO database driver. Use `service('nosocket')` style wiring or `NoSocket\CodeIgniter\Config\Services::nosocket()` to emit.

Run the included migration from `packages/codeigniter/src/Database/Migrations` or apply the core SQL schema.

## Adapter Fixtures

CI runs `php tests/php/adapters.php` to verify package manifests, required wiring files, migrations, and the `subscriptions` poll contract for Laravel, WordPress, Symfony, and CodeIgniter 4. Framework applications should still run their own HTTP integration tests after installing an adapter.
