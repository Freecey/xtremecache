# ESI Page Cache (`esipagecache`)

Full page cache for **PrestaShop 1.6 / 1.7** that serves cached HTML **before the
front controller runs**, so the heavy category/product listing SQL — the source of
MariaDB *on-disk temporary tables* — is **never executed on a cache hit**.

Anonymous visitors only, GET requests only, `200 OK` only. Safe by default.

> Reworked from the abandoned [`xtremecache`](https://github.com/SimoneS93/xtremecache)
> by Simone Salerno (MIT). See `AUDIT.md` for the full audit trail (findings F1–F6
> + reviews v1.1 / v1.2 / v2.0).

---

## Why

On a shared ISPConfig host, an anonymous category page re-runs the PrestaShop listing
query (derived subquery + `GROUP BY` + `filesort`) on **every** page view because the
PS object/SQL cache is off. That query spills to **on-disk temp tables** and triggers
the Zabbix alert *"on-disk temporary tables high"*.

A classic full page cache that serves on `displayHeader` is **too late** — `initContent()`
(and its SQL) already ran. This module serves on **`actionDispatcherBefore`**, i.e.
*before* the controller is instantiated, so a cache hit costs ~0 SQL.

## How it works

```
Request ─▶ Dispatcher::dispatch()
              │
              ├─ Hook actionDispatcherBefore ──▶ [this module] cache HIT? ─▶ echo HTML; exit;   (no controller, no SQL)
              │                                                   │ MISS
              ▼                                                   ▼
        Controller::run() ─▶ initContent() (heavy SQL) ─▶ smartyOutputContent()
                                                                  │
                                          [override] Hook actionRequestComplete ─▶ [this module] store(html, tags)
```

* **Serve** — `hookActionDispatcherBefore()` returns the cached page and `exit`s.
* **Store** — the `Controller` override emits `actionRequestComplete` with the final
  HTML; `hookActionRequestComplete()` writes it to disk, tagged for invalidation.
* **Invalidate** — product save/add/update → **targeted** purge (`product:<id>` +
  its categories + `listing`); product delete / any category change → safe **full flush**;
  BO *Clear cache* button → full flush (via the `AdminPerformanceController` override).

## What gets cached

A request is cached only when **all** hold:

| Condition | Reason |
|---|---|
| Front office (not `_PS_ADMIN_DIR_`) | back office is never cached |
| Not `_PS_MODE_DEV_` | dev mode off |
| `PS_SHOP_ENABLE` on | skip maintenance/catalog mode |
| `GET`, non-AJAX | no POST/AJAX |
| Anonymous (`!id_customer`, `!logged`) | per-customer content is never shared |
| Empty cart | never serve a stale cart block |
| `php_self` in allow-list | read-only catalog pages only (see below) |
| HTTP `200` | never cache errors/redirects |

**Cacheable pages** (`CACHEABLE_PAGES`): `index`, `category`, `product`, `manufacturer(s)`,
`supplier(s)`, `new-products`, `best-sales`, `prices-drop`, `cms`, `stores`, `sitemap`.
Form/token pages (contact, auth, cart, order, search…) are intentionally **excluded**.

**Cache key** = `md5(normalized_uri | id_lang | id_currency | id_shop | device)`.
Tracking params (`utm_*`, `fbclid`, `gclid`, `mc_`, `_ga`, `_hsenc`, `_hsmi`) are stripped
and the query string is sorted, so equivalent URLs share one entry. The key is computed
**once** at dispatch and reused at store time, so it cannot diverge if the controller
switches language/currency during `init()` (which would otherwise cause permanent misses
on multilingual / multi-currency shops).

## Storage

Plain files under `var/cache/<env>/esipagecache/` (`_PS_CACHE_DIR_`), **not** Memcached —
no eviction, no pollution of the shared object cache. Writes are **atomic** (`tmp` + `rename`).
A per-tag reverse index (`.../tags/<tag>/<key>`) makes a targeted purge `O(entries-of-tag)`
instead of scanning the whole cache. Default TTL: **7 days**.

## Install

This module ships **two overrides** (required):

```
override/classes/controller/FrontController.php             # emits actionRequestComplete (store source)
override/controllers/admin/AdminPerformanceController.php   # emits actionEmptySmartyCache (BO clear-cache)
```

> The store override is on **FrontController** (not Controller): `FrontControllerCore`
> defines its own `smartyOutputContent()` which shadows any `Controller` override, so a
> `Controller` override never fires for front pages. Verified on a real PrestaShop 1.7.6.1.

1. Copy the `esipagecache/` folder into `modules/`.
2. Install from the BO (*Modules*) or `php bin/console prestashop:module install esipagecache`.
   PrestaShop installs the overrides automatically; the custom `actionRequestComplete`
   hook is created on install.
3. If another module already overrides `Controller` or `AdminPerformanceController`,
   PrestaShop refuses the conflicting override and the install fails — merge the two
   overrides manually in that case.

Uninstall removes the hooks and flushes the cache.

## Configuration

Tunables are class constants in `esipagecache.php`:

| Constant | Default | Purpose |
|---|---|---|
| `TTL` | `3600` (1 h) | entry lifetime **and** freshness backstop for un-hooked changes (see below) |
| `DEBUG_HEADER` | `true` | send `X-Esipagecache: HIT` on a cache hit |
| `CACHEABLE_PAGES` | catalog pages | which `php_self` may be cached |
| `EXCLUDED_LANGS` / `EXCLUDE_CURRENCIES` / `EXCLUDE_SHOPS` | `[]` | per-context opt-out |

Verify a hit: `curl -sI https://shop/category/... | grep -i x-esipagecache`.

## Limitations / known trade-offs

* **Gains apply to anonymous/bot traffic only.** Logged-in customers and non-empty carts
  always bypass the cache (by design).
* **Invalidation coverage**: the explicit hooks purge **product** (save/add/update/delete)
  and **category** changes immediately. They do **not** cover specific prices / catalog
  price rules (promos), stock crossing to zero, CMS content, supplier/manufacturer edits,
  or theme/module changes — those become fresh only when the entry expires. **`TTL` is the
  backstop**: keep it short (default 1 h) on a live catalog, or clear the cache from the BO
  after a bulk promo/price change.
* **Depends on the `FrontController::smartyOutputContent` override** for the store path. If the
  override is not taken on a given PS build, the module **caches nothing** but breaks nothing
  (fail-safe).
* **Product moved between categories**: the *old* category page expires by TTL only (purge
  targets the product's *current* categories). Rare.
* **No background GC / unbounded growth**: each distinct query string creates a separate
  entry and expired files are only removed on access (TTL) or on flush. A crawler (or an
  attacker) hitting `?x=1`, `?x=2`… can fill the disk. **Mitigate with a cron purge**, a disk
  quota, and monitoring of `var/cache/<env>/esipagecache/`. Not bounded by a param allow-list
  on purpose (would break pagination / sort / layered navigation, all legitimate params).
* **Device in key**: triples entries on themes that serve different HTML per device; harmless
  on a fully responsive theme.

## Compatibility

* **PrestaShop**: 1.6 - 1.7.x (`ps_versions_compliancy` max `1.7.99.99`).
* **PHP**: syntax verified `php -l` on **7.0, 7.2, 7.4, 8.0, 8.1, 8.2** (module + both overrides).
  PrestaShop 1.7.6 itself caps the runtime at PHP 7.2/7.3 — the environment is the limit, not the module.

> ✅ **Functionally validated** on an isolated PrestaShop **1.7.6.1** copy (real shop DB,
> PHP 7.3): install/uninstall, byte-identical render parity (miss vs hit), exclusions
> (AJAX/POST/logged-in/non-empty cart), targeted invalidation on product update, and a
> real-browser render check (Playwright). Measured on a category page (788 products):
> a cache **hit creates 0 on-disk temp tables vs 7 without the cache**, and SELECTs drop
> from ~773 to ~91 (the heavy listing query no longer runs). For a long-lived production
> deployment, also weigh a maintained commercial FPC module.

## License

MIT — original author Simone Salerno; PS1.7 rework (c) ESI (Cedric AUDRIT).
