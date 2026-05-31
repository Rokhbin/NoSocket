# Framework Adapters

## Symfony

Copy or package [`packages/symfony`](../packages/symfony), import `config/services.yaml`, apply the core SQL schema, and enable attribute routing for `NoSocket\Symfony\Http\PollController`. The adapter expects a PDO-backed Doctrine connection.

## CodeIgniter 4

Copy or package [`packages/codeigniter`](../packages/codeigniter), apply the core SQL schema, and register:

```php
$routes->post('nosocket/poll', '\NoSocket\CodeIgniter\Controllers\PollController::index');
```

The adapter expects CodeIgniter's PDO database driver. Use `service('nosocket')` style wiring or `NoSocket\CodeIgniter\Config\Services::nosocket()` to emit.
