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

