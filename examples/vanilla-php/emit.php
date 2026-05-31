<?php

declare(strict_types=1);

/** @var \NoSocket\NoSocket $nosocket */
$nosocket = require __DIR__ . '/bootstrap.php';

$nosocket->emit('orders', 'order.created', ['id' => 123, 'total' => 49.95]);
