<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Calculadora\Data\BuildDataController;
use PivotPHP\Core\Core\Application;

$app = new Application();

// CORS não é necessário: o nginx do frontend faz proxy para /api/,
// então o browser sempre fala com a mesma origem (localhost:3000).

$app->post('/api/build', [BuildDataController::class, 'handle']);

$app->run();
