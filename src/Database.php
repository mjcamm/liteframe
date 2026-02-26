<?php

class Database
{

    private PDO $pdo;

    public function __construct(string $path = ':memory:')
    {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

        // Enable WAL mode for concurrent reads
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    /**
     * Execute a statement (CREATE, INSERT, UPDATE, DELETE)
     */
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Query a single row
     */
    public function one(string $sql, array $params = []): ?object
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Query multiple rows
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get the last inserted row ID
     */
    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Run a callback inside a transaction
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get the underlying PDO instance (escape hatch)
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
