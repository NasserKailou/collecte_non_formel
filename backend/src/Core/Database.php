<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Connexion PDO unique (paresseuse) avec garanties imposées par l'application :
 * requêtes préparées natives, mode SQL strict, UTC+1 (heure du Niger), utf8mb4.
 * Voir docs/database/README.md (« mode SQL strict imposé par l'application »).
 */
final class Database
{
    public const SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY';

    private ?PDO $pdo = null;
    private int $txDepth = 0;

    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
            $this->pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
            $this->pdo->exec("SET SESSION time_zone = '+01:00'");
        }
        return $this->pdo;
    }

    /** @return array<string,mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Exécute une requête modifiante et renvoie le nombre de lignes affectées. */
    public function execute(string $sql, array $params = []): int
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Exécute $fn dans une transaction (imbrication tolérée : seule la plus externe valide).
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($this->txDepth === 0) {
            $pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function databaseName(): string
    {
        return (string) $this->config['name'];
    }
}
