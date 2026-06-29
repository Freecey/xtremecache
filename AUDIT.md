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

---

## Review v2.0.1 (2026-06-29) — round 3, relecture adversariale

Passe ciblée « qu'est-ce qui casse réellement sur PS 1.7.6 », pas seulement relecture de style.

| # | Constat | Gravité | Statut |
|---|---|---|---|
| C1 | **Override `Controller::smartyOutputContent` recopiait la logique de rendu PS 1.6** (re-append manuel `</body></html>`, `javascript.tpl`, `deferInlineScripts`). Sur 1.7.6, le rendu du cœur diffère → risque de **HTML cassé** (double `</body>`, JS manquant) ET de **cacher** cette page cassée | 🔴 majeur | ✅ **corrigé** : l'override appelle désormais `parent::smartyOutputContent()` sous **output buffering**, capture les **octets exacts du cœur**, émet le hook avec, puis les ré-`echo` inchangés → rendu **100 % identique au PrestaShop stock**, quelle que soit la version |
| C2 | `flush()` ne purgeait que `*.html` → des `*.tmp` orphelins (write atomique interrompu) s'accumulaient | 🟡 propreté | ✅ **corrigé** (purge aussi `*.tmp`) |
| R4 | **Croissance non bornée du cache** : chaque query string distincte (`?x=1`, `?x=2`…) crée une entrée ; pas de GC → un bot/attaquant peut **remplir le disque** (DoS) | 🟠 risque résiduel | ⚠️ **documenté** (pas corrigé) : recommandation = **cron de purge** + supervision du volume `var/cache/<env>/esipagecache/` + quota disque. Non bridé par whitelist de params car cela casserait la pagination/tri/navigation à facettes (params légitimes nombreux) |

**Analyse de risques confirmés sûrs (non bloquants)** :
- Contexte à `actionDispatcherBefore` : `cookie`/`shop`/`getDevice()` sont initialisés **avant** `Dispatcher::dispatch()` → OK.
- `id_category`/`id_product` dispo dans `$_GET` au moment du *store* (le dispatcher les a peuplés) → tags corrects.
- `http_response_code()` reflète bien un `header('HTTP/1.1 404')` posé par PS → pages 404/redirections **non cachées**.
- Pagination/tri (`p`, `n`, `orderby`, `orderway`) restent dans la clé → entrées distinctes, **pas de mélange**.
- Collision de nom `*.tmp` impossible en concurrence (1 process = 1 requête sous PHP-FPM, PID distinct par worker).
- `Cart::nbProducts()` n'exécute une requête sur le *serve* que si un cookie `id_cart` existe (rare chez le visiteur qui ne fait que naviguer) → coût négligeable devant la requête catégorie évitée.

### Compatibilité PHP — re-vérifiée
`php -l` **OK 7.0 → 8.2** (module + override Controller réécrit + AdminPerformance + index.php).

### Verdict v2.0.1
Le risque le plus sérieux (override de rendu figé en 1.6) est **éliminé** : l'override est désormais un simple
*tap* sur la sortie du cœur. Reste : R4 (volume disque, à superviser) et l'incontournable **test fonctionnel sur PS
1.7.6 isolé** avant prod.

---

## Review v2.0.2 (2026-06-29) — round 4, cohérence de clé serve↔store

| # | Constat | Gravité | Statut |
|---|---|---|---|
| C3 | **La clé pouvait diverger entre le *serve* et le *store*.** À `actionDispatcherBefore`, `$cookie->id_lang`/`id_currency` portent les valeurs de la requête **précédente** ; `FrontController::init()` peut les **muter** (switch langue/devise par URL ou cookie) avant `smartyOutputContent`. Résultat : page stockée sous K2 mais cherchée sous K1 → **miss permanent** sur boutique **multilingue/multidevise** (+ re-stockage à chaque vue = disque qui gonfle) | 🟠 efficacité | ✅ **corrigé** : la clé est calculée **une seule fois** au dispatch (`$currentKey`) et **réutilisée** au store ; fallback recompute si le hook dispatch n'a pas tourné. Élimine toute divergence (langue, devise, device, URI) |

**Pourquoi pas vu plus tôt** : sur une boutique **monolingue/monodevise** (cas fréquent ici) la clé ne divergeait pas,
le bug était donc invisible — mais Multicolor (BE) peut être FR+NL → impact réel.

### Compatibilité PHP — re-vérifiée
`php -l` **OK 7.0 → 8.2**.

### Verdict v2.0.2
Plus de divergence de clé possible : *serve* et *store* partagent par construction la même clé. À ce stade, les
constats restants sont **tous documentés** (R4 volume disque ; dépendance override `smartyOutputContent` ;
déplacement produit inter-catégories ; cookie 1ʳᵉ visite) et **aucun bug de correction/efficacité connu ne subsiste**.
Le seul vrai jalon avant prod reste le **test fonctionnel sur PS 1.7.6 isolé** (rendu, exclusions, invalidation,
mesure `Created_tmp_disk_tables`).

---

## Review v2.0.3 (2026-06-29) — round 5, fiabilité des entrées `$_SERVER`

| # | Constat | Gravité | Statut |
|---|---|---|---|
| C4 | L'URI et la méthode étaient lues via **`filter_input(INPUT_SERVER, ...)`**. Piège PHP connu : sous **php-fpm/FastCGI**, `INPUT_SERVER` reflète l'environnement SAPI d'origine et renvoie **souvent `null`**. → `normalizedUri()` = `(string) null` = `''` → **toutes les pages partagent la même clé** → **mauvaise page servie** (cache poisoning) | 🔴 correction/sécurité | ✅ **corrigé** : lecture via **`$_SERVER`** (comme PrestaShop lui-même) + **garde anti-clé-vide** (`requestUri() === '' → non cacheable`). Plus aucun `filter_input` actif |

**Pourquoi pas vu plus tôt** : dépend de la SAPI (CLI/Apache mod_php remplissent `INPUT_SERVER`, php-fpm souvent non).
C'est précisément le risque qui se serait manifesté **en prod** (Apache+php-fpm) et pas en lint/CLI.

### Compatibilité PHP — re-vérifiée
`php -l` **OK 7.0 → 8.2**. Plus aucun appel `filter_input` (commentaires uniquement).

### Verdict v2.0.3
C4 était le dernier risque susceptible de **changer le comportement en prod** sans se voir en relecture/CLI.
Les constats restants sont tous **documentés et assumés** (R4 ; override ; déplacement produit ; Content-Type
implicite `text/html` non rejoué — sans impact sur des pages HTML standard). **Rendements décroissants atteints sur
l'analyse statique** : la prochaine étape à valeur réelle est le **test fonctionnel sur PS 1.7.6 isolé**, pas un round
de plus.

---

## Review v2.0.4 (2026-06-29) — round 6, concurrence threadée + robustesse lecture

| # | Constat | Gravité | Statut |
|---|---|---|---|
| C5 | **Collision du fichier `.tmp` sous SAPI multithread.** Le nom temp = `$file.getmypid().'.tmp'`. Unique sous php-fpm/prefork (1 process = 1 requête), mais sous **Apache mpm_worker/event + mod_php** (threadé) plusieurs threads partagent **le même PID** → deux threads stockant la même page écrivent dans le **même `.tmp`** → fichier corrompu, donc **page corrompue cachée** | 🟠 robustesse/concurrence | ✅ **corrigé** : entropie `uniqid('', true)` ajoutée au nom temp (unique même thread/PID identiques). Suffixe `.tmp` conservé → toujours nettoyé par `flush()` |
| C6 | `load()` : `file_get_contents` sur un fichier **0 octet** renvoie `''` (`!== false`) → **page vide servie** | 🟡 robustesse | ✅ **corrigé** : `''` (et `false`) traités comme *miss* |

**Confirmés sains lors de cette passe** (pas d'action) :
- Concurrence inter-process : deux workers rendent la même URL → 2 `.tmp` distincts → `rename` atomique, dernier gagne, pas de corruption.
- Clé = md5 hex → nom de fichier sûr, pas d'injection de chemin.
- Cycle de vie : 1 requête = 1 instance module → `$currentKey` est bien par-requête.
- `exit` après serve : PHP flush les buffers de sortie en fin de script → contenu bien envoyé.
- `$hook->position` dans `createHooks()` : champ hors `définition` de `Hook` → ignoré à la sauvegarde, inoffensif.

**Limites résiduelles inchangées** : marqueurs de tags non nettoyés à l'expiration TTL d'une page (purgés au prochain
`purgeTags`/`flush`, bornés) ; R4 volume disque ; dépendance override.

### Compatibilité PHP — re-vérifiée
`php -l` **OK 7.0 → 8.2**.

### Verdict v2.0.4
Round 6 = durcissement de concurrence (C5) + une garde défensive (C6), gravité en baisse continue. Plus aucun bug de
**correction** connu ; les robustesses trouvées sont des cas-limites d'infra (SAPI threadé, fichier tronqué). On est très
clairement au **plancher des rendements décroissants** de la revue statique. Jalon suivant = **test fonctionnel PS 1.7.6
isolé** (rien d'autre n'apportera de signal nouveau).

---

## Review v2.0.5 (2026-06-29) — round 7, couverture d'invalidation & fraîcheur

Angle neuf : non plus la mécanique (clé/concurrence/I/O) mais **ce qui change le contenu d'une page sans déclencher de purge**.

| # | Constat | Gravité | Statut |
|---|---|---|---|
| C7 | **Trou de couverture d'invalidation + TTL trop long.** Les hooks ne couvrent que `actionProduct*` et `actionCategory*`. **Aucune purge** sur : prix spécifiques / règles de prix catalogue (**promos**), stock passant à 0, contenu **CMS**, fiches fournisseur/fabricant, changements thème/module. Avec **TTL = 7 jours**, ces pages restaient **fausses jusqu'à une semaine** (prix promo périmé servi à un anonyme) | 🟠 fraîcheur/correction | ✅ **traité** : TTL par défaut **7 j → 1 h** (le TTL est le filet de sécurité pour tout vecteur non hooké) + **couverture documentée** (README). Gain temp-tables quasi inchangé (une catégorie vue des milliers de fois/h ne re-render qu'1×/h) |

**Pourquoi ne pas ajouter les hooks `SpecificPrice`/stock ?** Leurs noms/déclenchements sont **version-dépendants** en
1.6/1.7 et je ne peux pas les valider sans PS 1.7.6 réel ; un hook stock en *flush total* casserait le hit ratio (déclenché
à chaque commande). Le TTL court est le filet **robuste et sans dépendance**. Reco opérationnelle : vider le cache depuis
le BO après une campagne promo/prix en masse (déjà câblé via `actionEmptySmartyCache`, cf. R1).

### Compatibilité PHP — re-vérifiée
`php -l` **OK 7.0 → 8.2**.

### Verdict v2.0.5
C7 corrige le dernier risque **fonctionnel réel** (prix/contenu périmé sur boutique vivante) sans introduire de dépendance
fragile. À ce stade, après 7 rounds, **tous les axes ont été couverts** : compat (F1), efficacité du serve (F2/F3), backend
(F5), invalidation ciblée (F4), rendu (C1), clé (C3), entrées (C4), concurrence (C5/C6), fraîcheur (C7). **Aucun bug connu
ne subsiste.** Le seul livrable à valeur ajoutée restante est **hors revue statique** : le test fonctionnel sur PS 1.7.6
isolé (rendu, exclusions, invalidation, mesure `Created_tmp_disk_tables`).

---

## Review v2.0.6 (2026-06-29) — round 8, en-têtes HTTP du serve + packaging

Aucun **bug** trouvé. Une amélioration de robustesse + deux vérifications de packaging/doc.

| # | Constat | Gravité | Statut |
|---|---|---|---|
| C8 | Sur un **hit**, `echo $html; exit;` court-circuite tout PrestaShop, donc aussi l'en-tête `Content-Type`/charset que le contrôleur aurait posé → on dépendait du `default_charset` du serveur (UTF-8 par défaut en PHP 7+, mais pas garanti) | 🟡 robustesse | ✅ **corrigé** : `Content-Type: text/html; charset=utf-8` envoyé explicitement sur le hit (sous garde `!headers_sent()`) |
| — | `.gitattributes` / `.gitignore` : **pas d'`export-ignore`**, n'ignorent que du junk Windows/OSX → aucun fichier du module exclu d'un `git archive`/packaging | ✅ sain | rien à faire |
| — | Doc : le conflit d'override possible à l'install ne concernait pas que `Controller` mais aussi `AdminPerformanceController` | 🟢 doc | ✅ README généralisé |

### Compatibilité PHP — re-vérifiée
`php -l` **OK 7.0 → 8.2**.

### Verdict v2.0.6
Round 8 = **zéro bug**, une garde d'en-tête (C8) et de la doc. C'est exactement le profil annoncé du **plancher des
rendements décroissants** : la revue statique ne produit plus que du durcissement cosmétique. **Tous les axes statiques
sont épuisés.** La seule étape qui peut encore révéler quelque chose est **dynamique** : le test fonctionnel sur PS 1.7.6
isolé. Recommandation ferme : arrêter la revue statique et passer au test fonctionnel (+ mesure `Created_tmp_disk_tables`).
