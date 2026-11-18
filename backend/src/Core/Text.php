<?php

declare(strict_types=1);

namespace App\Core;

/** Utilitaires de texte (sans dépendance à l'extension intl, absente de l'environnement XAMPP). */
final class Text
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ú' => 'U',
        'Ÿ' => 'Y', 'Ñ' => 'N', 'Œ' => 'OE', 'Æ' => 'AE',
    ];

    /** Forme de comparaison : majuscules, sans accents, ponctuation réduite à des espaces, espaces simples. */
    public static function normalize(string $s): string
    {
        $s = strtr($s, self::ACCENTS);
        $s = mb_strtoupper($s);
        $s = (string) preg_replace('/[^A-Z0-9]+/', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** Nettoyage d'un libellé saisi : espaces de début/fin et multiples supprimés. */
    public static function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Numéro de téléphone nigérien sous forme canonique « +227XXXXXXXX » (8 chiffres nationaux),
     * ou null s'il n'est pas reconnu. Accepte espaces, points, tirets et préfixes +227 / 00227 / 227.
     */
    public static function normalizePhone(string $s): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $s);
        if (strlen($digits) === 13 && str_starts_with($digits, '00227')) {
            $digits = substr($digits, 5);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '227')) {
            $digits = substr($digits, 3);
        }
        return preg_match('/^\d{8}$/', $digits) === 1 ? '+227' . $digits : null;
    }

    /** UUID version 4 (aléatoire, cryptographiquement sûr). */
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
