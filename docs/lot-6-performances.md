# Lot 6 — Pagination et dashboard financier

Implémentation locale sur la base de `main` / `9afb18a433ff6e1967f54e2493048aa7794d7961` (lot 5). Aucun accès aux données Aiven, aucun déploiement Render, aucun commit/push et aucun travail du lot 7. Les services d'écriture financière, numérotation, conversion, Policies et Sanctum restent inchangés.

## Contrat des lectures

- `GET /api/dashboard/financial` : Policy `Company::viewFinancialStatistics`, donc admin/comptable ; un employé reçoit 403 avant les requêtes financières. Authentification absente : 401. Réponse `Cache-Control: no-store`.
- Les cinq listes et les paiements d'une facture acceptent `per_page` (1–100, défaut 25), `page`, `q`, `from`, `to`, `created_from`, `created_to`. Les dates de période concernent respectivement création du client/produit, date de facture, date de devis et date du règlement. Les bornes sont inclusives ; la borne haute SQL utilise le lendemain exclusif.
- Factures : `status` calculé selon les règles historiques et `document_status` ; devis : statut commercial ; règlements : `payment_method`. `client_id` doit appartenir à l'entreprise, sinon 404. Toute tentative de passer `company_id` est refusée dans le nouveau contrat paginé (422).
- Clients/factures/devis : `archived=0|1|all`. Clients actifs par défaut ; factures/devis côté API : tous par compatibilité. React demande explicitement les documents non archivés et permet de changer le filtre. Les archives sont conservées et consultables selon les Policies existantes.
- `lookup=1` clients/produits : sélecteurs distants, recherche et maximum 25 résultats dans React. La sélection actuelle reste visible si elle n'appartient pas à la page recherchée ; les clients archivés ne sont pas proposés pour de nouvelles pièces.
- Tri des pages : `created_at DESC, id DESC`. `pagination=cursor` : `id DESC`, sans COUNT global, compatible avec les anciennes dates NULL. Curseur opaque fourni par l'API, validé ; un ID de curseur ne remplace jamais le filtre d'entreprise. La pagination par numéro peut se décaler si des écritures surviennent entre deux pages ; utiliser le curseur pour une lecture continue sous écritures.
- Les réponses paginées Laravel contiennent `data`, liens et métadonnées. Les listes omettent lignes détaillées, instantanés complets et révisions. Les paiements contiennent une référence minimale à la facture/client. `totals_by_currency` porte sur **tous les résultats filtrés**, indépendamment du numéro de page.
- Les appels historiques sans paramètres conservent leur format tableau. Ils restent potentiellement volumineux : compatibilité conservée volontairement pour éviter de casser des consommateurs inconnus. Les écrans React de ce dépôt utilisent le nouveau contrat borné. Leur retrait devra être annoncé/versionné séparément.
- `GET /api/invoices/{id}?include_payments=0` conserve soldes, instantanés et audit financier détaillé, mais omet l'historique des paiements/révisions sérialisé. React utilise ce mode ; l'historique d'un paiement est lu à la demande. Sans paramètre, le contrat historique reste disponible. Le calcul/audit du détail peut toujours charger les règlements nécessaires : cette option optimise le transfert et les révisions, pas toute la lecture du grand livre.

## Règles du dashboard

1. Toutes les requêtes sont limitées à `company_id` ; paiements reliés aux factures de cette entreprise. Les jointures de noms de client vérifient également l'entreprise. Les clients archivés peuvent être référencés par leurs documents historiques.
2. Les indicateurs d'encours sont des **cumuls au jour du calcul**, pas le solde à la fin de la période choisie. La période pilote uniquement les flux mensuels et les classements ; cette distinction apparaît à l'écran. Défaut : 12 mois jusqu'à aujourd'hui, maximum 24 mois calendaires, bornes ordonnées.
3. État du document : `document_status`, ou repli historique draft/cancelled/issued identique au lot 3. Facturation et encours : factures émises uniquement ; brouillons et factures annulées ne contribuent pas au facturé. Les archives ne font pas disparaître un historique financier.
4. Encaissements : montant **actuel** de chaque ligne `payments`, une seule fois. Les révisions ne sont pas additionnées. Une correction de date déplace le montant dans le mois courant du règlement, sans recréer son ancien montant. Des règlements historiques sur une pièce non émise restent visibles dans les encaissements enregistrés, avec signalement de revue.
5. Reste à payer : somme des différences positives total moins règlements pour les pièces émises. Retard : échéance strictement antérieure à aujourd'hui et solde positif. Non réglée : total positif et aucun encaissement ; une facture partiellement réglée appartient à l'encours mais pas au compteur « non réglées ». Facture de total zéro : aucun encours.
6. Flux mensuels : `invoice_date` pour le facturé, `payment_date` pour l'encaissé, mois sans activité explicitement à zéro. Fuseau Laravel actuel : UTC ; échéances recalculées à la lecture et React actualise au changement de jour UTC et au retour de visibilité.
7. Devise issue exclusivement de `issued_snapshot.currency`, parmi MAD/EUR/USD ; absence ou valeur inconnue : groupe UNKNOWN. Aucun changement automatique d'historique et aucun taux de change inventé. Les montants consolidés UNKNOWN sont **NULL**, affichés « Non consolidable » ; les pièces individuelles restent consultables avec avertissement. Aucun total global mélangeant plusieurs devises.
8. Les devis ne possèdent pas d'instantané de devise fiable : comptes par statut et nombre en attente (envoyés + acceptés), mais `pending_total=null`. Les compteurs nuls restent à zéro. Les brouillons de facture sans devise historique sont comptés dans la répartition globale des statuts.
9. Les classements utilisent des identifiants, pas uniquement le nom des clients. Lignes produit issues de l'instantané, repli sur les lignes historiques seulement si l'instantané ne contient pas de lignes. Classement produit TTC avant allocation d'une éventuelle remise globale, mentionné à l'écran. Aucun classement monétaire consolidé UNKNOWN.
10. Les anomalies de grand livre et de total d'instantané sont signalées (`review_count`). Ce contrôle agrégé ne remplace pas l'audit complet des lignes et remises, conservé sur le détail et dans les audits financiers existants. Aucune anomalie n'est corrigée automatiquement.
11. MySQL : agrégats DECIMAL explicites ; SQLite : agrégat décimal exact enregistré sur PDO, comparaisons et tri décimaux exacts ; PHP : Brick Math. Les montants JSON sont des chaînes décimales ou NULL. React formate avec BigInt et utilise les poids/graduations calculés côté serveur pour les graphiques ; aucun cumul financier flottant dans le navigateur.
12. Le ratio de recouvrement utilise les paiements et totaux de la même population de factures émises, pas deux flux de périodes différentes. Les moyennes et variations sont arrondies explicitement ; variation sans base positive : NULL.

Les lectures de l'endpoint financier sont regroupées dans une courte transaction de lecture cohérente, sans verrou métier. La cohérence entre requêtes dépend de l'isolation transactionnelle du serveur (tester la configuration Aiven réelle sur une copie autorisée).

## Cache et ressources économiques

Le nouveau dashboard et les listes paginées ne mettent pas les montants financiers en cache dans cette livraison. Les mesures privilégient d'abord SQL et des réponses bornées. Pas de worker, service payant supplémentaire ou purge globale.

`CompanyCache` conserve les clés par entreprise et les TTL historiques (clients 5 min, produits 10 min, devis 2 min, compteurs 60 s). Une génération et un court verrou de publication empêchent un calcul commencé avant l'invalidation de republier ensuite sa valeur obsolète. Le callback SQL n'est exécuté qu'une fois ; une erreur SQL se propage ; une panne de cache n'annule pas une écriture déjà validée. Les invalidations métier après commit et leur ordre de verrouillage SQL restent ceux des lots précédents. Le verrou Redis n'entoure jamais le callback SQL ni une transaction d'écriture.

Ce protocole nécessite un cache partagé et des invalidations coopératives. Une panne prolongée, l'éviction de métadonnées de génération ou des écritures externes peuvent laisser des listes historiques obsolètes jusqu'au TTL ; ce n'est pas un registre financier transactionnel. Aucun total du nouveau dashboard ne dépend de ce cache. Ne pas activer un cache financier uniquement en ajoutant `remember()`.

## Index et préparation de livraison

Une seule nouvelle migration : `2026_11_10_000001_add_measured_list_indexes.php`.

- `clients_list_scope(company_id, deleted_at, created_at, id)`.
- `invoices_list_scope(company_id, archived_at, created_at, id)`.
- `invoices_period_scope(company_id, invoice_date, id)`.

Elle ne modifie ni montant, relation, numéro, archive ni migration historique. Tous les noms/colonnes/index équivalents sont contrôlés avant la première DDL ; un état partiel ou inattendu bloque et exige une revue. Son `down()` retire uniquement ces index. MySQL peut toutefois valider des DDL partiellement ; le précontrôle ne transforme pas un ensemble d'ALTER en transaction atomique.

Les autres candidats ne sont pas ajoutés : aucun gain suffisamment démontré sur produits/paiements et index devis ignoré par l'optimiseur sur les fixtures.

Procédure obligatoire avant toute production, selon [le guide de déploiement](deploiement-securise.md) :

1. Sauvegarde complète vérifiée, restauration réelle sur MySQL 8 isolé, comparaison des données/contraintes et de l'état des migrations ; aucune autorisation de production n'est donnée par ce rapport.
2. Sur la copie autorisée, exécuter `php artisan performance:audit --company=ID --explain` : lecture seule des volumes, index et plans. Examiner aussi SHOW CREATE TABLE/information_schema, collation, index équivalents et taille disponible. Relever le SHA et les plans avant modification.
3. Tester la seule migration nouvelle sur cette copie ; comparer lignes/empreintes, contraintes, audits métier et plans après. Évaluer temps/locks DDL sur les volumes réels et prévoir une fenêtre adaptée aux ressources Aiven.
4. En cas d'échec partiel, inventorier les index déjà créés et préparer un correctif en avant testé sur une copie de cet état. Ne pas relancer aveuglément, falsifier le registre des migrations ou utiliser un rollback global.
5. Livrer backend puis frontend avec le workflow manuel existant et SHA contrôlé. Retour applicatif possible au lot 5 sans retirer ces index ; aucune restauration destructive de données. Les anciens endpoints sont conservés pour la transition.

Aucune de ces opérations n'a été lancée sur Aiven. Les migrations locales de tests ciblent uniquement SQLite en mémoire et le conteneur MySQL en loopback.

## Reproduction des mesures locales

`backend/bin/benchmark-reads.php` refuse tout environnement autre que testing/MySQL/127.0.0.1/base dont le nom finit par `_test`. `seed` refuse une base contenant déjà des entreprises. Il est réservé à une base **jetable vide et migrée**, jamais à la copie d'une vraie base ni à Aiven. Aucun identifiant réel ne doit être passé dans les journaux.

Après préparation de cette seule base fictive : `php bin/benchmark-reads.php seed`, puis `php bin/benchmark-reads.php bench '/api/invoices?per_page=25&archived=0' null`. Modes `null` (sans cache), `miss` (clés de l'entreprise vidées entre observations), `hit` (cache conservé). `plans` réalise EXPLAIN/EXPLAIN ANALYZE en lecture seule ; `race` ajoute un client **fictif** et vérifie l'entrelacement du cache sur un Redis local. Ce dernier n'est pas un test multiprocessus.

Une observation d'échauffement puis cinq observations séquentielles, médiane ; pas de suite PHPUnit ou autre benchmark simultané. PHP limité à 1 CPU / 512 MiB, memory_limit 96 MiB. MySQL et Redis locaux ne reproduisent pas les contraintes ni la latence de Render/Aiven. Le chronométrage traverse le noyau HTTP Laravel avec acteur de test injecté avant mesure : il exclut démarrage PHP, authentification réelle, réseau/TLS, rendu React et latence distante. `peak_delta_mb` est le surcroît PHP pendant la requête, pas la mémoire totale Apache.

Jeu fictif : 20 entreprises, 2 000 clients, 1 000 produits, 10 000 factures/20 000 lignes, 5 000 devis/10 000 lignes, 7 980 règlements/1 160 révisions. Entreprise mesurée : 500 factures, 250 devis, 90 clients actifs, 50 produits, 399 règlements. Ces fixtures techniques contiennent volontairement des documents anciens incomplets ; elles servent aux volumes, pas à une certification de conformité historique.

## Résultats mesurés

Preuves brutes conservées dans [benchmarks/lot-6.json](benchmarks/lot-6.json). Comparaison des chargements utilisés par les écrans : ancien endpoint complet contre nouvelle page de 25 lignes (hors archives quand indiqué). Les tailles et contenus diffèrent volontairement ; ces chiffres ne comparent pas deux réponses fonctionnellement identiques.

| Lecture | Avant sans cache, ms | Après sans cache, ms | JSON avant → après, octets | SQL avant → après |
| --- | ---: | ---: | ---: | ---: |
| Clients : complet → page 25 | 12,015 | 6,907 | 23 644 → 7 509 | 2 → 3 |
| Produits : complet → page 25 | 8,580 | 8,536 | 9 983 → 5 745 | 2 → 3 |
| Devis : complet → page 25 actifs | 107,512 | 13,545 | 214 963 → 11 617 | 5 → 5 |
| Factures : complet → page 25 actives | 1 124,171 | 27,895 | 749 500 → 13 850 | 5 → 3 |
| Règlements : complet → page 25 | 238,039 | 25,103 | 532 164 → 10 173 | 5 → 4 |

La page clients existait déjà au lot 5 : comparaison équivalente de cette seule page, 6,521 → 6,907 ms et 7 407 → 7 509 octets, donc **aucun gain de temps démontré** pour ce cas déjà paginé. Sur les produits le gain de temps est négligeable ; l'intérêt mesuré est la réduction de volume et la borne constante.

La liste de factures réduit surtout hydratation, audit détaillé répété et transfert : surcroît de mémoire PHP 11,247 → 0,129 MiB. Le temps SQL y passe de 17,040 à 23,490 ms : les agrégats SQL n'améliorent pas chaque sous-mesure, mais remplacent le travail PHP excessif. Le détail conserve l'audit financier complet.

Le nouveau dashboard complet : **136,823 ms, 17 requêtes SQL, 14 721 octets, surcroît mémoire 0,214 MiB**, sans cache. L'ancien dashboard lisait six endpoints incluant toutes les listes ; additionner leurs médianes ne serait pas une mesure de son temps de chargement navigateur. Aucun avant/après global de rendu n'est annoncé.

| Lecture après | Sans cache, ms | Redis vidé, ms | Redis conservé, ms |
| --- | ---: | ---: | ---: |
| Compteurs historiques | 7,974 | 8,566 | 1,894 |
| Nouveau dashboard financier | 136,823 | 130,602 | 129,034 |
| Clients page 25 | 6,907 | 11,372 | 6,633 |
| Produits page 25 | 8,536 | 7,243 | 7,884 |
| Devis page 25 actifs | 13,545 | 14,602 | 14,313 |
| Factures page 25 actives | 27,895 | 29,287 | 28,404 |
| Règlements page 25 | 25,103 | 21,447 | 20,583 |

Seuls les compteurs historiques utilisent ici le cache. Les variations des nouvelles routes entre modes reflètent le bruit d'exécution, pas des hits financiers. Les modèles Eloquent complets ne sont pas ajoutés au cache des nouvelles pages.

EXPLAIN après la migration réelle sur MySQL isolé : `clients_list_scope` et `invoices_list_scope` utilisés, tri externe évité, lecture arrêtée après 25 résultats ; `invoices_period_scope` parcourt 63 entrées pour la période candidate, contre 500 avant. Médianes candidates avant → après : clients 0,863 → 0,941 ms (pas de gain temporel démontré sur cette passe), factures 2,032 → 0,783 ms, période 1,097 → 0,935 ms. Ces plans sont ceux des requêtes candidates du harnais, pas la preuve que chaque agrégat complexe utilisera le même plan en production. Les preuves incluent les candidats non retenus et les limites des plans de paiements/devis.

Redis réel, entrelacement déterministe : avant, valeur 90 republiée après invalidation alors que la base contient 91 ; après, cache NULL et base 91, donc absence de republication tardive. Le test `race` a été exécuté **après** toutes les mesures pour ne pas modifier leur population. Le harnais refuse APP_ENV=production (code 2) ; ce contrôle a utilisé le même conteneur local, pas une connexion de production.

## Validation et limites

- Suite complète MySQL 8.0.46 / PHP 8.2.35 : **350 tests réussis, 0 échec**, 1 923 assertions, aucun ignoré. Les 326 cas existants des lots 1–5 sont conservés ; les 12 scénarios propres à MySQL, dont concurrence multiprocessus, sont rejoués.
- Suite complète SQLite en mémoire / PHP 8.2.12 : **338 réussis, 12 ignorés, 0 échec**, 1 837 assertions. Les tests MySQL sont ignorés volontairement : SQLite ne prouve pas les verrous de MySQL.
- Après les derniers ajustements de total filtré page 2 et de recherche « 0 » : **13 tests ciblés réussis, 90 assertions, 0 échec sur chacun des deux moteurs**. Le reste des services n'a pas changé depuis la passe complète.
- Frontend : **40 tests Node réussis, 0 échec** (36 existants + 4 contrats pagination/concurrence des lectures/format financier), ESLint et build Vite réussis. Ce sont les tests des modules utilisés par React ; pas une suite navigateur ou une validation visuelle complète.
- Composer validate, Pint sur tous les fichiers PHP concernés, `git diff --check` et actionlint : réussis. Aucun workflow ni Dockerfile modifié.
- 11 tests des garde-fous CI/déploiement réussis, sans réseau de production. Image PHP 8.2/Apache construite et test HTTP du conteneur réussi sous 512 MiB : port dynamique, readiness, fichiers privés, Sanctum, lectures financières et cinq listes paginées via HTTP, redémarrage et refus de migration en environnement production simulé.
- Les images locales portent le SHA de base comme métadonnée de fixture ; elles sont construites depuis les modifications non commitées et ne constituent pas une livraison approuvée.
- 55 routes API inspectées ; le nouvel endpoint est dans le groupe authentifié existant. Audit `performance:audit --company=1 --explain` exécuté sur les fixtures uniquement.

Les premiers essais de harnais ont rencontré une initialisation MySQL non terminée puis une erreur d'ordre d'import PHP du script de mesure ; ils ont été corrigés avant la collecte retenue. Le défaut de total page 2 découvert en revue est corrigé et couvert. Les tests de migration de conservation retirent maintenant les nouveaux index avant de simuler le retrait des anciennes colonnes, uniquement dans leur fixture isolée ; aucune ancienne migration n'est modifiée.

Restent à vérifier avant livraison : restauration d'une vraie sauvegarde autorisée sur copie isolée, index/volumes/temps DDL du schéma Aiven réel, validation visuelle et clavier/mobile des parcours, latence Render–Aiven et mémoire Apache sous trafic réel. Le coût des 17 requêtes du dashboard doit être profilé avec cette latence ; aucun SLA ou test de charge concurrente du dashboard n'est revendiqué. Les anciens endpoints non paginés restent disponibles pour compatibilité ; le cache financier reste désactivé.

## Fichiers réellement concernés

- `backend/app/Console/Commands/AuditPerformance.php`
- `backend/app/Http/Controllers/ClientController.php`
- `backend/app/Http/Controllers/DashboardController.php`
- `backend/app/Http/Controllers/InvoiceController.php`
- `backend/app/Http/Controllers/PaymentController.php`
- `backend/app/Http/Controllers/ProductController.php`
- `backend/app/Http/Controllers/QuoteController.php`
- `backend/app/Services/DashboardMetricsService.php`
- `backend/app/Support/CompanyCache.php`
- `backend/app/Support/DocumentList.php`
- `backend/app/Support/ExactSql.php`
- `backend/app/Support/FinancialRead.php`
- `backend/app/Support/ListQuery.php`
- `backend/app/Support/PaymentList.php`
- `backend/bin/benchmark-reads.php`
- `backend/database/migrations/2026_11_10_000001_add_measured_list_indexes.php`
- `backend/routes/api.php`
- `backend/tests/Feature/DashboardAndListsTest.php`
- `backend/tests/Feature/FinancialRetentionMigrationTest.php`
- `backend/tests/Feature/ListIndexMigrationTest.php`
- `backend/tests/Feature/ReadOptimizationSafetyTest.php`
- `docs/benchmarks/lot-6.json`
- `docs/lot-6-performances.md`
- `frontEnd/src/components/clients/Clients.jsx`
- `frontEnd/src/components/invoices/Invoices.jsx`
- `frontEnd/src/components/payments/Payments.jsx`
- `frontEnd/src/components/products/Products.jsx`
- `frontEnd/src/components/quotes/Quotes.jsx`
- `frontEnd/src/components/shared/ListControls.jsx`
- `frontEnd/src/components/shared/RemoteSelect.jsx`
- `frontEnd/src/hooks/useServerList.js`
- `frontEnd/src/pages/dashboard/Overview.jsx`
- `frontEnd/src/services/financialView.js`
- `frontEnd/src/services/lists.js`
- `frontEnd/src/styles/ListControls.css`
- `frontEnd/tests/financialLists.test.js`
- `scripts/ops/smoke_container.py`
