<?php
/**
 * Full page cache for PrestaShop 1.6 / 1.7 — anonymous visitors only.
 *
 * @author Simone Salerno (original)
 * @author ESI / Cedric AUDRIT (PS1.7 rework: early serve, filesystem store, targeted invalidation)
 *
 * PHP compatibility: 7.0 -> 8.x (no typed properties, arrow fns, ??= or other 7.1+/7.4+ only syntax).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Xtremecache extends Module
{
    /** @var int cache TTL in seconds */
    const TTL = 3600 * 24 * 7;

    /** @var bool send a debug header on cache hit */
    const CUSTOMER_HEADER = true;

    /** @var int[] languages to skip */
    const EXCLUDED_LANGS = array();

    /** @var int[] currencies to skip */
    const EXCLUDE_CURRENCIES = array();

    /** @var int[] shops to skip */
    const EXCLUDE_SHOPS = array();

    public function __construct()
    {
        $this->name = 'xtremecache';
        $this->tab = 'front_office_features';
        $this->version = '1.2.0';
        $this->author = 'Simone Salerno / ESI';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Xtreme cache');
        $this->description = $this->l('Full page cache for PrestaShop (anonymous visitors).');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.7.99.99');
    }

    public function install()
    {
        $this->createHooks();

        return parent::install()
            && $this->registerHook('actionDispatcherBefore')   // F2: serve cache before controller init
            && $this->registerHook('actionRequestComplete')     // store rendered page (via Controller override)
            && $this->registerHook('actionProductAdd')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionProductSave')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('actionCategoryAdd')
            && $this->registerHook('actionCategoryUpdate')
            && $this->registerHook('actionCategoryDelete');
    }

    public function uninstall()
    {
        $this->flush();

        return parent::uninstall()
            && $this->unregisterHook('actionDispatcherBefore')
            && $this->unregisterHook('actionRequestComplete')
            && $this->unregisterHook('actionProductAdd')
            && $this->unregisterHook('actionProductUpdate')
            && $this->unregisterHook('actionProductSave')
            && $this->unregisterHook('actionProductDelete')
            && $this->unregisterHook('actionCategoryAdd')
            && $this->unregisterHook('actionCategoryUpdate')
            && $this->unregisterHook('actionCategoryDelete');
    }

    /**
     * F2 — serve the cached page BEFORE the controller runs, so the
     * expensive category/product SQL (the source of on-disk temp tables)
     * is never executed on a cache hit.
     *
     * @param array $params
     */
    public function hookActionDispatcherBefore($params)
    {
        if (!$this->isCacheableRequest()) {
            return;
        }

        $html = $this->load($this->key());
        if ($html !== false) {
            if (static::CUSTOMER_HEADER) {
                header('X-Xtremecache: HIT');
            }
            echo $html;
            exit;
        }
    }

    /**
     * Store the fully rendered page (emitted by the Controller override
     * through the custom "actionRequestComplete" hook), with cache tags
     * for targeted invalidation.
     *
     * @param array $params
     */
    public function hookActionRequestComplete(array $params)
    {
        if (!isset($params['output']) || $params['output'] === '') {
            return;
        }
        // never cache error pages (404, 500, redirects...)
        if (function_exists('http_response_code') && http_response_code() !== 200) {
            return;
        }
        if (!$this->isCacheableRequest()) {
            return;
        }

        $this->store($this->key(), $params['output'], $this->collectTags());
    }

    /* ----------------------------------------------------------------------
     * F4 — targeted invalidation
     * Frequent events (product save/add/update) purge only the affected
     * entries; rare structural events (product delete, any category change)
     * fall back to a safe full flush.
     * -------------------------------------------------------------------- */

    public function hookActionProductSave($params)
    {
        $this->invalidateProduct($params);
    }

    public function hookActionProductAdd($params)
    {
        $this->invalidateProduct($params);
    }

    public function hookActionProductUpdate($params)
    {
        $this->invalidateProduct($params);
    }

    public function hookActionProductDelete($params)
    {
        // categories may already be detached on delete -> safe full flush
        $this->flush();
    }

    public function hookActionCategoryAdd($params)
    {
        $this->flush();         // structure change affects the global menu
    }

    public function hookActionCategoryUpdate($params)
    {
        $this->flush();
    }

    public function hookActionCategoryDelete($params)
    {
        $this->flush();
    }

    /**
     * Cacheability test usable BOTH at dispatch time and at store time:
     * relies only on cookie/request data available early in the request.
     *
     * @return bool
     */
    private function isCacheableRequest()
    {
        if (defined('_PS_ADMIN_DIR_')) {
            return false;                                   // front office only
        }
        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            return false;                                   // skip in dev mode
        }
        if (!Configuration::get('PS_SHOP_ENABLE')) {
            return false;                                   // skip catalog/maintenance mode
        }
        if (strtoupper((string) filter_input(INPUT_SERVER, 'REQUEST_METHOD')) !== 'GET') {
            return false;                                   // GET only
        }
        if (Tools::getValue('ajax')) {
            return false;                                   // never cache AJAX
        }

        $cookie = $this->context->cookie;

        if (!empty($cookie->id_customer) || !empty($cookie->logged)) {
            return false;                                   // anonymous only
        }

        // empty cart only — avoids serving a stale cart block.
        // Cheap indexed lookup, negligible next to the query we save.
        if (!empty($cookie->id_cart)) {
            $cart = new Cart((int) $cookie->id_cart);
            if (Validate::isLoadedObject($cart) && (int) $cart->nbProducts() > 0) {
                return false;
            }
        }

        if (in_array((int) $cookie->id_lang, static::EXCLUDED_LANGS, true)) {
            return false;
        }
        if (in_array((int) $cookie->id_currency, static::EXCLUDE_CURRENCIES, true)) {
            return false;
        }
        if (in_array((int) $this->context->shop->id, static::EXCLUDE_SHOPS, true)) {
            return false;
        }

        return true;
    }

    /**
     * Cache key — built ONLY from data identical at dispatch and store time.
     *
     * @return string
     */
    private function key()
    {
        $cookie = $this->context->cookie;

        return md5(implode('|', array(
            $this->normalizedUri(),
            (int) $cookie->id_lang,
            (int) $cookie->id_currency,
            (int) $this->context->shop->id,
            (int) $this->context->getDevice(),
        )));
    }

    /**
     * Request URI with tracking params stripped (utm_*, fbclid, gclid...).
     *
     * @return string
     */
    private function normalizedUri()
    {
        $uri = (string) filter_input(INPUT_SERVER, 'REQUEST_URI');
        $parts = explode('?', $uri, 2);
        if (count($parts) < 2) {
            return $uri;
        }

        $query = array();
        parse_str($parts[1], $query);
        foreach (array_keys($query) as $param) {
            if (preg_match('/^(utm_|fbclid|gclid|mc_|_ga|_hsenc|_hsmi)/i', (string) $param)) {
                unset($query[$param]);
            }
        }
        ksort($query);
        $qs = http_build_query($query);

        return $parts[0] . ($qs !== '' ? '?' . $qs : '');
    }

    /**
     * Tags describing the current page, for targeted invalidation.
     * NB: category pages are tagged ONLY with their own id (never a broad
     * "listing" tag) so a single product update does not purge every category.
     *
     * @return string[]
     */
    private function collectTags()
    {
        $tags = array();
        $controller = $this->context->controller;
        $self = isset($controller->php_self) ? $controller->php_self : '';

        if ($self === 'category') {
            $id = (int) Tools::getValue('id_category');
            if ($id > 0) {
                $tags[] = 'category:' . $id;
            }
        } elseif ($self === 'product') {
            $idp = (int) Tools::getValue('id_product');
            if ($idp > 0) {
                $tags[] = 'product:' . $idp;
                foreach ((array) Product::getProductCategories($idp) as $idc) {
                    $tags[] = 'category:' . (int) $idc;
                }
            }
        } elseif (in_array($self, array('index', 'new-products', 'best-sales', 'prices-drop', 'search', 'manufacturer', 'supplier'), true)) {
            $tags[] = 'listing';
        }

        return $tags;
    }

    /**
     * Purge cache entries affected by a product change.
     *
     * @param array $params
     */
    private function invalidateProduct($params)
    {
        $id = 0;
        if (isset($params['id_product'])) {
            $id = (int) $params['id_product'];
        } elseif (isset($params['product']) && isset($params['product']->id)) {
            $id = (int) $params['product']->id;
        }

        $tags = array('listing');           // home / new-products / best-sales / prices-drop ...
        if ($id > 0) {
            $tags[] = 'product:' . $id;
            foreach ((array) Product::getProductCategories($id) as $idc) {
                $tags[] = 'category:' . (int) $idc;
            }
        }

        $this->purgeTags($tags);
    }

    /**
     * @return string
     */
    private function getCacheDir()
    {
        $dir = _PS_CACHE_DIR_ . 'xtremecache' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Reverse index directory for a tag (so a purge is O(entries-of-tag),
     * not O(whole-cache) — important during bulk imports).
     *
     * @param string $tag
     * @return string
     */
    private function tagDir($tag)
    {
        return $this->getCacheDir() . 'tags' . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-z0-9_]+/i', '_', $tag) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $key
     * @return string|false
     */
    private function load($key)
    {
        $file = $this->getCacheDir() . $key . '.html';
        if (!is_file($file)) {
            return false;
        }
        if ((time() - (int) @filemtime($file)) > static::TTL) {
            @unlink($file);

            return false;
        }

        return @file_get_contents($file);
    }

    /**
     * @param string   $key
     * @param string   $html
     * @param string[] $tags
     * @return bool
     */
    private function store($key, $html, array $tags)
    {
        $payload = $html . "\n<!-- xtremecache " . date('Y-m-d H:i:s') . " -->";
        $file = $this->getCacheDir() . $key . '.html';

        // atomic write: temp file + rename, so a concurrent reader never
        // gets a half-written page.
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        foreach (array_unique($tags) as $tag) {
            $dir = $this->tagDir($tag);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @touch($dir . $key);
        }

        return true;
    }

    /**
     * Delete every cache entry referenced by any of the given tags.
     *
     * @param string[] $tags
     * @return bool
     */
    private function purgeTags(array $tags)
    {
        foreach (array_unique($tags) as $tag) {
            $dir = $this->tagDir($tag);
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir . '*') as $marker) {
                $key = basename($marker);
                @unlink($this->getCacheDir() . $key . '.html');
                @unlink($marker);
            }
            @rmdir($dir);
        }

        return true;
    }

    /**
     * Delete the whole cache (pages + tag index).
     *
     * @return bool
     */
    private function flush()
    {
        $dir = $this->getCacheDir();
        foreach ((array) glob($dir . '*.html') as $file) {
            @unlink($file);
        }

        $tagsRoot = $dir . 'tags' . DIRECTORY_SEPARATOR;
        foreach ((array) glob($tagsRoot . '*', GLOB_ONLYDIR) as $tagDir) {
            foreach ((array) glob($tagDir . DIRECTORY_SEPARATOR . '*') as $marker) {
                @unlink($marker);
            }
            @rmdir($tagDir);
        }
        @rmdir($tagsRoot);

        return true;
    }

    /**
     * Register the custom "actionRequestComplete" hook used by the
     * Controller override to capture the rendered HTML.
     */
    private function createHooks()
    {
        if (!Hook::getIdByName('actionRequestComplete')) {
            $hook = new Hook();
            $hook->name = 'actionRequestComplete';
            $hook->title = 'actionRequestComplete';
            $hook->description = 'Full rendered page (xtremecache store)';
            $hook->position = 1;
            $hook->save();
        }
    }
}
