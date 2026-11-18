<?php

declare(strict_types=1);

namespace App\Http;

/** Requête HTTP immuable, construite depuis les superglobales ou directement (tests). */
final class Request
{
    /** @param array<string,string> $headers clés en minuscules */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly mixed $body = null,
        public readonly array $headers = [],
        public readonly string $ip = '0.0.0.0',
        /** @var array<string,UploadedFile> */
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($basePath === '') {
            // Installation dans un sous-dossier (ex. XAMPP) : on retire le dossier du contrôleur frontal.
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        }
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $raw = (string) file_get_contents('php://input');
        if (($headers['content-encoding'] ?? '') === 'gzip' && $raw !== '') {
            $raw = (string) gzdecode($raw);
        }
        $body = null;
        if ($raw !== '' && str_contains($headers['content-type'] ?? '', 'application/json')) {
            try {
                $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpException(400, 'BAD_REQUEST', 'Corps JSON invalide.');
            }
        }
        if ($body === null && str_contains($headers['content-type'] ?? '', 'multipart/form-data')) {
            $body = $_POST; // champs texte d'un formulaire avec fichier (ex. publication d'un APK)
        }
        $files = [];
        foreach ($_FILES as $field => $f) {
            if (is_array($f) && is_string($f['tmp_name'] ?? null)) { // un seul fichier par champ
                $files[(string) $field] = new UploadedFile((string) $f['name'], (string) $f['tmp_name'], (int) $f['size'], (int) $f['error'], true);
            }
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $uri, $_GET, $body, $headers, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $files);
    }

    public function file(string $field): ?UploadedFile
    {
        return $this->files[$field] ?? null;
    }

    /** Paramètre de requête entier positif (filtre), ou null. */
    public function queryInt(string $key): ?int
    {
        $v = $this->query[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || preg_match('/^\d{1,9}$/', $v) !== 1) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Paramètre « $key » invalide.", ['field' => $key]);
        }
        return (int) $v;
    }

    public function queryString(string $key, int $max = 100): ?string
    {
        $v = $this->query[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || mb_strlen($v) > $max) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Paramètre « $key » invalide.", ['field' => $key]);
        }
        return $v;
    }

    /** Pagination ?page=&per_page= (max. 200). @return array{page:int, per_page:int, offset:int} */
    public function pagination(int $default = 50): array
    {
        $page = max(1, $this->queryInt('page') ?? 1);
        $per = min(200, max(1, $this->queryInt('per_page') ?? $default));
        return ['page' => $page, 'per_page' => $per, 'offset' => ($page - 1) * $per];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Valeur d'un cookie (en-tête Cookie), ou null. */
    public function cookie(string $name): ?string
    {
        foreach (explode(';', (string) $this->header('cookie')) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($k === $name && $v !== null) {
                return urldecode($v);
            }
        }
        return null;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization');
        return ($h !== null && preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,200})$/', $h, $m) === 1) ? $m[1] : null;
    }

    /** Champ du corps JSON (objet attendu). */
    public function input(string $key, mixed $default = null): mixed
    {
        return is_array($this->body) && array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }

    public function string(string $key, int $max = 255): ?string
    {
        $v = $this->input($key);
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || mb_strlen($v) > $max) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Champ « $key » invalide.", ['field' => $key]);
        }
        return $v;
    }
}
