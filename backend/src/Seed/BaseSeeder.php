<?php

declare(strict_types=1);

namespace App\Seed;

use App\Core\Database;

/**
 * Données de base indispensables au fonctionnement (idempotent) :
 * rôles, permissions, types de structure (modalités Q1.9), motifs d'invalidation, paramètres.
 */
final class BaseSeeder
{
    public const PERMISSIONS = [
        'user.view' => 'Consulter les utilisateurs de son périmètre',
        'user.manage' => 'Créer, modifier, activer/désactiver les utilisateurs et réinitialiser les mots de passe',
        'user.import' => 'Importer des agents et superviseurs par fichier Excel',
        'territory.manage' => 'Importer et modifier le référentiel territorial',
        'structure.view' => 'Consulter les structures',
        'structure.manage' => 'Créer, modifier, approuver, fusionner les structures',
        'structure.propose' => 'Proposer une structure découverte sur le terrain',
        'campaign.manage' => 'Gérer les années statistiques et les campagnes',
        'assignment.manage' => 'Affecter les structures et les zones aux agents',
        'questionnaire.manage' => 'Publier des versions du questionnaire',
        'collection.submit' => 'Collecter et soumettre des questionnaires',
        'collection.view' => 'Consulter les collectes de son périmètre',
        'collection.review' => 'Contrôler, valider ou invalider les collectes de son périmètre',
        'collection.reopen' => 'Rouvrir exceptionnellement une collecte validée',
        'stats.view' => 'Consulter les tableaux de bord',
        'export.create' => 'Exporter des données',
        'audit.view' => 'Consulter les journaux',
        'settings.manage' => 'Modifier les paramètres de la plateforme',
    ];

    public const ROLES = [
        'ADMIN' => ['Administrateur national', ['*']],
        'SUP_REG' => ['Superviseur régional', ['user.view', 'structure.view', 'structure.manage', 'assignment.manage',
            'collection.view', 'collection.review', 'stats.view', 'export.create']],
        'SUP_DEP' => ['Superviseur départemental', ['user.view', 'structure.view', 'structure.manage', 'assignment.manage',
            'collection.view', 'collection.review', 'stats.view', 'export.create']],
        'AGENT' => ['Agent recenseur', ['collection.submit', 'structure.propose']],
        'VIEWER' => ['Lecteur / analyste', ['structure.view', 'collection.view', 'stats.view', 'export.create']],
    ];

    public const STRUCTURE_TYPES = [
        'a' => 'SFMA (SIFA)', 'b' => 'CFA', 'c' => 'Atelier', 'd' => 'SAA', 'e' => 'Foyer', 'f' => 'Groupement',
        'g' => 'Garage', 'h' => 'Coopérative/Association', 'i' => 'Cabinet de formation', 'j' => "Plateforme d'orientation",
        'k' => 'Autre structure',
    ];

    public const REJECTION_REASONS = [
        'INCOMPLET' => 'Questionnaire incomplet',
        'EFFECTIFS' => 'Effectifs incohérents ou invraisemblables',
        'FORMATEURS' => 'Informations sur les formateurs incorrectes',
        'GPS' => 'Coordonnées GPS absentes ou douteuses',
        'IDENTIFICATION' => 'Identification ou localisation de la structure erronée',
        'DOUBLON' => 'Structure déjà collectée (doublon)',
        'AUTRE' => 'Autre motif (préciser dans le commentaire)',
    ];

    public const SETTINGS = [
        'gps_max_km' => ['15', 'float', 'Distance maximale (km) entre le point GPS et le centre de la commune avant avertissement'],
        'gps_accuracy_warn_m' => ['50', 'int', 'Précision GPS (m) au-delà de laquelle un avertissement est émis'],
        'max_apprentices_per_trainer' => ['60', 'int', "Seuil d'avertissement du ratio apprentis/formateur"],
        'stats_basis' => ['validated', 'string', 'Base des indicateurs statistiques : validated (validées) ou submitted (soumises et validées)'],
        'purge_days_after_close' => ['60', 'int', 'Délai (jours) après clôture avant purge des données sur les tablettes'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function run(): void
    {
        $this->db->transaction(function (Database $db): void {
            foreach (self::PERMISSIONS as $code => $label) {
                $db->execute('INSERT INTO permissions (code, label) VALUES (?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)', [$code, $label]);
            }
            $permIds = array_column($db->fetchAll('SELECT id, code FROM permissions'), 'id', 'code');
            foreach (self::ROLES as $code => [$label, $perms]) {
                $db->execute('INSERT INTO roles (code, label) VALUES (?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)', [$code, $label]);
                $roleId = (int) $db->fetchValue('SELECT id FROM roles WHERE code = ?', [$code]);
                $db->execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
                $wanted = $perms === ['*'] ? array_keys(self::PERMISSIONS) : $perms;
                foreach ($wanted as $p) {
                    $db->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permIds[$p]]);
                }
            }
            $sort = 0;
            foreach (self::STRUCTURE_TYPES as $code => $label) {
                $db->execute('INSERT INTO structure_types (code, label, is_other, sort) VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE label = VALUES(label), is_other = VALUES(is_other), sort = VALUES(sort)',
                    [$code, $label, $code === 'k' ? 1 : 0, ++$sort]);
            }
            $sort = 0;
            foreach (self::REJECTION_REASONS as $code => $label) {
                $db->execute('INSERT INTO rejection_reasons (code, label, sort) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE label = VALUES(label), sort = VALUES(sort)', [$code, $label, ++$sort]);
            }
            foreach (self::SETTINGS as $key => [$value, $type, $description]) {
                // Une valeur déjà modifiée par l'administrateur n'est jamais écrasée.
                $db->execute('INSERT IGNORE INTO system_settings (setting_key, value, value_type, description) VALUES (?, ?, ?, ?)',
                    [$key, $value, $type, $description]);
            }
        });
    }
}
