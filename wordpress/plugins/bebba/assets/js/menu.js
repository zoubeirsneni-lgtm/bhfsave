/* ============================================================
 * BEBBA Healthy Food — Menu Public v1.1.0
 * assets/js/menu.js
 * Panier localStorage, sous-totaux, configuration produit.
 * ============================================================ */

(function () {
  'use strict';

  var config = window.BEBBA_MENU || {};
  var apiUrl = config.api_url || '';
  var currency = config.currency || 'DT';
  var loginUrl = config.login_url || '';
  var nonce = config.nonce || '';
  /* WP localise toutes les valeurs en chaine de caracteres : conversion explicite */
  var deliveryFee = Number(config.delivery_fee);
  if (!isFinite(deliveryFee)) { deliveryFee = 0; }

  /* ── State ── */
  var categories = [];
  var products = [];
  var activeCategory = 'all';
  var selectedProduct = null;
  var quantity = 1;
  var selectedProtein = null;
  var selectedVeggies = null;
  var selectedBase = null;
  var selectedSupplements = {};
  var specialInstructions = '';
  var cart = JSON.parse(localStorage.getItem('bebba_cart') || '[]');

  /* ── DOM Helpers ── */
  var $ = function (id) { return document.getElementById(id); };

  function $(sel) { return document.querySelector(sel.charAt(0) === '#' || sel.charAt(0) === '.' ? sel : '#' + sel); }
  function $$(sel) { return document.querySelectorAll(sel); }

  /* ── Cart persistence ── */
  function saveCart() {
    localStorage.setItem('bebba_cart', JSON.stringify(cart));
    updateCartUI();
  }

  function updateCartUI() {
    var countEl = $('bebba-cart-count');
    var totalEl = $('bebba-cart-total');
    var btn = $('bebba-cart-btn');

    var totalItems = cart.reduce(function (s, i) { return s + i.quantity; }, 0);
    var subtotal = cart.reduce(function (s, i) { return s + i.itemTotalPrice; }, 0);
    var grandTotal = subtotal + deliveryFee;

    if (btn) btn.style.display = cart.length > 0 ? 'flex' : 'none';
    if (countEl) countEl.textContent = totalItems;
    if (totalEl) totalEl.textContent = subtotal.toFixed(2) + ' ' + currency;

    renderCartItems();
    var feeEl = $('bebba-delivery-fee');
    var grandEl = $('bebba-cart-grand-total');
    if (feeEl) feeEl.textContent = deliveryFee.toFixed(2) + ' ' + currency;
    if (grandEl) grandEl.textContent = grandTotal.toFixed(2) + ' ' + currency;
  }

  /* ── Fetch menu data ── */
  async function fetchMenu() {
    try {
      var res = await fetch(apiUrl, {
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('API error: ' + res.status);
      var data = await res.json();
      categories = data.categories || [];
      products = data.products || [];
      renderCategories();
      renderProducts();
    } catch (err) {
      var grid = $('bebba-products-grid');
      if (grid) {
        grid.innerHTML = '<div class="bebba-load-error"><p>Erreur de chargement du menu.</p></div>';
      }
    }
  }

  /* ── Render category tabs ── */
  function renderCategories() {
    var track = $('.bebba-cats__track');
    if (!track) return;
    track.innerHTML = '';

    /* All button */
    track.innerHTML += '<button class="bebba-cat-btn active" data-cat="all" role="tab" aria-selected="true"><span class="bebba-cat-btn__icon">🍽️</span><span>Tout</span></button>';

    categories.forEach(function (cat) {
      var count = products.filter(function (p) { return p.category_id === cat.id; }).length;
      track.innerHTML += '<button class="bebba-cat-btn" data-cat="' + cat.id + '" role="tab"><span class="bebba-cat-btn__icon">' + (cat.icon || '🍽️') + '</span><span>' + cat.name + ' (' + count + ')</span></button>';
    });

    track.querySelectorAll('.bebba-cat-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        activeCategory = this.dataset.cat;
        track.querySelectorAll('.bebba-cat-btn').forEach(function (b) {
          b.classList.remove('active');
          b.setAttribute('aria-selected', 'false');
        });
        this.classList.add('active');
        this.setAttribute('aria-selected', 'true');
        renderProducts();
      });
    });
  }

  /* ── Render products ── */
  function renderProducts() {
    var grid = $('bebba-products-grid');
    if (!grid) return;

    var filtered = products.filter(function (p) {
      if (activeCategory !== 'all' && p.category_id !== parseInt(activeCategory)) return false;
      return true;
    });

    if (filtered.length === 0) {
      grid.innerHTML = '<div class="bebba-load-error"><p>Aucun produit trouvé.</p></div>';
      return;
    }

    grid.innerHTML = filtered.map(function (p) {
      var optsHtml = '';
      var opts = [];
      if (p.options && p.options.protein && p.options.protein.length) {
        opts.push('<span class="bebba-macro-pill">' + p.options.protein.length + ' protéines</span>');
      }
      if (p.options && p.options.base && p.options.base.length) {
        opts.push('<span class="bebba-macro-pill">' + p.options.base.length + ' bases</span>');
      }
      if (p.options && p.options.veggies && p.options.veggies.length) {
        opts.push('<span class="bebba-macro-pill">' + p.options.veggies.length + ' légumes</span>');
      }
      if (p.supplements && p.supplements.length) {
        opts.push('<span class="bebba-macro-pill">' + p.supplements.length + ' suppléments</span>');
      }
      optsHtml = opts.join(' · ');

      return '<div class="bebba-card" data-product-id="' + p.id + '">'
        + '<div class="bebba-card__img">'
        + (p.image_url ? '<img src="' + p.image_url + '" alt="' + p.name + '" loading="lazy">' : '')
        + (p.is_popular ? '<span class="bebba-card__popular">🔥 Populaire</span>' : '')
        + '</div>'
        + '<div class="bebba-card__body">'
        + '<div class="bebba-card__name">' + p.name + '</div>'
        + '<div class="bebba-card__desc">' + (p.description || '').substring(0, 120) + '</div>'
        + '<div class="bebba-card__macros">' + optsHtml + '</div>'
        + '</div>'
        + '<div class="bebba-card__footer">'
        + '<span class="bebba-card__price">' + p.base_price.toFixed(2) + ' ' + currency + '</span>'
        + '<button class="bebba-card__add" data-add="' + p.id + '" aria-label="Ajouter ' + p.name + '">+</button>'
        + '</div>'
        + '</div>';
    }).join('');

    /* Attach click events */
    grid.querySelectorAll('.bebba-card__add').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        openProductModal(parseInt(this.dataset.add));
      });
    });

    grid.querySelectorAll('.bebba-card').forEach(function (card) {
      card.addEventListener('click', function () {
        openProductModal(parseInt(this.dataset.productId));
      });
    });
  }

  /* ── Open product config modal ── */
  function openProductModal(productId) {
    var p = products.find(function (pr) { return pr.id === productId; });
    if (!p) return;
    selectedProduct = p;
    quantity = 1;
    selectedProtein = null;
    selectedVeggies = null;
    selectedBase = null;
    selectedSupplements = {};
    specialInstructions = '';

    /* Set defaults from product options */
    if (p.options && p.options.protein && p.options.protein.length) {
      var defaultProtein = p.options.protein.find(function (o) { return o.is_default; }) || p.options.protein[0];
      selectedProtein = defaultProtein;
    }
    if (p.options && p.options.veggies && p.options.veggies.length) {
      selectedVeggies = p.options.veggies[0];
    }
    if (p.options && p.options.base && p.options.base.length) {
      selectedBase = p.options.base[0];
    }
    if (p.supplements && p.supplements.length) {
      p.supplements.forEach(function (s) {
        selectedSupplements[s.id] = 0;
      });
    }

    renderModal();
    var overlay = $('bebba-modal-overlay');
    if (overlay) overlay.removeAttribute('hidden');
  }

  function closeModal() {
    var overlay = $('bebba-modal-overlay');
    if (overlay) overlay.setAttribute('hidden', '');
    selectedProduct = null;
  }

  /* ── Render modal content ── */
  function renderModal() {
    if (!selectedProduct) return;
    var p = selectedProduct;

    /* Name, desc, macros */
    var nameEl = $('bebba-modal-name');
    var descEl = $('bebba-modal-desc');
    var macrosEl = $('bebba-modal-macros');
    if (nameEl) nameEl.textContent = p.name;
    if (descEl) descEl.textContent = p.description || '';
    if (macrosEl) {
      var macroHtml = '';
      if (p.calories) macroHtml += '<span class="bebba-macro-pill">' + p.calories + ' kcal</span>';
      if (p.protein_g) macroHtml += '<span class="bebba-macro-pill">' + p.protein_g + 'g prot</span>';
      if (p.carbs_g) macroHtml += '<span class="bebba-macro-pill">' + p.carbs_g + 'g gluc</span>';
      if (p.fat_g) macroHtml += '<span class="bebba-macro-pill">' + p.fat_g + 'g lip</span>';
      macrosEl.innerHTML = macroHtml;
    }

    /* Options protein */
    var proteinList = $('bebba-opts-protein-list');
    var proteinGroup = $('bebba-opts-protein');
    if (p.options && p.options.protein && p.options.protein.length) {
      if (proteinGroup) proteinGroup.removeAttribute('hidden');
      proteinList.innerHTML = p.options.protein.map(function (opt) {
        var selected = selectedProtein && selectedProtein.label === opt.label;
        return '<button type="button" class="bebba-opt-btn' + (selected ? ' selected' : '') + '" data-opt-type="protein" data-opt-label="' + opt.label.replace(/"/g, '&quot;') + '">'
          + '<span class="bebba-opt-label">' + opt.label + '</span>'
          + '<span class="bebba-opt-btn__price">' + (opt.extra_price > 0 ? '+' + opt.extra_price.toFixed(1) + ' DT' : 'Inclus') + '</span>'
          + '</button>';
      }).join('');
    } else {
      if (proteinGroup) proteinGroup.setAttribute('hidden', '');
    }

    /* Options veggies */
    var veggiesList = $('bebba-opts-veggies-list');
    var veggiesGroup = $('bebba-opts-veggies');
    if (p.options && p.options.veggies && p.options.veggies.length) {
      if (veggiesGroup) veggiesGroup.removeAttribute('hidden');
      veggiesList.innerHTML = p.options.veggies.map(function (opt) {
        var selected = selectedVeggies && selectedVeggies.label === opt.label;
        return '<button type="button" class="bebba-opt-btn' + (selected ? ' selected' : '') + '" data-opt-type="veggies" data-opt-label="' + opt.label.replace(/"/g, '&quot;') + '">'
          + '<span class="bebba-opt-label">' + opt.label + '</span>'
          + '<span class="bebba-opt-btn__price">' + (opt.extra_price > 0 ? '+' + opt.extra_price.toFixed(1) + ' DT' : 'Inclus') + '</span>'
          + '</button>';
      }).join('');
    } else {
      if (veggiesGroup) veggiesGroup.setAttribute('hidden', '');
    }

    /* Options base */
    var baseList = $('bebba-opts-base-list');
    var baseGroup = $('bebba-opts-base');
    if (p.options && p.options.base && p.options.base.length) {
      if (baseGroup) baseGroup.removeAttribute('hidden');
      baseList.innerHTML = p.options.base.map(function (opt) {
        var selected = selectedBase && selectedBase.label === opt.label;
        return '<button type="button" class="bebba-opt-btn' + (selected ? ' selected' : '') + '" data-opt-type="base" data-opt-label="' + opt.label.replace(/"/g, '&quot;') + '">'
          + '<span class="bebba-opt-label">' + opt.label + '</span>'
          + '<span class="bebba-opt-btn__price">' + (opt.extra_price > 0 ? '+' + opt.extra_price.toFixed(1) + ' DT' : 'Inclus') + '</span>'
          + '</button>';
      }).join('');
    } else {
      if (baseGroup) baseGroup.setAttribute('hidden', '');
    }

    /* Supplements */
    var supList = $('bebba-supplements-list');
    var supGroup = $('bebba-supplements');
    if (p.supplements && p.supplements.length) {
      if (supGroup) supGroup.removeAttribute('hidden');
      supList.innerHTML = p.supplements.map(function (s) {
        var qty = selectedSupplements[s.id] || 0;
        return '<button type="button" class="bebba-sup-btn' + (qty > 0 ? ' selected' : '') + '" data-sup-id="' + s.id + '">'
          + '<span class="bebba-sup-checkbox">' + (qty > 0 ? '✓' : '') + '</span>'
          + '<span class="bebba-sup-btn__info">'
          + '<span class="bebba-sup-btn__name">' + s.name + '</span>'
          + '<span class="bebba-sup-btn__price">+' + s.price.toFixed(1) + ' DT</span>'
          + '</span>'
          + '<div class="bebba-cart-item__controls">'
          + '<button class="bebba-cart-item__qty-btn" data-sup-minus="' + s.id + '" aria-label="Diminuer">−</button>'
          + '<span class="bebba-cart-item__qty-val">' + qty + '</span>'
          + '<button class="bebba-cart-item__qty-btn" data-sup-plus="' + s.id + '" aria-label="Augmenter">+</button>'
          + '</div>'
          + '</button>';
      }).join('');
    } else {
      if (supGroup) supGroup.setAttribute('hidden', '');
    }

    /* Instructions */
    var instrEl = $('bebba-special-instructions');
    if (instrEl) instrEl.value = specialInstructions;

    /* Quantity */
    var qtyVal = $('bebba-qty-val');
    if (qtyVal) qtyVal.textContent = quantity;

    /* Line total */
    updateLineTotal();
  }

  function updateLineTotal() {
    if (!selectedProduct) return;
    var p = selectedProduct;
    var base = Number(p.base_price) || 0;
    var protein = Number((selectedProtein && selectedProtein.extra_price) || 0) || 0;
    var veggies = Number((selectedVeggies && selectedVeggies.extra_price) || 0) || 0;
    var baseChoice = Number((selectedBase && selectedBase.extra_price) || 0) || 0;
    var supplements = 0;
    Object.entries(selectedSupplements).forEach(function ([supId, qty]) {
      var sup = (p.supplements || []).find(function (s) { return s.id === Number(supId); });
      if (sup && Number(qty) > 0) supplements += Number(sup.price) * Number(qty);
    });
    var unitTotal = Math.round((base + protein + veggies + baseChoice + supplements) * 10) / 10;
    var grandTotal = Math.round(unitTotal * quantity * 10) / 10;
    var lineTotalEl = $('bebba-modal-line-total');
    if (lineTotalEl) lineTotalEl.textContent = grandTotal.toFixed(2) + ' ' + currency;
  }

  /* ── Cart operations ── */
  function addToCart() {
    if (!selectedProduct) return;
    var p = selectedProduct;
    var protein = selectedProtein ? { label: selectedProtein.label, extraPrice: selectedProtein.extra_price || 0, extraGrams: selectedProtein.extra_grams || 0 } : undefined;
    var veggies = selectedVeggies ? { label: selectedVeggies.label, extraPrice: selectedVeggies.extra_price || 0, extraGrams: selectedVeggies.extra_grams || 0 } : undefined;
    var baseChoice = selectedBase ? { label: selectedBase.label, extraPrice: selectedBase.extra_price || 0 } : undefined;
    var supplements = [];
    Object.entries(selectedSupplements).forEach(function ([supId, qty]) {
      var sup = (p.supplements || []).find(function (s) { return s.id === supId; });
      if (sup && qty > 0) {
        supplements.push({ supplementId: sup.id, name: sup.name, price: sup.price, quantity: qty, ingredientId: '', ingredientName: '', quantityConsumed: 0, unit: 'g' });
      }
    });

    var base = Number(p.base_price) || 0;
    var proteinExtra = Number((selectedProtein && selectedProtein.extra_price) || 0) || 0;
    var veggiesExtra = Number((selectedVeggies && selectedVeggies.extra_price) || 0) || 0;
    var baseExtra = Number((selectedBase && selectedBase.extra_price) || 0) || 0;
    var supPrice = 0;
    Object.entries(selectedSupplements).forEach(function ([supId, qty]) {
      var sup = (p.supplements || []).find(function (s) { return s.id === Number(supId); });
      if (sup && Number(qty) > 0) supPrice += Number(sup.price) * Number(qty);
    });
    var unitTotal = Math.round((base + proteinExtra + veggiesExtra + baseExtra + supPrice) * 10) / 10;
    var itemTotalPrice = Math.round(unitTotal * quantity * 10) / 10;

    var cartItem = {
      id: 'cart-' + Date.now() + '-' + Math.random().toString(36).substring(2, 6),
      productId: p.id,
      productName: p.name,
      unitPrice: unitTotal,
      quantity: quantity,
      proteinOption: protein,
      veggiesOption: veggies,
      baseChoice: baseChoice,
      supplements: supplements,
      specialInstructions: specialInstructions.trim() || undefined,
      itemTotalPrice: itemTotalPrice
    };

    /* Check if item already exists for same product with same options */
    var existingIndex = cart.findIndex(function (item) {
      return item.productId === p.id
        && item.quantity === quantity
        && (item.proteinOption?.label || null) === (selectedProtein?.label || null)
        && (item.veggiesOption?.label || null) === (selectedVeggies?.label || null)
        && (item.baseChoice?.label || null) === (selectedBase?.label || null);
    });

    if (existingIndex >= 0) {
      cart[existingIndex].quantity += quantity;
      cart[existingIndex].itemTotalPrice = Math.round(cart[existingIndex].unitPrice * cart[existingIndex].quantity * 10) / 10;
    } else {
      cart.push(cartItem);
    }

    saveCart();
    closeModal();
  }

  function removeFromCart(itemId) {
    cart = cart.filter(function (i) { return i.id !== itemId; });
    saveCart();
  }

  function updateCartQty(itemId, delta) {
    var item = cart.find(function (i) { return i.id === itemId; });
    if (!item) return;
    item.quantity += delta;
    if (item.quantity <= 0) {
      removeFromCart(itemId);
      return;
    }
    item.itemTotalPrice = Math.round(item.unitPrice * item.quantity * 10) / 10;
    saveCart();
  }

  /* ── Render cart items in panel ── */
  function renderCartItems() {
    var itemsContainer = $('bebba-cart-items');
    if (!itemsContainer) return;

    if (cart.length === 0) {
      itemsContainer.innerHTML = '<div class="bebba-cart-empty"><span class="bebba-cart-empty__icon">🛒</span><p>Votre panier est vide</p></div>';
      return;
    }

    itemsContainer.innerHTML = cart.map(function (item) {
      var supHtml = (item.supplements || []).map(function (s) {
        return s.name + ' x' + s.quantity + ' (' + (Number(s.price) * Number(s.quantity)).toFixed(1) + ' DT)';
      }).join(', ');

      return '<div class="bebba-cart-item">'
        + '<div class="bebba-cart-item__body">'
        + '<div class="bebba-cart-item__name">' + item.productName + '</div>'
        + '<div class="bebba-cart-item__opts">'
        + (item.proteinOption ? 'Protéine: ' + item.proteinOption.label + ' ' : '')
        + (item.veggiesOption ? 'Légumes: ' + item.veggiesOption.label + ' ' : '')
        + (item.baseChoice ? 'Base: ' + item.baseChoice.label + ' ' : '')
        + (supHtml ? 'Suppléments: ' + supHtml + ' ' : '')
        + '</div>'
        + '<div class="bebba-cart-item__price">' + item.itemTotalPrice.toFixed(2) + ' DT</div>'
        + '</div>'
        + '<div class="bebba-cart-item__controls">'
        + '<button class="bebba-cart-item__qty-btn" data-cart-qty-minus="' + item.id + '" aria-label="Diminuer">−</button>'
        + '<span class="bebba-cart-item__qty-val">' + item.quantity + '</span>'
        + '<button class="bebba-cart-item__qty-btn" data-cart-qty-plus="' + item.id + '" aria-label="Augmenter">+</button>'
        + '<button class="bebba-cart-item__remove" data-cart-remove="' + item.id + '" aria-label="Supprimer">&times;</button>'
        + '</div>'
        + '</div>';
    }).join('');

    /* Attach events */
    itemsContainer.querySelectorAll('[data-cart-qty-minus]').forEach(function (btn) {
      btn.addEventListener('click', function () { updateCartQty(this.dataset.cartQtyMinus, -1); });
    });
    itemsContainer.querySelectorAll('[data-cart-qty-plus]').forEach(function (btn) {
      btn.addEventListener('click', function () { updateCartQty(this.dataset.cartQtyPlus, 1); });
    });
    itemsContainer.querySelectorAll('[data-cart-remove]').forEach(function (btn) {
      btn.addEventListener('click', function () { removeFromCart(this.dataset.cartRemove); });
    });
  }

  /* ── Event delegation ── */
  function setupEvents() {
    /* Modal close */
    var modalClose = $('bebba-modal-close');
    if (modalClose) modalClose.addEventListener('click', closeModal);

    var overlay = $('bebba-modal-overlay');
    if (overlay) overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeModal();
    });

    /* Option buttons (delegation) */
    var optsProteinList = $('bebba-opts-protein-list');
    if (optsProteinList) {
      optsProteinList.addEventListener('click', function (e) {
        var btn = e.target.closest('.bebba-opt-btn');
        if (!btn) return;
        var label = btn.dataset.optLabel;
        var opt = (selectedProduct.options.protein || []).find(function (o) { return o.label === label; });
        if (opt) { selectedProtein = opt; renderModal(); }
      });
    }

    var optsVeggiesList = $('bebba-opts-veggies-list');
    if (optsVeggiesList) {
      optsVeggiesList.addEventListener('click', function (e) {
        var btn = e.target.closest('.bebba-opt-btn');
        if (!btn) return;
        var label = btn.dataset.optLabel;
        var opt = (selectedProduct.options.veggies || []).find(function (o) { return o.label === label; });
        if (opt) { selectedVeggies = opt; renderModal(); }
      });
    }

    var optsBaseList = $('bebba-opts-base-list');
    if (optsBaseList) {
      optsBaseList.addEventListener('click', function (e) {
        var btn = e.target.closest('.bebba-opt-btn');
        if (!btn) return;
        var label = btn.dataset.optLabel;
        var opt = (selectedProduct.options.base || []).find(function (o) { return o.label === label; });
        if (opt) { selectedBase = opt; renderModal(); }
      });
    }

    /* Supplement buttons */
    var supList = $('bebba-supplements-list');
    if (supList) {
      supList.addEventListener('click', function (e) {
        var minusBtn = e.target.closest('[data-sup-minus]');
        var plusBtn = e.target.closest('[data-sup-plus]');
        if (minusBtn) {
          var supId = minusBtn.dataset.supMinus;
          selectedSupplements[supId] = Math.max(0, (selectedSupplements[supId] || 0) - 1);
          renderModal();
          return;
        }
        if (plusBtn) {
          var supId2 = plusBtn.dataset.supPlus;
          selectedSupplements[supId2] = (selectedSupplements[supId2] || 0) + 1;
          renderModal();
          return;
        }
        var supBtn = e.target.closest('[data-sup-id]');
        if (supBtn) {
          var supId3 = supBtn.dataset.supId;
          selectedSupplements[supId3] = (selectedSupplements[supId3] || 0) + 1;
          renderModal();
        }
      });
    }

    /* Quantity buttons */
    var qtyMinus = $('bebba-qty-minus');
    var qtyPlus = $('bebba-qty-plus');
    if (qtyMinus) qtyMinus.addEventListener('click', function () {
      quantity = Math.max(1, quantity - 1);
      renderModal();
    });
    if (qtyPlus) qtyPlus.addEventListener('click', function () {
      quantity = Math.min(100, quantity + 1);
      renderModal();
    });

    /* Add to cart */
    var addBtn = $('bebba-add-to-cart');
    if (addBtn) addBtn.addEventListener('click', addToCart);

    /* Cart panel close */
    var cartClose = $('bebba-cart-close');
    if (cartClose) cartClose.addEventListener('click', function () {
      $('bebba-cart-panel').setAttribute('hidden', '');
      $('bebba-cart-backdrop').setAttribute('hidden', '');
    });
    var backdrop = $('bebba-cart-backdrop');
    if (backdrop) backdrop.addEventListener('click', function () {
      $('bebba-cart-panel').setAttribute('hidden', '');
      $('bebba-cart-backdrop').setAttribute('hidden', '');
    });

    /* Instructions */
    var instrEl = $('bebba-special-instructions');
    if (instrEl) {
      instrEl.addEventListener('input', function () {
        specialInstructions = this.value;
      });
    }
  }

  /* ── Init ── */
  function init() {
    setupEvents();
    fetchMenu();

    /* Restore cart from localStorage */
    try {
      var savedCart = localStorage.getItem('bebba_cart');
      if (savedCart) cart = JSON.parse(savedCart);
    } catch (e) {}

    updateCartUI();

    /* Cart floating button */
    var cartBtn = $('bebba-cart-btn');
    if (cartBtn) {
      cartBtn.addEventListener('click', function () {
        var panel = $('bebba-cart-panel');
        var backdrop = $('bebba-cart-backdrop');
        if (panel) panel.removeAttribute('hidden');
        if (backdrop) backdrop.removeAttribute('hidden');
        renderCartItems();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
