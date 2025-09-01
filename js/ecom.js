(function () {
    "use strict";

    const state = {
        shopper: null,      // {id, shopper_reference, name, label}
        products: [],       // [{id, sku, name, price_minor, currency}]
        cart: {}            // id -> {product, qty}
    };


    // make a minimal public API for other scripts (not the whole state)
    window.ECOM = {
        getShopperRef() {
            return state.shopper ? state.shopper.shopper_reference : null;
        },
        getCartAmount() {
            // returns { value: <minor units>, currency: 'AED' }
            let total = 0, currency = 'AED';
            Object.values(state.cart).forEach(ci => {
                total += (Number(ci.product.price_minor) || 0) * (Number(ci.qty) || 0);
                currency = ci.product.currency || currency;
            });
            return { value: total, currency };
        },
        // optional: useful if you want to send lineItems to Adyen sessions later
        getLineItems() {
            return Object.values(state.cart).map(ci => ({
                id: String(ci.product.id),
                description: ci.product.name,
                amountIncludingTax: ci.product.price_minor, // per unit
                quantity: ci.qty
            }));
        }
    };


    // ---------- Helpers ----------
    function fmt(minor, cur) { return (minor / 100).toFixed(2) + ' ' + (cur || 'AED'); }
    function cartTotal() {
        let t = 0, cur = 'AED';
        Object.values(state.cart).forEach(ci => { t += ci.product.price_minor * ci.qty; cur = ci.product.currency; });
        return fmt(t, cur);
    }
    function renderShopperLabel() {
        const el = $('#ecom-cart-shopper');
        if (!state.shopper) { el.text('No shopper selected'); return; }
        el.text(`${state.shopper.shopper_reference} — ${state.shopper.name}`);
    }
    function renderProducts() {
        const $grid = $('#ecom-products').empty();
        state.products.forEach(p => {
            const $card = $(`
          <div class="product" data-id="${p.id}">
            <h4>${p.name}</h4>
            <div class="muted">${p.sku || ''}</div>
            <div class="price">${fmt(p.price_minor, p.currency)}</div>
            <div class="actions">
              <button class="btn-ghost btn-minus" disabled>-</button>
              <button class="btn btn-plus">Add</button>
            </div>
          </div>
        `);
            $grid.append($card);
        });
    }
    function renderCart() {
        const $items = $('#ecom-cart-items').empty();
        const ids = Object.keys(state.cart);

        if (ids.length === 0) {
            $items.html('<div style="padding:12px;color:#64748b;">Cart is empty</div>');
        } else {
            ids.forEach(id => {
                const ci = state.cart[id];
                const $row = $(`
            <div class="cart-item" data-id="${id}">
              <div>
                <div style="font-weight:600">${ci.product.name}</div>
                <div class="muted" style="font-size:12px">${ci.product.sku || ''}</div>
              </div>
              <div class="qty">
                <button class="btn-ghost qty-dec">-</button>
                <div>${ci.qty}</div>
                <button class="btn-ghost qty-inc">+</button>
                <div style="width:90px;text-align:right;font-weight:700">
                  ${fmt(ci.product.price_minor * ci.qty, ci.product.currency)}
                </div>
                <button class="btn-ghost qty-remove" title="Remove">✕</button>
              </div>
            </div>
          `);
                $items.append($row);
            });
        }

        // Update total only; DO NOT disable the button
        $('#ecom-cart-total').text(cartTotal());
    }

    // ---------- Toast ----------
    function showToast(message, title = 'Action needed') {
        const $t = $('#toast');
        $t.find('.toast-title').text(title);
        $t.find('.toast-msg').text(message);
        $t.prop('hidden', false);
        clearTimeout($t.data('hideTimer'));
        const timer = setTimeout(() => $t.prop('hidden', true), 2800);
        $t.data('hideTimer', timer);
    }
    $(document).on('click', '#toast-close', function () { $('#toast').prop('hidden', true); });

    // ---------- Modal ----------
    function openModal() {
        $('#ecom-modal').prop('hidden', false).attr('aria-hidden', 'false');
    }
    function closeModal() {
        $('#ecom-modal').prop('hidden', true).attr('aria-hidden', 'true');
    }
    // Ensure hidden on load
    $('#ecom-modal').prop('hidden', true).attr('aria-hidden', 'true');
    // Close controls
    $('#ecom-modal-close').on('click', closeModal);
    $('#ecom-modal').on('click', function (e) { if (e.target === this) closeModal(); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

    // ---------- Events: products ----------
    $(document).on('click', '.btn-plus', function () {
        const id = $(this).closest('.product').data('id');
        const p = state.products.find(x => x.id == id);
        if (!p) return;
        if (!state.cart[id]) state.cart[id] = { product: p, qty: 0 };
        state.cart[id].qty++;
        renderCart();
    });
    $(document).on('click', '.qty-dec', function () {
        const id = $(this).closest('.cart-item').data('id');
        const ci = state.cart[id]; if (!ci) return;
        ci.qty--; if (ci.qty <= 0) delete state.cart[id];
        renderCart();
    });
    $(document).on('click', '.qty-inc', function () {
        const id = $(this).closest('.cart-item').data('id');
        const ci = state.cart[id]; if (!ci) return;
        ci.qty++; renderCart();
    });
    $(document).on('click', '.qty-remove', function () {
        const id = $(this).closest('.cart-item').data('id');
        delete state.cart[id]; renderCart();
    });

    // ---------- Events: shopper select ----------
    $('#ecom-customer').on('change', function () {
        const opt = this.options[this.selectedIndex];
        const id = $(opt).val();
        if (!id) {
            state.shopper = null;
        } else {
            state.shopper = {
                id: Number(id),
                shopper_reference: $(opt).data('ref'),
                name: $(opt).data('name'),
                label: opt.text
            };
        }
        renderShopperLabel();
        renderCart();
    });

    // ---------- Events: cart toggle ----------
    $('#ecom-cart-toggle').on('click', function () {
        $('#ecom-cart').toggleClass('open');
    });

    // ---------- Checkout with guards (always enabled) ----------
    $('#ecom-checkout').on('click', function (e) {
        e.preventDefault();
        const hasShopper = !!state.shopper;
        const hasItems = Object.keys(state.cart).length > 0;

        if (!hasShopper) { showToast('Choose a shopper to continue'); return; }
        if (!hasItems) { showToast('Your cart is empty'); return; }

        openModal();
        // TODO: Initialize Adyen payment sheet here
        // - Optionally call /apis/create_order.php to create order & return a Checkout Session
        // - Mount the session in #payment-sheet
    });

    // ---------- Load data ----------
    function loadProducts() {
        return $.getJSON('/apis/products_list.php').then(res => {
            state.products = (res.data || []).map(p => ({
                id: Number(p.id),
                sku: p.sku,
                name: p.name,
                price_minor: Number(p.price_minor),
                currency: p.currency || 'AED'
            }));
            renderProducts();
        });
    }
    function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
    function loadCustomers() {
        return $.getJSON('/apis/customers_list.php').then(res => {
            const $sel = $('#ecom-customer').empty().append('<option value="">-- Select shopper --</option>');
            (res.data || []).forEach(c => {
                const name = c.display_name || capitalize(String(c.email || '').split('@')[0] || '');
                const $opt = $('<option></option>')
                    .val(c.id).text(`${c.shopper_reference} — ${name}`)
                    .attr('data-ref', c.shopper_reference)
                    .attr('data-name', name);
                $sel.append($opt);
            });
        });
    }

    // ---------- Boot ----------
    $.when(loadProducts(), loadCustomers()).then(function () {
        renderCart();
        renderShopperLabel();
    });

})();
