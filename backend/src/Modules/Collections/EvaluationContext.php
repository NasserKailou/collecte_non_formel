<?php

declare(strict_types=1);

namespace App\Modules\Collections;

use App\Core\Database;

/**
 * Partie du contexte d'évaluation (`_ctx`) qui ne dépend pas de la collecte : seuils de `system_settings` et
 * listes des métiers admis par secteur. Utilisée par le serveur à la soumission et transmise à la tablette
 * par /sync/pull, pour que les deux moteurs appliquent exactement les mêmes contrôles.
 */
final class EvaluationContext
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{gps_max_km: float, max_apprentices_per_trainer: int, options: array<string, list<int|string>>} */
    public function shared(): array
    {
        $settings = array_column($this->db->fetchAll(
            "SELECT setting_key, value FROM system_settings WHERE setting_key IN ('gps_max_km', 'max_apprentices_per_trainer')"), 'value', 'setting_key');
        $options = [];
        foreach ($this->db->fetchAll('SELECT id FROM sectors ORDER BY id') as $s) {
            $ids = array_map('intval', array_column($this->db->fetchAll(
                'SELECT id FROM trades WHERE sector_id = ? AND active = 1 AND alias_of_id IS NULL AND is_other = 0 ORDER BY id', [$s['id']]), 'id'));
            $options['trades.' . $s['id']] = [...$ids, 'AUTRE'];
        }
        return [
            'gps_max_km' => (float) ($settings['gps_max_km'] ?? 15),
            'max_apprentices_per_trainer' => (int) ($settings['max_apprentices_per_trainer'] ?? 60),
            'options' => $options,
        ];
    }
}
