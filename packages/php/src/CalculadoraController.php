<?php

declare(strict_types=1);

namespace Calculadora\Data;

use PivotPHP\Core\Http\Request;
use PivotPHP\Core\Http\Response;

class CalculadoraController
{
    /**
     * GET /api/produtos
     * Retorna todas as marcas com seus produtos, lidos do banco de dados.
     */
    public static function listar(Request $request, Response $response): Response
    {
        try {
            $pdo = Database::connect();
        } catch (\Exception $e) {
            return $response->status(503)->json(['error' => 'Banco de dados indisponível: ' . $e->getMessage()]);
        }

        $stmt = $pdo->query(
            'SELECT m.id AS marca_id, m.nome AS marca_nome,
                    p.id, p.nome, p.preco
             FROM marcas m
             JOIN produtos p ON p.marca_id = m.id
             ORDER BY m.nome, p.nome'
        );

        $marcas = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = (int) $row['marca_id'];
            if (!isset($marcas[$mid])) {
                $marcas[$mid] = ['id' => $mid, 'nome' => $row['marca_nome'], 'produtos' => []];
            }
            $marcas[$mid]['produtos'][] = [
                'id'    => (int) $row['id'],
                'nome'  => $row['nome'],
                'preco' => (float) $row['preco'],
            ];
        }

        return $response->json(['marcas' => array_values($marcas)]);
    }

    /**
     * POST /api/calcular
     *
     * Body JSON: { "itens": [{"produto_id": 1, "qty": 10}, ...] }
     *
     * Regras (espelhadas do app.js):
     *   - Mínimo 3 produtos diferentes com qty > 0
     *   - Mínimo 100 unidades no total
     *
     * Resposta: { "total": 1234.56, "unidades": 120, "count": 5 }
     */
    public static function calcular(Request $request, Response $response): Response
    {
        $raw  = (string) $request->getBody();
        $body = json_decode($raw, true);
        $itens = $body['itens'] ?? [];

        if (empty($itens) || !is_array($itens)) {
            return $response->status(400)->json(['error' => 'Envie um array "itens" com produto_id e qty.']);
        }

        // Filtra apenas itens com qty > 0 e produto_id válido
        $itensValidos = array_filter($itens, static function (mixed $item): bool {
            return isset($item['produto_id'], $item['qty'])
                && (int) $item['qty'] > 0
                && (int) $item['produto_id'] > 0;
        });

        $count = count($itensValidos);
        $units = (int) array_sum(array_column(array_values($itensValidos), 'qty'));

        // Validações antecipadas (evita query desnecessária)
        if ($count < 3) {
            return $response->status(422)->json([
                'error' => 'Selecione pelo menos 3 produtos diferentes.',
                'count' => $count,
            ]);
        }
        if ($units < 100) {
            return $response->status(422)->json([
                'error'    => 'O total deve ser de pelo menos 100 unidades.',
                'unidades' => $units,
            ]);
        }

        // Busca preços no banco
        try {
            $pdo = Database::connect();
        } catch (\Exception $e) {
            return $response->status(503)->json(['error' => 'Banco de dados indisponível: ' . $e->getMessage()]);
        }

        $ids         = array_map(static fn(array $i) => (int) $i['produto_id'], array_values($itensValidos));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare("SELECT id, preco FROM produtos WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        /** @var array<int,float> $precos  [produto_id => preco] */
        $precos = [];
        foreach ($stmt->fetchAll() as $row) {
            $precos[(int) $row['id']] = (float) $row['preco'];
        }

        // Calcula o total (mesma lógica do app.js — arredondado apenas no resultado final)
        $total = 0.0;
        foreach ($itensValidos as $item) {
            $produtoId = (int) $item['produto_id'];
            $qty       = (int) $item['qty'];

            if (!isset($precos[$produtoId])) {
                continue; // produto não existe no DB — ignora silenciosamente
            }

            $total += $qty * $precos[$produtoId];
        }

        // sprintf('%.2f') formata diretamente sobre o valor binário IEEE 754,
        // sem a correção de arredondamento que round() aplica.
        // Isso replica o comportamento de Number.toFixed(2) do JavaScript.

        // DEBUG TEMPORÁRIO — remover após diagnóstico
        $debug = [];
        foreach ($itensValidos as $item) {
            $pid = (int) $item['produto_id'];
            $q   = (int) $item['qty'];
            $p   = $precos[$pid] ?? null;
            $debug[] = [
                'produto_id' => $pid,
                'qty'        => $q,
                'preco_db'   => $p,
                'subtotal'   => $p !== null ? $q * $p : null,
            ];
        }

        return $response->json([
            'total'     => (float) round($total, 2, PHP_ROUND_HALF_DOWN),
            '_total_raw' => $total,
            '_itens'    => $debug,
            'unidades'  => $units,
            'count'     => $count,
        ]);
    }
}
