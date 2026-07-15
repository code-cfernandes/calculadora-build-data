<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Calculadora\Data\Auth;
use Calculadora\Data\BuildDataController;
use Calculadora\Data\CalculadoraController;
use PivotPHP\Core\Core\Application;
use PivotPHP\Core\Http\Request;
use PivotPHP\Core\Http\Response;

$app = new Application();

// CORS não é necessário: o nginx do frontend faz proxy para /api/,
// então o browser sempre fala com a mesma origem.

$app->post('/api/login', function (Request $req, Response $res): Response {
    $body = $req->body;
    $user = trim((string) ($body->user ?? ''));
    $pass = (string) ($body->password ?? '');

    if ($user === '' || $pass === '') {
        return $res->status(400)->json(['error' => 'Informe usuário e senha.']);
    }

    $token = Auth::token($user, $pass);
    if ($token === false) {
        return $res->status(401)->json(['error' => 'Usuário ou senha incorretos.']);
    }

    return $res->json(['token' => $token]);
});

$app->post('/api/build',          [BuildDataController::class,   'handle']);
$app->get('/api/produtos',        [CalculadoraController::class, 'listar']);
$app->get('/api/admin/produtos',  [CalculadoraController::class, 'listarAdmin']);
$app->get('/api/admin/duty',      [CalculadoraController::class, 'exportDuty']);
$app->post('/api/calcular',       [CalculadoraController::class, 'calcular']);

$app->run();
