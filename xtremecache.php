<?php
/**
 * Full page cache for PrestaShop 1.6 / 1.7 — anonymous visitors only.
 *
 * @author Simone Salerno (original)
 * @author ESI / Cedric AUDRIT (PS1.7 rework: early serve at dispatcher, filesystem store)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Xtremecache extends Module
{
    /** @var int cache TTL in seconds */
    const TTL = 3600 * 24 * 7;

    /** @var bool flush whole cache on catalog updates */
    const REACTIVE = true;

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
        $this->version = '1.1.0';
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
            && $this->registerHook('actionCategoryAdd')
            && $this->registerHook('actionCategoryUpdate')
            && $this->registerHook('actionCategoryDelete')
            && $this->registerHook('actionProductAdd')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('actionProductSave');
    }

    public function uninstall()
    {
        $this->flush();

        return parent::uninstall()
            && $this->unregisterHook('actionDispatcherBefore')
            && $this->unregisterHook('actionRequestComplete')
            && $this->unregisterHook('actionCategoryAdd')
            && $this->unregisterHook('actionCategoryUpdate')
            && $this->unregisterHook('actionCategoryDelete')
            && $this->unregisterHook('actionProductAdd')
            && $this->unregisterHook('actionProductUpdate')
            && $this->unregisterHook('actionProductDelete')
            && $this->unregisterHook('actionProductSave');
    }

    /**
     * F2 — serve the cached page BEFORE the controller runs, so the
     * expensive category/product SQL (the source of on-disk temp tables)
     * is never executed on a cache hit.
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
     * through the custom "actionRequestComplete" hook).
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

        $this->store($this->key(), $params['output']);
    }

    /**
     * Catalog-change hooks (actionProduct / actionCategory ...) are not defined
     * as real methods, so they land here -> flush the whole cache (safe: no
     * stale page). Targeted invalidation is a possible future improvement.
     */
    public function __call($name, $arguments)
    {
        if (static::REACTIVE && 0 === strpos(strtolower($name), 'hookaction')) {
            $this->flush();

            return null;
        }

        return parent::__call($name, $arguments);
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
     * Cache key — built ONLY from data identical at dispatch and store time,
     * so a page stored on a miss is found again on the next request.
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
     * Request URI with tracking params stripped, to avoid cache fragmentation
     * (utm_*, fbclid, gclid, mc_*, _ga...).
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
     * @param string $key
     * @param string $html
     * @return bool
     */
    private function store($key, $html)
    {
        $payload = $html . "\n<!-- xtremecache " . date('Y-m-d H:i:s') . " -->";

        return (bool) @file_put_contents($this->getCacheDir() . $key . '.html', $payload, LOCK_EX);
    }

    /**
     * Delete every cached page.
     *
     * @return bool
     */
    private function flush()
    {
        foreach ((array) glob($this->getCacheDir() . '*.html') as $file) {
            @unlink($file);
        }

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
