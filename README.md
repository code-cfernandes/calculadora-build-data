# Calculadora de Vendas — Build Data

Serviço que converte uma planilha Excel de precificação em um bundle de dados consumível pelo frontend da Calculadora de Vendas, além de expor rotas para listagem de produtos e cálculo de pedidos.

---

## Arquitetura

```
┌─────────────────────────────────────────────────────────┐
│                     Docker Compose                      │
│                                                         │
│  ┌──────────────┐   proxy /api/   ┌──────────────────┐  │
│  │   frontend   │ ──────────────► │       api        │  │
│  │  nginx:alpine│                 │  php:8.3-apache  │  │
│  │  porta host  │                 │   porta host     │  │
│  │ FRONTEND_PORT│                 │    API_PORT      │  │
│  └──────────────┘                 └────────┬─────────┘  │
│                                            │ PDO        │
│                                   ┌────────▼─────────┐  │
│                                   │        db        │  │
│                                   │ postgres:16-alpine│  │
│                                   │  volume db_data  │  │
│                                   └──────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

O nginx do frontend faz proxy reverso para `/api/*`, eliminando a necessidade de CORS.

---

## Serviços

| Serviço    | Imagem               | Variável de porta  | Descrição                          |
|------------|----------------------|--------------------|------------------------------------|
| `frontend` | `nginx:1.27-alpine`  | `FRONTEND_PORT`    | Serve o HTML/JS estático           |
| `api`      | `php:8.3-apache`     | `API_PORT`         | REST API — processa Excel e calcula|
| `db`       | `postgres:16-alpine` | —                  | Persistência dos dados             |

---

## Configuração

Copie `.env.example` para `.env` e ajuste os valores:

```bash
cp .env.example .env
```

### Variáveis de ambiente

| Variável        | Padrão        | Descrição                              |
|-----------------|---------------|----------------------------------------|
| `FRONTEND_PORT` | `3000`        | Porta do frontend no host              |
| `API_PORT`      | `8080`        | Porta da API no host                   |
| `NGINX_PORT`    | `80`          | Porta interna do nginx (container)     |
| `DB_NAME`       | `calculadora` | Nome do banco PostgreSQL               |
| `DB_USER`       | `calculadora` | Usuário do banco                       |
| `DB_PASSWORD`   | `secret`      | Senha do banco                         |

---

## Setup

```bash
# Subir todos os serviços
docker compose up --build

# Subir em background
docker compose up --build -d

# Parar
docker compose down

# Remover dados do banco (volume)
docker compose down -v
```

---

## Layout esperado da planilha

A aba ativa (ou a especificada via parâmetro `sheet`) deve ter cabeçalho na linha 1 e dados a partir da linha 2:

| Coluna | Campo           | Obrigatório |
|--------|-----------------|-------------|
| A      | MARCA           | Sim         |
| B      | CATEGORIA       | Ignorado    |
| C      | PRODUTO PRICING | Sim         |
| D      | PRECO VAREJO    | Sim         |

Linhas sem marca, produto ou preço são ignoradas silenciosamente.

---

## API

**Base URL:** `http://localhost:<API_PORT>`

---

### `POST /api/build`

Processa um arquivo Excel, persiste os dados no banco e retorna o bundle `data.duty` (JSON minificado em base64).

**A cada novo upload, todos os registros anteriores são apagados (TRUNCATE) e substituídos pelos dados do novo arquivo.**

**Request**

```
Content-Type: multipart/form-data
```

| Campo   | Tipo   | Obrigatório | Descrição                                   |
|---------|--------|-------------|---------------------------------------------|
| `file`  | file   | Sim         | Planilha `.xlsx`, `.xls`, `.ods` ou `.csv`  |
| `sheet` | string | Não         | Nome da aba (usa a aba ativa por padrão)    |

**Response `200`**

```json
{
  "duty": "<base64>",
  "stats": {
    "marcas": 5,
    "produtos": 120,
    "pulados": 3
  }
}
```

| Campo            | Descrição                                              |
|------------------|--------------------------------------------------------|
| `duty`           | Conteúdo do arquivo `data.duty` (base64 de JSON ASCII) |
| `stats.marcas`   | Número de marcas importadas                            |
| `stats.produtos` | Número de produtos importados                          |
| `stats.pulados`  | Linhas ignoradas por dados incompletos ou inválidos    |

**Formato do payload decodificado (`atob(duty)`):**

```json
{
  "marcas": [
    {
      "nome": "Marca A",
      "produtos": [
        { "nome": "Produto X", "preco": 19.99 }
      ]
    }
  ]
}
```

**Erros**

| Código | Situação                                  |
|--------|-------------------------------------------|
| `400`  | Nenhum arquivo enviado ou erro de upload  |
| `422`  | Arquivo inválido ou aba não encontrada    |
| `500`  | Falha ao persistir no banco de dados      |

---

### `GET /api/produtos`

Retorna todas as marcas e produtos atualmente armazenados no banco, agrupados por marca.

**Response `200`**

```json
{
  "marcas": [
    {
      "id": 1,
      "nome": "Marca A",
      "produtos": [
        { "id": 1, "nome": "Produto X", "preco": 19.99 },
        { "id": 2, "nome": "Produto Y", "preco": 34.50 }
      ]
    }
  ]
}
```

**Erros**

| Código | Situação                    |
|--------|-----------------------------|
| `503`  | Banco de dados indisponível |

---

### `POST /api/calcular`

Calcula o total de um pedido a partir de produtos e quantidades. Aplica as regras de negócio da calculadora de vendas.

**Regras de negócio:**
- Mínimo de **3 produtos diferentes** com quantidade > 0
- Mínimo de **100 unidades** no total
- Total = Σ (qty × preço), arredondado a 2 casas decimais

**Request**

```
Content-Type: application/json
```

```json
{
  "itens": [
    { "produto_id": 1, "qty": 50 },
    { "produto_id": 2, "qty": 30 },
    { "produto_id": 3, "qty": 25 }
  ]
}
```

| Campo              | Tipo    | Descrição              |
|--------------------|---------|------------------------|
| `itens`            | array   | Lista de itens         |
| `itens[].produto_id` | int   | ID do produto (do banco) |
| `itens[].qty`      | int     | Quantidade (≥ 1)       |

**Response `200`**

```json
{
  "total": 1234.56,
  "unidades": 105,
  "count": 3
}
```

| Campo      | Descrição                               |
|------------|-----------------------------------------|
| `total`    | Valor total do pedido em reais          |
| `unidades` | Total de unidades somadas               |
| `count`    | Número de produtos distintos com qty > 0|

**Erros**

| Código | Situação                                        |
|--------|-------------------------------------------------|
| `400`  | Body inválido ou sem itens                      |
| `422`  | Menos de 3 produtos ou menos de 100 unidades    |
| `503`  | Banco de dados indisponível                     |

---

## Gestão do arquivo Excel

O arquivo enviado ao `POST /api/build` **nunca é armazenado em disco** pelo servidor:

1. O PHP recebe o upload como arquivo temporário em `/tmp` (gerenciado pelo PHP internamente)
2. O PhpSpreadsheet lê o arquivo e carrega os dados na memória
3. A memória da planilha é liberada explicitamente (`disconnectWorksheets()`) assim que as linhas são extraídas — antes das operações no banco
4. O arquivo temporário em `/tmp` é removido automaticamente pelo PHP ao fim da requisição

---

## Estrutura do projeto

```
calculadora-build-data/
├── .env                        # Variáveis locais (não versionado)
├── .env.example                # Template de variáveis
├── docker-compose.yml
├── build-data.js               # Script CLI legado (Node.js)
├── app.js                      # Lógica de cálculo do frontend (referência)
├── packages/
│   ├── php/                    # API REST
│   │   ├── Dockerfile
│   │   ├── composer.json
│   │   ├── docker/
│   │   │   ├── apache.conf     # VirtualHost → public/
│   │   │   └── php.ini         # Limites de upload
│   │   ├── public/
│   │   │   ├── index.php       # Entry point (pivotphp)
│   │   │   └── .htaccess
│   │   └── src/
│   │       ├── Database.php           # Conexão PDO + migrate
│   │       ├── BuildDataController.php # POST /api/build
│   │       └── CalculadoraController.php # GET /api/produtos, POST /api/calcular
│   └── frontend/               # Interface web
│       ├── Dockerfile
│       ├── nginx.conf.template # Proxy + static server
│       └── index.html          # Upload form
```
