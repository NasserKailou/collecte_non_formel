<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\UploadedFile;

/**
 * Versions de l'application mobile : publication par l'administrateur, annonce à la tablette, version obligatoire
 * (426 en dessous), lien de téléchargement signé à durée limitée.
 */
final class ReleasesApiTest extends ApiTestCase
{
    private static function fakeApk(string $marker): string
    {
        $path = sys_get_temp_dir() . '/eftp-test-' . bin2hex(random_bytes(4)) . '.apk';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('AndroidManifest.xml', "<manifest marker=\"$marker\"/>");
        $zip->addFromString('classes.dex', 'dex');
        $zip->close();
        return $path;
    }

    private function publish(string $token, string $version, string $file, bool $mandatory = false): \App\Http\Response
    {
        return $this->call('POST', '/app/releases', ['version' => $version, 'notes' => "Nouveautés $version", 'mandatory' => $mandatory ? '1' : '0'], $token,
            files: ['file' => new UploadedFile('app-release.apk', $file, (int) filesize($file))]);
    }

    public function testAdminPublishesAndTabletsDetectUpdates(): void
    {
        $admin = $this->tokenFor($this->createUser('ADMIN'));
        $agent = $this->tokenFor($this->createUser('AGENT'));

        $this->assertSame(403, $this->publish($agent, '9.0.0', self::fakeApk('a'))->status, "Réservé à l'administration");
        $bad = sys_get_temp_dir() . '/eftp-not-apk.apk';
        file_put_contents($bad, 'pas une archive');
        $this->assertSame('INVALID_APK', $this->publish($admin, '9.0.0', $bad)->body['error']['code']);

        $r = $this->publish($admin, '9.0.0', self::fakeApk('9.0.0'));
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('9.0.0', $r->body['data']['version']);
        $this->assertSame('VERSION_NOT_NEWER', $this->publish($admin, '8.9.9', self::fakeApk('old'))->body['error']['code']);

        // La tablette (version 1.0.0) voit la mise à jour, facultative.
        $latest = $this->call('GET', '/app/releases/latest', token: $agent, headers: ['x-app-version' => '1.0.0'])->body['data'];
        $this->assertTrue($latest['update_available']);
        $this->assertFalse($latest['required']);
        $this->assertSame('Nouveautés 9.0.0', $latest['notes']);

        // Lien signé : téléchargement sans session ; lien modifié refusé.
        parse_str((string) parse_url($latest['download_path'], PHP_URL_QUERY), $q);
        $path = (string) parse_url($latest['download_path'], PHP_URL_PATH);
        $dl = $this->call('GET', $path, query: $q);
        $this->assertSame(200, $dl->status);
        $this->assertSame('application/vnd.android.package-archive', $dl->headers['Content-Type']);
        $this->assertSame($latest['sha256'], hash_file('sha256', (string) $dl->filePath), 'Empreinte annoncée = fichier servi');
        $this->assertSame(403, $this->call('GET', $path, query: ['expires' => $q['expires'], 'signature' => str_repeat('0', 64)])->status);

        // Version obligatoire : l'ancienne application est refusée (426) jusqu'à la mise à jour.
        $m = $this->publish($admin, '9.1.0', self::fakeApk('9.1.0'), true);
        $this->assertSame(201, $m->status);
        try {
            $old = $this->call('GET', '/sync/pull', token: $agent, headers: ['x-app-version' => '9.0.0']);
            $this->assertSame(426, $old->status);
            $this->assertSame('9.1.0', $old->body['error']['details']['min_version']);
            $blocked = $this->call('GET', '/app/releases/latest', token: $agent, headers: ['x-app-version' => '9.0.0']);
            $this->assertSame(200, $blocked->status, "Une application trop ancienne voit quand même la mise à jour");
            $this->assertTrue($blocked->body['data']['required']);
            $this->assertSame(200, $this->call('GET', '/sync/pull', token: $agent, headers: ['x-app-version' => '9.1.0'])->status);
            $this->assertSame(['9.1.0', '9.0.0'], array_column($this->call('GET', '/app/releases', token: $admin)->body['data'], 'version'));
        } finally {
            // Les autres tests utilisent la version 1.0.0 : les versions publiées ici sont retirées.
            foreach ($this->call('GET', '/app/releases', token: $admin)->body['data'] as $rel) {
                $this->call('POST', "/app/releases/{$rel['id']}/retire", [], $admin);
            }
        }
        $this->assertSame(200, $this->call('GET', '/sync/pull', token: $agent, headers: ['x-app-version' => '1.0.0'])->status, 'Version retirée : plus obligatoire');
    }
}
