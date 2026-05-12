<?php

declare(strict_types=1);

namespace Calculadora\Data;

use PivotPHP\Core\Http\Request;
use PivotPHP\Core\Http\Response;

class Auth
{
    /**
     * Valida o token Bearer da requisição usando hash_equals (tempo constante).
     *
     * O token esperado é HMAC-SHA256(ADMIN_USER:ADMIN_PASSWORD, ADMIN_SECRET)
     * codificado em base64. Nunca compara user/pass diretamente.
     */
    public static function check(Request $request): bool
    {
        $header = $request->headers->authorization() ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $received = substr($header, 7);
        $expected = self::expectedToken();

        if ($expected === null) {
            return false;
        }

        return hash_equals($expected, $received);
    }

    /**
     * Valida user/pass e devolve o token completo "Bearer <hmac>" ou false.
     * Chamado apenas no endpoint de login.
     */
    public static function token(string $user, string $pass): string|false
    {
        if (!self::credentialsValid($user, $pass)) {
            return false;
        }

        $token = self::expectedToken();

        if ($token === null) {
            return false;
        }

        return 'Bearer ' . $token;
    }

    /**
     * Resposta 401 sem WWW-Authenticate (não usamos Basic Auth nativo).
     */
    public static function deny(Response $response): Response
    {
        return $response->status(401)->json(['error' => 'Autenticação necessária.']);
    }

    /**
     * Gera o token canônico a partir das variáveis de ambiente:
     *   base64( HMAC-SHA256( ADMIN_USER:ADMIN_PASSWORD, ADMIN_SECRET ) )
     *
     * Retorna null se alguma variável não estiver configurada.
     */
    private static function expectedToken(): ?string
    {
        $user   = (string) (getenv('ADMIN_USER')     ?: '');
        $pass   = (string) (getenv('ADMIN_PASSWORD') ?: '');
        $secret = (string) (getenv('ADMIN_SECRET')   ?: '');

        if ($user === '' || $pass === '' || $secret === '') {
            return null;
        }

        return base64_encode(hash_hmac('sha256', $user . ':' . $pass, $secret));
    }

    /**
     * Valida user/pass contra as variáveis de ambiente em tempo constante.
     */
    private static function credentialsValid(string $user, string $pass): bool
    {
        $expectedUser = (string) (getenv('ADMIN_USER')     ?: '');
        $expectedPass = (string) (getenv('ADMIN_PASSWORD') ?: '');

        return $expectedUser !== ''
            && $expectedPass !== ''
            && hash_equals($expectedUser, $user)
            && hash_equals($expectedPass, $pass);
    }
}
