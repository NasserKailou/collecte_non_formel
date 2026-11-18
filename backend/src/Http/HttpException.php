<?php

declare(strict_types=1);

namespace App\Http;

/** Erreur métier ou d'accès renvoyée telle quelle au client (codes : docs/api/README.md §1). */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
