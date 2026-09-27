# Plugin BEBBA Healthy Food

## Structure de l'arborescence

```
wordpress/plugins/bebba/
├── bebba.php                      (initialisation, rôles, admin, authentification)
├── README.md
├── includes/
│   └── order-creation.php         (BLOC 8.1 — endpoint REST + page de commande)
└── assets/
    ├── css/
    │   ├── menu.css
    │   └── checkout.css           (BLOC 8.1)
    └── js/
        ├── menu.js
        └── checkout.js            (BLOC 8.1)
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

## BLOC 8.1 — Passage de commande

### Endpoint

```
POST /wp-json/bebba/v1/orders
```

### En-têtes

| En-tête | Rôle |
|---|---|
| `Content-Type: application/json` | obligatoire |
| `X-WP-Nonce` | jeton REST WordPress |
| `Idempotency-Key` | facultatif mais recommandé : évite les doublons en cas de double clic ou de coupure réseau |

### Corps de la requête

```json
{
  "client": {
    "name": "Sami Ben Ali",
    "phone": "+216 20 123 456",
    "deliveryAddress": "12 rue de la Paix, Sousse",
    "notes": "Sonner deux fois"
  },
  "items": [
    {
      "productId": 1,
      "quantity": 2,
      "proteinOption": { "label": "Portion sportive (+100g de poulet)" },
      "veggiesOption": { "label": "Portion normale (180g)" },
      "baseChoice": { "label": "Quinoa royal aux graines (+1.5 DT)" },
      "supplements": { "5": 1 },
      "specialInstructions": "sans sauce"
    }
  ]
}
```

### ⚠️ Autorité serveur sur les prix

**Aucun prix n'est lu depuis la requête.** Les options ne sont transmises que par leur
**libellé**. Le serveur retrouve l'option officielle en base et applique **son** prix.

Tout champ `unitPrice`, `extra_price`, `subtotal`, `totalAmount` présent dans la requête
est **ignoré**. Un client qui enverrait `"unitPrice": 0.01` sera facturé au prix catalogue.

### Réponses

| Code | Signification |
|---|---|
| `201` | Commande créée |
| `200` | Commande déjà créée avec cette clé d'idempotence et un contenu identique |
| `400` | Requête invalide (produit, option, quantité, coordonnées) |
| `403` | Clé d'idempotence appartenant à un autre appelant |
| `409` | Stock insuffisant — le détail des ingrédients manquants est renvoyé |
| `422` | Clé d'idempotence déjà utilisée avec un contenu différent |
| `500` | Échec technique — **aucune écriture n'a été conservée** |

Réponse `201` :

```json
{
  "order_id": 55,
  "order_number": "BEBBA-1006",
  "tracking_token": "tk_5c30c97abbc4",
  "status": "received",
  "subtotal": 44.00,
  "delivery_fee": 2.50,
  "total_amount": 46.50,
  "placed_at": "2026-09-23 05:59:12",
  "is_existing": false
}
```

Réponse `409` :

```json
{
  "code": "bebba_insufficient_stock",
  "message": "Stock insuffisant pour préparer cette commande.",
  "data": {
    "status": 409,
    "details": [
      { "ingredient": "Pack Repas Nutritionnel Équilibré BEBBA",
        "required": 100, "available": 60, "missing": 40, "unit": "portion" }
    ]
  }
}
```

### Principes appliqués

1. **Transaction unique** — commande, lignes, fiches de préparation, mouvements de stock et
   compteur sont écrits dans une seule transaction InnoDB. En cas d'échec : `ROLLBACK` total.
2. **Verrouillage** — le compteur de commande et les ingrédients sont lus en
   `SELECT … FOR UPDATE`. Deux commandes simultanées ne peuvent pas obtenir le même numéro
   ni consommer deux fois le même stock.
3. **Anti-IDOR** — l'identité du client provient **exclusivement** de la session WordPress.
   Aucun champ `clientId` de la requête n'est lu. Une commande invitée est enregistrée sous
   `guest:<téléphone normalisé>`.
4. **Idempotence** — empreinte SHA-256 du contenu canonique. Contenu identique → même
   commande (200). Contenu différent → 422. Appelant différent → 403.
5. **Fiche de préparation** — les lignes d'ingrédients sont calculées à partir de la recette
   du produit, puis ajustées selon les options choisies (voir ci-dessous).

### Règles de calcul des ingrédients

| Situation | Règle |
|---|---|
| Recette | La consommation de base vient de `bebba_product_ingredients` |
| Protéine supplémentaire | Les grammes de l'option s'ajoutent à la protéine nommée dans le libellé |
| Protéine absente de la recette | Elle est **ajoutée** (ex. « Ajout Poulet grillé +120g » sur un plat sans poulet) |
| Libellé contenant « Remplacer » | Les protéines de la recette non citées sont **retirées** au profit des citées (option végétarienne) |
| Légumes supplémentaires | Répartis à parts égales entre les ingrédients cités, sinon sur `ing-legumes` |
| Changement de base | Le féculent de la recette est **remplacé** (riz ↔ quinoa ↔ patates douces) |
| Base « 100 % légumes / sans féculent » | Le féculent est retiré et son poids reporté sur les légumes |
| Suppléments | Ajoutés en dernier, jamais retirés par une substitution |

### Page de commande

Le shortcode `[bebba_commande]` (page `/commande/`) affiche :

- le récapitulatif du panier lu depuis `localStorage` (clé `bebba_cart`) ;
- un formulaire nom / téléphone / adresse / note ;
- le bouton « Confirmer la commande » ;
- l'écran de confirmation avec le numéro de commande.

Le panier est vidé après confirmation. En cas d'échec, il est conservé.

### Idempotence côté navigateur

La clé est conservée dans `sessionStorage` tant que le contenu envoyé ne change pas.
Un nouvel essai après une coupure réseau réutilise donc la même clé et ne crée **pas** de
seconde commande. Toute modification du panier ou des coordonnées génère une nouvelle clé.

---

## Ce qui reste à construire

- [x] Système de checkout (BLOC 8.1 — commande enregistrée, stock décrémenté)
- [ ] Suivi de commande client (BLOC 8.2)
- [ ] Écran cuisine (BLOC 8.3)
- [ ] Espace livreur (BLOC 8.4)
- [ ] Encaissement (BLOC 8.5)
- [ ] Administration des commandes (BLOC 8.5)
