<?php
/*
Plugin Name: BEBBA Healthy Food
Description: Gestion de BEBBA Healthy Food.
Version: 1.0.0
Author: BEBBA
*/

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capacités BEBBA par rôle.
 */
function bebba_get_role_capabilities() {
    return array(
        'bebba_administrator' => array(
            'bebba_manage' => true,
            'bebba_manage_products' => true,
            'bebba_manage_categories' => true,
            'bebba_manage_stock' => true,
            'bebba_manage_suppliers' => true,
            'bebba_manage_drivers' => true,
            'bebba_manage_orders' => true,
            'bebba_manage_kitchen' => true,
            'bebba_manage_payments' => true,
            'bebba_manage_users' => true,
            'bebba_view_reports' => true,
            'bebba_place_orders' => true,
            'bebba_view_own_orders' => true,
            'bebba_manage_own_delivery' => true,
            'bebba_collect_payment' => true,
        ),
        'bebba_cuisine' => array(
            'bebba_manage_kitchen' => true,
        ),
        'bebba_livreur' => array(
            'bebba_manage_own_delivery' => true,
            'bebba_collect_payment' => true,
        ),
        'bebba_client' => array(
            'bebba_place_orders' => true,
            'bebba_view_own_orders' => true,
        ),
    );
}

/**
 * Enregistre les rôles BEBBA avec leurs capacités.
 * Appelé lors de l'activation du plugin.
 */
function bebba_register_roles() {
    $capabilities = bebba_get_role_capabilities();

    foreach ($capabilities as $role_name => $caps) {
        if (!get_role($role_name)) {
            add_role($role_name, ucfirst(str_replace('bebba_', '', $role_name)), $caps);
        } else {
            $role = get_role($role_name);
            foreach ($caps as $cap => $grant) {
                $role->add_cap($cap);
            }
        }
    }
}

register_activation_hook(__FILE__, 'bebba_register_roles');

/**
 * Accorde l'accès aux capacités BEBBA aux administrateurs WordPress
 * sans modifier leurs capacités stockées.
 */
function bebba_filter_user_has_cap($allcaps, $caps, $args, $user) {
    if (!isset($user->roles) || !in_array('administrator', $user->roles, true)) {
        return $allcaps;
    }

    $bebba_caps = array_keys(bebba_get_role_capabilities()['bebba_administrator']);

    foreach ($caps as $cap) {
        if (in_array($cap, $bebba_caps, true)) {
            $allcaps[$cap] = true;
        }
    }

    return $allcaps;
}

add_filter('user_has_cap', 'bebba_filter_user_has_cap', 10, 4);

/**
 * Modifie le libellé de la page "Connexion BEBBA" dans les menus de navigation
 * selon l'état de connexion de l'utilisateur.
 * Cible la page par son post_name 'connexion-bebba' (post_type 'page').
 */
function bebba_filter_navigation_page_title($pages, $args) {
    if (is_admin()) {
        return $pages;
    }

    foreach ($pages as $page) {
        if (
            isset($page->post_name) &&
            $page->post_name === 'connexion-bebba' &&
            isset($page->post_type) &&
            $page->post_type === 'page'
        ) {
            $page->post_title = is_user_logged_in() ? 'Connecté à BEBBA' : 'Connexion BEBBA';
        }
    }

    return $pages;
}

add_filter('get_pages', 'bebba_filter_navigation_page_title', 10, 2);

/**
 * Remplace le lien "Connecté à BEBBA" par un texte simple dans la navigation
 * lorsque l'utilisateur est connecté.
 * Utilise pre_render_block pour intercepter le rendu de core/page-list.
 */
function bebba_filter_page_list_render($pre_render, $parsed_block) {
    if (
        ! isset($parsed_block['blockName']) ||
        $parsed_block['blockName'] !== 'core/page-list' ||
        is_admin() ||
        ! is_user_logged_in()
    ) {
        return $pre_render;
    }

    // Rendu original du bloc page-list
    $html = render_block_core_page_list(
        $parsed_block['attrs'] ?? [],
        $parsed_block['innerHTML'] ?? '',
        new WP_Block($parsed_block)
    );

    // URL de la page connexion-bebba
    $connexion_url = esc_url(home_url('/connexion-bebba/'));

    // Remplacer le lien par un span pour cette page spécifique
    // Accepte les attributs éventuels entre href et > (ex: aria-current="page")
    $html = preg_replace(
        '#<a class="wp-block-pages-list__item__link[^"]*" href="' . preg_quote($connexion_url, '#') . '"[^>]*>Connecté à BEBBA</a>#',
        '<span class="wp-block-pages-list__item__link">Connecté à BEBBA</span>',
        $html
    );

    return $html;
}

add_filter('pre_render_block', 'bebba_filter_page_list_render', 10, 2);

/**
 * Vérifie la présence des tables principales BEBBA.
 */
function bebba_check_database() {
    global $wpdb;

    $tables = array(
        'bebba_categories',
        'bebba_products',
        'bebba_orders',
        'bebba_ingredients',
        'bebba_drivers',
        'bebba_stock_movements',
    );

    $result = array();

    foreach ($tables as $table) {
        $full_table = $wpdb->prefix . $table;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $full_table
            )
        );

        $result[$table] = ($exists === $full_table);
    }

    return $result;
}

/**
 * Page BEBBA dans l'administration WordPress.
 */
function bebba_admin_page() {
    $tables = bebba_check_database();
    ?>
    <div class="wrap">
        <h1>BEBBA Healthy Food</h1>

        <p>Connexion à la base de données BEBBA via WordPress.</p>

        <h2>Tables BEBBA</h2>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Table</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables as $table => $exists) : ?>
                    <tr>
                        <td><?php echo esc_html($table); ?></td>
                        <td>
                            <?php echo $exists ? '✓ Présente' : '✗ Absente'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Ajoute le menu BEBBA dans l'administration.
 */
function bebba_register_admin_menu() {
    add_menu_page(
        'BEBBA Healthy Food',
        'BEBBA',
        'bebba_manage',
        'bebba',
        'bebba_admin_page',
        'dashicons-store',
        25
    );
}

add_action('admin_menu', 'bebba_register_admin_menu');
/**
 * Page des catégories BEBBA.
 */
function bebba_categories_page() {
    global $wpdb;

    $table = 'bebba_categories';

    $categories = $wpdb->get_results(
        "SELECT id, name, slug, active, sort_order
         FROM {$table}
         ORDER BY sort_order ASC, id ASC"
    );
    ?>
    <div class="wrap">
        <h1>Catégories BEBBA</h1>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom</th>
                    <th>Slug</th>
                    <th>Active</th>
                    <th>Ordre</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)) : ?>
                    <tr>
                        <td colspan="5">Aucune catégorie enregistrée.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($categories as $category) : ?>
                        <tr>
                            <td><?php echo esc_html($category->id); ?></td>
                            <td><?php echo esc_html($category->name); ?></td>
                            <td><?php echo esc_html($category->slug); ?></td>
                            <td><?php echo $category->active ? 'Oui' : 'Non'; ?></td>
                            <td><?php echo esc_html($category->sort_order); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Ajoute la page Catégories comme sous-menu BEBBA.
 */
function bebba_register_categories_menu() {
    add_submenu_page(
        'bebba',
        'Catégories',
        'Catégories',
        'bebba_manage_categories',
        'bebba-categories',
        'bebba_categories_page'
    );
}

add_action('admin_menu', 'bebba_register_categories_menu');
function bebba_products_page() {
    global $wpdb;

    $products = $wpdb->get_results(
        "SELECT
            p.id,
            p.name,
            p.base_price,
            p.active,
            p.is_available,
            c.name AS category_name
         FROM bebba_products p
         LEFT JOIN bebba_categories c ON c.id = p.category_id
         ORDER BY p.sort_order ASC, p.id ASC"
    );
    ?>
    <div class="wrap">
        <h1>Produits BEBBA</h1>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom</th>
                    <th>Catégorie</th>
                    <th>Prix</th>
                    <th>Actif</th>
                    <th>Disponible</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)) : ?>
                    <tr>
                        <td colspan="6">Aucun produit enregistré.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($products as $product) : ?>
                        <tr>
                            <td><?php echo esc_html($product->id); ?></td>
                            <td><?php echo esc_html($product->name); ?></td>
                            <td><?php echo esc_html($product->category_name ?? ''); ?></td>
                            <td><?php echo esc_html($product->base_price); ?> DT</td>
                            <td><?php echo $product->active ? 'Oui' : 'Non'; ?></td>
                            <td><?php echo $product->is_available ? 'Oui' : 'Non'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function bebba_register_products_menu() {
    add_submenu_page(
        'bebba',
        'Produits',
        'Produits',
        'bebba_manage_products',
        'bebba-products',
        'bebba_products_page'
    );
}

add_action('admin_menu', 'bebba_register_products_menu');

/**
 * Récupère les utilisateurs WordPress ayant un rôle BEBBA.
 */
function bebba_get_bebba_users() {
    $bebba_roles = array('bebba_administrator', 'bebba_cuisine', 'bebba_livreur', 'bebba_client');
    $users = array();

    foreach ($bebba_roles as $role) {
        $role_users = get_users(array(
            'role'    => $role,
            'fields'  => 'all',
            'orderby' => 'registered',
            'order'   => 'DESC',
        ));

        foreach ($role_users as $user) {
            $users[] = array(
                'ID'            => $user->ID,
                'user_login'    => $user->user_login,
                'user_email'    => $user->user_email,
                'user_registered' => $user->user_registered,
                'bebba_role'    => $role,
                'display_name'  => $user->display_name,
            );
        }
    }

    usort($users, function($a, $b) {
        return strtotime($b['user_registered']) - strtotime($a['user_registered']);
    });

    return $users;
}

/**
 * Affiche la page Utilisateurs BEBBA.
 */
function bebba_users_page() {
    $users = bebba_get_bebba_users();

    $role_labels = array(
        'bebba_administrator' => 'Administrateur BEBBA',
        'bebba_cuisine'       => 'Cuisine',
        'bebba_livreur'       => 'Livreur',
        'bebba_client'        => 'Client',
    );
    ?>
    <div class="wrap">
        <h1>Utilisateurs BEBBA</h1>

        <p class="description">
            Liste des comptes WordPress disposant d'un rôle BEBBA.
            Le compte WordPress natif <code>administrator</code> n'est pas affiché ici.
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Identifiant</th>
                    <th>Email</th>
                    <th>Rôle BEBBA</th>
                    <th>Date d'inscription</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)) : ?>
                    <tr>
                        <td colspan="6">Aucun utilisateur BEBBA trouvé.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($users as $user) : ?>
                        <tr>
                            <td><?php echo esc_html($user['ID']); ?></td>
                            <td><?php echo esc_html($user['user_login']); ?></td>
                            <td><?php echo esc_html($user['user_email']); ?></td>
                            <td><?php echo esc_html($role_labels[$user['bebba_role']] ?? $user['bebba_role']); ?></td>
                            <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($user['user_registered']))); ?></td>
                            <td><?php echo $user['display_name'] ? 'Actif' : 'Inconnu'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Ajouter un utilisateur BEBBA</h2>
        <p class="description">
            <em>Fonctionnalité à venir : création et modification de comptes BEBBA.</em>
        </p>
    </div>
    <?php
}

/**
 * Ajoute la page Utilisateurs comme sous-menu BEBBA.
 */
function bebba_register_users_menu() {
    add_submenu_page(
        'bebba',
        'Utilisateurs',
        'Utilisateurs',
        'bebba_manage_users',
        'bebba-users',
        'bebba_users_page'
    );
}

add_action('admin_menu', 'bebba_register_users_menu');

/**
 * Normalise un numéro de téléphone international.
 * Supprime tous les séparateurs non numériques, conserve tous les chiffres.
 * Retourne le numéro normalisé (ex: +216 98 123 456 -> 0021698123456).
 */
function bebba_normalize_phone($phone_raw) {
    if (!is_string($phone_raw)) {
        return '';
    }
    $digits = preg_replace('/\D/', '', $phone_raw);
    if (str_starts_with($digits, '00')) {
        return $digits;
    }
    return '00' . $digits;
}

/**
 * Génère l'identifiant BHF à partir du numéro normalisé.
 */
function bebba_generate_user_login($normalized_phone) {
    return 'BHF' . $normalized_phone;
}

/**
 * Vérifie si un numéro de téléphone est déjà utilisé.
 */
function bebba_phone_exists($normalized_phone) {
    global $wpdb;
    $meta_key = 'bebba_phone';
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
        $meta_key, $normalized_phone
    ));
    return (int)$count > 0;
}

/**
 * Traite l'inscription client BEBBA.
 */
function bebba_handle_registration() {
    if (!isset($_POST['bebba_register_nonce']) || !wp_verify_nonce($_POST['bebba_register_nonce'], 'bebba_register')) {
        return new WP_Error('invalid_nonce', 'Nonce invalide.');
    }

    $country_code = isset($_POST['bebba_phone_country']) ? sanitize_text_field($_POST['bebba_phone_country']) : '';
    $national_number = isset($_POST['bebba_phone_national']) ? sanitize_text_field($_POST['bebba_phone_national']) : '';
    $password = isset($_POST['bebba_password']) ? $_POST['bebba_password'] : '';
    $password_confirm = isset($_POST['bebba_password_confirm']) ? $_POST['bebba_password_confirm'] : '';
    $email = isset($_POST['bebba_email']) ? sanitize_email($_POST['bebba_email']) : '';

    if (empty($country_code) || empty($national_number)) {
        return new WP_Error('missing_phone', 'Indicatif et numéro de téléphone requis.');
    }

    $full_phone_raw = '+' . $country_code . $national_number;
    $normalized_phone = bebba_normalize_phone($full_phone_raw);

    if (empty($normalized_phone) || strlen($normalized_phone) < 4) {
        return new WP_Error('invalid_phone', 'Numéro de téléphone invalide.');
    }

    if (bebba_phone_exists($normalized_phone)) {
        return new WP_Error('phone_exists', 'Ce numéro de téléphone est déjà utilisé.');
    }

    if (empty($password)) {
        return new WP_Error('missing_password', 'Mot de passe requis.');
    }

    if ($password !== $password_confirm) {
        return new WP_Error('password_mismatch', 'Les mots de passe ne correspondent pas.');
    }

    if (strlen($password) < 8) {
        return new WP_Error('weak_password', 'Le mot de passe doit contenir au moins 8 caractères.');
    }

    $user_login = bebba_generate_user_login($normalized_phone);

    if (username_exists($user_login)) {
        return new WP_Error('login_exists', 'Identifiant déjà utilisé.');
    }

    $user_data = array(
        'user_login'    => $user_login,
        'user_pass'     => $password,
        'user_email'    => $email,
        'role'          => 'bebba_client',
        'display_name'  => $user_login,
    );

    $user_id = wp_insert_user($user_data);

    if (is_wp_error($user_id)) {
        return $user_id;
    }

    update_user_meta($user_id, 'bebba_phone_country_code', sanitize_text_field($country_code));
    update_user_meta($user_id, 'bebba_phone_national', sanitize_text_field($national_number));
    update_user_meta($user_id, 'bebba_phone', $normalized_phone);

    return $user_id;
}

/**
 * Traite la connexion BEBBA.
 */
function bebba_handle_login() {
    if (!isset($_POST['bebba_login_nonce']) || !wp_verify_nonce($_POST['bebba_login_nonce'], 'bebba_login')) {
        return new WP_Error('invalid_nonce', 'Nonce invalide.');
    }

    $user_login = isset($_POST['bebba_user_login']) ? sanitize_text_field($_POST['bebba_user_login']) : '';
    $password = isset($_POST['bebba_password']) ? $_POST['bebba_password'] : '';
    $remember = isset($_POST['bebba_remember']) && $_POST['bebba_remember'] === '1';

    if (empty($user_login) || empty($password)) {
        return new WP_Error('missing_credentials', 'Identifiant et mot de passe requis.');
    }

    $creds = array(
        'user_login'    => $user_login,
        'user_password' => $password,
        'remember'      => $remember,
    );

    $user = wp_signon($creds, false);

    if (is_wp_error($user)) {
        return new WP_Error('login_failed', 'Identifiant ou mot de passe incorrect.');
    }

    // Vérifier que l'utilisateur possède au moins un rôle BEBBA
    $bebba_roles = array('bebba_administrator', 'bebba_cuisine', 'bebba_livreur', 'bebba_client');
    $has_bebba_role = array_intersect($bebba_roles, (array) $user->roles);

    if (empty($has_bebba_role)) {
        // L'utilisateur n'a pas de rôle BEBBA : refuser la connexion BEBBA
        // NE PAS appeler wp_logout() pour ne pas affecter la session WordPress existante
        return new WP_Error('login_failed', 'Identifiant ou mot de passe incorrect.');
    }

    return $user;
}

/**
 * Traite la déconnexion BEBBA.
 */
function bebba_handle_logout() {
    if (!isset($_POST['bebba_logout_nonce']) || !wp_verify_nonce($_POST['bebba_logout_nonce'], 'bebba_logout')) {
        return new WP_Error('invalid_nonce', 'Nonce invalide.');
    }

    wp_logout();
    return true;
}

/**
 * Point d'entrée unique pour traiter les formulaires d'authentification.
 * Appelé sur 'init' pour traiter avant l'affichage.
 */
function bebba_process_auth_forms() {
    if (is_admin()) {
        return;
    }

    if (isset($_POST['bebba_action'])) {
        $action = sanitize_text_field($_POST['bebba_action']);

        switch ($action) {
            case 'register':
                $result = bebba_handle_registration();
                if (is_wp_error($result)) {
                    bebba_set_flash_error($result->get_error_message());
                } else {
                    $creds = array(
                        'user_login'    => bebba_generate_user_login(bebba_normalize_phone('+' . ($_POST['bebba_phone_country'] ?? '') . ($_POST['bebba_phone_national'] ?? ''))),
                        'user_password' => $_POST['bebba_password'] ?? '',
                        'remember'      => false,
                    );
                    $signon_result = wp_signon($creds, false);
                    if (is_wp_error($signon_result)) {
                        bebba_set_flash_error('Connexion automatique échouée : ' . $signon_result->get_error_message());
                    } else {
                        wp_safe_redirect(wp_unslash($_SERVER['REQUEST_URI']));
                        exit;
                    }
                }
                break;

            case 'login':
                $result = bebba_handle_login();
                if (is_wp_error($result)) {
                    bebba_set_flash_error($result->get_error_message());
                } else {
                    wp_safe_redirect(wp_unslash($_SERVER['REQUEST_URI']));
                    exit;
                }
                break;

            case 'logout':
                bebba_handle_logout();
                bebba_set_flash_success('Déconnexion réussie.');
                break;
        }
    }
}

add_action('init', 'bebba_process_auth_forms');

/**
 * Stockage temporaire des messages flash (erreurs/succès).
 */
function bebba_set_flash_error($message) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['bebba_flash_error'] = $message;
}

function bebba_set_flash_success($message) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['bebba_flash_success'] = $message;
}

function bebba_get_flash_error() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $msg = $_SESSION['bebba_flash_error'] ?? '';
    unset($_SESSION['bebba_flash_error']);
    return $msg;
}

function bebba_get_flash_success() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $msg = $_SESSION['bebba_flash_success'] ?? '';
    unset($_SESSION['bebba_flash_success']);
    return $msg;
}

/**
 * Shortcode [bebba_auth] : affiche inscription/connexion ou état connecté.
 */
function bebba_auth_shortcode() {
    $current_user = wp_get_current_user();
    $bebba_roles = array('bebba_administrator', 'bebba_cuisine', 'bebba_livreur', 'bebba_client');
    $user_bebba_role = '';
    foreach ($bebba_roles as $role) {
        if (in_array($role, (array) $current_user->roles, true)) {
            $user_bebba_role = $role;
            break;
        }
    }

    $is_bebba_user = ! empty($user_bebba_role);

    if ($is_bebba_user) {
        $phone = get_user_meta($current_user->ID, 'bebba_phone', true);
        $obfuscated_phone = $phone ? '******' . substr($phone, -4) : 'Non défini';
        $is_client = $user_bebba_role === 'bebba_client';

        ob_start();
        ?>
        <div class="bebba-auth bebba-auth--logged-in">
            <h3>Espace Client BEBBA</h3>
            <p><strong>Identifiant :</strong> <?php echo esc_html($current_user->user_login); ?></p>
            <?php if (!$is_client) : ?>
                <p><strong>Rôle :</strong> <?php echo esc_html($user_bebba_role ?: 'Aucun rôle BEBBA'); ?></p>
            <?php endif; ?>
            <p><strong>Téléphone :</strong> <?php echo esc_html($obfuscated_phone); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('bebba_logout', 'bebba_logout_nonce'); ?>
                <input type="hidden" name="bebba_action" value="logout">
                <button type="submit" class="button button-primary">Se déconnecter</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // Utilisateur non connecté BEBBA (même s'il est connecté à WordPress)
    $error = bebba_get_flash_error();
    $success = bebba_get_flash_success();

    ob_start();
    ?>
    <div class="bebba-auth bebba-auth--logged-out">
        <?php if ($error) : ?>
            <div class="bebba-notice bebba-notice--error"><?php echo esc_html($error); ?></div>
        <?php endif; ?>
        <?php if ($success) : ?>
            <div class="bebba-notice bebba-notice--success"><?php echo esc_html($success); ?></div>
        <?php endif; ?>

        <div class="bebba-auth-tabs">
            <button type="button" class="bebba-tab-btn active" data-tab="login">Connexion</button>
            <button type="button" class="bebba-tab-btn" data-tab="register">Inscription</button>
        </div>

        <form method="post" action="" id="bebba-login-form" class="bebba-auth-form active">
            <?php wp_nonce_field('bebba_login', 'bebba_login_nonce'); ?>
            <input type="hidden" name="bebba_action" value="login">
            <h4>Connexion</h4>
            <p>
                <label for="bebba_user_login">Identifiant BHF <span class="required">*</span></label>
                <input type="text" name="bebba_user_login" id="bebba_user_login" required autocomplete="username" placeholder="Ex: BHF0021698123456">
            </p>
            <p>
                <label for="bebba_login_password">Mot de passe <span class="required">*</span></label>
                <input type="password" name="bebba_password" id="bebba_login_password" required autocomplete="current-password">
            </p>
            <p>
                <label for="bebba_remember">
                    <input type="checkbox" name="bebba_remember" id="bebba_remember" value="1">
                    Rester connecté sur cet appareil
                </label>
            </p>
            <p class="submit">
                <button type="submit" class="button button-primary">Se connecter</button>
            </p>
        </form>

        <form method="post" action="" id="bebba-register-form" class="bebba-auth-form" style="display:none;">
            <?php wp_nonce_field('bebba_register', 'bebba_register_nonce'); ?>
            <input type="hidden" name="bebba_action" value="register">
            <h4>Inscription Client</h4>
            <p>
                <label for="bebba_phone_country">Indicatif <span class="required">*</span></label>
                <input type="text" name="bebba_phone_country" id="bebba_phone_country" required placeholder="+216" pattern="\+\d{1,4}" title="Indicatif international (ex: +216)">
            </p>
            <p>
                <label for="bebba_phone_national">Numéro national <span class="required">*</span></label>
                <input type="tel" name="bebba_phone_national" id="bebba_phone_national" required placeholder="98 123 456" pattern="[\d\s\-\.]{6,}" title="Numéro national sans l'indicatif">
            </p>
            <p>
                <label for="bebba_email">Email (facultatif)</label>
                <input type="email" name="bebba_email" id="bebba_email" placeholder="vous@exemple.com">
            </p>
            <p>
                <label for="bebba_reg_password">Mot de passe <span class="required">*</span></label>
                <input type="password" name="bebba_password" id="bebba_reg_password" required autocomplete="new-password" minlength="8">
            </p>
            <p>
                <label for="bebba_password_confirm">Confirmation <span class="required">*</span></label>
                <input type="password" name="bebba_password_confirm" id="bebba_password_confirm" required autocomplete="new-password" minlength="8">
            </p>
            <p class="submit">
                <button type="submit" class="button button-primary">Créer mon compte</button>
            </p>
        </form>
    </div>

    <script>
    (function() {
        var tabBtns = document.querySelectorAll('.bebba-tab-btn');
        var forms = document.querySelectorAll('.bebba-auth-form');
        tabBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = this.dataset.tab;
                tabBtns.forEach(function(b) { b.classList.remove('active'); });
                forms.forEach(function(f) { f.style.display = 'none'; f.classList.remove('active'); });
                this.classList.add('active');
                var targetForm = document.getElementById('bebba-' + target + '-form');
                if (targetForm) { targetForm.style.display = 'block'; targetForm.classList.add('active'); }
            });
        });
    })();
    </script>
    <style>
    .bebba-auth { max-width: 480px; margin: 0 auto; padding: 20px; font-family: inherit; }
    .bebba-auth h3, .bebba-auth h4 { margin-top: 0; }
    .bebba-auth p { margin: 12px 0; }
    .bebba-auth label { display: block; margin-bottom: 4px; font-weight: 600; }
    .bebba-auth input[type="text"],
    .bebba-auth input[type="tel"],
    .bebba-auth input[type="email"],
    .bebba-auth input[type="password"] { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
    .bebba-auth .required { color: #d63638; }
    .bebba-auth .submit { margin-top: 16px; }
    .bebba-auth .button-primary { background: #0073aa; color: #fff; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 1rem; }
    .bebba-auth .button-primary:hover { background: #005a87; }
    .bebba-auth-tabs { display: flex; gap: 8px; margin-bottom: 16px; }
    .bebba-tab-btn { padding: 8px 16px; border: 1px solid #ddd; background: #f5f5f5; border-radius: 4px 4px 0 0; cursor: pointer; }
    .bebba-tab-btn.active { background: #fff; border-bottom-color: #fff; margin-bottom: -1px; }
    .bebba-notice { padding: 12px; border-radius: 4px; margin-bottom: 16px; }
    .bebba-notice--error { background: #fbeaea; border: 1px solid #d63638; color: #d63638; }
    .bebba-notice--success { background: #e3f7e8; border: 1px solid #46b450; color: #46b450; }
    .bebba-auth--logged-in { background: #f5f5f5; border-radius: 8px; }
    .bebba-auth--logged-in p { margin: 8px 0; }
    </style>
    <?php
    return ob_get_clean();
}

add_shortcode('bebba_auth', 'bebba_auth_shortcode');