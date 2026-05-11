<?php

declare(strict_types=1);

namespace Calculadora\Data;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function connect(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host     = getenv('DB_HOST')     ?: 'db';
        $dbname   = getenv('DB_NAME')     ?: 'calculadora';
        $user     = getenv('DB_USER')     ?: 'calculadora';
        $password = getenv('DB_PASSWORD') ?: '';

        $dsn = "pgsql:host=$host;dbname=$dbname";

        self::$instance = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        self::migrate(self::$instance);

        return self::$instance;
    }

    private static function migrate(PDO $pdo): void
    {
        // Nowdoc evita interpolação de $ pelo PHP (necessário para DO $$ ... $$ do PostgreSQL).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS marcas (
                id   SERIAL PRIMARY KEY,
                nome VARCHAR(255) NOT NULL
            );

            CREATE TABLE IF NOT EXISTS produtos (
                id       SERIAL PRIMARY KEY,
                marca_id INT NOT NULL REFERENCES marcas(id) ON DELETE CASCADE,
                nome     VARCHAR(255) NOT NULL,
                preco    DOUBLE PRECISION NOT NULL
            );

            -- Migra bancos existentes que ainda usam NUMERIC(12,4)
            DO $$ BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name  = 'produtos'
                      AND column_name = 'preco'
                      AND data_type   = 'numeric'
                ) THEN
                    ALTER TABLE produtos ALTER COLUMN preco TYPE DOUBLE PRECISION;
                END IF;
            END $$;
        SQL);
    }
}
