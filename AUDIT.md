# Audit — module xtremecache (full page cache PrestaShop)

**Auditeur :** ESI (Cedric AUDRIT) — 2026-06-29
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
| F4 | Invalidation = `flush()` **total** du cache à chaque modif produit/catégorie | 🟡 crude | conservé (sûr) ; ciblé = amélioration future |
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
2. Améliorations possibles ultérieures : invalidation **ciblée** (F4) au lieu du flush total ; purge programmée du dossier
   de cache ; éventuel backend Memcached **dédié** si volume élevé.

> ⚠️ **Avertissement prod** : `preprod.multicolor-sa.com` est en réalité la **PROD** (DB jamais renommée, simple alias
> d'URL). Ne **pas** installer/tester ce module dessus : utiliser une **copie isolée**. Vu l'état « abandonné » de l'upstream,
> pour un usage production durable, évaluer aussi un **module commercial maintenu**.
