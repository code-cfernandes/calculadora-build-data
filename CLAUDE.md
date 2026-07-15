# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Calculadora de Vendas — Build Data** is a microservice that converts Excel pricing spreadsheets into a consumable data bundle (`data.duty`) for a sales calculator frontend. It exposes REST API routes for product listing, order calculation, and admin data management.

## Commands

All orchestration is through Docker Compose — there are no Makefile or NPM scripts.

```bash
# Start all services (frontend, api, db)
docker compose up --build

# Start in background
docker compose up --build -d

# Stop services
docker compose down

# Remove services including database volume
docker compose down -v
```

**Service URLs (default .env):**
- Frontend: `http://localhost:3875`
- API: `http://localhost:8913`

**Quick API test:**
```bash
curl http://localhost:8913/api/produtos
curl -X POST http://localhost:8913/api/login \
  -H "Content-Type: application/json" \
  -d '{"user":"admin","password":"secret"}'
```

There are no native test or lint commands — testing is done via API calls to the running container.

## Architecture

Three Docker services orchestrated via `docker-compose.yml`:

```
Frontend (NGINX :3875) --proxy /api/*--> API (PHP :8913) --> PostgreSQL :5432
```

- **`packages/frontend/`** — NGINX 1.27 Alpine serving static HTML/JS. Proxies `/api/*` to the backend (no CORS needed). Config via `nginx.conf.template` with env variable substitution.
- **`packages/php/`** — PHP 8.3 + Apache REST API. Entry point is `public/index.php` using the PivotPHP 2.0 routing framework.
- **PostgreSQL 16** — persistent via `db_data` volume. Schema is auto-migrated on first connection by `Database::migrate()`.

## PHP Backend Structure

```
packages/php/src/
├── Database.php                # PDO connection + auto-migration (creates/updates tables)
├── Auth.php                    # HMAC-SHA256 Bearer token validation
├── BuildDataController.php     # POST /api/build — Excel upload and processing
└── CalculadoraController.php   # Product listing and order calculation routes
```

**Entry point:** `packages/php/public/index.php` — registers all routes with PivotPHP.

## API Endpoints

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/api/login` | No | Returns Bearer token |
| POST | `/api/build` | Yes | Upload Excel, persist data, return `data.duty` |
| GET | `/api/produtos` | No | List brands/products (no prices) |
| GET | `/api/admin/produtos` | Yes | List brands/products with prices |
| GET | `/api/admin/duty` | Yes | Rebuild `data.duty` from the currently saved database data (no upload) |
| POST | `/api/calcular` | No | Calculate order total |

**`/api/calcular` business rules:** minimum 3 different products and 100 total units.

**`/api/build` behavior:** truncates all existing product data on every upload (not incremental). The `duty` field in the response is a base64-encoded JSON payload.

## Authentication

Stateless HMAC-SHA256 Bearer token:
```
token = base64_encode(HMAC-SHA256(ADMIN_USER:ADMIN_PASSWORD, ADMIN_SECRET))
```
Credentials are stored in env vars (`ADMIN_USER`, `ADMIN_PASSWORD`, `ADMIN_SECRET`). Validated via `hash_equals()` (constant-time). Client stores token in `sessionStorage`.

## Database Schema

```sql
CREATE TABLE marcas  (id SERIAL PRIMARY KEY, nome VARCHAR(255) NOT NULL);
CREATE TABLE produtos (id SERIAL PRIMARY KEY, marca_id INT REFERENCES marcas(id) ON DELETE CASCADE, nome VARCHAR(255) NOT NULL, preco DOUBLE PRECISION NOT NULL);
```

`Database::migrate()` auto-creates tables on first run and handles the legacy `NUMERIC(12,4)` → `DOUBLE PRECISION` column migration.

## Environment Variables

Copy `.env.example` to `.env` before first run. Key variables:

| Variable | Description |
|----------|-------------|
| `FRONTEND_PORT` | Host port for frontend |
| `API_PORT` | Host port for API |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | PostgreSQL credentials |
| `ADMIN_USER` / `ADMIN_PASSWORD` | Admin login credentials |
| `ADMIN_SECRET` | HMAC secret — generate with `openssl rand -hex 32` |
| `MAX_BUILD_ROWS` | Row limit for `/api/build` uploads (default `50000`) — protects against PHP memory exhaustion on large spreadsheets |

## Excel File Format (for `/api/build`)

- Accepted formats: `.xlsx`, `.xls`, `.ods`, `.csv`
- Max upload size: 20MB
- Max rows: `MAX_BUILD_ROWS` (default 50000) — larger sheets are rejected with a 422 before parsing
- Row 1: headers; data from row 2+
- Required columns: `MARCA`, `PRODUTO PRICING`, `PRECO VAREJO`
- `CATEGORIA` column is present but ignored
- Rows with missing required fields are silently skipped

## PHP Error Handling

`display_errors` is off and `log_errors` is on (`packages/php/docker/php.ini`), logging to `storage/logs/php-error.log` inside the container. This is intentional: with Apache's mod_php, `display_errors=STDOUT` would inject raw PHP warnings/notices/fatal errors directly into the HTTP response body, corrupting the JSON returned by every endpoint. Never re-enable `display_errors` in this setup — use the log file for debugging instead.
