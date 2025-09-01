(function () {
    const titleMap = {
      dashboard: "Dashboard",
      customers: "Customers",
      orders: "Orders",
      stores: "Stores",
      products: "Products",
      invoices: "Invoices",
      settlements: "Settlements",
      reconciliation: "Reconciliation",
      payouts: "Payouts",
      chargebacks: "Chargebacks"
    };
  
    function loadComponent(name) {
      $("#pageTitle").text(titleMap[name] || name);
      $("#app").html("Loading…");
      $("#app").load(`/components/${name}.php`, function (res, status, xhr) {
        if (status === "error") {
          $("#app").html(`<div class="card"><h3>Error</h3><p>${xhr.status} ${xhr.statusText}</p></div>`);
        }
      });
      $(".sidebar nav a").removeClass("active").filter(`[data-route="${name}"]`).addClass("active");
    }
  
    // load order detail with id
    function loadOrderDetail(id) {
      $("#pageTitle").text("Order Details");
      $("#app").html("Loading…");
      $("#app").load(`/components/order_detail.php?id=${encodeURIComponent(id)}`, function (res, status, xhr) {
        if (status === "error") {
          $("#app").html(`<div class="card"><h3>Error</h3><p>${xhr.status} ${xhr.statusText}</p></div>`);
        }
      });
      $(".sidebar nav a").removeClass("active").filter(`[data-route="orders"]`).addClass("active");
    }
  
    // sidebar nav
    $(document).on("click", ".sidebar nav a", function (e) {
      e.preventDefault();
      const route = $(this).data("route");
      history.replaceState({}, "", `#${route}`);
      loadComponent(route);
    });
  
    // clicking an order in the list
    $(document).on("click", ".order-link", function (e) {
      e.preventDefault();
      const id = $(this).data("id");
      history.replaceState({}, "", `#orders/${id}`);
      loadOrderDetail(id);
    });
  
    // initial route parsing: #orders/123 or #orders
    function boot() {
      const hash = (location.hash || "#dashboard").slice(1); // e.g., "orders/123"
      const parts = hash.split("/");
      if (parts[0] === "orders" && parts[1]) {
        loadOrderDetail(parts[1]);
      } else {
        loadComponent(parts[0] || "dashboard");
      }
    }
  
    boot();
  })();
  