<?php
/**
 * BEBBA — Creation des comptes WordPress cuisine et livreurs  (BLOC 8.0)
 *
 * Usage :
 *   php.exe creer-comptes-bebba.php "<racine WordPress>" "<fichier identifiants>"
 *
 * Exemple :
 *   php.exe creer-comptes-bebba.php "C:/wamp64/www/bebba_test" "C:/Users/moi/Desktop/identifiants-bebba.txt"
 *
 * Le script est IDEMPOTENT : relance, il ne cree pas de doublon.
 * Les mots de passe ne sont JAMAIS affiches en console : uniquement dans le fichier.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ce script doit etre execute en ligne de commande.\n");
    exit(1);
}

$wp_root = isset($argv[1]) ? rtrim($argv[1], "/\\") : '';
$fichier = isset($argv[2]) ? $argv[2] : '';

if ($wp_root === '' || !is_file($wp_root . '/wp-load.php')) {
    fwrite(STDERR, "ERREUR : wp-load.php introuvable dans '{$wp_root}'.\n");
    exit(1);
}
if ($fichier === '') {
    fwrite(STDERR, "ERREUR : chemin du fichier d'identifiants manquant (2e argument).\n");
    exit(1);
}
// Le fichier d'identifiants ne doit JAMAIS se trouver dans la racine web
$racine_reelle = str_replace('\\', '/', realpath($wp_root));
$fichier_reel  = str_replace('\\', '/', realpath(dirname($fichier)) . '/' . basename($fichier));
if (strpos($fichier_reel, $racine_reelle) === 0) {
    fwrite(STDERR, "ERREUR : le fichier d'identifiants serait accessible depuis le web. Choisissez un autre emplacement.\n");
    exit(1);
}

require_once $wp_root . '/wp-load.php';

global $wpdb;

$comptes = array(
    array(
        'login'   => 'BHF0021671000002',
        'role'    => 'bebba_cuisine',
        'prenom'  => 'Cuisine',
        'nom'     => 'BEBBA',
        'cc'      => '216',
        'nat'     => '71000002',
        'legacy'  => null,
    ),
    array(
        'login'   => 'BHF0021698123456',
        'role'    => 'bebba_livreur',
        'prenom'  => 'Yassine',
        'nom'     => 'Ben Amor',
        'cc'      => '216',
        'nat'     => '98123456',
        'legacy'  => 'drv-1',
    ),
    array(
        'login'   => 'BHF0021655987654',
        'role'    => 'bebba_livreur',
        'prenom'  => 'Amine',
        'nom'     => 'Trabelsi',
        'cc'      => '216',
        'nat'     => '55987654',
        'legacy'  => 'drv-2',
    ),
    array(
        'login'   => 'BHF0021622456789',
        'role'    => 'bebba_livreur',
        'prenom'  => 'Karim',
        'nom'     => 'Bouazizi',
        'cc'      => '216',
        'nat'     => '22456789',
        'legacy'  => 'drv-3',
    ),
);

$lignes    = array();
$rapport   = array();
$erreurs   = 0;

foreach ($comptes as $c) {

    // --- le role existe-t-il ? ---
    if (!get_role($c['role'])) {
        fwrite(STDERR, "ERREUR : le role '{$c['role']}' n'existe pas. Le plugin BEBBA est-il actif ?\n");
        exit(1);
    }

    $normalise    = '00' . preg_replace('/\D/', '', $c['cc'] . $c['nat']);
    $display_name = trim($c['prenom'] . ' ' . $c['nom']);
    $existant     = get_user_by('login', $c['login']);
    $mot_de_passe = null;

    if ($existant) {
        $user_id = (int) $existant->ID;
        $action  = 'deja existant';
        $u = new WP_User($user_id);
        $u->set_role($c['role']);
        wp_update_user(array(
            'ID'           => $user_id,
            'display_name' => $display_name,
            'first_name'   => $c['prenom'],
            'last_name'    => $c['nom'],
        ));
    } else {
        $mot_de_passe = wp_generate_password(16, true, false);
        $user_id = wp_insert_user(array(
            'user_login'   => $c['login'],
            'user_pass'    => $mot_de_passe,
            'user_email'   => $c['login'] . '@bebba.local',
            'display_name' => $display_name,
            'first_name'   => $c['prenom'],
            'last_name'    => $c['nom'],
            'role'         => $c['role'],
        ));
        if (is_wp_error($user_id)) {
            $erreurs++;
            fwrite(STDERR, "ERREUR sur {$c['login']} : " . $user_id->get_error_message() . "\n");
            continue;
        }
        $action = 'cree';
    }

    // --- metadonnees BEBBA (memes cles que le plugin) ---
    update_user_meta($user_id, 'bebba_phone_country_code', $c['cc']);
    update_user_meta($user_id, 'bebba_phone_national',     $c['nat']);
    update_user_meta($user_id, 'bebba_phone',              $normalise);

    // --- rattachement a la fiche livreur ---
    $lien = 'n/a';
    if ($c['legacy'] !== null) {
        $fiche_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM bebba_drivers WHERE legacy_id = %s", $c['legacy']
        ));
        if ($fiche_id === null) {
            $erreurs++;
            $lien = 'AUCUNE FICHE ' . $c['legacy'];
        } else {
            $actuel = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM bebba_drivers WHERE id = %d", (int) $fiche_id
            ));
            if ((int) $actuel === $user_id) {
                $lien = 'deja lie a ' . $c['legacy'];
            } else {
                $maj = $wpdb->update(
                    'bebba_drivers',
                    array('user_id' => $user_id),
                    array('legacy_id' => $c['legacy']),
                    array('%d'), array('%s')
                );
                if ($maj === false) { $erreurs++; $lien = 'ERREUR SQL'; }
                else                { $lien = 'lie a ' . $c['legacy']; }
            }
        }
    }

    $rapport[] = array(
        'login' => $c['login'], 'role' => $c['role'], 'nom' => $display_name,
        'id' => $user_id, 'action' => $action, 'lien' => $lien, 'mdp' => $mot_de_passe,
    );

    if ($mot_de_passe !== null) {
        $lignes[] = sprintf("%s\t%s\t%s\t%s", $c['login'], $mot_de_passe, $c['role'], $display_name);
    }
}

// --- ecriture du fichier d'identifiants (jamais dans la console) ---
if (!empty($lignes)) {
    $contenu  = "IDENTIFIANTS BEBBA — a conserver confidentiellement\n";
    $contenu .= "Generes le " . date('d/m/Y H:i') . "\n";
    $contenu .= "Connexion : " . home_url('/connexion-bebba/') . "\n";
    $contenu .= str_repeat('=', 70) . "\n\n";
    $contenu .= "identifiant\tmot de passe\trole\tnom\n";
    $contenu .= str_repeat('-', 70) . "\n";
    $contenu .= implode("\n", $lignes) . "\n\n";
    $contenu .= "IMPORTANT : changez ces mots de passe apres la premiere connexion.\n";
    if (@file_put_contents($fichier, $contenu) === false) {
        fwrite(STDERR, "ERREUR : impossible d'ecrire '{$fichier}'.\n");
        exit(1);
    }
    $ecrit = 'oui (' . count($lignes) . " compte(s))";
} else {
    $ecrit = 'aucun nouveau compte -> aucun mot de passe a enregistrer';
}

// --- sortie console SANS mot de passe ---
echo "\n";
echo "=== COMPTES BEBBA ===\n";
printf("  %-22s %-18s %-16s %s\n", 'identifiant', 'role', 'etat', 'rattachement');
echo '  ' . str_repeat('-', 76) . "\n";
foreach ($rapport as $r) {
    printf("  %-22s %-18s %-16s %s\n", $r['login'], $r['role'], $r['action'], $r['lien']);
}
echo "\n";
echo "  Fichier d'identifiants : {$fichier}  [{$ecrit}]\n";
echo "  Erreurs                : {$erreurs}\n";
echo "\n  Les mots de passe ne sont PAS affiches ici : voir le fichier ci-dessus.\n\n";

exit($erreurs > 0 ? 2 : 0);
