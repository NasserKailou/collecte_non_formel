<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Text;
use App\Http\Request;
use App\Http\Response;
use App\Http\UploadedFile;
use App\Modules\Auth\AuthService;
use App\Modules\Questionnaires\QuestionnairePublisher;
use App\Modules\Territory\TerritoryImporter;
use App\Seed\BaseSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'intégration : base dédiée eftp_nf_test recréée pour chaque classe de test,
 * stockage de fichiers temporaire, territoire fictif réduit. La base de travail n'est jamais touchée.
 *
 * Territoire de test :
 *   REGION TEST (1)  → DEPARTEMENT TEST (101) → COMMUNE TEST (10101), COMMUNE TEST 2 (10102)
 *                    → DEPARTEMENT B (102)    → COMMUNE TEST 3 (10201)
 *   REGION DEUX (2)  → VILLE DE DEUX (201)    → COMMUNE TEST 4 (20101)
 *                    → DEPARTEMENT D (202)    → COMMUNE TEST 5 (20201), COMMUNE TEST 6 (20202)
 */
abstract class ApiTestCase extends TestCase
{
    protected const TEST_DB = 'eftp_nf_test';
    protected const PASSWORD = 'MotDePasse2026';
    protected static App $app;
    protected static Database $db;
    protected static array $config;
    protected static string $storage;
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        $config = require __DIR__ . '/../../config/config.php';
        $config['app']['env'] = 'test';
        $config['app']['debug'] = true;
        $config['db']['name'] = self::TEST_DB;
        self::$storage = sys_get_temp_dir() . '/eftp_nf_test_storage_' . getmypid();
        $config['paths']['storage'] = self::$storage;
        self::$config = $config;

        $c = $config['db'];
        $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . self::TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        self::$db = new Database($config['db']);
        $migrator = new Migrator(self::$db, $config['paths']['migrations']);
        $migrator->dropAll('test');
        $migrator->migrate();
        (new BaseSeeder(self::$db))->run();
        (new TerritoryImporter(self::$db, []))->importRows([
            ['region', '1', 'REGION TEST'], ['region', '2', 'REGION DEUX'],
            ['departement', '101', 'DEPARTEMENT TEST', null, '1'], ['departement', '102', 'DEPARTEMENT B', null, '1'],
            ['departement', '201', 'VILLE DE DEUX', null, '2'], ['departement', '202', 'DEPARTEMENT D', null, '2'],
            ['communes', '10101', 'COMMUNE TEST', null, null, '101'], ['communes', '10102', 'COMMUNE TEST 2', null, null, '101'],
            ['communes', '10201', 'COMMUNE TEST 3', null, null, '102'], ['communes', '20101', 'COMMUNE TEST 4', null, null, '201'],
            ['communes', '20201', 'COMMUNE TEST 5', null, null, '202'], ['communes', '20202', 'COMMUNE TEST 6', null, null, '202'],
        ]);
        foreach (glob($config['paths']['shared'] . '/questionnaire/eftp-nf-*.json') as $file) { // 2025.1, 2025.2, 2025.3…
            (new QuestionnairePublisher(self::$db))->publishFile($file);
        }

        self::$app = new App($config, self::$db);
        (require __DIR__ . '/../../config/routes.php')(self::$app);
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDir(self::$storage);
    }

    // ------------------------------------------------------------------ Territoire

    protected static function regionId(string $code): int
    {
        return (int) self::$db->fetchValue('SELECT id FROM regions WHERE code_renacom = ?', [$code]);
    }

    protected static function departmentId(string $code): int
    {
        return (int) self::$db->fetchValue('SELECT id FROM departments WHERE code_renacom = ?', [$code]);
    }

    protected static function communeId(string $code): int
    {
        return (int) self::$db->fetchValue('SELECT id FROM communes WHERE code_renacom = ?', [$code]);
    }

    // ------------------------------------------------------------------ Comptes

    /**
     * Crée un compte directement en base et renvoie son matricule.
     * Périmètre par défaut : ADMIN/VIEWER national, SUP_REG région 1, SUP_DEP département 101, AGENT commune 10101.
     * @param list<array>|null $scopes lignes {level, region_id?, department_id?, commune_id?}
     */
    protected function createUser(string $role, bool $mustChange = false, string $status = 'ACTIF', ?array $scopes = null, ?int $supervisorId = null): string
    {
        $matricule = mb_strtoupper('T-' . $role . '-' . (++self::$seq) . '-' . bin2hex(random_bytes(3)));
        $id = self::$db->insert(
            'INSERT INTO users (uuid, matricule, last_name, first_name, sex, password_hash, must_change_password, status, supervisor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Text::uuid4(), $matricule, 'TEST', 'Utilisateur', 'M', AuthService::hashPassword(self::PASSWORD), $mustChange ? 1 : 0, $status, $supervisorId]);
        self::$db->execute('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?', [$id, $role]);
        $scopes ??= match ($role) {
            'ADMIN', 'VIEWER' => [['level' => 'NATIONAL']],
            'SUP_REG' => [['level' => 'REGION', 'region_id' => self::regionId('1')]],
            'SUP_DEP' => [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('101')]],
            default => [['level' => 'COMMUNE', 'commune_id' => self::communeId('10101')]],
        };
        foreach ($scopes as $s) {
            self::$db->execute('INSERT INTO user_scopes (user_id, level, region_id, department_id, commune_id) VALUES (?, ?, ?, ?, ?)',
                [$id, $s['level'], $s['region_id'] ?? null, $s['department_id'] ?? null, $s['commune_id'] ?? null]);
        }
        return $matricule;
    }

    protected function userId(string $matricule): int
    {
        return (int) self::$db->fetchValue('SELECT id FROM users WHERE matricule = ?', [$matricule]);
    }

    protected function uuidOf(string $matricule): string
    {
        return (string) self::$db->fetchValue('SELECT uuid FROM users WHERE matricule = ?', [$matricule]);
    }

    protected function login(string $identifier, string $password = self::PASSWORD, ?string $ip = null): Response
    {
        return $this->call('POST', '/auth/login', [
            'identifier' => $identifier, 'password' => $password,
            'device' => ['uid' => 'test-device-' . md5($identifier), 'model' => 'Tablette test', 'os_version' => 'Android 13', 'app_version' => '1.0.0'],
        ], ip: $ip ?? $this->ip());
    }

    protected function tokenFor(string $matricule): string
    {
        return $this->login($matricule)->body['data']['access_token'];
    }

    protected function ip(): string
    {
        return '10.0.' . intdiv(++self::$seq, 250) . '.' . (self::$seq % 250 + 1);
    }

    /** @param array<string,UploadedFile> $files */
    protected function call(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = [], ?string $ip = null,
        array $query = [], array $files = []): Response
    {
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        return self::$app->handle(new Request($method, App::PREFIX . $path, $query, $body, $headers, $ip ?? '127.0.0.1', $files));
    }

    protected static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
