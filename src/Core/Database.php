<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/** SQLite por PDO con consultas preparadas siempre. Un sitio por instancia (ADR-0001). */
final class Database
{
    private \PDO $pdo;
    private bool $inTx = false;

    public function __construct(string $file)
    {
        $new = !is_file($file);
        Fs::ensureDir(dirname($file), 0750);
        try {
            $this->pdo = new \PDO('sqlite:' . $file, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            throw new LuxException('No se pudo abrir la base de datos.', $e->getMessage(), 1, $e);
        }
        if ($new) {
            @chmod($file, 0640);
        }
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $mode = (string)$this->pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
        if (strtolower($mode) === 'wal') {
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** @param list<scalar|null> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $r = $this->run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param list<scalar|null> $params */
    public function val(string $sql, array $params = []): mixed
    {
        $r = $this->run($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public function lastId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /** @template T @param callable():T $fn @return T */
    public function transaction(callable $fn): mixed
    {
        if ($this->inTx) {
            return $fn(); // anidada: participa en la transacción exterior
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->inTx = true; // PDO::inTransaction() no siempre detecta un BEGIN ejecutado con exec()
        try {
            $r = $fn();
            $this->pdo->exec('COMMIT');
            return $r;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // ya estaba cerrada por SQLite
            }
            throw $e;
        } finally {
            $this->inTx = false;
        }
    }
}
