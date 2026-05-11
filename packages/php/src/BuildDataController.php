<?php

declare(strict_types=1);

namespace Calculadora\Data;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PivotPHP\Core\Http\Request;
use PivotPHP\Core\Http\Response;

class BuildDataController
{
    public static function handle(Request $request, Response $response): Response
    {
        // Valida o upload
        if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            return $response->status(400)->json([
                'error' => 'Nenhum arquivo enviado. Use o campo "file" no multipart/form-data.',
            ]);
        }

        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return $response->status(400)->json([
                'error' => 'Erro no upload do arquivo. Código: ' . $_FILES['file']['error'],
            ]);
        }

        $tmpPath   = $_FILES['file']['tmp_name'];
        $sheetName = $_POST['sheet'] ?? $_GET['sheet'] ?? null;

        // Carrega a planilha
        try {
            $spreadsheet = IOFactory::load($tmpPath);
        } catch (\Exception $e) {
            return $response->status(422)->json([
                'error' => 'Arquivo inválido ou formato não suportado: ' . $e->getMessage(),
            ]);
        }

        // Seleciona a aba
        if ($sheetName !== null && $sheetName !== '') {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (!$sheet) {
                $available = implode(', ', $spreadsheet->getSheetNames());
                return $response->status(422)->json([
                    'error' => "Aba \"$sheetName\" não encontrada. Disponíveis: $available",
                ]);
            }
        } else {
            $sheet = $spreadsheet->getActiveSheet();
        }

        /*
         * Lê as linhas com valores brutos (raw):
         *   toArray($nullValue, $calculateFormulas, $formatData, $returnCellRef)
         *   - false, false = sem cálculo de fórmula e sem formatação
         *   - false        = índices 0-based (não usa referência de célula)
         *
         * Layout esperado (a partir da linha 2, índice 1):
         *   [0] => MARCA
         *   [1] => CATEGORIA (ignorada)
         *   [2] => PRODUTO PRICING
         *   [3] => PRECO VAREJO
         */
        $rows    = $sheet->toArray(null, false, false, false);
        $marcasMap = [];
        $pulados   = 0;

        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue; // pula cabeçalho
            }

            $marca   = $row[0] ?? null;
            $produto = $row[2] ?? null;
            $preco   = $row[3] ?? null;

            if (!$marca || !$produto || $preco === null || $preco === '') {
                $pulados++;
                continue;
            }

            // Converte preço para float (suporta vírgula como separador decimal)
            if (is_numeric($preco)) {
                $precoNum = (float) $preco;
            } else {
                $precoNum = (float) str_replace(',', '.', (string) $preco);
            }

            if (!is_finite($precoNum)) {
                $pulados++;
                continue;
            }

            $marcaKey = trim((string) $marca);
            if (!isset($marcasMap[$marcaKey])) {
                $marcasMap[$marcaKey] = [];
            }
            $marcasMap[$marcaKey][] = [
                'nome'  => trim((string) $produto),
                'preco' => $precoNum,
            ];
        }

        $data = [
            'marcas' => array_map(
                static fn(string $nome, array $produtos) => ['nome' => $nome, 'produtos' => $produtos],
                array_keys($marcasMap),
                array_values($marcasMap)
            ),
        ];

        /*
         * json_encode sem JSON_UNESCAPED_UNICODE escapa caracteres não-ASCII para \uXXXX
         * por padrão — equivalente ao que o build-data.js faz manualmente com .replace().
         * O resultado é uma string pure-ASCII segura para base64 e para atob() no browser.
         */
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $duty = base64_encode($json);

        $totalProdutos = (int) array_sum(
            array_map(static fn(array $m) => count($m['produtos']), $data['marcas'])
        );

        return $response->json([
            'duty'  => $duty,
            'stats' => [
                'marcas'   => count($data['marcas']),
                'produtos' => $totalProdutos,
                'pulados'  => $pulados,
            ],
        ]);
    }
}
