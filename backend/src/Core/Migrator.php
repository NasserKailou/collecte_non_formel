<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applique les migrations SQL de database/migrations (NNN_nom.sql) dans l'ordre,
 * une seule fois chacune, et refuse toute migration déjà appliquée dont le contenu a changé.
 *
 * Les instructions sont exécutées une par une (et non en multi-requête) pour que
 * toute erreur soit remontée : PDO ignore les erreurs des requêtes suivant la première
 * dans un envoi multi-requêtes.
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<string> versions appliquées lors de cet appel */
    public function migrate(?callable $log = null): array
    {
        $log ??= static fn(string $m) => null;
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               version VARCHAR(40) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               checksum CHAR(64) CHARACTER SET ascii NOT NULL, PRIMARY KEY (version)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $applied = [];
        foreach ($this->db->fetchAll('SELECT version, checksum FROM schema_migrations') as $r) {
            $applied[$r['version']] = $r['checksum'];
        }

        $done = [];
        foreach ($this->files() as $version => $path) {
            $sql = (string) file_get_contents($path);
            $checksum = hash('sha256', $sql);
            if (isset($applied[$version])) {
                if ($applied[$version] !== $checksum) {
                    throw new \RuntimeException("La migration $version a été modifiée après application. Créer une nouvelle migration.");
                }
                continue;
            }
            $log("Application de {$version}…");
            foreach (self::splitStatements($sql) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->execute('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)', [$version, $checksum]);
            $done[] = $version;
        }
        return $done;
    }

    /** Supprime toutes les tables et vues de la base (développement et tests uniquement). */
    public function dropAll(string $env): void
    {
        if ($env === 'prod') {
            throw new \RuntimeException('Opération interdite en production.');
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SET foreign_key_checks = 0');
        $objects = $this->db->fetchAll(
            'SELECT table_name AS name, table_type AS type FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        foreach ($objects as $o) {
            $kind = $o['type'] === 'VIEW' ? 'VIEW' : 'TABLE';
            $pdo->exec("DROP $kind IF EXISTS `" . str_replace('`', '', $o['name']) . '`');
        }
        $pdo->exec('SET foreign_key_checks = 1');
    }

    /** @return array<string,string> version => chemin, triés */
    private function files(): array
    {
        $files = [];
        foreach (glob(rtrim($this->dir, '/\\') . '/*.sql') ?: [] as $path) {
            if (preg_match('/^(\d{3}_[a-z0-9_]+)\.sql$/', basename($path), $m) === 1) {
                $files[$m[1]] = $path;
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Découpe un script en instructions : retire les commentaires « -- » de ligne entière,
     * puis coupe sur les « ; » de fin de ligne. Les scripts ne doivent pas contenir de
     * procédures stockées ni de « ; » en fin de ligne à l'intérieur d'une chaîne.
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(preg_split('/\R/', $sql) ?: [], static fn($l) => !preg_match('/^\s*--/', $l));
        $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
    }
}
