(function () {
    "use strict";

    const state = { customer: null, terminal: null, products: [], cart: {} };

    window.POS = {
        getShopperRef() { return state.customer ? state.customer.shopper_reference : null; },
        getTerminalId() { return state.terminal ? (state.terminal.terminal_id || state.terminal.id || state.terminal.label) : null; },
        getCartAmount() {
            let total = 0, currency = 'AED';
            Object.values(state.cart).forEach(ci => { total += (Number(ci.product.price_minor) || 0) * (Number(ci.qty) || 0); currency = ci.product.currency || currency; });
            return { value: total, currency };
        }
    };

    function fmt(minor, cur) { return (minor / 100).toFixed(2) + ' ' + (cur || 'AED'); }
    function showToast(m, t = 'Action needed') { const $t = $('#toast'); $t.find('.toast-title').text(t); $t.find('.toast-msg').text(m); $t.prop('hidden', false); clearTimeout($t.data('hideTimer')); $t.data('hideTimer', setTimeout(() => $t.prop('hidden', true), 2600)); }
    $(document).on('click', '#toast-close', () => $('#toast').prop('hidden', true));

    function renderProducts() {
        const $g = $('#pos-products').empty(); state.products.forEach(p => {
            $g.append(`
      <div class="product" data-id="${p.id}">
        <h4>${p.name}</h4>
        <div class="muted">${p.sku || ''}</div>
        <div class="price">${fmt(p.price_minor, p.currency)}</div>
        <div class="actions"><button class="btn-ghost btn-minus" disabled>-</button><button class="btn btn-plus">Add</button></div>
      </div>`);
        });
    }
    function renderCart() {
        const $it = $('#pos-cart-items').empty(); const ids = Object.keys(state.cart);
        if (!ids.length) $it.html('<div style="padding:12px;color:#64748b;">Cart is empty</div>');
        else ids.forEach(id => {
            const ci = state.cart[id]; $it.append(`
        <div class="cart-item" data-id="${id}">
          <div><div style="font-weight:600">${ci.product.name}</div><div class="muted" style="font-size:12px">${ci.product.sku || ''}</div></div>
          <div class="qty">
            <button class="btn-ghost qty-dec">-</button><div>${ci.qty}</div><button class="btn-ghost qty-inc">+</button>
            <div style="width:90px;text-align:right;font-weight:700">${fmt(ci.product.price_minor * ci.qty, ci.product.currency)}</div>
            <button class="btn-ghost qty-remove" title="Remove">✕</button>
          </div>
        </div>`);
        });
        const { value, currency } = window.POS.getCartAmount();
        $('#pos-cart-total').text(fmt(value, currency));
        const shopper = state.customer ? `${state.customer.shopper_reference} — ${state.customer.name}` : 'No customer selected';
        const term = state.terminal ? (state.terminal.label || state.terminal.terminal_id || state.terminal.id) : 'No terminal selected';
        $('#pos-cart-info').text(`${shopper} • ${term}`);
    }

    $(document).on('click', '.btn-plus', function () { const id = $(this).closest('.product').data('id'); const p = state.products.find(x => x.id == id); if (!p) return; if (!state.cart[id]) state.cart[id] = { product: p, qty: 0 }; state.cart[id].qty++; renderCart(); });
    $(document).on('click', '.qty-dec', function () { const id = $(this).closest('.cart-item').data('id'); const ci = state.cart[id]; if (!ci) return; if (--ci.qty <= 0) delete state.cart[id]; renderCart(); });
    $(document).on('click', '.qty-inc', function () { const id = $(this).closest('.cart-item').data('id'); const ci = state.cart[id]; if (!ci) return; ci.qty++; renderCart(); });
    $(document).on('click', '.qty-remove', function () { const id = $(this).closest('.cart-item').data('id'); delete state.cart[id]; renderCart(); });

    $('#pos-customer').on('change', function () { const opt = this.options[this.selectedIndex]; const id = $(opt).val(); state.customer = id ? { id: Number(id), shopper_reference: $(opt).data('ref'), name: $(opt).data('name') || $(opt).text() } : null; renderCart(); });
    $('#pos-terminal').on('change', function () { const opt = this.options[this.selectedIndex]; const val = $(opt).val(); state.terminal = val ? { terminal_id: $(opt).data('terminal-id') || val, id: val, label: $(opt).text() } : null; renderCart(); });
    $('#pos-cart-toggle').on('click', () => $('#pos-cart').toggleClass('open'));

    function openModal() { $('#pos-modal').prop('hidden', false).attr('aria-hidden', 'false'); }
    function closeModal() { $('#pos-modal').prop('hidden', true).attr('aria-hidden', 'true'); }
    $('#pos-modal-close').on('click', closeModal);
    $('#pos-modal').on('click', e => { if (e.target === e.currentTarget) closeModal(); });
    $(document).on('keydown', e => { if (e.key === 'Escape') closeModal(); });
    $('#pos-modal').prop('hidden', true).attr('aria-hidden', 'true');

    // ---- apis
    function payOnTerminal(terminalId, amountMinor, currency, transactionId) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: '/apis/pos_pay.php', method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ terminalId, amount: { value: amountMinor, currency }, transactionId }),
                success: resolve,
                error: (xhr, _s, e) => reject({ message: e, raw: xhr && xhr.responseText })
            });
        });
    }
    function createPosOrder(payload) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: '/apis/create_pos_order.php', method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify(payload),
                success: resolve,
                error: (xhr, _s, e) => reject({ message: e, raw: xhr && xhr.responseText })
            });
        });
    }

    function openPblModal() { $('#pbl-modal').prop('hidden', false).attr('aria-hidden', 'false'); }
    function closePblModal() { $('#pbl-modal').prop('hidden', true).attr('aria-hidden', 'true'); }
    $('#pbl-modal-close').on('click', closePblModal);
    $('#pbl-modal').on('click', e => { if (e.target === e.currentTarget) closePblModal(); });
    $(document).on('keydown', e => { if (e.key === 'Escape') closePblModal(); });
    $('#pbl-modal').prop('hidden', true).attr('aria-hidden', 'true');

    function createPaymentLink(payload){
        return new Promise((resolve, reject) => {
          $.ajax({
            url: '/apis/payment_links_create.php',
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: resolve,
            error: (xhr, _s, e) => reject({ message: e, raw: xhr && xhr.responseText })
          });
        });
      }
      function renderPblResult(pbl){
        const url = pbl && pbl.url ? pbl.url : '';
        const exp = pbl && pbl.expiresAt ? pbl.expiresAt : '';
        $('#pbl-url').attr('href', url).text(url || '—');
        $('#pbl-meta').text(exp ? `Expires ${exp}` : 'Expires —');
      
        // QR plugin fallback
        const $qr = $('#pbl-qr').empty();
        if ($.fn.qrcode && url) {
          try {
            $qr.qrcode({ width: 220, height: 220, text: url });
          } catch (e) {
            $qr.text('QR unavailable');
          }
        } else {
          $qr.text('QR unavailable');
        }
      
        $('#pbl-copy').off('click').on('click', async function(){
          try {
            await navigator.clipboard.writeText(url);
            $(this).text('Copied').prop('disabled', true);
            setTimeout(() => { $('#pbl-copy').text('Copy').prop('disabled', false); }, 1200);
          } catch {
            alert('Copy failed');
          }
        });
      }
    //   function genOrderRef(){ return 'ord_' + Date.now() + String(Math.floor(Math.random()*1000)).padStart(3,'0'); }
      $('#pos-pbl').on('click', async function(e){
        e.preventDefault();
        const shopperRef = window.POS.getShopperRef && window.POS.getShopperRef();
        const { value, currency } = window.POS.getCartAmount ? window.POS.getCartAmount() : { value:0, currency:'AED' };
      
        if (!shopperRef) { showToast('Choose a customer to continue'); return; }
        if (!value)      { showToast('Your cart is empty'); return; }
      
        // Build a friendly description, optional
        const itemNames = Object.values(state.cart || {}).map(ci => ci.product.name);
        const desc = itemNames.length ? (itemNames[0] + (itemNames.length > 1 ? ` + ${itemNames.length-1} more` : '')) : 'Order';
      
        // Create the link
        const reference = genOrderRef();
        try {
          const res = await createPaymentLink({
            reference,
            shopperReference: shopperRef,
            amount: { value, currency },
            description: desc
          });
          if (!res || !res.ok) {
            showToast('Failed to create Pay by Link', 'Error');
            return;
          }
          renderPblResult(res);
          openPblModal();
        } catch (err) {
          console.error('PBL error', err);
          showToast('Could not create Pay by Link', 'Error');
        }
      });
                  

    function decodeReceiptLines(arr) { const lines = []; (arr || []).forEach(o => { const txt = (o && o.Text) || ''; const map = Object.fromEntries(txt.split('&').map(p => { const [k, v] = p.split('='); try { return [k, decodeURIComponent(v || '')] } catch { return [k, v || ''] } })); if (map.name && (map.value || map.key)) lines.push(`${map.name}${map.value ? ': ' + map.value : ''}`); else if (txt) lines.push(txt); }); return lines; }

    function renderTerminalResponse(resp) {
        const r = resp && resp.response && resp.response.SaleToPOIResponse; if (!r) { $('#pos-terminal-message').html('<div style="color:#b91c1c">Invalid response from terminal</div>'); return; }
        const pr = r.PaymentResponse || {}; const res = pr.Response || {}; const result = res.Result || 'Unknown';
        const poiData = pr.POIData || {}; const paymentResult = pr.PaymentResult || {}; const amountsResp = paymentResult.AmountsResp || {};
        const instr = paymentResult.PaymentInstrumentData || {}; const card = instr.CardData || {};
        const masked = card.MaskedPan || ''; const brand = card.PaymentBrand || '';
        const authCode = (paymentResult.PaymentAcquirerData && paymentResult.PaymentAcquirerData.ApprovalCode) || '';
        const txRef = (poiData.POITransactionID && poiData.POITransactionID.TransactionID) || '';
        const ts = (poiData.POITransactionID && poiData.POITransactionID.TimeStamp) || '';
        const receipts = Array.isArray(pr.PaymentReceipt) ? pr.PaymentReceipt : [];
        const cashier = receipts.find(x => x.DocumentQualifier === 'CashierReceipt') || null;
        const customer = receipts.find(x => x.DocumentQualifier === 'CustomerReceipt') || null;
        const cashierLines = cashier && cashier.OutputContent && Array.isArray(cashier.OutputContent.OutputText) ? decodeReceiptLines(cashier.OutputContent.OutputText) : [];
        const customerLines = customer && customer.OutputContent && Array.isArray(customer.OutputContent.OutputText) ? decodeReceiptLines(customer.OutputContent.OutputText) : [];
        const badge = (result === 'Success') ? '<span class="badge" style="background:#16a34a;color:#fff">APPROVED</span>' : `<span class="badge" style="background:#b91c1c;color:#fff">${result}</span>`;
        $('#pos-terminal-message').html(`
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div>${badge}</div>
          <div><strong>Amount:</strong> ${(amountsResp.AuthorizedAmount ?? '')} ${(amountsResp.Currency ?? '')}</div>
          <div><strong>Card:</strong> ${brand || ''} ${masked || ''}</div>
          <div><strong>Auth code:</strong> ${authCode || '—'}</div>
          <div><strong>Terminal Tx:</strong> ${txRef || '—'}</div>
          <div><strong>Time:</strong> ${ts || '—'}</div>
          ${(cashierLines.length || customerLines.length) ? `
            <div style="margin-top:8px">
              <details><summary style="cursor:pointer">View receipt (cashier)</summary><pre style="white-space:pre-wrap;margin:6px 0 0 0;font-size:12px;color:#334155">${cashierLines.join('\n')}</pre></details>
              <details style="margin-top:6px"><summary style="cursor:pointer">View receipt (customer)</summary><pre style="white-space:pre-wrap;margin:6px 0 0 0;font-size:12px;color:#334155">${customerLines.join('\n')}</pre></details>
            </div>` : ''}
        </div>`);
    }

    function extractPaymentDetails(resp) {
        const r = resp && resp.response && resp.response.SaleToPOIResponse;
        const pr = r ? r.PaymentResponse : null;
        const result = pr && pr.Response ? pr.Response.Result : 'Unknown';
        const paymentResult = pr && pr.PaymentResult ? pr.PaymentResult : {};
        const poiData = pr && pr.POIData ? pr.POIData : {};
        const instr = paymentResult.PaymentInstrumentData || {};
        const card = instr.CardData || {};
        const acq = paymentResult.PaymentAcquirerData || {};
        const amountsResp = paymentResult.AmountsResp || {};

        const ar = pr && pr.Response && pr.Response.AdditionalResponse ? pr.Response.AdditionalResponse : '';
        const map = {};
        (ar || '').split('&').forEach(pair => {
            const idx = pair.indexOf('='); if (idx > -1) { const k = pair.slice(0, idx); const v = pair.slice(idx + 1); try { map[k] = decodeURIComponent(v); } catch { map[k] = v; } }
        });

        return {
            ok: result === 'Success',
            pspReference: map.pspReference || (acq.AcquirerTransactionID && acq.AcquirerTransactionID.TransactionID) || null,
            paymentBrand: card.PaymentBrand || map.paymentMethod || '',
            maskedPan: card.MaskedPan || map.cardSummary || '',
            authCode: (acq && acq.ApprovalCode) || map.authCode || '',
            terminalTxRef: (poiData.POITransactionID && poiData.POITransactionID.TransactionID) || '',
            deviceTime: (poiData.POITransactionID && poiData.POITransactionID.TimeStamp) || '',
            authorizedAmount: amountsResp.AuthorizedAmount || null,
            currency: amountsResp.Currency || null,
            storeCode: map.store || null
        };
    }

    // Generate e-com style order ref for POS too
    function genOrderRef() { return 'ord_' + Date.now() + String(Math.floor(Math.random() * 1000)).padStart(3, '0'); }

    $('#pos-checkout').on('click', async function (e) {
        e.preventDefault();

        const shopperRef = window.POS.getShopperRef();
        const termId = window.POS.getTerminalId();
        const { value, currency } = window.POS.getCartAmount();

        if (!shopperRef) { showToast('Choose a customer to continue'); return; }
        if (!termId) { showToast('Select a terminal'); return; }
        if (!value) { showToast('Your cart is empty'); return; }

        $('#pos-terminal-message').html(`
        <div style="display:flex;align-items:center;gap:10px;">
          <div class="spinner" style="width:16px;height:16px;border:2px solid #cbd5e1;border-top-color:#334155;border-radius:50%;animation:spin 0.8s linear infinite"></div>
          <div>Sending ${fmt(value, currency)} to terminal <strong>${termId}</strong>…</div>
        </div>
        <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
      `);
        openModal();

        // Use ord_* format for terminal SaleTransactionID.TransactionID
        const txId = genOrderRef();

        try {
            const resp = await payOnTerminal(termId, value, currency, txId);
            if (!resp || !resp.ok) {
                $('#pos-terminal-message').html('<div style="color:#b91c1c">Payment request failed</div>');
                if (resp && resp.error) $('#pos-terminal-message').append(`<pre style="white-space:pre-wrap;color:#334155">${(resp.error || '') + ' ' + (resp.details || '')}</pre>`);
                return;
            }

            renderTerminalResponse(resp);

            const details = extractPaymentDetails(resp);
            if (details.ok) {
                const items = Object.values(state.cart).map(ci => ({ productId: Number(ci.product.id), qty: Number(ci.qty) }));

                // Prefer the terminal echo, else keep our txId; both should be ord_* now
                const rspTxId = resp && resp.response &&
                    resp.response.SaleToPOIResponse &&
                    resp.response.SaleToPOIResponse.PaymentResponse &&
                    resp.response.SaleToPOIResponse.PaymentResponse.SaleData &&
                    resp.response.SaleToPOIResponse.PaymentResponse.SaleData.SaleTransactionID &&
                    resp.response.SaleToPOIResponse.PaymentResponse.SaleData.SaleTransactionID.TransactionID;

                const reference = rspTxId || txId;

                const payload = {
                    reference,
                    shopperReference: shopperRef,
                    amount: { value, currency },
                    items,
                    terminalId: termId,
                    pspReference: details.pspReference,
                    paymentBrand: details.paymentBrand,
                    maskedPan: details.maskedPan,
                    authCode: details.authCode,
                    terminalTxRef: details.terminalTxRef,
                    deviceTime: details.deviceTime,
                    storeCode: details.storeCode
                };

                try {
                    const saved = await createPosOrder(payload);
                    if (saved && saved.ok) {
                        showToast('POS order created', 'Success');
                        state.cart = {};
                        renderCart();
                    } else {
                        showToast('Paid on terminal but saving failed (no ok).', 'Save error');
                    }
                } catch (e2) {
                    console.error('Create POS order failed:', e2.raw || e2.message || e2);
                    showToast('Paid on terminal but saving failed. Check logs.', 'Save error');
                }
            }
        } catch (err) {
            console.error(err);
            $('#pos-terminal-message').html(`<div style="color:#b91c1c">Error sending to terminal</div><pre style="white-space:pre-wrap;color:#334155">${(err && err.message) || String(err)}</pre>`);
        }
    });

    function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
    function loadProducts() { return $.getJSON('/apis/products_list.php').then(res => { state.products = (res.data || []).map(p => ({ id: Number(p.id), sku: p.sku, name: p.name, price_minor: Number(p.price_minor), currency: p.currency || 'AED' })); renderProducts(); }); }
    function loadCustomers() { return $.getJSON('/apis/customers_list.php').then(res => { const $s = $('#pos-customer').empty().append('<option value="">-- Select customer --</option>'); (res.data || []).forEach(c => { const name = c.display_name || capitalize(String(c.email || '').split('@')[0] || ''); $('<option>').val(c.id).text(`${c.shopper_reference} — ${name}`).attr('data-ref', c.shopper_reference).attr('data-name', name).appendTo($s); }); }); }
    function normalizeTerminals(data) { const out = []; (data || []).forEach(t => { if (!t) return; if (typeof t === 'string') out.push({ terminal_id: t, label: t }); else if (typeof t === 'object') { const id = t.terminal_id || t.id || ''; const label = t.label || id; if (id) out.push({ terminal_id: id, label }); } }); return out; }
    function loadTerminals() { return $.getJSON('/apis/terminals_list.php').then(res => { const items = normalizeTerminals(res.data); const $s = $('#pos-terminal').empty().append('<option value="">-- Select terminal --</option>'); items.forEach(t => { $('<option>').val(t.terminal_id).text(t.label || t.terminal_id).attr('data-terminal-id', t.terminal_id).appendTo($s); }); }).catch(() => { const $s = $('#pos-terminal').empty().append('<option value="">-- Select terminal --</option>');['P400Plus-TEST', 'V400m-TEST'].forEach(id => $('<option>').val(id).text(id).attr('data-terminal-id', id).appendTo($s)); }); }

    $.when(loadProducts(), loadCustomers(), loadTerminals()).then(() => { renderCart(); });
})();
