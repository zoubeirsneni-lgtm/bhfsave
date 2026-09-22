# Plugin BEBBA Healthy Food

## Structure de l'arborescence

```
wordpress/plugins/bebba/
├── bebba.php
├── README.md
└── assets/
    ├── css/
    │   └── menu.css
    └── js/
        └── menu.js
```

**⚠️ Important** : `menu.css` et `menu.js` doivent impérativement se trouver dans `assets/css/` et `assets/js/` respectivement. Le plugin les charge via :
```php
plugin_dir_url(__FILE__) . 'assets/css/menu.css'
plugin_dir_url(__FILE__) . 'assets/js/menu.js'
```

## Procédure d'installation dans WAMP

1. Copier le dossier `wordpress/plugins/bebba/` dans `wp-content/plugins/` de votre installation WAMP.
2. Activer le plugin "BEBBA Healthy Food" depuis l'admin WordPress (Extensions).
3. Le plugin crée automatiquement les pages `/menu/` et `/commande/` à l'activation.

## Correctifs appliqués

### Correctif 1 — Frais de livraison dynamiques

**Fichier** : `bebba.php`, fonction `bebba_enqueue_menu_assets`

**Avant** :
```php
'delivery_fee'  => 0,
```

**Après** :
```php
'delivery_fee'  => (float) get_option('bebba_delivery_fee', 2.50),
```

**Justification** : Les commandes en base portent 2.50 DT de frais (défaut de `bebba_orders.delivery_fee`). Le panier affichait 14.50 DT au lieu de 17.00 DT. La valeur devient en plus réglable via l'option WordPress `bebba_delivery_fee`, sans toucher au code.

---

### Correctif 2 — Suppression d'un bloc mort

**Fichier** : `bebba.php`, fonction `bebba_commande_shortcode`

**Avant** :
```html
<script>
(function(){
  if(typeof updateCartUI === 'function'){ updateCartUI(); }
})();
</script>
```

**Après** : supprimé (5 lignes)

**Justification** : `updateCartUI` est déclarée à l'intérieur de l'IIFE `(function(){ … })()` de `menu.js` : elle n'est jamais exposée sur `window`, donc `typeof updateCartUI` vaut toujours `"undefined"`. De plus, `menu.js` est chargé en pied de page (3ᵉ argument `true` de `wp_enqueue_script`), donc après ce script inline. Le bloc ne s'exécute jamais. `init()` dans `menu.js` appelle déjà `updateCartUI()` au chargement.

---

### Correctif 3 — Conversion numérique explicite

**Fichier** : `assets/js/menu.js`

**Avant** :
```javascript
var deliveryFee = typeof config.delivery_fee === 'number' ? config.delivery_fee : 0;
```

**Après** :
```javascript
/* WP localise toutes les valeurs en chaine de caracteres : conversion explicite */
var deliveryFee = Number(config.delivery_fee);
if (!isFinite(deliveryFee)) { deliveryFee = 0; }
```

**Justification** : `wp_localize_script` convertit toutes les valeurs en chaînes de caractères (`"2.5"`). Le test `typeof … === 'number'` est donc toujours faux et les frais retombaient à 0, même avec la bonne valeur côté PHP.

---

### Correctif 4 — Emplacement des assets

**Avant** : `menu.css` et `menu.js` à la racine du dépôt source.

**Après** : `assets/css/menu.css` et `assets/js/menu.js` dans l'arborescence attendue par le plugin.

**Justification** : Le plugin charge les assets via `plugin_dir_url(__FILE__) . 'assets/css/menu.css'` et `plugin_dir_url(__FILE__) . 'assets/js/menu.js'`. Sans cette structure, les assets ne seraient pas chargés.

---

## Ce qui reste à construire

- [ ] Système de checkout / paiement
- [ ] Écran cuisine (préparation des commandes)
- [ ] Espace livreur (suivi et validation)
- [ ] Suivi de commande en temps réel
- [ ] Décrémentation automatique du stock
- [ ] Encaissement
- [ ] Administration des commandes
