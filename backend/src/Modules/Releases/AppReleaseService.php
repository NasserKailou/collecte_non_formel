<?php

declare(strict_types=1);

namespace App\Modules\Releases;

use App\Core\Audit;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\UploadedFile;

/**
 * Versions de l'application mobile (APK) : publication depuis l'administration, annonce aux tablettes, téléchargement
 * par lien signé à durée limitée (le navigateur Android télécharge puis propose l'installation).
 */
final class AppReleaseService
{
    public const MAX_BYTES = 300 * 1024 * 1024;
    public const LINK_TTL = 24 * 3600;

    /** @param \Closure(string):string $sign signature HMAC de l'application */
    public function __construct(private readonly Database $db, private readonly string $storage, private readonly \Closure $sign)
    {
    }

    public function list(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT r.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS publisher FROM app_releases r JOIN users u ON u.id = r.published_by");
        usort($rows, static fn($a, $b) => version_compare($b['version'], $a['version']));
        return array_map(fn($r) => $this->present($r, false) + ['published_by' => $r['publisher']], $rows);
    }

    public function publish(int $userId, UploadedFile $file, array $in, string $ip): array
    {
        $version = is_string($in['version'] ?? null) ? trim($in['version']) : '';
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,4}$/', $version) !== 1) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Version attendue au format X.Y.Z (ex. 1.2.0), identique à celle de l'application.",
                ['errors' => [['field' => 'version', 'message' => 'Format X.Y.Z (ex. 1.2.0).']]]);
        }
        foreach ($this->db->fetchAll('SELECT version FROM app_releases') as $r) {
            if (version_compare($version, $r['version'], '<=')) {
                throw new HttpException(422, 'VERSION_NOT_NEWER', "La version $version n'est pas plus récente que la version {$r['version']} déjà publiée.",
                    ['errors' => [['field' => 'version', 'message' => "Doit être supérieure à {$r['version']}."]]]);
            }
        }
        $file->assertValid(['apk'], self::MAX_BYTES);
        $this->assertApk($file->path);
        $sha = hash_file('sha256', $file->path);
        if ($this->db->fetchValue('SELECT id FROM app_releases WHERE sha256 = ?', [$sha]) !== null) {
            throw new HttpException(409, 'DUPLICATE_FILE', 'Ce fichier APK a déjà été publié sous une autre version.');
        }
        $name = "eftp-collecte-$version.apk";
        $path = "releases/$name";
        $file->moveTo($this->storage . '/' . $path);
        $notes = is_string($in['notes'] ?? null) ? mb_substr(trim($in['notes']), 0, 5000) : null;
        $mandatory = in_array($in['mandatory'] ?? false, [true, 1, '1', 'true', 'on'], true) ? 1 : 0;
        $id = $this->db->insert('INSERT INTO app_releases (version, file_name, storage_path, size_bytes, sha256, notes, mandatory, published_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$version, $name, $path, filesize($this->storage . '/' . $path), $sha, $notes === '' ? null : $notes, $mandatory, $userId]);
        (new Audit($this->db))->log($userId, 'APP_RELEASE_PUBLISH', 'app_release', (string) $id, null, ['version' => $version, 'sha256' => $sha, 'mandatory' => $mandatory], null, $ip);
        return $this->present($this->db->fetchOne('SELECT * FROM app_releases WHERE id = ?', [$id]), false);
    }

    public function retire(int $userId, int $id, string $ip): array
    {
        $r = $this->db->fetchOne('SELECT * FROM app_releases WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Version introuvable.');
        if ($r['status'] === 'PUBLIEE') {
            $this->db->execute("UPDATE app_releases SET status = 'RETIREE', retired_at = NOW() WHERE id = ?", [$id]);
            (new Audit($this->db))->log($userId, 'APP_RELEASE_RETIRE', 'app_release', (string) $id, ['status' => 'PUBLIEE'], ['status' => 'RETIREE'], null, $ip);
        }
        return $this->present($this->db->fetchOne('SELECT * FROM app_releases WHERE id = ?', [$id]), false);
    }

    /**
     * Dernière version publiée, annoncée à la tablette (avec lien de téléchargement signé), ou null.
     * `update_available` et `required` sont calculés pour la version installée transmise (X-App-Version).
     */
    public function latest(?string $installed, string $minVersion): ?array
    {
        $rows = $this->db->fetchAll("SELECT * FROM app_releases WHERE status = 'PUBLIEE'");
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn($a, $b) => version_compare($b['version'], $a['version']));
        return $this->present($rows[0], true) + [
            'min_version' => $minVersion,
            'installed' => $installed,
            'update_available' => $installed === null || version_compare($rows[0]['version'], $installed, '>'),
            'required' => $installed !== null && version_compare($installed, $minVersion, '<'),
        ];
    }

    /** Fichier d'une version, si le lien signé est valide et non expiré. */
    public function fileFor(int $id, string $expires, string $signature): array
    {
        if (!ctype_digit($expires) || (int) $expires < time() || !hash_equals(($this->sign)("release|$id|$expires"), $signature)) {
            throw new HttpException(403, 'LINK_EXPIRED', "Lien de téléchargement invalide ou expiré : relancer la recherche de mise à jour depuis l'application.");
        }
        $r = $this->db->fetchOne("SELECT * FROM app_releases WHERE id = ? AND status = 'PUBLIEE'", [$id]);
        $path = $r === null ? null : $this->storage . '/' . $r['storage_path'];
        if ($path === null || !is_file($path)) {
            throw new HttpException(404, 'NOT_FOUND', 'Version introuvable ou retirée.');
        }
        return [$path, $r['file_name']];
    }

    private function present(array $r, bool $withLink): array
    {
        $out = [
            'id' => (int) $r['id'], 'version' => $r['version'], 'file_name' => $r['file_name'], 'size_bytes' => (int) $r['size_bytes'],
            'sha256' => $r['sha256'], 'notes' => $r['notes'], 'mandatory' => (bool) $r['mandatory'], 'status' => $r['status'],
            'published_at' => $r['published_at'], 'retired_at' => $r['retired_at'] ?? null,
        ];
        if ($withLink) {
            $exp = time() + self::LINK_TTL;
            $out['download_path'] = "/app/releases/{$r['id']}/download?expires=$exp&signature=" . ($this->sign)("release|{$r['id']}|$exp");
            $out['link_expires_at'] = date('c', $exp);
        }
        return $out;
    }

    /** Un APK est une archive ZIP contenant AndroidManifest.xml et classes.dex. */
    private function assertApk(string $path): void
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($path) === true;
        $ok = $opened && $zip->locateName('AndroidManifest.xml') !== false && $zip->locateName('classes.dex') !== false;
        if ($opened) {
            $zip->close();
        }
        if (!$ok) {
            throw new HttpException(422, 'INVALID_APK', "Ce fichier n'est pas une application Android valide (APK).");
        }
    }
}
