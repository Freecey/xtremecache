# Audit — module `esipagecache` (ex-xtremecache, full page cache PrestaShop)

**Auditeur :** ESI (Cedric AUDRIT) — 2026-06-29
**Renommage :** le module est renommé `xtremecache` → **`esipagecache`** (classe `Esipagecache`, auteur ESI, v2.0.0) à partir de la review v2.0 — voir section dédiée.
**Source :** fork de `SimoneS93/xtremecache` (branche master, dernier commit upstream 2020-08-02 « abandoned »)
**Cible :** PrestaShop **1.7.6.1**, **PHP 7.2**, serveur **Apache**, cache PS = **Memcached** (64 Mo partagé)
**Objectif métier :** réduire les *temp tables sur disque* MariaDB causées par la requête de listing catégorie (cf. site all4auto/Multicolor).

---

## Synthèse

| # | Constat | Gravité | Statut |
|---|---|---|---|
| F1 | `ps_versions_compliancy` plafonné à `1.6.99.99` → **n'installe pas** sur 1.7.6 | 🔴 bloquant | ✅ **corrigé** (→ 1.7.99.99) |
| F2 | Cache servi sur `hookDisplayHeader` = **après** `initContent()` → la requête SQL lourde **a déjà tourné** | 🔴 majeur | ✅ **corrigé** (serve sur `actionDispatcherBefore`) — **à tester sur 1.7.6** |
| F3 | Module écoute `actionResponse`/`html` alors que l'override émet `actionRequestComplete`/`output` → **rien n'est stocké** | 🟠 bug fonctionnel | ✅ **corrigé** |
| F4 | Invalidation = `flush()` **total** du cache à chaque modif produit/catégorie | 🟡 crude | ✅ **corrigé** (invalidation **ciblée par tags** sur maj produit ; flush total pour delete/catégorie) |
| F5 | Stockage via `Cache::getInstance()` = **Memcached 64 Mo partagé** entre ~168 sites | 🟠 dimensionnement | ✅ **corrigé** (stockage **filesystem** dédié) |
| F6 | Override de `Controller::smartyOutputContent` (API 1.6) ; libs `vendor/` (phpfastcache, predis) **inutilisées** | 🟡 robustesse/poids | ✅ `vendor/` **supprimé** ; override **conservé, à valider sur 1.7.6** |
| + | Améliorations : **200-only**, **panier vide** garanti, normalisation params tracking (utm/fbclid…) | 🟢 | ✅ ajouté |

---

## Détail

### F1 — Compatibilité PS 1.7 (corrigé)
`ps_versions_compliancy` valait `min 1.6 / max 1.6.99.99` → PrestaShop 1.7.6 refuse l'installation.
**Correction :** `max` porté à `1.7.99.99`.

### F2 — Le cache est servi trop tard (MAJEUR, non corrigé)
Le module sert la page cachée dans `hookDisplayHeader()` (`die($html)`). Or `displayHeader` est exécuté **pendant le rendu**
du template, donc **après** `FrontController::initContent()` — qui est précisément l'étape où s'exécute la **requête de
listing catégorie** (la source des temp tables sur disque).

➡️ En l'état, le module économise le **rendu/PHP** mais **PAS la requête SQL** : l'objectif métier n'est pas atteint.

**Correction recommandée :** servir le cache **avant** l'init du contrôleur, via `hookActionDispatcher`
(exécuté dans `Dispatcher::dispatch()` avant `initContent()`), en redérivant l'état anonyme/panier depuis les cookies
(le contexte `cart`/`customer` n'est pas encore initialisé à ce stade). C'est un vrai rework, **à implémenter et tester
sur un PrestaShop 1.7.6 de test** avant tout usage réel.

### F3 — Le module ne stockait rien (corrigé)
L'override `smartyOutputContent()` émet `Hook::exec('actionRequestComplete', ['output' => ...])`, mais le module
enregistrait/écoutait `actionResponse` et lisait `$params['html']`. Les deux ne se rencontrent jamais → aucune page
n'était mise en cache.
**Correction :** alignement complet sur `actionRequestComplete` / `$params['output']` (install, uninstall,
`hookActionRequestComplete()`, `createHooks()`).

### F4 — Invalidation globale (acceptable)
La magie `__call()` intercepte tout `hookAction*` non défini (actionProduct*/actionCategory*) et fait `cache->flush()`
→ purge **toute** la cache à chaque modification catalogue. C'est **sûr** (pas de page périmée) mais inefficace
(cache froid après chaque édition). Optimisation possible plus tard : invalidation ciblée par URL/catégorie.

### F5 — Backend de stockage (à traiter)
`Cache::getInstance()` renvoie le backend configuré dans PrestaShop = **Memcached** sur ce serveur, **limité à 64 Mo et
partagé entre tous les sites** (hit ratio déjà observé à ~28 %). Y stocker des **pages HTML entières** provoquera des
évictions rapides et polluera le cache des autres sites.
**Recommandation :** dédier le FPC à un stockage **filesystem** (CacheFs) ou à une **instance Memcached séparée** /
préfixe dédié, et augmenter la mémoire si Memcached est conservé.

### F6 — Robustesse / poids
- L'override porte sur `Controller::smartyOutputContent` (méthode de rendu PS 1.6). En 1.7 (thème classique) elle existe
  encore, mais le comportement doit être **vérifié sur 1.7.6** (un override du `Controller` de base est intrusif).
- Le dépôt embarque `vendor/phpfastcache` + `predis` (des centaines de fichiers) qui semblent **inutilisés** (le module
  passe par `Cache::getInstance()`). À retirer pour alléger et réduire la surface.
- `die($html)` court-circuite l'arrêt normal de PrestaShop (pas de hooks de fin) — acceptable pour un FPC mais à connaître.

---

## Corrigé dans cette branche (`fix/ps176-compat`) — v1.1.0
- **F1** : compatibilité 1.7 (`ps_versions_compliancy` max → 1.7.99.99).
- **F2** : service du cache déplacé de `hookDisplayHeader` vers **`hookActionDispatcherBefore`** → la page cachée est
  renvoyée (`echo` + `exit`) **avant** l'instanciation/init du contrôleur, donc **avant** `initContent()` → la requête
  catégorie n'est plus exécutée sur un hit. Décision de cacheabilité basée **cookie** (dispo à ce stade).
- **F3** : capture alignée sur `actionRequestComplete` / `$params['output']`.
- **F5** : stockage **filesystem dédié** (`_PS_CACHE_DIR_/xtremecache/`) au lieu du Memcached 64 Mo partagé → pas
  d'éviction ni de pollution inter-sites ; `flush()` = suppression des fichiers.
- **F6** : `vendor/` (phpfastcache, predis) **supprimé** (inutilisé) + fichiers doublons `1.0.5/1.0.6` retirés.
- **Clé de cache** : recalculée à partir de données **identiques au dispatch et au stockage** (URI normalisée + id_lang
  + id_currency + id_shop + device) pour garantir l'appariement miss→hit.
- **Robustesse** : cache **200-only** (jamais d'erreur/redirection cachée), **anonyme + panier vide** garantis,
  **normalisation** des paramètres de tracking (utm_/fbclid/gclid/mc_/_ga) pour le hit ratio.

## Reste à faire avant tout usage en production
1. **Tests sur un PrestaShop 1.7.6 _isolé_** (PAS la prod) :
   - installation/désinstallation du module ;
   - **validité de l'override** `Controller::smartyOutputContent` sur 1.7.6 (le store en dépend) ;
   - le hook `actionDispatcherBefore` est bien exécuté avant `initContent` sur cette version ;
   - rendu identique vs sans cache ; exclusions OK (connecté / panier non vide / AJAX / POST / dev) ;
   - invalidation sur modif produit/prix/stock ; pages de formulaire (tokens) cohérentes ;
   - **mesure réelle** de la baisse des `Created_tmp_disk_tables` côté MariaDB (objectif).
2. Améliorations possibles ultérieures (cf. Review v1.2.0) : purge programmée (GC) du dossier de cache ; gestion du
   déplacement de produit entre catégories ; éventuel backend Memcached **dédié** si volume élevé.

> ⚠️ **Avertissement prod** : `preprod.multicolor-sa.com` est en réalité la **PROD** (DB jamais renommée, simple alias
> d'URL). Ne **pas** installer/tester ce module dessus : utiliser une **copie isolée**. Vu l'état « abandonné » de l'upstream,
> pour un usage production durable, évaluer aussi un **module commercial maintenu**.

---

## Review v1.2.0 (2026-06-29) — invalidation ciblée (F4) + compatibilité PHP

### F4 — invalidation ciblée (implémentée)
- Les hooks catalogue sont désormais de **vraies méthodes** (plus de `__call`).
- **Tagging au stockage** (`collectTags()`) : page catégorie → `category:<id>` **seulement** (jamais un tag global,
  sinon une maj produit purgerait toutes les catégories) ; page produit → `product:<id>` + `category:<ses catégories>` ;
  home/new-products/best-sales/prices-drop/search/manufacturer/supplier → `listing`.
- **Maj produit** (`actionProductSave/Add/Update`, événements fréquents) → purge **ciblée** :
  `product:<id>` + `category:<catégories du produit>` + `listing`. Les autres catégories restent en cache.
- **Suppression produit** et **tout changement catégorie** → **flush total** (rare ; touche le menu global) — choix sûr.
- **Index inversé par tag** (`tags/<tag>/<key>`) → une purge coûte O(entrées du tag), pas O(cache entier) :
  important pendant les imports en masse.

### Compatibilité PHP — vérifiée
`php -l` **OK sur 7.0, 7.2, 7.4, 8.0, 8.1, 8.2** (module + override). Code volontairement en syntaxe PHP 7.0
(pas de propriétés typées, fonctions fléchées, `??=`…). Casts défensifs `(string)`/`(int)`/`(array)` pour éviter les
dépréciations PHP 8.1 (null vers fonctions string). Pas de propriété dynamique (dépréciation 8.2). 
> NB : PrestaShop **1.7.6 lui-même** ne tourne officiellement que jusqu'à PHP 7.2/7.3 — c'est l'environnement qui borne,
> pas le module. Le module est prêt pour une future montée PHP/PrestaShop.

### Robustesse ajoutée
- **Écriture atomique** du cache (`fichier.tmp` + `rename()`) → un lecteur concurrent ne reçoit jamais une page tronquée.
- Cache **200-only**, **anonyme + panier vide**, clé indépendante des paramètres de tracking.

### Points résiduels / limites connues (non bloquants)
1. **Clé & langue** : `id_lang` lu depuis le cookie au dispatch ; pour un tout premier visiteur sans cookie, la 1ʳᵉ requête
   est de toute façon un *miss*. L'URL (incluse dans la clé) reste le différenciateur principal entre langues.
2. **Déplacement de produit entre catégories** : l'**ancienne** catégorie n'est pas purgée par la maj (on purge les
   catégories *actuelles*) → page de l'ancienne catégorie périmée jusqu'au TTL. Rare.
3. **Pas de GC** des fichiers expirés : ils ne sont supprimés qu'à l'accès (TTL) ou au flush. Prévoir un cron de purge
   si le volume d'URLs explose.
4. **Dépendance à l'override** `Controller::smartyOutputContent` pour le stockage : si l'override n'est pas pris en 1.7.6,
   le module ne casse rien mais **ne cache rien** (fail-safe). À valider en test.

### Verdict
Le module est cohérent, sûr par défaut (anonyme/200/atomique), et l'invalidation ciblée évite le *cold cache* sur les
syncs produit fréquents. **Reste obligatoire** : test fonctionnel + mesure sur un **PrestaShop 1.7.6 isolé** (cf. plan ci-dessus)
avant tout usage sur la prod (`preprod.multicolor-sa.com` = prod réelle).

---

## Review v2.0 (2026-06-29) — review complète + renommage + fit-for-purpose

Relecture intégrale de tous les fichiers (module + 2 overrides + meta). Trois constats neufs, tous traités.

| # | Constat | Gravité | Statut |
|---|---|---|---|
| R1 | Le bouton **« Vider le cache » du BO** (Perf.) émet `actionEmptySmartyCache` via l'override `AdminPerformanceController`, mais le module **n'écoutait pas** ce hook → un admin qui vide le cache laissait des **pages FPC périmées** en ligne | 🟠 cohérence | ✅ **corrigé** (hook enregistré + `hookActionEmptySmartyCache()` → `flush()`) |
| R2 | `README.md` = `[Abandoned]` (vide, hérité de l'upstream) | 🟡 doc | ✅ **réécrit** (README complet : why/how/install/config/limites/compat) |
| R3 | Aucune **allow-list de pages** au stockage → un endpoint **GET dynamique** de module passant par `smartyOutputContent` pouvait être caché | 🟠 sûreté | ✅ **corrigé** (`isCacheablePage()` : seules les pages catalogue en lecture seule sont cachées ; `search` retiré du tagging pour éviter le *cache bloat* par requête) |
| + | **Renommage** `xtremecache` → `esipagecache` (identité ESI, v2.0.0), doublon de version supprimé, ajout des `index.php` de garde, constante `CUSTOMER_HEADER`→`DEBUG_HEADER`, en-tête `X-Xtremecache`→`X-Esipagecache`, dossier cache `esipagecache/` | 🟢 | ✅ fait |

**Note de sûreté (R3)** : même sans l'allow-list, le chemin *serve* (dispatch) ne peut renvoyer qu'une clé **déjà stockée** ;
borner le *store* aux pages catalogue borne donc aussi le *serve*. Les formulaires (contact/auth/panier/commande) restent
exclus par les gardes anonyme/panier-vide ; l'allow-list est une **défense en profondeur** contre les endpoints GET inconnus.

**Override `Controller::smartyOutputContent`** (toujours le point sensible) : c'est la méthode de rendu héritée de PS 1.6.
Elle existe encore en 1.7 (thème classique) mais l'override est intrusif et **doit être validé sur 1.7.6** — si non pris,
le module ne casse rien mais ne **stocke** rien (fail-safe).

### Compatibilité PHP — re-vérifiée
`php -l` **OK sur 7.0, 7.2, 7.4, 8.0, 8.1, 8.2** (module renommé + les 2 overrides + `index.php`).

### Fit-for-purpose — « est-ce ce qu'on a besoin ? »
**Besoin initial** : réduire les *on-disk temp tables* MariaDB de vm150, causées par la requête de listing catégorie
**anonyme** du site PrestaShop 1.7.6.1 (cache objet PS désactivé). Cf. [[vm150-prestashop-tmptables]].

- ✅ **Adéquation** : servir avant `initContent()` supprime *exactement* la requête coupable sur un hit. C'est le seul
  des modules FPC gratuits PS qui, une fois réécrit (F2), attaque la cause racine côté SQL et pas seulement le rendu PHP.
- ⚠️ **Périmètre** : le gain ne porte que sur le **trafic anonyme / bots** (les visiteurs connectés et paniers non vides
  bypassent). Pour ce site, c'est précisément le trafic qui génère les temp tables → cohérent. Mais ce **n'est pas** un
  substitut au cache objet PrestaShop (Memcached/Redis), qui resterait bénéfique pour le back-office et les connectés.
- ⚠️ **Risques** : (1) **non testé** fonctionnellement sur PS 1.7.6 ; (2) dépend d'un **override intrusif** ;
  (3) invalidation sur **boutique vivante** — un bug de purge sert une page périmée (prix/stock) à un anonyme.
- 🔁 **Alternatives** : (a) activer le **cache objet PS + Memcached/Redis** (moins risqué, gain plus large mais
  ne supprime pas forcément la requête sur page froide) ; (b) **module commercial maintenu** pour un usage prod durable
  (l'upstream est abandonné) ; (c) cache **reverse-proxy** (Varnish/Nginx micro-cache) — plus robuste mais hors PrestaShop.

**Conclusion** : le module **répond au besoin** pour réduire les temp tables du trafic anonyme, et il est maintenant
propre/sûr par défaut. Il **n'est pas** prêt prod tel quel : prérequis = **validation sur un PS 1.7.6 isolé** (jamais
`preprod.multicolor-sa.com` = prod réelle) avec mesure réelle des `Created_tmp_disk_tables`. À court terme, le plus sûr
reste d'**activer le cache objet PS** ; le FPC vient en complément une fois validé.
