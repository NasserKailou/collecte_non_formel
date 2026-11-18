<?php

declare(strict_types=1);

namespace App\Http;

/** Fichier reçu (multipart/form-data). En test, $fromHttp = false permet d'utiliser un fichier local. */
final class UploadedFile
{
    public function __construct(
        public readonly string $clientName,
        public readonly string $path,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $fromHttp = false,
    ) {
    }

    /** Vérifie l'envoi, la taille et l'extension ; lève une erreur 422 sinon. */
    public function assertValid(array $extensions, int $maxBytes): void
    {
        if ($this->error !== UPLOAD_ERR_OK || ($this->fromHttp && !is_uploaded_file($this->path)) || !is_file($this->path)) {
            throw new HttpException(422, 'UPLOAD_FAILED', "Le fichier n'a pas été reçu correctement.");
        }
        if ($this->size <= 0 || $this->size > $maxBytes || filesize($this->path) > $maxBytes) {
            throw new HttpException(422, 'FILE_TOO_LARGE', sprintf('Fichier vide ou trop volumineux (maximum %d Mo).', intdiv($maxBytes, 1048576)));
        }
        $ext = strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) {
            throw new HttpException(422, 'UNSUPPORTED_FILE', 'Format de fichier non accepté (attendu : ' . implode(', ', $extensions) . ').');
        }
    }

    /** Copie le contenu vers $destination (déplacement sécurisé pour un envoi HTTP). */
    public function moveTo(string $destination): void
    {
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer le dossier $dir");
        }
        $ok = $this->fromHttp ? move_uploaded_file($this->path, $destination) : copy($this->path, $destination);
        if (!$ok) {
            throw new \RuntimeException('Impossible d\'enregistrer le fichier reçu.');
        }
    }
}
