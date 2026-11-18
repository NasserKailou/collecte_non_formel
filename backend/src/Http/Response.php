<?php

declare(strict_types=1);

namespace App\Http;

/** Réponse JSON (enveloppe {data, meta} ou {error}) — docs/api/README.md §1. */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly ?array $body = null,
        public readonly array $headers = [],
        /** Fichier à transmettre tel quel (téléchargement) au lieu d'un corps JSON. */
        public readonly ?string $filePath = null,
    ) {
    }

    /** Téléchargement d'un fichier stocké hors de la racine web. */
    public static function file(string $path, string $downloadName, string $mime): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName);
        return new self(200, null, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) filesize($path),
        ], $path);
    }

    public static function data(mixed $data, int $status = 200, ?array $meta = null, array $headers = []): self
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        return new self($status, $body, $headers);
    }

    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return new self($status, ['error' => $error], $headers);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value], $this->filePath);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $defaults = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ];
        foreach ([...$defaults, ...$this->headers] as $k => $v) {
            header("$k: $v");
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        if ($this->body !== null && $this->status !== 204 && $this->status !== 304) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
