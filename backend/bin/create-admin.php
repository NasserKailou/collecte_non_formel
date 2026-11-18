<?php

declare(strict_types=1);

/**
 * Crée un administrateur national (premier compte de la plateforme).
 * Le mot de passe initial est généré aléatoirement, affiché UNE SEULE FOIS, et doit être changé
 * à la première connexion.
 *
 * Usage : php bin/create-admin.php --matricule=ADM001 --nom=NOM --prenom=Prénom --sexe=M|F [--telephone=9xxxxxxx] [--email=…]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

['db' => $db] = require __DIR__ . '/../bootstrap.php';

$o = getopt('', ['matricule:', 'nom:', 'prenom:', 'sexe:', 'telephone::', 'email::']);
foreach (['matricule', 'nom', 'prenom', 'sexe'] as $k) {
    if (empty($o[$k])) {
        fwrite(STDERR, "Option --$k obligatoire.\n");
        exit(1);
    }
}
if (!in_array($o['sexe'], ['M', 'F'], true)) {
    fwrite(STDERR, "--sexe doit valoir M ou F.\n");
    exit(1);
}
if (isset($o['telephone']) && !preg_match('/^[0-9]{8}$/', $o['telephone'])) {
    fwrite(STDERR, "--telephone : 8 chiffres attendus.\n");
    exit(1);
}

// Mot de passe initial : 14 caractères sans caractères ambigus, avec au moins une lettre et un chiffre.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
do {
    $password = '';
    for ($i = 0; $i < 14; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
} while (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password));

try {
    $db->transaction(function (App\Core\Database $db) use ($o, $password): void {
        $roleId = $db->fetchValue("SELECT id FROM roles WHERE code = 'ADMIN'");
        if ($roleId === null) {
            throw new RuntimeException("Rôle ADMIN absent : exécuter d'abord php bin/seed.php.");
        }
        $id = $db->insert(
            'INSERT INTO users (uuid, matricule, last_name, first_name, sex, phone, email, password_hash, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [App\Core\Text::uuid4(), trim($o['matricule']), trim($o['nom']), trim($o['prenom']), $o['sexe'],
                $o['telephone'] ?? null, $o['email'] ?? null, App\Modules\Auth\AuthService::hashPassword($password)]
        );
        $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $roleId]);
        $db->execute("INSERT INTO user_scopes (user_id, level) VALUES (?, 'NATIONAL')", [$id]);
        $db->execute("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, reason) VALUES (NULL, 'USER_CREATE', 'user', ?, ?)",
            [(string) $id, 'Création en ligne de commande (bin/create-admin.php)']);
    });
} catch (PDOException $e) {
    fwrite(STDERR, str_contains($e->getMessage(), 'Duplicate')
        ? "Un utilisateur avec ce matricule, ce téléphone ou cet e-mail existe déjà.\n"
        : 'ÉCHEC : ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ÉCHEC : ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Administrateur « {$o['matricule']} » créé.\n");
fwrite(STDOUT, "Mot de passe initial (affiché une seule fois, à changer à la première connexion) : $password\n");
