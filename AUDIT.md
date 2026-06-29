# Audit — module xtremecache (full page cache PrestaShop)

**Auditeur :** ESI (Cedric AUDRIT) — 2026-06-29
**Source :** fork de `SimoneS93/xtremecache` (branche master, dernier commit upstream 2020-08-02 « abandoned »)
**Cible :** PrestaShop **1.7.6.1**, **PHP 7.2**, serveur **Apache**, cache PS = **Memcached** (64 Mo partagé)
**Objectif métier :** réduire les *temp tables sur disque* MariaDB causées par la requête de listing catégorie (cf. site all4auto/Multicolor).

---

## Synthèse

| # | Constat | Gravité | Statut |
|---|---|---|---|
| F1 | `ps_versions_compliancy` plafonné à `1.6.99.99` → **n'installe pas** sur 1.7.6 | 🔴 bloquant | ✅ **corrigé** |
| F2 | Cache servi sur `hookDisplayHeader` = **après** `initContent()` → la requête SQL lourde **a déjà tourné** | 🔴 majeur | ⚠️ **non corrigé** (rework + test requis) |
| F3 | Module écoute `actionResponse`/`html` alors que l'override émet `actionRequestComplete`/`output` → **rien n'est stocké** | 🟠 bug fonctionnel | ✅ **corrigé** |
| F4 | Invalidation = `flush()` **total** du cache à chaque modif produit/catégorie | 🟡 crude | acceptable |
| F5 | Stockage via `Cache::getInstance()` = **Memcached 64 Mo partagé** entre ~168 sites | 🟠 dimensionnement | à traiter |
| F6 | Override de `Controller::smartyOutputContent` (API 1.6) ; libs `vendor/` (phpfastcache, predis) **inutilisées** | 🟡 robustesse/poids | à vérifier/nettoyer |

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

## Corrigé dans cette branche (`fix/ps176-compat`)
- F1 : compatibilité 1.7 (`ps_versions_compliancy` max → 1.7.99.99).
- F3 : cohérence du hook de capture (`actionRequestComplete` / `output`) → le module stocke effectivement.

## Reste à faire avant tout usage en production
1. **F2** : réécrire le service du cache au niveau `actionDispatcher` (sinon aucun gain DB — l'objectif n°1).
2. **F5** : isoler le stockage FPC (filesystem ou Memcached dédié) pour ne pas casser le cache partagé.
3. **F6** : valider/adapter l'override sur 1.7.6 ; supprimer les libs `vendor/` inutilisées.
4. **Tests** sur un PrestaShop 1.7.6 **de test** : installation, rendu identique, exclusion correcte (connecté/panier/AJAX/POST),
   invalidation, et **mesure réelle** de la baisse des temp tables côté MariaDB.

> ⚠️ **Avertissement prod** : le site « preprod » (preprod.multicolor-sa.com) est en réalité la **prod**. Ne pas installer
> ce module dessus sans une copie de test isolée. Vu l'état « abandonné » du module et les corrections encore nécessaires,
> évaluer aussi un module commercial maintenu pour un usage production.
