(() => {
  const apiBase = 'api.php';
  const state = {
    catalog: null,
    csrf: null,
  };

  const formatMoney = (value) => `$${Number(value || 0).toFixed(2)}`;

  async function fetchCatalog() {
    if (state.catalog) return state.catalog;

    const response = await fetch(`${apiBase}?action=catalog`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const payload = await response.json();
    state.catalog = payload.catalog;
    state.csrf = payload.csrf_token;
    return state.catalog;
  }

  async function fetchCart() {
    const response = await fetch(`${apiBase}?action=cart`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    return response.json();
  }

  function updateCartCount(count) {
    document.querySelectorAll('[data-cart-count]').forEach((el) => {
      el.textContent = count;
    });
  }

  function renderMenu(catalog) {
    const grid = document.querySelector('[data-menu-grid]');
    if (!grid) return;

    grid.innerHTML = '';
    catalog.coffees.forEach((coffee) => {
      const card = document.createElement('div');
      card.className = 'menu-card';
      card.innerHTML = `
        <strong>${coffee.name}</strong><br>
        <span class="muted">${coffee.description || ''}</span><br>
        Base: ${formatMoney(coffee.base_price)}
      `;
      grid.appendChild(card);
    });
  }

  function hydrateSelect(select, options, placeholder, formatter) {
    if (!select) return;
    const current = select.value;
    select.innerHTML = '';
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = placeholder;
    select.appendChild(defaultOpt);

    options.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.id;
      option.textContent = formatter(item);
      if (String(item.id) === current) {
        option.selected = true;
      }
      select.appendChild(option);
    });
  }

  function getSelectedIds(select) {
    return Array.from(select?.selectedOptions || []).map((opt) => Number(opt.value)).filter(Boolean);
  }

  function calculatePreview(catalog, form) {
    if (!catalog || !form) return null;

    const coffeeId = Number(form.elements['coffee_id']?.value || 0);
    const sizeId = Number(form.elements['size_id']?.value || 0);
    const quantity = Math.max(1, Math.min(12, Number(form.elements['quantity']?.value || 1)));

    const coffee = catalog.coffees.find((row) => Number(row.id) === coffeeId);
    const size = catalog.sizes.find((row) => Number(row.id) === sizeId);
    if (!coffee || !size) return null;

    const sweeteners = getSelectedIds(form.elements['sweeteners[]']);
    const creamers = getSelectedIds(form.elements['creamers[]']);

    const sweetenerLookup = Object.fromEntries(catalog.sweeteners.map((row) => [row.id, row]));
    const creamerLookup = Object.fromEntries(catalog.creamers.map((row) => [row.id, row]));

    const extrasPerCup = sweeteners.reduce(
      (total, id) => total + Number(sweetenerLookup[id]?.additional_cost || 0),
      0
    ) +
      creamers.reduce((total, id) => total + Number(creamerLookup[id]?.additional_cost || 0), 0);

    const basePrice = Number(coffee.base_price) + Number(size.price_modifier);
    const unitPrice = basePrice + extrasPerCup;
    const lineTotal = unitPrice * quantity;

    return {
      unitPrice,
      lineTotal,
      quantity,
    };
  }

  function updatePricePreview(catalog, form) {
    const preview = document.querySelector('[data-price-preview]');
    if (!preview) return;

    const calc = calculatePreview(catalog, form);
    if (!calc) {
      preview.textContent = 'Select a drink and size to see pricing.';
      return;
    }

    preview.textContent = `${calc.quantity} × unit ${formatMoney(calc.unitPrice)} = ${formatMoney(calc.lineTotal)}`;
  }

  function bindOrderForm(catalog) {
    const form = document.querySelector('[data-order-form]');
    if (!form) return;

    hydrateSelect(
      form.querySelector('select[name="coffee_id"]'),
      catalog.coffees,
      'Select a coffee',
      (item) => `${item.name} — ${formatMoney(item.base_price)}`
    );
    hydrateSelect(
      form.querySelector('select[name="size_id"]'),
      catalog.sizes,
      'Select a size',
      (item) => `${item.label} (${item.ounces} oz) — +${formatMoney(item.price_modifier)}`
    );

    ['coffee_id', 'size_id', 'quantity'].forEach((name) => {
      const field = form.elements[name];
      if (field) {
        field.addEventListener('change', () => updatePricePreview(catalog, form));
      }
    });

    ['sweeteners[]', 'creamers[]'].forEach((name) => {
      const field = form.elements[name];
      if (field) {
        field.addEventListener('change', () => updatePricePreview(catalog, form));
      }
    });

    updatePricePreview(catalog, form);

    form.addEventListener('submit', async (event) => {
      if (!window.fetch) return;
      event.preventDefault();

      const status = document.querySelector('[data-cart-status]');
      if (status) {
        status.textContent = 'Adding to cart...';
      }

      const formData = new FormData(form);
      formData.append('action', 'add_to_cart');
      formData.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || state.csrf || '');

      const response = await fetch(apiBase, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: formData,
      });

      const payload = await response.json();

      if (!response.ok || payload.errors) {
        if (status) {
          status.textContent = (payload.errors || [payload.error || 'Could not add to cart.']).join(' ');
        }
        return;
      }

      updateCartCount(payload.cart?.cart_count || 0);
      if (status) {
        status.textContent = payload.message || 'Added to cart.';
      }
    });
  }

  function renderCartItems(cart, csrfToken) {
    const container = document.getElementById('cartItems');
    const totals = document.getElementById('cartTotals');
    const status = document.getElementById('cartStatus');
    if (!container || !totals) return;

    container.innerHTML = '';
    if (!cart.items || cart.items.length === 0) {
      container.innerHTML = '<p class="muted">Your cart is empty.</p>';
      totals.textContent = '';
      updateCartCount(0);
      return;
    }

    cart.items.forEach((line) => {
      const row = document.createElement('div');
      row.className = 'cart-item';
      row.dataset.lineId = line.id;
      row.innerHTML = `
        <div>
          <strong>${line.coffee}</strong> — ${line.size}<br>
          <span class="muted">${line.sweeteners.join(', ') || 'No sweeteners'} | ${line.creamers.join(', ') || 'No creamers'}</span>
        </div>
        <div style="text-align:right;">
          <label class="muted">Qty
            <input type="number" min="1" max="12" value="${line.quantity}" data-quantity>
          </label>
          <div>${formatMoney(line.line_total)}</div>
          <button type="button" data-remove>Remove</button>
        </div>
      `;
      container.appendChild(row);
    });

    totals.textContent = `Cart total: ${formatMoney(cart.cart_total)}`;
    updateCartCount(cart.cart_count || 0);

    if (status) {
      status.textContent = '';
    }
  }

  async function updateCartLine(lineId, quantity, csrfToken) {
    const formData = new FormData();
    formData.append('action', 'update_cart_line');
    formData.append('line_id', lineId);
    formData.append('quantity', quantity);
    formData.append('csrf_token', csrfToken || state.csrf || '');

    const response = await fetch(apiBase, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: formData,
    });

    return response.json();
  }

  async function removeCartLine(lineId, csrfToken) {
    const formData = new FormData();
    formData.append('action', 'remove_cart_line');
    formData.append('line_id', lineId);
    formData.append('csrf_token', csrfToken || state.csrf || '');

    const response = await fetch(apiBase, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: formData,
    });

    return response.json();
  }

  async function bindCart(csrfToken) {
    const cartApp = document.querySelector('[data-cart-app]');
    if (!cartApp) return;

    const cartData = await fetchCart();
    renderCartItems(cartData, csrfToken);

    cartApp.addEventListener('change', async (event) => {
      const target = event.target;
      if (!target.matches('input[data-quantity]')) return;

      const lineId = target.closest('[data-line-id]')?.dataset.lineId;
      if (!lineId) return;

      const qty = Math.max(1, Math.min(12, Number(target.value || 1)));
      target.value = qty;
      const payload = await updateCartLine(lineId, qty, csrfToken);
      if (payload.cart) {
        renderCartItems(payload.cart, csrfToken);
      }
    });

    cartApp.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (!target.matches('button[data-remove]')) return;

      const lineId = target.closest('[data-line-id]')?.dataset.lineId;
      if (!lineId) return;

      const payload = await removeCartLine(lineId, csrfToken);
      if (payload.cart) {
        renderCartItems(payload.cart, csrfToken);
      }
    });
  }

  function bindLoginForm() {
    const form = document.querySelector('[data-login-form]');
    if (!form) return;

    const status = form.querySelector('[data-login-status]');

    form.addEventListener('submit', async (event) => {
      if (!window.fetch) return;
      event.preventDefault();

      if (status) {
        status.textContent = 'Signing in...';
      }

      const formData = new FormData(form);
      const response = await fetch(form.action || 'login.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: formData,
      });

      const payload = await response.json();
      if (!response.ok || payload.error) {
        if (status) status.textContent = payload.error || 'Login failed.';
        return;
      }

      if (status) status.textContent = payload.message || 'Logged in.';
      updateCartCount(payload.cart_count || document.querySelector('[data-cart-count]')?.textContent || 0);
      setTimeout(() => window.location.reload(), 400);
    });
  }

  async function init() {
    try {
      const catalog = await fetchCatalog();
      renderMenu(catalog);
      bindOrderForm(catalog);
      bindLoginForm();

      const cartApp = document.querySelector('[data-cart-app]');
      if (cartApp) {
        const csrfToken = cartApp.getAttribute('data-csrf') || state.csrf;
        await bindCart(csrfToken);
      } else {
        const cartData = await fetchCart();
        updateCartCount(cartData.cart_count || 0);
      }
    } catch (error) {
      console.error('JS init failed', error);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
