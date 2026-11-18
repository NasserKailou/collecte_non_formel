<?php

declare(strict_types=1);

namespace App\Core;

/** Import rejeté : contient la liste détaillée des anomalies ; aucune donnée n'a été écrite. */
final class ImportException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
