<?php
/**
 * BEBBA Healthy Food — Création de commande (BLOC 8.1)
 *
 * Expose POST /wp-json/bebba/v1/orders
 *
 * Principes appliqués :
 *  - AUTORITÉ SERVEUR ABSOLUE sur les prix : toute valeur de prix envoyée par le
 *    navigateur est ignorée. Le serveur recalcule à partir de bebba_products,
 *    bebba_product_options et bebba_supplements.
 *  - TRANSACTION UNIQUE : commande, lignes, fiches de préparation, mouvements de
 *    stock et compteur sont écrits dans une seule transaction InnoDB.
 *  - VERROUILLAGE : compteur et ingrédients sont lus en SELECT ... FOR UPDATE.
 *  - IDEMPOTENCE : en-tête Idempotency-Key, avec détection de conflit de contenu
 *    (422) et d'usurpation par un tiers (403).
 *  - ANTI-IDOR : l'identité du client vient de la session WordPress, jamais du corps.
 *
 * @package BEBBA
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ============================================================================
 * 1. TABLES DE RÉFÉRENCE MÉTIER
 * ========================================================================== */

/**
 * Classification des ingrédients par fonction culinaire.
 * Sert aux substitutions (base) et aux additions (protéine / légumes).
 *
 * @return array
 */
function bebba_oc_ingredient_sets() {
    return array(
        'protein' => array('ing-poulet', 'ing-boeuf', 'ing-dinde', 'ing-saumon', 'ing-halloumi'),
        'starch'  => array('ing-riz', 'ing-quinoa', 'ing-patate-douce'),
        'veggies' => array('ing-legumes'),
        'egg'     => array('ing-oeuf'),
    );
}

/**
 * Mots-clés reconnus dans les libellés d'options, associés à un ingrédient.
 * L'ordre compte : les entrées longues doivent être testées avant les courtes.
 *
 * @return array
 */
function bebba_oc_keyword_map() {
    return array(
        'poulet'   => 'ing-poulet',
        'bœuf'     => 'ing-boeuf',
        'boeuf'    => 'ing-boeuf',
        'dinde'    => 'ing-dinde',
        'saumon'   => 'ing-saumon',
        'halloumi' => 'ing-halloumi',
        'avocat'   => 'ing-avocat',
        'quinoa'   => 'ing-quinoa',
        'patate'   => 'ing-patate-douce',
        'riz'      => 'ing-riz',
        'légumes'  => 'ing-legumes',
        'legumes'  => 'ing-legumes',
        'œuf'      => 'ing-oeuf',
        'oeuf'     => 'ing-oeuf',
    );
}

/**
 * Extrait d'un libellé les ingrédients cités.
 *
 * @param string $label    Libellé de l'option.
 * @param array  $keywords Table mot-clé => legacy_id.
 * @param array  $allow    Liste blanche de legacy_id (vide = tous).
 * @return array legacy_id uniques
 */
function bebba_oc_keywords_in_label($label, $keywords, $allow = array()) {
    $label = mb_strtolower((string) $label, 'UTF-8');
    $trouves = array();

    /* « bœuf » contient « œuf » : on neutralise le piège avant toute recherche. */
    $contient_boeuf = (mb_strpos($label, 'bœuf') !== false) || (mb_strpos($label, 'boeuf') !== false);

    foreach ($keywords as $mot => $legacy) {
        if (!empty($allow) && !in_array($legacy, $allow, true)) {
            continue;
        }
        if (($mot === 'œuf' || $mot === 'oeuf') && $contient_boeuf) {
            continue;
        }
        if (mb_strpos($label, $mot) !== false && !in_array($legacy, $trouves, true)) {
            $trouves[] = $legacy;
        }
    }
    return $trouves;
}

/**
 * Charge une fois l'index des ingrédients (legacy_id => données).
 *
 * @return array
 */
function bebba_oc_ingredient_index() {
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    global $wpdb;
    $index = array();
    $rows  = $wpdb->get_results(
        "SELECT id, legacy_id, name, unit, stock_quantity, active FROM bebba_ingredients"
    );
    if ($rows) {
        foreach ($rows as $r) {
            $index[$r->legacy_id] = array(
                'id'    => (int) $r->id,
                'name'  => $r->name,
                'unit'  => $r->unit !== null ? $r->unit : 'g',
                'stock' => (float) $r->stock_quantity,
                'active'=> (int) $r->active,
            );
        }
    }
    return $index;
}

/**
 * Nom et unité d'un ingrédient d'après son identifiant legacy.
 *
 * @param array  $index  Index des ingrédients.
 * @param string $legacy Identifiant legacy.
 * @return array [nom, unité]
 */
function bebba_oc_ing_meta($index, $legacy) {
    if (isset($index[$legacy])) {
        return array($index[$legacy]['name'], $index[$legacy]['unit']);
    }
    return array($legacy, 'g');
}

/* ============================================================================
 * 2. RÉSOLUTION DES OPTIONS — AUTORITÉ SERVEUR
 * ========================================================================== */

/**
 * Retrouve une option officielle par son libellé.
 * Le prix et les grammes proviennent EXCLUSIVEMENT de la base.
 *
 * @param array  $options Options du produit (toutes catégories).
 * @param string $type    'protein' | 'veggies' | 'base'
 * @param mixed  $input   Objet {label:...} ou chaîne.
 * @return array|WP_Error|null
 */
function bebba_oc_resolve_option($options, $type, $input) {
    if (empty($input)) {
        return null;
    }
    if (is_array($input)) {
        $label = isset($input['label']) ? $input['label'] : '';
    } else {
        $label = $input;
    }
    $label = trim((string) $label);
    if ($label === '') {
        return null;
    }

    foreach ($options as $opt) {
        if ($opt->option_type !== $type) {
            continue;
        }
        if (mb_strtolower(trim($opt->label), 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
            return array(
                'label'       => $opt->label,
                'extra_price' => round((float) $opt->extra_price, 2),
                'extra_grams' => $opt->extra_grams === null ? 0.0 : round((float) $opt->extra_grams, 2),
            );
        }
    }

    $noms = array('protein' => 'portion de protéine', 'veggies' => 'portion de légumes', 'base' => 'choix de base');
    return new WP_Error(
        'bebba_invalid_option',
        sprintf("Le choix « %s » (%s) n'est pas autorisé pour ce plat.", $label, $noms[$type]),
        array('status' => 400)
    );
}

/* ============================================================================
 * 3. CALCUL DE LA CONSOMMATION D'INGRÉDIENTS
 * ========================================================================== */

/**
 * Calcule la consommation d'ingrédients d'une ligne de commande.
 *
 * Règles appliquées, déduites des libellés officiels du catalogue :
 *  1. La recette du produit fournit la consommation de base.
 *  2. PROTÉINE — si le libellé cite une protéine absente de la recette, elle est
 *     ajoutée (ex. « Ajout Poulet grillé +120g »). S'il contient « Remplacer »,
 *     les protéines de la recette non citées sont retirées au profit des citées
 *     (ex. option végétarienne). Sinon, les grammes supplémentaires sont ajoutés
 *     à la protéine citée.
 *  3. LÉGUMES — les grammes supplémentaires vont aux ingrédients cités, répartis
 *     à parts égales, ou aux légumes par défaut.
 *  4. BASE — le féculent de la recette est remplacé selon le libellé choisi ;
 *     une base « 100 % légumes / sans féculent » reporte son poids sur les légumes.
 *  5. SUPPLÉMENTS — ajoutés en dernier, jamais retirés par une substitution.
 *
 * @param array $recipe              Lignes de recette [legacy, name, qty, unit].
 * @param array|null $protein        Option protéine résolue.
 * @param array|null $veggies        Option légumes résolue.
 * @param array|null $base           Option base résolue.
 * @param array $supplements         Suppléments résolus.
 * @param array $index               Index des ingrédients.
 * @return array legacy_id => ['name','qty','unit']
 */
function bebba_oc_compute_consumption($recipe, $protein, $veggies, $base, $supplements, $index) {
    $cons     = array();
    $sets     = bebba_oc_ingredient_sets();
    $keywords = bebba_oc_keyword_map();

    /* --- 1. Recette de base --- */
    foreach ($recipe as $line) {
        $legacy = $line['legacy'];
        if (!isset($cons[$legacy])) {
            $cons[$legacy] = array(
                'name' => $line['name'],
                'qty'  => 0.0,
                'unit' => $line['unit'],
            );
        }
        $cons[$legacy]['qty'] += (float) $line['qty'];
    }

    /* --- 2. Protéine --- */
    if (!empty($protein)) {
        $label   = (string) $protein['label'];
        $grammes = (float) $protein['extra_grams'];
        $cibles  = bebba_oc_keywords_in_label(
            $label,
            $keywords,
            array('ing-poulet', 'ing-boeuf', 'ing-dinde', 'ing-saumon', 'ing-halloumi', 'ing-oeuf')
        );

        $substitution = (mb_stripos($label, 'remplacer', 0, 'UTF-8') !== false);

        if ($substitution && !empty($cibles)) {
            $retires = 0.0;
            foreach (array_keys($cons) as $legacy) {
                if (in_array($legacy, $sets['protein'], true) && !in_array($legacy, $cibles, true)) {
                    $retires += (float) $cons[$legacy]['qty'];
                    unset($cons[$legacy]);
                }
            }
            foreach ($cibles as $legacy) {
                if (isset($cons[$legacy])) {
                    continue; /* déjà dans la recette : rien à faire */
                }
                list($nom, $unite) = bebba_oc_ing_meta($index, $legacy);
                $qte = ($unite === 'piece') ? 1.0 : ($retires > 0 ? $retires : 100.0);
                $cons[$legacy] = array('name' => $nom, 'qty' => $qte, 'unit' => $unite);
            }
        } elseif ($grammes > 0) {
            if (empty($cibles)) {
                $prot_recette = array();
                foreach (array_keys($cons) as $legacy) {
                    if (in_array($legacy, $sets['protein'], true)) {
                        $prot_recette[] = $legacy;
                    }
                }
                if (count($prot_recette) === 1) {
                    $cibles = $prot_recette;
                }
            }
            foreach ($cibles as $legacy) {
                if (isset($cons[$legacy])) {
                    $cons[$legacy]['qty'] += $grammes;
                } else {
                    list($nom, $unite) = bebba_oc_ing_meta($index, $legacy);
                    $cons[$legacy] = array('name' => $nom, 'qty' => $grammes, 'unit' => $unite);
                }
            }
        }
    }

    /* --- 3. Légumes --- */
    if (!empty($veggies)) {
        $grammes = (float) $veggies['extra_grams'];
        if ($grammes > 0) {
            $cibles = bebba_oc_keywords_in_label($veggies['label'], $keywords, array('ing-legumes', 'ing-avocat'));
            if (empty($cibles)) {
                $cibles = array('ing-legumes');
            }
            $part = $grammes / count($cibles);
            foreach ($cibles as $legacy) {
                if (isset($cons[$legacy])) {
                    $cons[$legacy]['qty'] += $part;
                } else {
                    list($nom, $unite) = bebba_oc_ing_meta($index, $legacy);
                    $cons[$legacy] = array('name' => $nom, 'qty' => $part, 'unit' => $unite);
                }
            }
        }
    }

    /* --- 4. Base / féculent --- */
    if (!empty($base)) {
        $label  = (string) $base['label'];
        $feucle = null;
        foreach (array_keys($cons) as $legacy) {
            if (in_array($legacy, $sets['starch'], true)) {
                $feucle = $legacy;
                break;
            }
        }
        if ($feucle !== null) {
            $poids   = (float) $cons[$feucle]['qty'];
            $cible   = null;
            $vers_legumes = false;

            if (mb_stripos($label, 'quinoa', 0, 'UTF-8') !== false) {
                $cible = 'ing-quinoa';
            } elseif (mb_stripos($label, 'patate', 0, 'UTF-8') !== false) {
                $cible = 'ing-patate-douce';
            } elseif (mb_stripos($label, 'riz', 0, 'UTF-8') !== false) {
                $cible = 'ing-riz';
            } elseif (
                mb_stripos($label, '100%', 0, 'UTF-8') !== false ||
                mb_stripos($label, 'sans féculent', 0, 'UTF-8') !== false ||
                mb_stripos($label, 'sans feculent', 0, 'UTF-8') !== false
            ) {
                $vers_legumes = true;
            }

            if ($cible === $feucle) {
                $cible = null; /* même féculent : aucun changement */
            }

            if ($cible !== null) {
                unset($cons[$feucle]);
                if (isset($cons[$cible])) {
                    $cons[$cible]['qty'] += $poids;
                } else {
                    list($nom, $unite) = bebba_oc_ing_meta($index, $cible);
                    $cons[$cible] = array('name' => $nom, 'qty' => $poids, 'unit' => $unite);
                }
            } elseif ($vers_legumes) {
                unset($cons[$feucle]);
                if (isset($cons['ing-legumes'])) {
                    $cons['ing-legumes']['qty'] += $poids;
                } else {
                    list($nom, $unite) = bebba_oc_ing_meta($index, 'ing-legumes');
                    $cons['ing-legumes'] = array('name' => $nom, 'qty' => $poids, 'unit' => $unite);
                }
            }
        }
    }

    /* --- 5. Suppléments --- */
    foreach ($supplements as $sup) {
        $legacy = $sup['ingredient_legacy_id'];
        if ($legacy === null || $legacy === '') {
            continue;
        }
        $qte   = (float) $sup['quantity_consumed'] * (int) $sup['quantity'];
        $nom   = $sup['ingredient_name'] !== null && $sup['ingredient_name'] !== ''
            ? $sup['ingredient_name']
            : $sup['name'];
        $unite = $sup['unit'] !== null && $sup['unit'] !== '' ? $sup['unit'] : 'g';

        if (isset($cons[$legacy])) {
            $cons[$legacy]['qty'] += $qte;
        } else {
            $cons[$legacy] = array('name' => $nom, 'qty' => $qte, 'unit' => $unite);
        }
    }

    /* Arrondi final des quantités */
    foreach ($cons as $legacy => $line) {
        $cons[$legacy]['qty'] = round($line['qty'], 2);
    }

    return $cons;
}

/* ============================================================================
 * 4. CHARGEMENT D'UN PRODUIT POUR LA COMMANDE
 * ========================================================================== */

/**
 * Charge un produit avec ses options, ses suppléments autorisés et sa recette.
 *
 * @param int $product_id Identifiant du produit.
 * @return object|null
 */
function bebba_oc_load_product($product_id) {
    global $wpdb;

    $p = $wpdb->get_row($wpdb->prepare(
        "SELECT id, legacy_id, name, base_price, active, is_available
         FROM bebba_products WHERE id = %d",
        $product_id
    ));
    if (!$p) {
        return null;
    }

    $p->options = $wpdb->get_results($wpdb->prepare(
        "SELECT id, option_type, position, label, extra_price, extra_grams, is_default
         FROM bebba_product_options
         WHERE product_id = %d
         ORDER BY option_type ASC, sort_order ASC, position ASC",
        $product_id
    ));

    $p->supplements = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id, s.legacy_id, s.name, s.price, s.ingredient_id, s.ingredient_legacy_id,
                s.ingredient_name_snapshot, s.quantity_consumed, s.unit, s.available, s.active
         FROM bebba_product_supplements ps
         JOIN bebba_supplements s ON s.id = ps.supplement_id
         WHERE ps.product_id = %d
         ORDER BY ps.sort_order ASC",
        $product_id
    ));

    $p->recipe = $wpdb->get_results($wpdb->prepare(
        "SELECT pi.ingredient_id, pi.ingredient_legacy_id, pi.ingredient_name_snapshot,
                pi.quantity, pi.unit, i.active AS ingredient_active, i.name AS ingredient_name
         FROM bebba_product_ingredients pi
         LEFT JOIN bebba_ingredients i ON i.id = pi.ingredient_id
         WHERE pi.product_id = %d
         ORDER BY pi.position ASC",
        $product_id
    ));

    return $p;
}

/* ============================================================================
 * 5. EMPREINTE DÉTERMINISTE DE LA DEMANDE (IDEMPOTENCE)
 * ========================================================================== */

/**
 * Calcule l'empreinte SHA-256 canonique d'une demande de commande.
 * Deux demandes identiques produisent la même empreinte ; un changement de
 * contenu (quantité, option, adresse) la fait changer.
 *
 * @param array $payload Données normalisées.
 * @return string 64 caractères hexadécimaux.
 */
function bebba_oc_canonical_hash($payload) {
    $lignes = array();
    foreach ($payload['items'] as $item) {
        $sups = $item['supplements'];
        ksort($sups); /* ordre stable quel que soit l'ordre d'envoi */
        $lignes[] = array(
            'product_id' => (int) $item['product_id'],
            'quantity'   => (int) $item['quantity'],
            'protein'    => (string) $item['protein_label'],
            'veggies'    => (string) $item['veggies_label'],
            'base'       => (string) $item['base_label'],
            'supplements'=> $sups,
            'note'       => (string) $item['special_instructions'],
        );
    }
    usort($lignes, function ($a, $b) {
        return strcmp(wp_json_encode($a), wp_json_encode($b));
    });

    $canonique = wp_json_encode(array(
        'caller'  => $payload['caller_id'],
        'client'  => array(
            'name'    => $payload['customer_name'],
            'phone'   => $payload['customer_phone_normalized'],
            'address' => $payload['delivery_address'],
            'notes'   => (string) $payload['customer_notes'],
        ),
        'items'   => $lignes,
    ));

    return hash('sha256', $canonique);
}

/* ============================================================================
 * 6. CRÉATION DE LA COMMANDE — TRANSACTION ATOMIQUE
 * ========================================================================== */

/**
 * Handler REST POST /wp-json/bebba/v1/orders
 *
 * @param WP_REST_Request $request Requête.
 * @return WP_REST_Response|WP_Error
 */
function bebba_rest_create_order($request) {
    global $wpdb;

    /* ---------- 6.1 Lecture et validation du corps ---------- */
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return new WP_Error('bebba_invalid_body', 'Corps de requête JSON invalide.', array('status' => 400));
    }

    $client = isset($body['client']) && is_array($body['client']) ? $body['client'] : array();
    $name   = isset($client['name']) ? trim((string) $client['name']) : '';
    $phone  = isset($client['phone']) ? trim((string) $client['phone']) : '';
    $addr   = isset($client['deliveryAddress']) ? trim((string) $client['deliveryAddress']) : '';
    $notes  = isset($client['notes']) ? trim((string) $client['notes']) : '';

    if ($name === '' || $phone === '' || $addr === '') {
        return new WP_Error(
            'bebba_missing_customer',
            'Nom, téléphone et adresse de livraison sont obligatoires.',
            array('status' => 400)
        );
    }
    if (mb_strlen($name, 'UTF-8') > 128 || mb_strlen($addr, 'UTF-8') > 512) {
        return new WP_Error('bebba_customer_too_long', 'Nom ou adresse trop long.', array('status' => 400));
    }
    $phone_normalise = bebba_normalize_phone($phone);
    if ($phone_normalise === '' || strlen($phone_normalise) < 6) {
        return new WP_Error('bebba_invalid_phone', 'Numéro de téléphone invalide.', array('status' => 400));
    }

    $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
    if (empty($items)) {
        return new WP_Error('bebba_empty_cart', 'Le panier est vide.', array('status' => 400));
    }
    if (count($items) > 50) {
        return new WP_Error('bebba_too_many_items', 'Trop de lignes dans la commande (maximum 50).', array('status' => 400));
    }

    /* ---------- 6.2 Identité — ANTI-IDOR ---------- */
    /* Le corps de la requête ne peut jamais désigner le client : seule la session
       WordPress authentifiée fait foi. Aucun champ clientId n'est lu. */
    $wp_customer_id = 0;
    $caller_id      = 'guest:' . $phone_normalise;
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        if ($u && in_array('bebba_client', (array) $u->roles, true)) {
            $wp_customer_id = (int) $u->ID;
            $caller_id      = 'client:' . $wp_customer_id;
        }
    }

    /* ---------- 6.3 Préparation des lignes ---------- */
    $index    = bebba_oc_ingredient_index();
    $lignes   = array();
    $produits = array();

    foreach ($items as $position => $raw) {
        if (!is_array($raw) || !isset($raw['productId'])) {
            return new WP_Error('bebba_invalid_item', sprintf('Ligne %d invalide.', $position + 1), array('status' => 400));
        }
        $pid = (int) $raw['productId'];
        $qte = isset($raw['quantity']) ? $raw['quantity'] : 1;
        if (!is_numeric($qte) || (int) $qte != $qte || (int) $qte < 1 || (int) $qte > 100) {
            return new WP_Error(
                'bebba_invalid_quantity',
                'La quantité doit être un nombre entier compris entre 1 et 100.',
                array('status' => 400)
            );
        }
        $qte = (int) $qte;

        $produit = bebba_oc_load_product($pid);
        if ($produit === null) {
            return new WP_Error('bebba_unknown_product', sprintf('Produit #%d introuvable.', $pid), array('status' => 400));
        }
        if ((int) $produit->active !== 1) {
            return new WP_Error(
                'bebba_product_inactive',
                sprintf('Le plat « %s » n\'est plus au catalogue.', $produit->name),
                array('status' => 400)
            );
        }
        if ((int) $produit->is_available !== 1) {
            return new WP_Error(
                'bebba_product_unavailable',
                sprintf('Le plat « %s » est momentanément indisponible.', $produit->name),
                array('status' => 400)
            );
        }

        /* Résolution des options — le serveur ignore tout prix envoyé par le client */
        $opt_protein = bebba_oc_resolve_option($produit->options, 'protein', isset($raw['proteinOption']) ? $raw['proteinOption'] : null);
        if (is_wp_error($opt_protein)) {
            return $opt_protein;
        }
        $opt_veggies = bebba_oc_resolve_option($produit->options, 'veggies', isset($raw['veggiesOption']) ? $raw['veggiesOption'] : null);
        if (is_wp_error($opt_veggies)) {
            return $opt_veggies;
        }
        $opt_base = bebba_oc_resolve_option($produit->options, 'base', isset($raw['baseChoice']) ? $raw['baseChoice'] : null);
        if (is_wp_error($opt_base)) {
            return $opt_base;
        }

        /* Suppléments — accepte {id: quantité} ou [{id, quantity}] */
        $sup_dispo = array();
        foreach ($produit->supplements as $s) {
            $sup_dispo[(int) $s->id] = $s;
        }
        $sup_demande = array();
        $sup_brut = isset($raw['supplements']) ? $raw['supplements'] : array();
        if (is_array($sup_brut)) {
            foreach ($sup_brut as $cle => $valeur) {
                if (is_array($valeur)) {
                    $sid = isset($valeur['id']) ? (int) $valeur['id'] : 0;
                    $sq  = isset($valeur['quantity']) ? (int) $valeur['quantity'] : 1;
                } else {
                    $sid = (int) $cle;
                    $sq  = (int) $valeur;
                }
                if ($sid > 0 && $sq > 0) {
                    $sup_demande[$sid] = isset($sup_demande[$sid]) ? $sup_demande[$sid] + $sq : $sq;
                }
            }
        }

        $sup_resolus = array();
        foreach ($sup_demande as $sid => $sq) {
            if ($sq > 100) {
                return new WP_Error('bebba_invalid_supplement', 'Quantité de supplément trop élevée.', array('status' => 400));
            }
            if (!isset($sup_dispo[$sid])) {
                return new WP_Error(
                    'bebba_invalid_supplement',
                    sprintf('Le supplément #%d n\'est pas proposé avec le plat « %s ».', $sid, $produit->name),
                    array('status' => 400)
                );
            }
            $s = $sup_dispo[$sid];
            if ((int) $s->active !== 1 || (int) $s->available !== 1) {
                return new WP_Error(
                    'bebba_supplement_unavailable',
                    sprintf('Le supplément « %s » n\'est plus disponible.', $s->name),
                    array('status' => 400)
                );
            }
            $sup_resolus[] = array(
                'id'                    => (int) $s->id,
                'legacy_id'             => $s->legacy_id,
                'name'                  => $s->name,
                'price'                 => round((float) $s->price, 2),
                'quantity'              => $sq,
                'ingredient_id'         => $s->ingredient_id !== null ? (int) $s->ingredient_id : null,
                'ingredient_legacy_id'  => $s->ingredient_legacy_id,
                'ingredient_name'       => $s->ingredient_name_snapshot,
                'quantity_consumed'     => round((float) $s->quantity_consumed, 2),
                'unit'                  => $s->unit !== null ? $s->unit : 'g',
            );
        }

        /* Recette normalisée */
        $recette = array();
        foreach ($produit->recipe as $r) {
            if ($r->ingredient_legacy_id === null || $r->ingredient_legacy_id === '') {
                continue;
            }
            if ($r->ingredient_active !== null && (int) $r->ingredient_active === 0) {
                return new WP_Error(
                    'bebba_ingredient_inactive',
                    sprintf(
                        'Le plat « %s » ne peut pas être commandé : l\'ingrédient « %s » est désactivé.',
                        $produit->name,
                        $r->ingredient_name !== null ? $r->ingredient_name : $r->ingredient_name_snapshot
                    ),
                    array('status' => 400)
                );
            }
            $recette[] = array(
                'legacy' => $r->ingredient_legacy_id,
                'name'   => $r->ingredient_name_snapshot,
                'qty'    => (float) $r->quantity,
                'unit'   => $r->unit !== null ? $r->unit : 'g',
            );
        }
        if (empty($recette)) {
            return new WP_Error(
                'bebba_product_without_recipe',
                sprintf('Le plat « %s » n\'a pas de fiche technique.', $produit->name),
                array('status' => 400)
            );
        }

        /* Prix unitaire — recalculé intégralement côté serveur */
        $prix = round((float) $produit->base_price, 2);
        if ($opt_protein) { $prix += $opt_protein['extra_price']; }
        if ($opt_veggies) { $prix += $opt_veggies['extra_price']; }
        if ($opt_base)    { $prix += $opt_base['extra_price']; }
        foreach ($sup_resolus as $s) {
            $prix += $s['price'] * $s['quantity'];
        }
        $prix = round($prix, 2);

        $consommation = bebba_oc_compute_consumption($recette, $opt_protein, $opt_veggies, $opt_base, $sup_resolus, $index);

        $lignes[] = array(
            'produit'      => $produit,
            'quantity'     => $qte,
            'unit_price'   => $prix,
            'item_total'   => round($prix * $qte, 2),
            'protein'      => $opt_protein,
            'veggies'      => $opt_veggies,
            'base'         => $opt_base,
            'supplements'  => $sup_resolus,
            'consommation' => $consommation,
            'note'         => isset($raw['specialInstructions']) ? trim((string) $raw['specialInstructions']) : '',
        );

        /* Index du produit mis en cache pour éviter un rechargement à l'insertion */
        $produits[$pid] = $produit;
    }

    /* ---------- 6.4 Idempotence ---------- */
    $idem_key = $request->get_header('Idempotency-Key');
    $idem_key = $idem_key !== null ? trim($idem_key) : '';
    if (strlen($idem_key) > 128) {
        return new WP_Error('bebba_invalid_idempotency_key', 'Clé d\'idempotence trop longue.', array('status' => 400));
    }

    $payload_pour_hash = array(
        'caller_id'                 => $caller_id,
        'customer_name'             => $name,
        'customer_phone_normalized' => $phone_normalise,
        'delivery_address'          => $addr,
        'customer_notes'            => $notes,
        'items'                     => array(),
    );
    foreach ($lignes as $l) {
        $sups_map = array();
        foreach ($l['supplements'] as $s) {
            $sups_map[(string) $s['id']] = (int) $s['quantity'];
        }
        $payload_pour_hash['items'][] = array(
            'product_id'           => (int) $l['produit']->id,
            'quantity'             => (int) $l['quantity'],
            'protein_label'        => $l['protein'] ? $l['protein']['label'] : '',
            'veggies_label'        => $l['veggies'] ? $l['veggies']['label'] : '',
            'base_label'           => $l['base'] ? $l['base']['label'] : '',
            'supplements'          => $sups_map,
            'special_instructions' => $l['note'],
        );
    }
    $request_hash = bebba_oc_canonical_hash($payload_pour_hash);

    if ($idem_key !== '') {
        $existante = $wpdb->get_row($wpdb->prepare(
            "SELECT id, caller_id, request_hash, order_id
             FROM bebba_order_idempotency WHERE idempotency_key = %s",
            $idem_key
        ));
        if ($existante) {
            if ($existante->caller_id !== $caller_id) {
                return new WP_Error(
                    'bebba_idempotency_forbidden',
                    'Cette clé d\'idempotence appartient à un autre appelant.',
                    array('status' => 403)
                );
            }
            if ($existante->request_hash !== $request_hash) {
                return new WP_Error(
                    'bebba_idempotency_conflict',
                    'Cette clé d\'idempotence a déjà servi pour une commande au contenu différent.',
                    array('status' => 422)
                );
            }
            $ordre = $wpdb->get_row($wpdb->prepare(
                "SELECT id, order_number, tracking_token, status, subtotal, delivery_fee, total_amount, placed_at
                 FROM bebba_orders WHERE id = %d",
                (int) $existante->order_id
            ));
            if ($ordre) {
                return new WP_REST_Response(bebba_oc_order_response($ordre, true), 200);
            }
        }
    }

    /* ---------- 6.5 Déduction du stock nécessaire (mémoire, aucune écriture) ---------- */
    $besoins = array(); /* legacy_id => quantité totale requise */
    foreach ($lignes as $l) {
        foreach ($l['consommation'] as $legacy => $c) {
            $total = round($c['qty'] * $l['quantity'], 2);
            if (!isset($besoins[$legacy])) {
                $besoins[$legacy] = 0.0;
            }
            $besoins[$legacy] = round($besoins[$legacy] + $total, 2);
        }
    }

    foreach (array_keys($besoins) as $legacy) {
        if (!isset($index[$legacy])) {
            return new WP_Error(
                'bebba_unknown_ingredient',
                sprintf('Ingrédient « %s » introuvable dans le stock.', $legacy),
                array('status' => 400)
            );
        }
        if ((int) $index[$legacy]['active'] === 0) {
            return new WP_Error(
                'bebba_ingredient_inactive',
                sprintf('L\'ingrédient « %s » est désactivé.', $index[$legacy]['name']),
                array('status' => 400)
            );
        }
    }

    /* ---------- 6.6 TRANSACTION ---------- */
    $suppress = $wpdb->suppress_errors(true);
    $wpdb->query('START TRANSACTION');

    try {
        /* a) Compteur verrouillé — contient le prochain numéro à attribuer */
        $seq = $wpdb->get_var(
            "SELECT current_value FROM bebba_counters WHERE counter_name = 'order_sequence' FOR UPDATE"
        );
        if ($seq === null) {
            throw new Exception('Compteur de commande introuvable.');
        }
        /* bebba_counters.order_sequence contient LE PROCHAIN numéro à attribuer. */
        $numero = (int) $seq;

        /* b) Ingrédients verrouillés et contrôlés */
        $legacy_list  = array_keys($besoins);
        $placeholders = implode(',', array_fill(0, count($legacy_list), '%s'));
        $lignes_stock = $wpdb->get_results($wpdb->prepare(
            "SELECT id, legacy_id, name, unit, stock_quantity
             FROM bebba_ingredients
             WHERE legacy_id IN ($placeholders)
             FOR UPDATE",
            $legacy_list
        ));
        if (!$lignes_stock || count($lignes_stock) !== count($legacy_list)) {
            throw new Exception('Verrouillage du stock incomplet.');
        }

        $stock_par_legacy = array();
        foreach ($lignes_stock as $ls) {
            $stock_par_legacy[$ls->legacy_id] = $ls;
        }

        $manquants = array();
        foreach ($besoins as $legacy => $requis) {
            $dispo = round((float) $stock_par_legacy[$legacy]->stock_quantity, 2);
            if ($dispo < $requis) {
                $manquants[] = array(
                    'ingredient' => $stock_par_legacy[$legacy]->name,
                    'required'   => $requis,
                    'available'  => $dispo,
                    'missing'    => round($requis - $dispo, 2),
                    'unit'       => $stock_par_legacy[$legacy]->unit,
                );
            }
        }
        if (!empty($manquants)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'bebba_insufficient_stock',
                'Stock insuffisant pour préparer cette commande.',
                array('status' => 409, 'details' => $manquants)
            );
        }

        /* c) Calcul des montants */
        $sous_total = 0.0;
        foreach ($lignes as $l) {
            $sous_total = round($sous_total + $l['item_total'], 2);
        }
        $frais_livraison = round((float) get_option('bebba_delivery_fee', 2.50), 2);
        $montant_total   = round($sous_total + $frais_livraison, 2);

        $maintenant = current_time('mysql');
        $horodatage = current_time('mysql') . '.000';
        $jeton      = 'tk_' . bin2hex(random_bytes(6));
        $legacy_ord = 'ord-' . round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3));
        $num_commande = 'BEBBA-' . $numero;

        $acteur = $wp_customer_id > 0
            ? 'Client BEBBA (#' . $wp_customer_id . ')'
            : 'Système Client';

        /* d) Commande */
        $ok = $wpdb->insert('bebba_orders', array(
            'legacy_id'            => $legacy_ord,
            'order_number'         => $num_commande,
            'tracking_token'       => $jeton,
            'placed_at'            => $maintenant,
            'customer_name'        => $name,
            'customer_phone'       => $phone,
            'delivery_address'     => $addr,
            'customer_notes'       => $notes === '' ? null : $notes,
            'wp_customer_id'       => $wp_customer_id > 0 ? $wp_customer_id : null,
            'subtotal'             => $sous_total,
            'delivery_fee'         => $frais_livraison,
            'total_amount'         => $montant_total,
            'status'               => 'received',
            'payment_status'       => 'to_collect',
            'payment_method'       => 'cash_on_delivery',
            'driver_id'            => null,
            'driver_legacy_id'     => null,
            'driver_name_snapshot' => null,
            'stock_consumed'       => 1,
            'idempotency_key'      => $idem_key === '' ? null : $idem_key,
        ), array(
            '%s','%s','%s','%s','%s','%s','%s','%s','%d','%f','%f','%f',
            '%s','%s','%s','%d','%s','%s','%d','%s',
        ));
        if ($ok === false) {
            throw new Exception('Insertion de la commande impossible : ' . $wpdb->last_error);
        }
        $order_id = (int) $wpdb->insert_id;

        /* e) Lignes, fiches de préparation, suppléments */
        foreach ($lignes as $l) {
            $p = $l['produit'];

            $lignes_recap = array();
            foreach ($l['consommation'] as $c) {
                $lignes_recap[] = sprintf('%s : %s %s', $c['name'], rtrim(rtrim(number_format($c['qty'], 2, '.', ''), '0'), '.'), $c['unit']);
            }
            if ($l['note'] !== '') {
                $lignes_recap[] = 'NOTE CLIENT : « ' . $l['note'] . ' »';
            }

            $ok = $wpdb->insert('bebba_order_items', array(
                'order_id'                    => $order_id,
                'legacy_id'                   => 'item-' . bin2hex(random_bytes(4)),
                'product_id'                  => (int) $p->id,
                'product_legacy_id'           => $p->legacy_id,
                'product_name_snapshot'       => $p->name,
                'unit_price'                  => $l['unit_price'],
                'quantity'                    => (int) $l['quantity'],
                'protein_option_label'        => $l['protein'] ? $l['protein']['label'] : null,
                'protein_option_extra_price'  => $l['protein'] ? $l['protein']['extra_price'] : null,
                'protein_option_extra_grams'  => $l['protein'] ? $l['protein']['extra_grams'] : null,
                'veggies_option_label'        => $l['veggies'] ? $l['veggies']['label'] : null,
                'veggies_option_extra_price'  => $l['veggies'] ? $l['veggies']['extra_price'] : null,
                'veggies_option_extra_grams'  => $l['veggies'] ? $l['veggies']['extra_grams'] : null,
                'base_choice_label'           => $l['base'] ? $l['base']['label'] : null,
                'base_choice_extra_price'     => $l['base'] ? $l['base']['extra_price'] : null,
                'options_raw_json'            => wp_json_encode(array(
                    'proteinOption' => $l['protein'],
                    'veggiesOption' => $l['veggies'],
                    'baseChoice'    => $l['base'],
                )),
                'special_instructions'        => $l['note'] === '' ? null : $l['note'],
                'item_total_price'            => $l['item_total'],
                'summary_lines_json'          => wp_json_encode($lignes_recap),
            ));
            if ($ok === false) {
                throw new Exception('Insertion d\'une ligne de commande impossible : ' . $wpdb->last_error);
            }
            $item_id = (int) $wpdb->insert_id;

            foreach ($l['consommation'] as $legacy => $c) {
                $total_ligne = round($c['qty'] * $l['quantity'], 2);
                $ok = $wpdb->insert('bebba_order_item_prep', array(
                    'order_item_id'          => $item_id,
                    'ingredient_id'          => isset($index[$legacy]) ? $index[$legacy]['id'] : null,
                    'ingredient_legacy_id'   => $legacy,
                    'ingredient_name_snapshot'=> $c['name'],
                    'total_quantity'         => $total_ligne,
                    'unit'                   => $c['unit'],
                ));
                if ($ok === false) {
                    throw new Exception('Insertion d\'une ligne de préparation impossible : ' . $wpdb->last_error);
                }
            }

            foreach ($l['supplements'] as $s) {
                $ok = $wpdb->insert('bebba_order_item_supplements', array(
                    'order_item_id'           => $item_id,
                    'supplement_id'           => $s['id'],
                    'supplement_legacy_id'    => $s['legacy_id'],
                    'supplement_name_snapshot'=> $s['name'],
                    'price'                   => $s['price'],
                    'quantity'                => (int) $s['quantity'],
                    'ingredient_id'           => $s['ingredient_id'],
                    'ingredient_legacy_id'    => $s['ingredient_legacy_id'],
                    'ingredient_name_snapshot'=> $s['ingredient_name'],
                    'quantity_consumed'       => round($s['quantity_consumed'] * $s['quantity'], 2),
                    'unit'                    => $s['unit'],
                ));
                if ($ok === false) {
                    throw new Exception('Insertion d\'un supplément impossible : ' . $wpdb->last_error);
                }
            }
        }

        /* f) Historique de statut */
        $ok = $wpdb->insert('bebba_order_status_history', array(
            'order_id'   => $order_id,
            'position'   => 0,
            'status'     => 'received',
            'label'      => 'Commande reçue & transmise à la cuisine',
            'timestamp'  => $horodatage,
            'note'       => 'Paiement à la livraison sélectionné',
            'updated_by' => $acteur,
        ), array('%d','%d','%s','%s','%s','%s','%s'));
        if ($ok === false) {
            throw new Exception('Insertion de l\'historique impossible : ' . $wpdb->last_error);
        }

        /* g) Décrément du stock + mouvements */
        foreach ($besoins as $legacy => $requis) {
            $ing = bebba_oc_stock_lookup($lignes_stock, $legacy);
            if ($ing === null) {
                throw new Exception('Ingrédient introuvable au moment du décrement : ' . $legacy);
            }
            $nouveau = round((float) $ing->stock_quantity - $requis, 2);
            $ok = $wpdb->update(
                'bebba_ingredients',
                array('stock_quantity' => $nouveau),
                array('id' => (int) $ing->id),
                array('%f'),
                array('%d')
            );
            if ($ok === false) {
                throw new Exception('Mise à jour du stock impossible : ' . $wpdb->last_error);
            }

            $ok = $wpdb->insert('bebba_stock_movements', array(
                'legacy_id'               => 'mov-' . round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3)),
                'ingredient_id'           => (int) $ing->id,
                'ingredient_legacy_id'    => $legacy,
                'ingredient_name_snapshot'=> $ing->name,
                'movement_type'           => 'order_consumption',
                'quantity'                => -1 * $requis,
                'unit'                    => $ing->unit,
                'order_id'                => $order_id,
                'order_legacy_id'         => $legacy_ord,
                'order_number_snapshot'   => $num_commande,
                'notes'                   => 'Consommation automatique commande #' . $num_commande,
                'performed_by'            => $acteur,
                'timestamp'               => $maintenant,
            ), array('%s','%d','%s','%s','%s','%f','%s','%d','%s','%s','%s','%s','%s'));
            if ($ok === false) {
                throw new Exception('Insertion d\'un mouvement de stock impossible : ' . $wpdb->last_error);
            }
        }

        /* h) Compteur incrémenté */
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE bebba_counters SET current_value = %d WHERE counter_name = 'order_sequence'",
            $numero + 1
        ));
        if ($ok === false) {
            throw new Exception('Mise à jour du compteur impossible.');
        }

        /* i) Référence d'idempotence */
        if ($idem_key !== '') {
            $ok = $wpdb->insert('bebba_order_idempotency', array(
                'idempotency_key' => $idem_key,
                'caller_id'       => $caller_id,
                'request_hash'    => $request_hash,
                'order_id'        => $order_id,
                'order_legacy_id' => $legacy_ord,
            ), array('%s','%s','%s','%d','%s'));
            if ($ok === false) {
                throw new Exception('Enregistrement de la clé d\'idempotence impossible : ' . $wpdb->last_error);
            }
        }

        $wpdb->query('COMMIT');
        $wpdb->suppress_errors($suppress);

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        $wpdb->suppress_errors($suppress);
        error_log('[BEBBA] Création de commande échouée : ' . $e->getMessage());
        return new WP_Error(
            'bebba_order_creation_failed',
            'La commande n\'a pas pu être enregistrée. Aucun montant n\'a été débité et le stock n\'a pas été modifié.',
            array('status' => 500)
        );
    }

    $ordre = $wpdb->get_row($wpdb->prepare(
        "SELECT id, order_number, tracking_token, status, subtotal, delivery_fee, total_amount, placed_at
         FROM bebba_orders WHERE id = %d",
        $order_id
    ));

    return new WP_REST_Response(bebba_oc_order_response($ordre, false), 201);
}

/**
 * Retrouve une ligne verrouillée par identifiant legacy.
 *
 * @param array  $lignes_stock Résultat du SELECT ... FOR UPDATE.
 * @param string $legacy       Identifiant legacy.
 * @return object|null
 */
function bebba_oc_stock_lookup($lignes_stock, $legacy) {
    foreach ($lignes_stock as $l) {
        if ($l->legacy_id === $legacy) {
            return $l;
        }
    }
    return null;
}

/**
 * Construit la réponse renvoyée au navigateur.
 *
 * @param object $ordre      Ligne de bebba_orders.
 * @param bool   $est_existante Vrai si la commande existait déjà (idempotence).
 * @return array
 */
function bebba_oc_order_response($ordre, $est_existante) {
    return array(
        'order_id'       => (int) $ordre->id,
        'order_number'   => $ordre->order_number,
        'tracking_token' => $ordre->tracking_token,
        'status'         => $ordre->status,
        'subtotal'       => (float) $ordre->subtotal,
        'delivery_fee'   => (float) $ordre->delivery_fee,
        'total_amount'   => (float) $ordre->total_amount,
        'placed_at'      => $ordre->placed_at,
        'is_existing'    => (bool) $est_existante,
    );
}

/* ============================================================================
 * 7. ENREGISTREMENT DE LA ROUTE REST
 * ========================================================================== */

/**
 * Déclare POST /wp-json/bebba/v1/orders
 */
function bebba_oc_register_rest_routes() {
    register_rest_route('bebba/v1', '/orders', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'bebba_rest_create_order',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'bebba_oc_register_rest_routes');

/* ============================================================================
 * 8. PAGE DE COMMANDE
 * ========================================================================== */

/**
 * Chemins du plugin, déduits de l'emplacement de ce fichier.
 *
 * @return array [dossier, url]
 */
function bebba_oc_paths() {
    static $paths = null;
    if ($paths === null) {
        $principal = dirname(__DIR__) . '/bebba.php';
        $paths = array(
            'dir' => plugin_dir_path($principal),
            'url' => plugin_dir_url($principal),
        );
    }
    return $paths;
}

/**
 * Charge les styles et le script de la page de commande.
 */
function bebba_oc_enqueue_checkout_assets() {
    $chemins = bebba_oc_paths();
    $version = '1.2.0';

    wp_enqueue_style('bebba-menu', $chemins['url'] . 'assets/css/menu.css', array(), $version);
    wp_enqueue_style('bebba-checkout', $chemins['url'] . 'assets/css/checkout.css', array('bebba-menu'), $version);
    wp_enqueue_script('bebba-checkout', $chemins['url'] . 'assets/js/checkout.js', array(), $version, true);

    $nom   = '';
    $tel   = '';
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        if ($u && in_array('bebba_client', (array) $u->roles, true)) {
            $nom = trim($u->first_name . ' ' . $u->last_name);
            if ($nom === '') {
                $nom = $u->display_name;
            }
            $tel = (string) get_user_meta($u->ID, 'bebba_phone', true);
        }
    }

    wp_localize_script('bebba-checkout', 'BEBBA_CHECKOUT', array(
        'orders_url'     => esc_url_raw(rest_url('bebba/v1/orders')),
        'nonce'          => wp_create_nonce('wp_rest'),
        'currency'       => 'DT',
        'delivery_fee'   => (float) get_option('bebba_delivery_fee', 2.50),
        'menu_url'       => esc_url(home_url('/menu/')),
        'login_url'      => esc_url(home_url('/connexion-bebba/')),
        'is_logged_in'   => is_user_logged_in(),
        'customer_name'  => $nom,
        'customer_phone' => $tel,
    ));
}

/**
 * Rendu de la page de commande (shortcode [bebba_commande]).
 *
 * @return string HTML
 */
function bebba_render_checkout_page() {
    bebba_oc_enqueue_checkout_assets();

    ob_start();
    ?>
    <div id="bebba-checkout" class="bebba-checkout">

        <header class="bebba-checkout__header">
            <h1 class="bebba-checkout__title">Finaliser ma <span>commande</span></h1>
            <a class="bebba-checkout__back" href="<?php echo esc_url(home_url('/menu/')); ?>">&larr; Retour au menu</a>
        </header>

        <?php /* ---------- Panier vide ---------- */ ?>
        <div id="bebba-checkout-empty" class="bebba-checkout__empty" hidden>
            <span class="bebba-checkout__empty-icon" aria-hidden="true">🛒</span>
            <p class="bebba-checkout__empty-text">Votre panier est vide.</p>
            <a class="bebba-checkout__empty-link" href="<?php echo esc_url(home_url('/menu/')); ?>">Voir le menu</a>
        </div>

        <?php /* ---------- Formulaire ---------- */ ?>
        <div id="bebba-checkout-body" class="bebba-checkout__body">

            <section class="bebba-checkout__recap" aria-labelledby="bebba-recap-title">
                <h2 id="bebba-recap-title" class="bebba-checkout__section-title">Récapitulatif</h2>
                <div id="bebba-checkout-summary" class="bebba-checkout__summary"></div>
                <div class="bebba-checkout__totals">
                    <div class="bebba-checkout__total-row">
                        <span>Sous-total</span>
                        <span id="bebba-checkout-subtotal">0.00 DT</span>
                    </div>
                    <div class="bebba-checkout__total-row">
                        <span>Livraison</span>
                        <span id="bebba-checkout-fee">0.00 DT</span>
                    </div>
                    <div class="bebba-checkout__total-row bebba-checkout__total-row--grand">
                        <span>Total à payer</span>
                        <strong id="bebba-checkout-total">0.00 DT</strong>
                    </div>
                    <p class="bebba-checkout__payment-note">💵 Paiement à la livraison (espèces)</p>
                </div>
            </section>

            <section class="bebba-checkout__form-wrap" aria-labelledby="bebba-form-title">
                <h2 id="bebba-form-title" class="bebba-checkout__section-title">Mes coordonnées</h2>

                <form id="bebba-checkout-form" class="bebba-checkout__form" novalidate>

                    <div class="bebba-checkout__field">
                        <label for="bebba-field-name">Nom complet <span aria-hidden="true">*</span></label>
                        <input type="text" id="bebba-field-name" name="name" autocomplete="name"
                               maxlength="128" required placeholder="Ex. Sami Ben Ali">
                    </div>

                    <div class="bebba-checkout__field">
                        <label for="bebba-field-phone">Téléphone <span aria-hidden="true">*</span></label>
                        <input type="tel" id="bebba-field-phone" name="phone" autocomplete="tel"
                               maxlength="32" required placeholder="Ex. +216 20 123 456">
                    </div>

                    <div class="bebba-checkout__field">
                        <label for="bebba-field-address">Adresse de livraison <span aria-hidden="true">*</span></label>
                        <textarea id="bebba-field-address" name="address" rows="3" maxlength="512" required
                                  placeholder="Rue, immeuble, étage, ville"></textarea>
                    </div>

                    <div class="bebba-checkout__field">
                        <label for="bebba-field-notes">Note pour la cuisine <span class="bebba-checkout__optional">(facultatif)</span></label>
                        <textarea id="bebba-field-notes" name="notes" rows="2"
                                  placeholder="Allergies, code d'accès, préférences…"></textarea>
                    </div>

                    <div id="bebba-checkout-message" class="bebba-checkout__message" role="alert" hidden></div>

                    <button type="submit" id="bebba-checkout-submit" class="bebba-checkout__submit">
                        <span id="bebba-checkout-submit-label">Confirmer la commande</span>
                        <span id="bebba-checkout-submit-total" class="bebba-checkout__submit-total">0.00 DT</span>
                    </button>

                    <?php if (is_user_logged_in()) : ?>
                        <p class="bebba-checkout__identity">Commande enregistrée sur votre compte BEBBA.</p>
                    <?php else : ?>
                        <p class="bebba-checkout__identity">
                            Vous commandez en tant qu’invité.
                            <a href="<?php echo esc_url(home_url('/connexion-bebba/')); ?>">Se connecter</a>
                            pour retrouver vos commandes.
                        </p>
                    <?php endif; ?>

                </form>
            </section>

        </div>

        <?php /* ---------- Confirmation ---------- */ ?>
        <div id="bebba-checkout-success" class="bebba-checkout__success" hidden>
            <div class="bebba-checkout__success-icon" aria-hidden="true">✅</div>
            <h2 class="bebba-checkout__success-title">Commande confirmée</h2>
            <p class="bebba-checkout__success-number">
                Votre numéro de commande<br>
                <strong id="bebba-success-number">—</strong>
            </p>
            <p class="bebba-checkout__success-total">
                Montant à régler à la livraison : <strong id="bebba-success-total">0.00 DT</strong>
            </p>
            <p class="bebba-checkout__success-note">
                Conservez ce numéro. La cuisine prépare votre commande dès maintenant.
            </p>
            <a class="bebba-checkout__success-link" href="<?php echo esc_url(home_url('/menu/')); ?>">
                Commander autre chose
            </a>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
