<?php
/*
Plugin Name: BEBBA Healthy Food
Description: Gestion de BEBBA Healthy Food.
Version: 1.1.0
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
register_activation_hook(__FILE__, 'bebba_create_pages');

function bebba_commande_shortcode() {
    bebba_enqueue_menu_assets();
    ob_start();
    ?>
    <div id="bebba-menu" class="bebba-menu" role="main"></div>
    <aside id="bebba-cart-panel" class="bebba-cart-panel" aria-label="Panier">
        <div class="bebba-cart-panel__header">
            <h2 class="bebba-cart-panel__title">🛒 Mon panier</h2>
            <button id="bebba-cart-close" class="bebba-modal__close" aria-label="Fermer le panier">&times;</button>
        </div>
        <div id="bebba-cart-items" class="bebba-cart-panel__items"></div>
        <div class="bebba-cart-panel__footer">
            <div class="bebba-cart-panel__fee-row">
                <span>Livraison</span>
                <span id="bebba-delivery-fee">0.00 DT</span>
            </div>
            <div class="bebba-cart-panel__total-row">
                <span>Total</span>
                <strong id="bebba-cart-grand-total">0.00 DT</strong>
            </div>
            <a href="<?php echo esc_url(home_url('/commande/')); ?>" id="bebba-checkout-btn" class="bebba-checkout-btn">
                Commander
            </a>
        </div>
    </aside>
    <div id="bebba-cart-backdrop" class="bebba-cart-backdrop" hidden></div>
    <script>
    (function(){
      if(typeof updateCartUI === 'function'){ updateCartUI(); }
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('bebba_commande', 'bebba_commande_shortcode');

/**
 * Crée les pages WordPress /menu/ et /commande/ à l'activation du plugin.
 */
function bebba_create_pages() {
    $menu_page = get_page_by_path('menu');
    if (!$menu_page) {
        wp_insert_post(array(
            'post_title'   => 'Menu BEBBA',
            'post_name'    => 'menu',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[bebba_menu]',
        ));
    }
    $commande_page = get_page_by_path('commande');
    if (!$commande_page) {
        wp_insert_post(array(
            'post_title'   => 'Commande BEBBA',
            'post_name'    => 'commande',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[bebba_commande]',
        ));
    }
    flush_rewrite_rules();
}

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

/* ============================================================
 * SECTION 2 — REST API : GET /wp-json/bebba/v1/menu
 * Retourne catégories actives + produits + options + suppléments
 * ============================================================ */

/**
 * Enregistre les endpoints REST BEBBA.
 */
function bebba_register_rest_routes() {
    register_rest_route('bebba/v1', '/menu', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bebba_rest_menu',
        'permission_callback' => '__return_true',
    ));
}

add_action('rest_api_init', 'bebba_register_rest_routes');

/**
 * Handler REST GET /wp-json/bebba/v1/menu
 * Retourne le catalogue complet structuré pour le frontend.
 *
 * @return WP_REST_Response
 */
function bebba_rest_menu() {
    global $wpdb;

    // --- Catégories actives ---
    $categories_raw = $wpdb->get_results(
        "SELECT id, name, slug, icon, image_url, sort_order
         FROM bebba_categories
         WHERE active = 1
         ORDER BY sort_order ASC, id ASC"
    );

    // --- Produits actifs avec leur catégorie ---
    $products_raw = $wpdb->get_results(
        "SELECT
             p.id,
             p.category_id,
             p.name,
             p.description,
             p.base_price,
             p.image_url,
             p.calories,
             p.protein_grams,
             p.carbs_grams,
             p.fat_grams,
             p.is_available,
             p.is_popular,
             p.sort_order
         FROM bebba_products p
         WHERE p.active = 1 AND p.is_available = 1
         ORDER BY p.sort_order ASC, p.id ASC"
    );

    if (empty($products_raw)) {
        return new WP_REST_Response(array(
            'categories' => array(),
            'products'   => array(),
        ), 200);
    }

    // Collecte des IDs produits
    $product_ids = array_map(function($p) { return (int) $p->id; }, $products_raw);
    $ids_placeholder = implode(',', $product_ids);

    // --- Options produit (protein / veggies / base) ---
    $options_raw = $wpdb->get_results(
        "SELECT id, product_id, option_type, position, label, extra_price, extra_grams, sort_order, is_default
         FROM bebba_product_options
         WHERE product_id IN ({$ids_placeholder})
         ORDER BY product_id ASC, option_type ASC, sort_order ASC, position ASC"
    );

    // --- Suppléments disponibles par produit ---
    $supplements_raw = $wpdb->get_results(
        "SELECT
             ps.product_id,
             s.id         AS supplement_id,
             s.name,
             s.description,
             s.price,
             s.available,
             ps.sort_order
         FROM bebba_product_supplements ps
         JOIN bebba_supplements s ON s.id = ps.supplement_id
         WHERE ps.product_id IN ({$ids_placeholder})
           AND s.active = 1
           AND s.available = 1
         ORDER BY ps.product_id ASC, ps.sort_order ASC"
    );

    // --- Indexation options par product_id et option_type ---
    $options_by_product = array();
    foreach ($options_raw as $opt) {
        $pid  = (int) $opt->product_id;
        $type = $opt->option_type;
        if (!isset($options_by_product[$pid])) {
            $options_by_product[$pid] = array();
        }
        if (!isset($options_by_product[$pid][$type])) {
            $options_by_product[$pid][$type] = array();
        }
        $options_by_product[$pid][$type][] = array(
            'id'          => (int)   $opt->id,
            'label'       =>         $opt->label,
            'extra_price' => (float) $opt->extra_price,
            'extra_grams' => $opt->extra_grams !== null ? (float) $opt->extra_grams : null,
            'is_default'  => (bool)  $opt->is_default,
        );
    }

    // --- Indexation suppléments par product_id ---
    $supplements_by_product = array();
    foreach ($supplements_raw as $sup) {
        $pid = (int) $sup->product_id;
        if (!isset($supplements_by_product[$pid])) {
            $supplements_by_product[$pid] = array();
        }
        $supplements_by_product[$pid][] = array(
            'id'          => (int)   $sup->supplement_id,
            'name'        =>         $sup->name,
            'description' =>         $sup->description,
            'price'       => (float) $sup->price,
        );
    }

    // --- Construction produits enrichis ---
    $products = array();
    foreach ($products_raw as $p) {
        $pid = (int) $p->id;
        $products[] = array(
            'id'           => $pid,
            'category_id'  => (int)   $p->category_id,
            'name'         =>         $p->name,
            'description'  =>         $p->description,
            'base_price'   => (float) $p->base_price,
            'image_url'    =>         $p->image_url,
            'calories'     => $p->calories    !== null ? (int)   $p->calories    : null,
            'protein_g'    => $p->protein_grams !== null ? (float) $p->protein_grams : null,
            'carbs_g'      => $p->carbs_grams  !== null ? (float) $p->carbs_grams  : null,
            'fat_g'        => $p->fat_grams    !== null ? (float) $p->fat_grams    : null,
            'is_popular'   => (bool)  $p->is_popular,
            'options'      => $options_by_product[$pid]     ?? array(),
            'supplements'  => $supplements_by_product[$pid] ?? array(),
        );
    }

    // --- Construction catégories enrichies (seulement celles qui ont des produits) ---
    $cat_ids_with_products = array_unique(
        array_map(function($pr) { return $pr['category_id']; }, $products)
    );

    $categories = array();
    foreach ($categories_raw as $c) {
        if (!in_array((int) $c->id, $cat_ids_with_products, true)) {
            continue;
        }
        $categories[] = array(
            'id'        => (int) $c->id,
            'name'      =>       $c->name,
            'slug'      =>       $c->slug,
            'icon'      =>       $c->icon,
            'image_url' =>       $c->image_url,
        );
    }

    return new WP_REST_Response(array(
        'categories' => $categories,
        'products'   => $products,
    ), 200);
}

/* ============================================================
 * SECTION 3 — Shortcode [bebba_menu]
 * Catalogue public : onglets catégories, produits, modal config,
 * panier localStorage.
 * ============================================================ */

/**
 * Enqueue CSS + JS dédiés au catalogue (uniquement quand le shortcode est rendu).
 */
function bebba_enqueue_menu_assets() {
    wp_enqueue_style(
        'bebba-menu',
        plugin_dir_url(__FILE__) . 'assets/css/menu.css',
        array(),
        '1.1.0'
    );
    wp_enqueue_script(
        'bebba-menu',
        plugin_dir_url(__FILE__) . 'assets/js/menu.js',
        array(),
        '1.1.0',
        true
    );
    wp_localize_script('bebba-menu', 'BEBBA_MENU', array(
        'api_url'       => esc_url_raw(rest_url('bebba/v1/menu')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'currency'      => 'DT',
        'delivery_fee'  => 0,
        'login_url'     => esc_url(home_url('/connexion-bebba/')),
        'is_logged_in'  => is_user_logged_in(),
    ));
}

/**
 * Shortcode [bebba_menu] — rendu de la coquille HTML.
 */
function bebba_menu_shortcode() {
    bebba_create_pages();
    bebba_enqueue_menu_assets();

    ob_start();
    ?>
    <div id="bebba-menu" class="bebba-menu" role="main">

        <!-- En-tête catalogue -->
        <header class="bebba-menu__header">
            <div class="bebba-menu__header-inner">
                <h1 class="bebba-menu__title">Notre <span>Menu</span></h1>
                <p class="bebba-menu__subtitle">Sain, savoureux, livré chez vous</p>
            </div>
            <!-- Panier flottant -->
            <button id="bebba-cart-btn" class="bebba-cart-btn" aria-label="Voir mon panier" style="display:none;">
                <span class="bebba-cart-btn__icon">🛒</span>
                <span id="bebba-cart-count" class="bebba-cart-btn__count">0</span>
                <span id="bebba-cart-total" class="bebba-cart-btn__total">0.00 DT</span>
            </button>
        </header>

        <!-- Filtres catégories -->
        <nav id="bebba-cats" class="bebba-cats" aria-label="Catégories" role="tablist">
            <div class="bebba-cats__track">
                <button class="bebba-cat-btn active" data-cat="all" role="tab" aria-selected="true">
                    <span class="bebba-cat-btn__icon">🍽️</span>
                    <span>Tout</span>
                </button>
            </div>
        </nav>

        <!-- Grille produits -->
        <section id="bebba-products-grid" class="bebba-products-grid" aria-live="polite">
            <div class="bebba-loader">
                <span class="bebba-loader__dot"></span>
                <span class="bebba-loader__dot"></span>
                <span class="bebba-loader__dot"></span>
            </div>
        </section>

        <!-- Modal configuration produit -->
        <div id="bebba-modal-overlay" class="bebba-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bebba-modal-name" hidden>
            <div class="bebba-modal">
                <button id="bebba-modal-close" class="bebba-modal__close" aria-label="Fermer">&times;</button>

                <div class="bebba-modal__hero">
                    <div id="bebba-modal-img" class="bebba-modal__img"></div>
                    <div class="bebba-modal__hero-info">
                        <span id="bebba-modal-badge" class="bebba-modal__badge" hidden>🔥 Populaire</span>
                        <h2 id="bebba-modal-name" class="bebba-modal__name"></h2>
                        <p id="bebba-modal-desc" class="bebba-modal__desc"></p>
                        <div id="bebba-modal-macros" class="bebba-modal__macros"></div>
                    </div>
                </div>

                <div class="bebba-modal__body">

                    <!-- Options protein -->
                    <div id="bebba-opts-protein" class="bebba-modal__opts-group" hidden>
                        <h3 class="bebba-modal__opts-title">🥩 Protéine</h3>
                        <div id="bebba-opts-protein-list" class="bebba-modal__opts-list"></div>
                    </div>

                    <!-- Options veggies -->
                    <div id="bebba-opts-veggies" class="bebba-modal__opts-group" hidden>
                        <h3 class="bebba-modal__opts-title">🥗 Légumes</h3>
                        <div id="bebba-opts-veggies-list" class="bebba-modal__opts-list"></div>
                    </div>

                    <!-- Options base -->
                    <div id="bebba-opts-base" class="bebba-modal__opts-group" hidden>
                        <h3 class="bebba-modal__opts-title">🌾 Base</h3>
                        <div id="bebba-opts-base-list" class="bebba-modal__opts-list"></div>
                    </div>

                    <!-- Suppléments -->
                    <div id="bebba-supplements" class="bebba-modal__opts-group" hidden>
                        <h3 class="bebba-modal__opts-title">➕ Suppléments</h3>
                        <div id="bebba-supplements-list" class="bebba-modal__supplements-list"></div>
                    </div>

                    <!-- Instructions spéciales -->
                    <div class="bebba-modal__opts-group">
                        <h3 class="bebba-modal__opts-title">📝 Instructions spéciales</h3>
                        <textarea id="bebba-special-instructions" class="bebba-modal__instructions"
                            placeholder="Allergies, préférences, demandes particulières…" maxlength="300" rows="2"></textarea>
                    </div>

                </div><!-- /.bebba-modal__body -->

                <!-- Footer modal : quantité + total + ajout panier -->
                <footer class="bebba-modal__footer">
                    <div class="bebba-modal__qty">
                        <button id="bebba-qty-minus" class="bebba-qty-btn" aria-label="Diminuer la quantité">−</button>
                        <span id="bebba-qty-val" class="bebba-qty-val">1</span>
                        <button id="bebba-qty-plus" class="bebba-qty-btn" aria-label="Augmenter la quantité">+</button>
                    </div>
                    <button id="bebba-add-to-cart" class="bebba-add-to-cart">
                        Ajouter — <span id="bebba-modal-line-total">0.00 DT</span>
                    </button>
                </footer>

            </div><!-- /.bebba-modal -->
        </div><!-- /.bebba-modal-overlay -->

        <!-- Panneau panier latéral -->
        <aside id="bebba-cart-panel" class="bebba-cart-panel" aria-label="Panier" hidden>
            <div class="bebba-cart-panel__header">
                <h2 class="bebba-cart-panel__title">🛒 Mon panier</h2>
                <button id="bebba-cart-close" class="bebba-modal__close" aria-label="Fermer le panier">&times;</button>
            </div>
            <div id="bebba-cart-items" class="bebba-cart-panel__items"></div>
            <div class="bebba-cart-panel__footer">
                <div class="bebba-cart-panel__fee-row">
                    <span>Livraison</span>
                    <span id="bebba-delivery-fee">0.00 DT</span>
                </div>
                <div class="bebba-cart-panel__total-row">
                    <span>Total</span>
                    <strong id="bebba-cart-grand-total">0.00 DT</strong>
                </div>
                <a href="<?php echo esc_url(home_url('/commande/')); ?>" id="bebba-checkout-btn" class="bebba-checkout-btn">
                    Commander
                </a>
            </div>
        </aside>
        <div id="bebba-cart-backdrop" class="bebba-cart-backdrop" hidden></div>

    </div><!-- /#bebba-menu -->
    <?php
    return ob_get_clean();
}

add_shortcode('bebba_menu', 'bebba_menu_shortcode');