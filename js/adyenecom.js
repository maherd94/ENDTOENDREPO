$(document).ready(function () {
    // ---------- Toast ----------
    function showToast(message, title = 'Action needed') {
      const $t = $('#toast');
      if ($t.length) {
        $t.find('.toast-title').text(title);
        $t.find('.toast-msg').text(message);
        $t.prop('hidden', false);
        clearTimeout($t.data('hideTimer'));
        const timer = setTimeout(() => $t.prop('hidden', true), 2800);
        $t.data('hideTimer', timer);
      } else {
        alert(message);
      }
    }
  
    // ---------- Public state (from ecom.js) ----------
    function getShopperRef() {
      if (window.ECOM && typeof window.ECOM.getShopperRef === 'function') {
        return window.ECOM.getShopperRef();
      }
      const $sel = $('#ecom-customer');
      if ($sel.length && $sel.val()) {
        return $sel.find('option:selected').data('ref') || $sel.val();
      }
      return null;
    }
    function getCartAmount() {
      if (window.ECOM && typeof window.ECOM.getCartAmount === 'function') {
        return window.ECOM.getCartAmount(); // { value, currency }
      }
      const txt = ($('#ecom-cart-total').text() || '').trim();
      const m = txt.match(/^([0-9]+(?:\.[0-9]+)?)\s*([A-Z]{3})?/);
      if (!m) return { value: 0, currency: 'AED' };
      const amount = parseFloat(m[1] || '0');
      const currency = (m[2] || 'AED').toUpperCase();
      return { value: Math.round(amount * 100), currency };
    }
    function getOrderItems() {
      // Use the public ECOM getter if available
      if (window.ECOM && typeof window.ECOM.getLineItems === 'function') {
        const lis = window.ECOM.getLineItems(); // [{id, description, amountIncludingTax, quantity}]
        return (lis || []).map(li => ({
          productId: Number(li.id),
          qty: Number(li.quantity || 1)
        }));
      }
      return []; // fallback
    }
  
    // ---------- Utilities ----------
    function genOrderRef() {
      return `ord_${Date.now()}${Math.floor(Math.random() * 1000)}`;
    }
    function openModal() {
      $('#ecom-modal').prop('hidden', false).attr('aria-hidden', 'false');
    }
    function ensureModalHidden() {
      $('#ecom-modal').prop('hidden', true).attr('aria-hidden', 'true');
    }
    ensureModalHidden(); // start hidden
  
    // ---------- API ----------
    async function callSessions(input) {
      return new Promise((resolve, reject) => {
        $.ajax({
          url: "/apis/sessions.php",
          method: "POST",
          contentType: "application/json",
          dataType: "json",
          data: JSON.stringify(input),
          success: resolve,
          error: function (xhr, _status, error) {
            reject(`AJAX Error: ${error} ${xhr.responseText || ''}`);
          }
        });
      });
    }
  
    async function createOrder(payload) {
      return new Promise((resolve, reject) => {
        $.ajax({
          url: "/apis/create_order.php",
          method: "POST",
          contentType: "application/json",
          dataType: "json",
          data: JSON.stringify(payload),
          success: resolve,
          error: function (xhr, _status, error) {
            reject(`AJAX Error: ${error} ${xhr.responseText || ''}`);
          }
        });
      });
    }
  
    // ---------- Drop-in ----------
    let dropinMounted = false;
    let currentRef = null;          // keep the order reference used for the session
    let currentShopperRef = null;   // keep shopper
    let currentAmount = null;       // { value, currency }
  
    async function loadDropin(input) {
      try {
        const session = await callSessions(input);
        if (!session || !session.id || !session.sessionData) {
          console.error('Unexpected sessions response:', session);
          showToast('Payment session failed to initialize. Check server logs.', 'Checkout error');
          return;
        }
  
        const cfg = {
          session: { id: session.id, sessionData: session.sessionData },
          environment: 'test', // switch to 'live' for prod
          clientKey: 'test_DNYUPHYAUJEWPEKUYET53S3U2EBHNJJD',
          locale: 'en_US',
          countryCode: 'AE',
          amount: input.amount,
  
          // ---- YOUR CALLBACKS ----
          onPaymentCompleted: async (result, component) => {
            console.info('onPaymentCompleted', result, component);
  
            try {
              const items = getOrderItems();
              const payload = {
                reference: currentRef,                     // same reference as session
                shopperReference: currentShopperRef,
                shopperInteraction:'Ecommerce',
                storePaymentMethodMode:'enabled',
                recurringProcessingModel:'CardOnFile',
                amount: currentAmount,                     // {value, currency}
                items: items,                              // [{productId, qty}]
                pspReference: result && result.pspReference ? result.pspReference : null,
                paymentMethodType: result && result.paymentMethodType ? result.paymentMethodType : null
              };
  
              const saved = await createOrder(payload);
              showToast('Order created successfully', 'Success');
              // Optional: redirect or clear cart here
              // location.href = '/htmls/index.html#orders';  // e.g., jump to internal system Orders
            } catch (err) {
              console.error('Order creation failed:', err);
              showToast('Order was paid but could not be saved. Check logs.', 'Save error');
            }
          },
  
          onPaymentFailed: (result, component) => {
            console.log('onPaymentFailed', result);
            console.info(result, component);
            showToast('Payment failed. Please try another method.', 'Payment error');
          },
  
          onError: (error, component) => {
            console.error(error.name, error.message, error.stack, component);
            showToast('Unexpected error during checkout.', 'Checkout error');
          }
        };
  
        // Avoid double-mounts if user clicks Checkout multiple times
        if (dropinMounted) {
          $('#payment-sheet').empty();
          dropinMounted = false;
        }
  
        const { AdyenCheckout, Dropin } = window.AdyenWeb;
        const checkout = await AdyenCheckout(cfg);
        new Dropin(checkout).mount('#payment-sheet');
        dropinMounted = true;
      } catch (err) {
        console.error(err);
        showToast('Could not start checkout. Please try again.', 'Checkout error');
      }
    }
  
    // ---------- Checkout click (trigger) ----------
    $('#ecom-checkout').on('click', async function (e) {
      e.preventDefault();
  
      const shopperReference = getShopperRef();
      const { value, currency } = getCartAmount();
  
      if (!shopperReference) { showToast('Choose a shopper to continue'); return; }
      if (!value || value <= 0) { showToast('Your cart is empty'); return; }
  
      // Save current context for the callbacks
      currentRef = genOrderRef();
      currentShopperRef = shopperReference;
      currentAmount = { value, currency };
  
      // Build Sessions payload (merchantAccount/returnUrl injected server-side)
      const payload = {
        amount: currentAmount,
        reference: currentRef,
        shopperReference: currentShopperRef,
        countryCode: 'AE',
        shopperInteraction: 'Ecommerce',
        storePaymentMethodMode:'enabled',
        recurringProcessingModel: 'CardOnFile'
      };
      const li = getOrderItems();
      if (Array.isArray(li) && li.length) payload.lineItems = li.map(x => ({
        id: String(x.productId),
        description: '',                // optional
        amountIncludingTax: null,       // optional
        quantity: x.qty
      }));
  
      // Open modal then initialize drop-in
      openModal();
      await loadDropin(payload);
    });
  
    // ---------- Modal close bindings ----------
    $('#ecom-modal-close').on('click', function(){ ensureModalHidden(); });
    $('#ecom-modal').on('click', function(e){ if (e.target === this) ensureModalHidden(); });
    $(document).on('keydown', function(e){ if (e.key === 'Escape') ensureModalHidden(); });
  });
  