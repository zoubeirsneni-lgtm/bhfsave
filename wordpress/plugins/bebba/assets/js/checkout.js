/* ============================================================
 * BEBBA Healthy Food — Page de commande v1.2.0
 * assets/js/checkout.js
 * Lit le panier localStorage, envoie la commande au serveur,
 * affiche la confirmation ou l'erreur.
 * ============================================================ */

(function () {
  'use strict';

  var config    = window.BEBBA_CHECKOUT || {};
  var ordersUrl = config.orders_url || '';
  var nonce     = config.nonce || '';
  var currency  = config.currency || 'DT';
  var menuUrl   = config.menu_url || '/';

  var deliveryFee = Number(config.delivery_fee);
  if (!isFinite(deliveryFee)) { deliveryFee = 0; }

  var CART_KEY = 'bebba_cart';
  var IDEM_KEY = 'bebba_checkout_idem';

  var $ = function (id) { return document.getElementById(id); };
  var cart = [];
  var submitting = false;

  /* ── Utilitaires ── */

  function money(v) {
    var n = Number(v);
    if (!isFinite(n)) { n = 0; }
    return n.toFixed(2) + ' ' + currency;
  }

  function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function readCart() {
    try {
      var brut = localStorage.getItem(CART_KEY);
      var data = brut ? JSON.parse(brut) : [];
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  function clearCart() {
    try { localStorage.removeItem(CART_KEY); } catch (e) {}
  }

  /* ── Rendu du récapitulatif ── */

  function optionLabel(opt) {
    if (!opt) { return ''; }
    if (typeof opt === 'string') { return opt; }
    return opt.label || '';
  }

  function renderSummary() {
    var box = $('bebba-checkout-summary');
    if (!box) { return; }

    if (cart.length === 0) {
      box.innerHTML = '';
      return;
    }

    box.innerHTML = cart.map(function (item) {
      var lignes = [];
      var p = optionLabel(item.proteinOption);
      var v = optionLabel(item.veggiesOption);
      var b = optionLabel(item.baseChoice);
      if (p) { lignes.push(p); }
      if (v) { lignes.push(v); }
      if (b) { lignes.push(b); }

      var sups = [];
      var map = item.supplements || {};
      Object.keys(map).forEach(function (k) {
        var q = Number(map[k]);
        if (q > 0) { sups.push('Supplément × ' + q); }
      });
      if (sups.length) { lignes.push(sups.join(', ')); }

      var details = lignes.length
        ? '<div class="bebba-checkout__line-opts">' + escapeHtml(lignes.join(' · ')) + '</div>'
        : '';
      var note = item.specialInstructions
        ? '<div class="bebba-checkout__line-note">Note : ' + escapeHtml(item.specialInstructions) + '</div>'
        : '';

      return '<div class="bebba-checkout__line">'
        + '<div class="bebba-checkout__line-main">'
        +   '<div class="bebba-checkout__line-qty">' + Number(item.quantity) + '×</div>'
        +   '<div class="bebba-checkout__line-body">'
        +     '<div class="bebba-checkout__line-name">' + escapeHtml(item.productName) + '</div>'
        +     details
        +     note
        +   '</div>'
        +   '<div class="bebba-checkout__line-price">' + money(item.itemTotalPrice) + '</div>'
        + '</div>'
        + '</div>';
    }).join('');
  }

  function renderTotals() {
    var subtotal = cart.reduce(function (s, i) { return s + (Number(i.itemTotalPrice) || 0); }, 0);
    var total = subtotal + deliveryFee;

    var elSub = $('bebba-checkout-subtotal');
    var elFee = $('bebba-checkout-fee');
    var elTot = $('bebba-checkout-total');
    var elBtn = $('bebba-checkout-submit-total');

    if (elSub) { elSub.textContent = money(subtotal); }
    if (elFee) { elFee.textContent = money(deliveryFee); }
    if (elTot) { elTot.textContent = money(total); }
    if (elBtn) { elBtn.textContent = money(total); }
  }

  /* ── Affichage erreur / information ── */

  function showMessage(type, html) {
    var box = $('bebba-checkout-message');
    if (!box) { return; }
    box.className = 'bebba-checkout__message bebba-checkout__message--' + type;
    box.innerHTML = html;
    box.removeAttribute('hidden');
  }

  function hideMessage() {
    var box = $('bebba-checkout-message');
    if (!box) { return; }
    box.setAttribute('hidden', '');
    box.innerHTML = '';
  }

  /* ── Clé d'idempotence ──
     Réutilisée tant que le contenu envoyé est identique : un nouvel essai après
     une coupure réseau ne crée pas de seconde commande. Toute modification du
     panier ou des coordonnées produit une nouvelle clé. */

  function randomKey() {
    try {
      if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
      if (window.crypto && window.crypto.getRandomValues) {
        var a = new Uint8Array(16);
        window.crypto.getRandomValues(a);
        return Array.prototype.map.call(a, function (b) {
          return ('0' + b.toString(16)).slice(-2);
        }).join('');
      }
    } catch (e) {}
    return 'k' + Date.now() + Math.random().toString(36).slice(2, 12);
  }

  function payloadSignature(payload) {
    return JSON.stringify(payload);
  }

  function idempotencyKeyFor(payload) {
    var sig = payloadSignature(payload);
    var stored = null;
    try { stored = JSON.parse(sessionStorage.getItem(IDEM_KEY) || 'null'); } catch (e) {}
    if (stored && stored.sig === sig && stored.key) { return stored.key; }
    var key = randomKey();
    try { sessionStorage.setItem(IDEM_KEY, JSON.stringify({ sig: sig, key: key })); } catch (e) {}
    return key;
  }

  function forgetIdempotencyKey() {
    try { sessionStorage.removeItem(IDEM_KEY); } catch (e) {}
  }

  /* ── Construction du corps de requête ──
     Seuls les LIBELLÉS d'options sont transmis. Aucun prix n'est envoyé :
     le serveur les recalcule intégralement. */

  function buildPayload() {
    return {
      client: {
        name:            ($('bebba-field-name') || {}).value ? $('bebba-field-name').value.trim() : '',
        phone:           ($('bebba-field-phone') || {}).value ? $('bebba-field-phone').value.trim() : '',
        deliveryAddress: ($('bebba-field-address') || {}).value ? $('bebba-field-address').value.trim() : '',
        notes:           ($('bebba-field-notes') || {}).value ? $('bebba-field-notes').value.trim() : ''
      },
      items: cart.map(function (item) {
        var sups = {};
        var map = item.supplements || {};
        Object.keys(map).forEach(function (k) {
          var q = parseInt(map[k], 10);
          if (q > 0) { sups[String(parseInt(k, 10))] = q; }
        });
        return {
          productId:           parseInt(item.productId, 10),
          quantity:            parseInt(item.quantity, 10) || 1,
          proteinOption:       optionLabel(item.proteinOption) ? { label: optionLabel(item.proteinOption) } : null,
          veggiesOption:       optionLabel(item.veggiesOption) ? { label: optionLabel(item.veggiesOption) } : null,
          baseChoice:          optionLabel(item.baseChoice) ? { label: optionLabel(item.baseChoice) } : null,
          supplements:         sups,
          specialInstructions: item.specialInstructions || ''
        };
      })
    };
  }

  /* ── Validation locale (le serveur revalide tout) ── */

  function validate(payload) {
    var erreurs = [];
    if (!payload.client.name) { erreurs.push('Votre nom complet est requis.'); }
    if (!payload.client.phone) { erreurs.push('Votre numéro de téléphone est requis.'); }
    else if (payload.client.phone.replace(/\D/g, '').length < 6) {
      erreurs.push('Le numéro de téléphone semble incomplet.');
    }
    if (!payload.client.deliveryAddress) { erreurs.push('Votre adresse de livraison est requise.'); }
    if (!payload.items.length) { erreurs.push('Votre panier est vide.'); }
    return erreurs;
  }

  /* ── Envoi ── */

  function setSubmitting(etat) {
    submitting = etat;
    var btn = $('bebba-checkout-submit');
    var lab = $('bebba-checkout-submit-label');
    if (btn) {
      if (etat) { btn.setAttribute('disabled', 'disabled'); }
      else      { btn.removeAttribute('disabled'); }
    }
    if (lab) { lab.textContent = etat ? 'Envoi en cours…' : 'Confirmer la commande'; }
  }

  function showSuccess(order) {
    var body = $('bebba-checkout-body');
    var ok   = $('bebba-checkout-success');
    if (body) { body.setAttribute('hidden', ''); }
    if (ok)   { ok.removeAttribute('hidden'); }

    var num = $('bebba-success-number');
    var tot = $('bebba-success-total');
    if (num) { num.textContent = order.order_number || '—'; }
    if (tot) { tot.textContent = money(order.total_amount); }

    try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) {}
  }

  function describeError(status, data) {
    var message = (data && data.message) ? data.message : 'Une erreur est survenue.';
    if (status === 409 && data && data.data && Array.isArray(data.data.details)) {
      var lignes = data.data.details.map(function (d) {
        return '<li><strong>' + escapeHtml(d.ingredient) + '</strong> — il manque '
          + escapeHtml(String(d.missing)) + ' ' + escapeHtml(d.unit || '')
          + ' (disponible : ' + escapeHtml(String(d.available)) + ' ' + escapeHtml(d.unit || '') + ')</li>';
      }).join('');
      return '<p>Certains ingrédients ne sont plus en quantité suffisante :</p><ul>' + lignes
        + '</ul><p>Retirez les plats concernés ou réessayez plus tard.</p>';
    }
    if (status === 422) {
      return '<p>' + escapeHtml(message) + '</p>'
        + '<p>Rechargez la page puis recommencez votre commande.</p>';
    }
    if (status === 403) {
      /* Nonce WordPress expire : la session a trop duree. */
      if (data && data.code === 'rest_cookie_invalid_nonce') {
        return '<p>Votre session a expiré.</p>'
          + '<p>Vos coordonnées sont conservées : rechargez la page puis validez à nouveau.</p>';
      }
      return '<p>' + escapeHtml(message) + '</p>';
    }
    if (status >= 500) {
      return '<p>' + escapeHtml(message) + '</p>'
        + '<p>Vous pouvez réessayer : aucune commande n’aura été créée deux fois.</p>';
    }
    return '<p>' + escapeHtml(message) + '</p>';
  }

  function submitOrder(evt) {
    if (evt) { evt.preventDefault(); }
    if (submitting) { return; }

    hideMessage();

    var payload = buildPayload();
    var erreurs = validate(payload);
    if (erreurs.length) {
      showMessage('error', '<ul>' + erreurs.map(function (e) {
        return '<li>' + escapeHtml(e) + '</li>';
      }).join('') + '</ul>');
      return;
    }

    var idemKey = idempotencyKeyFor(payload);
    setSubmitting(true);

    fetch(ordersUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-WP-Nonce': nonce,
        'Idempotency-Key': idemKey
      },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { status: res.status, data: data };
      });
    }).then(function (r) {
      setSubmitting(false);

      if (r.status === 201 || r.status === 200) {
        clearCart();
        forgetIdempotencyKey();
        showSuccess(r.data);
        return;
      }

      showMessage('error', describeError(r.status, r.data));

      /* Le contenu a divergé pour cette clé : on repart sur une clé neuve. */
      if (r.status === 422) { forgetIdempotencyKey(); }
    }).catch(function () {
      setSubmitting(false);
      showMessage('error',
        '<p>Impossible de joindre le serveur. Vérifiez votre connexion puis réessayez.</p>'
        + '<p>Aucune commande n’a été créée : vous pouvez réessayer sans risque de doublon.</p>');
    });
  }

  /* ── Initialisation ── */

  function init() {
    var racine = $('bebba-checkout');
    if (!racine) { return; }

    cart = readCart();

    if (cart.length === 0) {
      var vide = $('bebba-checkout-empty');
      var body = $('bebba-checkout-body');
      if (vide) { vide.removeAttribute('hidden'); }
      if (body) { body.setAttribute('hidden', ''); }
      return;
    }

    /* Restaure les coordonnées saisies lors d'un précédent passage sur la page */
    try {
      var memo = JSON.parse(sessionStorage.getItem('bebba_checkout_form') || 'null');
      if (memo) {
        ['bebba-field-name', 'bebba-field-phone', 'bebba-field-address', 'bebba-field-notes'].forEach(function (id) {
          var el = $(id);
          if (el && memo[id]) { el.value = memo[id]; }
        });
      }
    } catch (e) {}

    /* Pré-remplissage pour un client connecté */
    var champNom = $('bebba-field-name');
    var champTel = $('bebba-field-phone');
    if (champNom && !champNom.value && config.customer_name) { champNom.value = config.customer_name; }
    if (champTel && !champTel.value && config.customer_phone) {
      var tel = String(config.customer_phone);
      /* « 0021698123456 » -> « +216 98 123 456 » pour l'affichage */
      if (/^00\d{9,}$/.test(tel)) {
        tel = '+' + tel.slice(2, 5) + ' ' + tel.slice(5, 7) + ' ' + tel.slice(7, 10) + ' ' + tel.slice(10);
      }
      champTel.value = tel.trim();
    }

    renderSummary();
    renderTotals();

    var form = $('bebba-checkout-form');
    if (form) {
      form.addEventListener('submit', submitOrder);
      /* Mémorise la saisie : un rechargement ne fait rien perdre au client. */
      ['bebba-field-name', 'bebba-field-phone', 'bebba-field-address', 'bebba-field-notes'].forEach(function (id) {
        var el = $(id);
        if (!el) { return; }
        el.addEventListener('input', function () {
          try {
            var memo = JSON.parse(sessionStorage.getItem('bebba_checkout_form') || '{}') || {};
            memo[id] = el.value;
            sessionStorage.setItem('bebba_checkout_form', JSON.stringify(memo));
          } catch (e) {}
        });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
