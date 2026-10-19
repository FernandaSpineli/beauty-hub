// Beauty Hub demo storefront. One store per subdomain; everything goes
// through the JSON API (/api/v1), logged to the console as it happens.
// DOM is built with textContent only: API data never becomes HTML.

const store = JSON.parse(document.body.dataset.store);
document.documentElement.style.setProperty("--accent", store.theme_color);

const app = document.getElementById("app");
const nav = document.getElementById("nav");
const money = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
const brl = (cents) => money.format(cents / 100);

const SKIN = { normal: "Normal", dry: "Seca", oily: "Oleosa", combination: "Mista", sensitive: "Sensível" };
const STATUS = {
  pending_payment: "Aguardando pagamento", paid: "Pago", shipped: "Enviado",
  delivered: "Entregue", cancelled: "Cancelado", expired: "Expirado",
};
const MESSAGES = {
  INVALID_CREDENTIALS: "E-mail ou senha incorretos.",
  INSUFFICIENT_STOCK: "Não há unidades suficientes em estoque.",
  VARIANT_UNAVAILABLE: "Este item não está à venda.",
  CART_EMPTY: "Seu carrinho está vazio.",
  CART_NOT_READY: "Alguns itens do carrinho mudaram. Revise antes de finalizar.",
  COUPON_NOT_FOUND: "Cupom não encontrado.",
  COUPON_INACTIVE: "Este cupom não está ativo.",
  COUPON_EXPIRED: "Este cupom expirou.",
  COUPON_NOT_STARTED: "Este cupom ainda não começou a valer.",
  COUPON_EXHAUSTED: "Este cupom esgotou.",
  COUPON_MIN_SUBTOTAL: "O carrinho não atingiu o valor mínimo do cupom.",
  COUPON_ALREADY_USED: "Você já usou este cupom.",
  STORE_PAYMENTS_NOT_READY: "Esta loja ainda não está recebendo pagamentos.",
  PAYMENTS_UNAVAILABLE: "Pagamentos não estão configurados neste servidor (defina as chaves do Stripe).",
  ORDER_STATUS_CONFLICT: "O pedido mudou de situação. Atualize a página.",
  INVALID_RESET_TOKEN: "Este link expirou ou já foi usado. Peça um novo.",
  RATE_LIMITED: "Muitas tentativas. Aguarde um minuto.",
  UNAUTHENTICATED: "Entre na sua conta para continuar.",
  FORBIDDEN: "Você não tem acesso a esta área.",
};

// ---------------------------------------------------------------- API

class ApiError extends Error {
  constructor(status, error) {
    super(MESSAGES[error?.code] ?? error?.message ?? "Algo deu errado.");
    this.status = status;
    this.code = error?.code;
    this.details = error?.details ?? [];
  }
}

// A store just created on the platform page arrives with its owner's token
// in the URL fragment (never sent to a server); keep it and clean the URL.
const handoff = new URLSearchParams(location.hash.slice(1)).get("token");

const session = {
  get token() { try { return localStorage.getItem("token"); } catch { return null; } },
  set token(value) { try { value ? localStorage.setItem("token", value) : localStorage.removeItem("token"); } catch { /* private mode */ } },
  user: null,
};

async function api(method, path, { body, headers = {} } = {}) {
  const init = { method, headers: { Accept: "application/json", ...headers } };
  if (session.token) init.headers.Authorization = `Bearer ${session.token}`;
  if (body !== undefined) {
    init.headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body);
  }
  const response = await fetch(`/api/v1${path}`, init);
  const data = response.status === 204 ? null : await response.json().catch(() => null);
  console.info(`${method} /api/v1${path} → ${response.status}`, data);
  if (!response.ok) {
    if (response.status === 401 && session.token) {
      session.token = null;
      session.user = null;
    }
    throw new ApiError(response.status, data?.error);
  }
  return data;
}

if (handoff) {
  session.token = handoff;
  history.replaceState(null, "", "/admin/pagamentos");
}

// ---------------------------------------------------------------- DOM

function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === false || value === null || value === undefined) continue;
    if (key.startsWith("on")) el.addEventListener(key.slice(2), value);
    else if (key === "class") el.className = value;
    else el.setAttribute(key, value === true ? "" : value);
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return el;
}

const link = (href, ...children) => h("a", { href, "data-link": true }, ...children);

// replaceChildren() would print null/false as text: drop them first.
const present = (nodes) => nodes.flat().filter((n) => n !== null && n !== undefined && n !== false);

function render(...nodes) {
  app.replaceChildren(...present(nodes));
  window.scrollTo(0, 0);
}

function notice(message, kind = "info") {
  return h("p", { class: `notice ${kind}`, role: kind === "error" ? "alert" : "status" }, message);
}

function swatch(hex, name) {
  const dot = h("span", { class: "swatch", title: name, "aria-label": name });
  dot.style.background = hex;
  return dot;
}

function formToObject(form) {
  return Object.fromEntries(new FormData(form).entries());
}

// Runs a form submission, showing API errors next to the form.
function onSubmit(form, handler) {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button[type=submit]");
    form.querySelector(".notice")?.remove();
    button.disabled = true;
    try {
      await handler(formToObject(form));
    } catch (error) {
      const fields = error.details?.filter((d) => d.field).map((d) => d.message) ?? [];
      form.append(notice([error.message, ...fields].join(" "), "error"));
    } finally {
      button.disabled = false;
    }
  });
  return form;
}

// ---------------------------------------------------------------- router

const routes = [
  [/^\/$/, catalogPage],
  [/^\/produto\/([a-z0-9-]+)$/, productPage],
  [/^\/carrinho$/, cartPage],
  [/^\/checkout$/, checkoutPage],
  [/^\/pedidos$/, ordersPage],
  [/^\/pedidos\/([0-9a-f-]{36})$/, orderPage],
  [/^\/entrar$/, loginPage],
  [/^\/cadastro$/, registerPage],
  [/^\/esqueci-senha$/, forgotPage],
  [/^\/redefinir-senha$/, resetPage],
  [/^\/admin$/, adminPage],
  [/^\/admin\/pedidos\/([0-9a-f-]{36})$/, adminOrderPage],
  [/^\/admin\/estoque$/, adminStockPage],
  [/^\/admin\/pagamentos$/, adminPaymentsPage],
];

function go(path) {
  history.pushState(null, "", path);
  route();
}

async function route() {
  const path = location.pathname.replace(/\/+$/, "") || "/";
  const match = routes.find(([pattern]) => pattern.test(path));
  await refreshNav();
  if (!match) return render(h("h1", {}, "Página não encontrada"), link("/", "Voltar à loja"));
  try {
    await match[1](...path.match(match[0]).slice(1));
  } catch (error) {
    if (error.status === 401) return go(`/entrar?voltar=${encodeURIComponent(location.pathname)}`);
    render(notice(error.message, "error"));
  }
}

document.addEventListener("click", (event) => {
  const a = event.target.closest("a[data-link]");
  if (a && a.origin === location.origin && !event.metaKey && !event.ctrlKey) {
    event.preventDefault();
    go(a.getAttribute("href"));
  }
});
window.addEventListener("popstate", route);

async function currentUser() {
  if (!session.token) return null;
  if (!session.user) session.user = (await api("GET", "/me").catch(() => null))?.data ?? null;
  return session.user;
}

async function refreshNav() {
  const user = await currentUser();
  const manager = user && user.role !== "customer";
  nav.replaceChildren(...present([
    link("/", "Produtos"),
    link("/carrinho", "Carrinho"),
    user ? link("/pedidos", "Meus pedidos") : null,
    manager ? link("/admin", "Painel") : null,
    user
      ? h("button", { class: "linklike", onclick: logout }, `Sair (${user.name.split(" ")[0]})`)
      : link("/entrar", "Entrar"),
  ]));
}

async function logout() {
  await api("POST", "/auth/logout").catch(() => null);
  session.token = null;
  session.user = null;
  go("/");
}

function requireLogin() {
  if (session.token) return true;
  go(`/entrar?voltar=${encodeURIComponent(location.pathname)}`);
  return false;
}

// ---------------------------------------------------------------- catalog

async function catalogPage() {
  const params = new URLSearchParams(location.search);
  const [{ data: categories }, products] = await Promise.all([
    api("GET", "/categories"),
    api("GET", `/products?${params}`),
  ]);

  const filters = h("form", { class: "filters", role: "search" },
    h("input", { name: "q", type: "search", placeholder: "Buscar (aceita erros de digitação)", value: params.get("q") ?? "" }),
    h("select", { name: "category" }, h("option", { value: "" }, "Todas as categorias"),
      categories.flatMap((c) => [
        h("option", { value: c.slug, selected: params.get("category") === c.slug }, c.name),
        ...c.children.map((s) => h("option", { value: s.slug, selected: params.get("category") === s.slug }, `— ${s.name}`)),
      ])),
    h("select", { name: "skin_type" }, h("option", { value: "" }, "Qualquer pele"),
      Object.entries(SKIN).map(([value, label]) => h("option", { value, selected: params.get("skin_type") === value }, `Pele ${label.toLowerCase()}`))),
    h("select", { name: "sort" },
      [["", "Mais recentes"], ["price_asc", "Menor preço"], ["price_desc", "Maior preço"], ["name", "Nome"]].map(
        ([value, label]) => h("option", { value, selected: (params.get("sort") ?? "") === value }, label))),
    checkbox("vegan", "Vegano", params), checkbox("cruelty_free", "Cruelty-free", params), checkbox("in_stock", "Disponível", params),
    h("button", { class: "btn", type: "submit" }, "Filtrar"),
  );
  filters.addEventListener("submit", (event) => {
    event.preventDefault();
    const query = new URLSearchParams([...new FormData(filters)].filter(([, v]) => v !== ""));
    go(`/?${query}`);
  });

  const grid = products.data.length
    ? h("ul", { class: "grid" }, products.data.map(productCard))
    : notice("Nenhum produto encontrado.");

  render(h("h1", {}, store.name), filters, grid, pager(products.meta, params));
}

function checkbox(name, label, params) {
  return h("label", { class: "check" }, h("input", { type: "checkbox", name, value: "1", checked: params.get(name) === "1" }), label);
}

function productCard(p) {
  return h("li", { class: `card product ${p.in_stock ? "" : "dim"}` },
    link(`/produto/${p.slug}`,
      p.image_url ? h("img", { src: p.image_url, alt: "" }) : h("div", { class: "placeholder", "aria-hidden": "true" }, p.name[0]),
      h("span", { class: "muted small" }, p.brand.name),
      h("strong", {}, p.name),
      h("span", {}, `a partir de ${brl(p.from_price_cents)}`),
      p.shades.length ? h("span", { class: "swatches" }, p.shades.map((s) => swatch(s.hex, s.name))) : null,
      h("span", { class: "tags" }, p.is_vegan ? h("span", { class: "tag" }, "vegano") : null,
        p.is_cruelty_free ? h("span", { class: "tag" }, "cruelty-free") : null,
        p.in_stock ? null : h("span", { class: "tag warn" }, "esgotado")),
    ));
}

function pager(meta, params) {
  if (meta.last_page <= 1) return null;
  const page = (n) => { const q = new URLSearchParams(params); q.set("page", n); return `/?${q}`; };
  return h("nav", { class: "pager", "aria-label": "Páginas" },
    meta.current_page > 1 ? link(page(meta.current_page - 1), "← Anterior") : null,
    h("span", {}, `Página ${meta.current_page} de ${meta.last_page}`),
    meta.current_page < meta.last_page ? link(page(meta.current_page + 1), "Próxima →") : null);
}

async function productPage(slug) {
  const { data: p } = await api("GET", `/products/${slug}`);
  let selected = p.variants.find((v) => v.in_stock) ?? p.variants[0];

  const detail = h("div", { class: "variant-detail" });
  const quantity = h("input", { type: "number", min: "1", max: "20", value: "1", "aria-label": "Quantidade" });
  const add = h("button", { class: "btn", type: "button" }, "Adicionar ao carrinho");
  const message = h("div");

  const choose = (variant) => {
    selected = variant;
    for (const b of shades.querySelectorAll("button")) b.setAttribute("aria-pressed", b.dataset.id === variant.id);
    const price = [h("strong", { class: "price" }, brl(variant.price_cents))];
    if (variant.compare_at_price_cents) price.push(h("s", { class: "muted" }, brl(variant.compare_at_price_cents)));
    detail.replaceChildren(
      h("p", {}, ...price),
      variant.in_stock
        ? h("p", { class: "muted" }, variant.low_stock ? "Últimas unidades. " : "Em estoque. ",
            variant.ships_with_expiry ? `Validade do lote enviado: ${variant.ships_with_expiry.split("-").reverse().join("/")}.` : "")
        : h("p", { class: "warn-text" }, "Esgotado."));
    add.disabled = !variant.in_stock;
  };

  const shades = h("div", { class: "shade-picker", role: "group", "aria-label": "Tons e tamanhos" },
    p.variants.map((v) => h("button", { type: "button", class: "shade", "data-id": v.id, onclick: () => choose(v), disabled: false },
      v.shade_hex ? swatch(v.shade_hex, v.name) : null, v.name, v.in_stock ? null : " (esgotado)")));

  add.addEventListener("click", async () => {
    if (!requireLogin()) return;
    message.replaceChildren();
    try {
      await api("POST", "/cart/items", { body: { variant_id: selected.id, quantity: Number(quantity.value) } });
      message.append(notice("Adicionado ao carrinho. ", "ok"), link("/carrinho", "Ver carrinho →"));
    } catch (error) {
      message.append(notice(error.message, "error"));
    }
  });

  render(
    link("/", "← Produtos"),
    h("article", { class: "product-page" },
      h("div", {},
        h("p", { class: "muted" }, `${p.brand.name} · ${p.category.name}`),
        h("h1", {}, p.name),
        h("p", {}, p.description),
        shades, detail,
        h("p", { class: "buy" }, quantity, add), message,
        h("dl", { class: "facts" },
          p.skin_types.length ? [h("dt", {}, "Tipo de pele"), h("dd", {}, p.skin_types.map((s) => SKIN[s]).join(", "))] : null,
          [h("dt", {}, "Vegano"), h("dd", {}, p.is_vegan ? "Sim" : "Não")],
          [h("dt", {}, "Cruelty-free"), h("dd", {}, p.is_cruelty_free ? "Sim" : "Não")],
          p.ingredients ? [h("dt", {}, "Ingredientes (INCI)"), h("dd", {}, p.ingredients)] : null,
          p.anvisa_registration ? [h("dt", {}, "Registro Anvisa"), h("dd", {}, p.anvisa_registration)] : null,
        ),
      ),
    ),
  );
  choose(selected);
}

// ---------------------------------------------------------------- cart & checkout

const PROBLEMS = { unavailable: "Indisponível", insufficient_stock: "Estoque insuficiente" };

async function cartPage() {
  if (!requireLogin()) return;
  const { data: cart } = await api("GET", "/cart");
  if (!cart.items.length) return render(h("h1", {}, "Carrinho"), notice("Seu carrinho está vazio."), link("/", "Ver produtos"));

  const rerender = () => cartPage();
  const act = async (fn) => { try { await fn(); rerender(); } catch (error) { app.prepend(notice(error.message, "error")); } };

  const rows = cart.items.map((item) => h("tr", {},
    h("td", {}, item.shade_hex ? swatch(item.shade_hex, item.name) : null, ` ${item.product.name} — ${item.name}`,
      item.problem ? h("span", { class: "tag warn" }, PROBLEMS[item.problem]) : null),
    h("td", {}, h("input", { type: "number", min: "1", max: "20", value: item.quantity, "aria-label": "Quantidade",
      onchange: (e) => act(() => api("PATCH", `/cart/items/${item.variant_id}`, { body: { quantity: Number(e.target.value) } })) })),
    h("td", { class: "num" }, brl(item.line_total_cents)),
    h("td", {}, h("button", { class: "linklike", onclick: () => act(() => api("DELETE", `/cart/items/${item.variant_id}`)) }, "Remover")),
  ));

  const coupon = cart.coupon
    ? h("p", {}, `Cupom ${cart.coupon.code}`, cart.coupon.problem ? h("span", { class: "tag warn" }, MESSAGES[cart.coupon.problem.code] ?? cart.coupon.problem.message) : null,
        " ", h("button", { class: "linklike", onclick: () => act(() => api("DELETE", "/cart/coupon")) }, "remover"))
    : onSubmit(h("form", { class: "inline" }, h("input", { name: "code", placeholder: "Cupom (ex.: BEMVINDA10)", required: true }),
        h("button", { class: "btn quiet", type: "submit" }, "Aplicar")),
      async ({ code }) => { await api("PUT", "/cart/coupon", { body: { code } }); rerender(); });

  render(
    h("h1", {}, "Carrinho"),
    h("table", { class: "lines" }, h("tbody", {}, rows)),
    coupon,
    totals(cart),
    cart.can_checkout ? link("/checkout", h("span", { class: "btn" }, "Finalizar compra"))
      : notice("Ajuste os itens marcados para finalizar.", "error"),
  );
}

function totals(o) {
  return h("dl", { class: "totals" },
    h("dt", {}, "Subtotal"), h("dd", {}, brl(o.subtotal_cents)),
    o.discount_cents ? [h("dt", {}, "Desconto"), h("dd", {}, `− ${brl(o.discount_cents)}`)] : null,
    h("dt", {}, "Total"), h("dd", {}, h("strong", {}, brl(o.total_cents))));
}

const UF = "AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO".split(" ");

async function checkoutPage() {
  if (!requireLogin()) return;
  const { data: cart } = await api("GET", "/cart");
  // One key per checkout attempt: a double click or retry cannot place two orders.
  const idempotencyKey = crypto.randomUUID();
  const field = (name, label, attrs = {}) => h("label", {}, label, h("input", { name, required: true, ...attrs }));

  const form = onSubmit(h("form", { class: "form" },
    field("postal_code", "CEP", { inputmode: "numeric", placeholder: "00000-000" }),
    field("street", "Rua"), field("number", "Número"),
    h("label", {}, "Complemento", h("input", { name: "complement" })),
    field("district", "Bairro"), field("city", "Cidade"),
    h("label", {}, "UF", h("select", { name: "state", required: true }, UF.map((uf) => h("option", { value: uf, selected: uf === "SP" }, uf)))),
    h("button", { class: "btn", type: "submit" }, `Fechar pedido — ${brl(cart.total_cents)}`),
  ), async (address) => {
    if (!address.complement) delete address.complement;
    const { data: order } = await api("POST", "/orders", { body: { shipping_address: address }, headers: { "Idempotency-Key": idempotencyKey } });
    go(`/pedidos/${order.id}`);
  });

  render(h("h1", {}, "Entrega"), totals(cart), form);
}

// ---------------------------------------------------------------- orders

async function ordersPage() {
  if (!requireLogin()) return;
  const { data: orders } = await api("GET", "/orders");
  render(h("h1", {}, "Meus pedidos"), orders.length
    ? h("ul", { class: "list" }, orders.map((o) => h("li", {}, link(`/pedidos/${o.id}`, o.reference), ` · ${STATUS[o.status]} · ${brl(o.total_cents)}`)))
    : notice("Você ainda não fez pedidos."));
}

async function orderPage(id) {
  if (!requireLogin()) return;
  const params = new URLSearchParams(location.search);
  let { data: order } = await api("GET", `/orders/${id}`);

  // Back from Stripe: the order turns paid only when the signed webhook
  // arrives, so wait for it instead of trusting the redirect.
  if (params.get("pagamento") === "concluido" && order.status === "pending_payment") {
    render(h("h1", {}, "Confirmando pagamento…"), notice("O Stripe recebeu seu pagamento. Aguardando a confirmação assinada."));
    for (let i = 0; i < 15 && order.status === "pending_payment"; i++) {
      await new Promise((resolve) => setTimeout(resolve, 2000));
      ({ data: order } = await api("GET", `/orders/${id}`));
    }
  }

  const actions = h("p", { class: "buy" });
  const message = h("div");
  if (order.status === "pending_payment") {
    actions.append(
      h("button", { class: "btn", onclick: async () => {
        try { location.href = (await api("POST", `/orders/${id}/checkout`)).data.checkout_url; }
        catch (error) { message.replaceChildren(notice(error.message, "error")); }
      } }, "Pagar com Stripe"),
      h("button", { class: "btn quiet", onclick: async () => {
        try { await api("POST", `/orders/${id}/cancel`); orderPage(id); }
        catch (error) { message.replaceChildren(notice(error.message, "error")); }
      } }, "Cancelar pedido"));
  }

  render(
    link("/pedidos", "← Meus pedidos"),
    h("h1", {}, `Pedido ${order.reference}`),
    h("p", {}, h("span", { class: `status ${order.status}` }, STATUS[order.status]),
      order.status === "pending_payment" ? ` · reserva até ${new Date(order.expires_at).toLocaleString("pt-BR")}` : null),
    params.get("pagamento") === "cancelado" ? notice("Pagamento não concluído. Você pode tentar de novo.") : null,
    itemsTable(order), totals(order), actions, message,
    h("p", { class: "muted small" }, `Entrega: ${order.shipping_address.street}, ${order.shipping_address.number} — ${order.shipping_address.city}/${order.shipping_address.state}`),
  );
}

function itemsTable(order) {
  return h("table", { class: "lines" }, h("tbody", {}, order.items.map((i) => h("tr", {},
    h("td", {}, `${i.product_name} — ${i.variant_name}`), h("td", {}, `${i.quantity} ×`), h("td", { class: "num" }, brl(i.line_total_cents))))));
}

// ---------------------------------------------------------------- account

function afterLogin(data) {
  session.token = data.token;
  session.user = data.user;
  go(new URLSearchParams(location.search).get("voltar") ?? "/");
}

function loginPage() {
  render(h("h1", {}, "Entrar"), onSubmit(h("form", { class: "form narrow" },
    h("label", {}, "E-mail", h("input", { name: "email", type: "email", required: true, autocomplete: "email" })),
    h("label", {}, "Senha", h("input", { name: "password", type: "password", required: true, autocomplete: "current-password" })),
    h("button", { class: "btn", type: "submit" }, "Entrar"),
    h("p", {}, link("/cadastro", "Criar conta"), " · ", link("/esqueci-senha", "Esqueci minha senha")),
  ), async (body) => afterLogin((await api("POST", "/auth/login", { body: { ...body, device_name: "navegador" } })).data)));
}

function registerPage() {
  render(h("h1", {}, "Criar conta"), onSubmit(h("form", { class: "form narrow" },
    h("label", {}, "Nome", h("input", { name: "name", required: true, autocomplete: "name" })),
    h("label", {}, "E-mail", h("input", { name: "email", type: "email", required: true, autocomplete: "email" })),
    h("label", {}, "Senha (8+ caracteres, letras e números)", h("input", { name: "password", type: "password", required: true, autocomplete: "new-password" })),
    h("label", {}, "Confirme a senha", h("input", { name: "password_confirmation", type: "password", required: true, autocomplete: "new-password" })),
    h("button", { class: "btn", type: "submit" }, "Criar conta"),
  ), async (body) => afterLogin((await api("POST", "/auth/register", { body: { ...body, device_name: "navegador" } })).data)));
}

function forgotPage() {
  const form = onSubmit(h("form", { class: "form narrow" },
    h("label", {}, "E-mail", h("input", { name: "email", type: "email", required: true })),
    h("button", { class: "btn", type: "submit" }, "Enviar link"),
  ), async (body) => {
    await api("POST", "/auth/password/forgot", { body });
    form.replaceWith(notice("Se houver uma conta com este e-mail, o link chega em instantes (veja o Mailpit em localhost:8025).", "ok"));
  });
  render(h("h1", {}, "Esqueci minha senha"), form);
}

function resetPage() {
  const params = new URLSearchParams(location.search);
  const invite = params.get("convite") === "1";
  render(h("h1", {}, invite ? "Bem-vinda(o) à equipe" : "Nova senha"), onSubmit(h("form", { class: "form narrow" },
    h("input", { type: "hidden", name: "token", value: params.get("token") ?? "" }),
    h("label", {}, "E-mail", h("input", { name: "email", type: "email", required: true, value: params.get("email") ?? "" })),
    h("label", {}, "Nova senha", h("input", { name: "password", type: "password", required: true, autocomplete: "new-password" })),
    h("label", {}, "Confirme", h("input", { name: "password_confirmation", type: "password", required: true, autocomplete: "new-password" })),
    h("button", { class: "btn", type: "submit" }, invite ? "Criar minha senha" : "Salvar senha"),
  ), async (body) => {
    await api("POST", "/auth/password/reset", { body });
    render(notice("Senha definida. Entre com ela.", "ok"), link("/entrar", "Entrar"));
  }));
}

// ---------------------------------------------------------------- admin

async function adminPage() {
  if (!requireLogin()) return;
  const [{ data: d }, { data: toShip }] = await Promise.all([
    api("GET", "/admin/dashboard"),
    api("GET", "/admin/orders?status=paid"),
  ]);
  const stat = (label, value) => h("div", { class: "stat" }, h("span", { class: "muted small" }, label), h("strong", {}, value));

  render(
    h("h1", {}, "Painel"),
    adminTabs(),
    h("div", { class: "stats" },
      stat("Vendas hoje", brl(d.sales.today_cents)),
      stat("Últimos 30 dias", brl(d.sales.last_30_days_cents)),
      stat("Ticket médio", brl(d.sales.average_ticket_cents)),
      stat("A enviar", d.orders.to_ship),
      stat("Aguardando pagamento", d.orders.awaiting_payment),
      stat("Lotes fora de venda", d.stock.lots_out_of_sale)),
    h("h2", {}, "Pedidos pagos para enviar"),
    toShip.length
      ? h("ul", { class: "list" }, toShip.map((o) => h("li", {}, link(`/admin/pedidos/${o.id}`, o.reference), ` · ${o.customer.name} · ${brl(o.total_cents)}`)))
      : notice("Nenhum pedido esperando envio."),
    h("h2", {}, "Mais vendidos (30 dias)"),
    d.top_products_last_30_days.length
      ? h("ul", { class: "list" }, d.top_products_last_30_days.map((p) => h("li", {}, `${p.product_name}: ${p.units} un. · ${brl(p.revenue_cents)}`)))
      : notice("Nenhuma venda nos últimos 30 dias."),
  );
}

function adminTabs() {
  return h("nav", { class: "tabs" }, link("/admin", "Resumo"), link("/admin/estoque", "Validade"), link("/admin/pagamentos", "Pagamentos"));
}

async function adminOrderPage(id) {
  if (!requireLogin()) return;
  const { data: order } = await api("GET", `/admin/orders/${id}`);
  const message = h("div");
  const ship = order.status === "paid"
    ? onSubmit(h("form", { class: "inline" }, h("input", { name: "note", placeholder: "Código de rastreio (opcional)" }),
        h("button", { class: "btn", type: "submit" }, "Marcar como enviado")),
      async ({ note }) => { await api("PATCH", `/admin/orders/${id}/status`, { body: { status: "shipped", ...(note ? { note } : {}) } }); adminOrderPage(id); })
    : null;

  render(
    adminTabs(),
    h("h1", {}, `Pedido ${order.reference}`),
    h("p", {}, h("span", { class: `status ${order.status}` }, STATUS[order.status]), ` · ${order.customer.name} <${order.customer.email}>`),
    itemsTable(order), totals(order), ship, message,
    h("h2", {}, "Histórico"),
    h("ul", { class: "list" }, order.history.map((c) => h("li", {}, `${new Date(c.at).toLocaleString("pt-BR")} — ${STATUS[c.to]}${c.note ? ` (${c.note})` : ""}`))),
  );
}

async function adminStockPage() {
  if (!requireLogin()) return;
  const { data: lots } = await api("GET", "/admin/stock/expiring?within_days=90");
  render(
    h("h1", {}, "Validade dos lotes"),
    adminTabs(),
    h("p", { class: "muted" }, "Lotes com unidades em estoque que vencem nos próximos 90 dias. Os fora de venda já estão dentro da validade mínima da loja."),
    lots.length ? h("table", { class: "lines" }, h("tbody", {}, lots.map((lot) => h("tr", {},
      h("td", {}, `${lot.variant.product} — ${lot.variant.name}`), h("td", {}, `lote ${lot.lot_code}`),
      h("td", {}, new Date(`${lot.expires_on}T12:00:00`).toLocaleDateString("pt-BR")), h("td", { class: "num" }, `${lot.on_hand} un.`),
      h("td", {}, lot.sellable ? h("span", { class: "tag" }, "à venda") : h("span", { class: "tag warn" }, "fora de venda")))))) : notice("Nada vencendo nos próximos 90 dias."),
  );
}

async function adminPaymentsPage() {
  if (!requireLogin()) return;
  const message = h("div");
  let status;
  try {
    ({ data: status } = await api("GET", "/admin/payments"));
  } catch (error) {
    return render(h("h1", {}, "Pagamentos"), adminTabs(), notice(error.message, "error"));
  }
  render(
    h("h1", {}, "Pagamentos"),
    adminTabs(),
    h("p", {}, status.charges_enabled ? "Sua conta Stripe está recebendo pagamentos."
      : status.connected ? "Cadastro no Stripe em andamento." : "Conecte uma conta Stripe para receber pelos pedidos."),
    h("p", { class: "muted small" }, `Taxa da plataforma: ${(status.platform_fee_bps / 100).toLocaleString("pt-BR")}% por pedido.`),
    status.charges_enabled ? null : h("button", { class: "btn", onclick: async () => {
      try { location.href = (await api("POST", "/admin/payments/onboarding")).data.url; }
      catch (error) { message.replaceChildren(notice(error.message, "error")); }
    } }, status.connected ? "Continuar cadastro no Stripe" : "Conectar conta Stripe"),
    message,
  );
}

route();
