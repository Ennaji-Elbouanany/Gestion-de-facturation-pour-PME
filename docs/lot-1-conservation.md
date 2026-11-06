# Lot 1 — Conservation de l’historique financier

## Comportement livré

- Clients : archivage SoftDeletes, liste des archives et restauration. Aucun endpoint de purge.
- DELETE /api/clients/{id} archive le client (200), même si déjà archivé.
- GET /api/clients?archived=1 retourne les archives paginées de l’entreprise ; la liste normale et les sélecteurs restent actifs uniquement.
- PATCH /api/clients/{id}/restore restaure le client (200), de manière idempotente.
- Les relations client des factures/devis incluent les archives. Les paiements retrouvent ainsi leur client historique.
- Un nouveau document ne peut pas sélectionner un client archivé. Un document existant peut garder le sien ; la conversion d’un devis existant reste possible.
- DELETE factures/devis/paiements retourne 409 après vérification d’appartenance. Les données et soldes sont inchangés.
- PATCH /api/{invoices|quotes}/{id}/archive et /restore conservent le numéro et les lignes. Seuls les brouillons sont éligibles ; une facture avec règlement ne l’est pas.
- Les listes de documents incluent les archives avec archived_at. Les brouillons archivés sont consultables mais leurs mutations, paiements et conversions sont refusés (409) jusqu’à restauration.
- Les compteurs et KPI du dashboard excluent les brouillons archivés ; les archives client ne suppriment aucun montant ni règlement. Les caches concernés sont invalidés après archivage/restauration.
- Les modèles Eloquent refusent les suppressions physiques des documents/règlements et forceDelete des clients. Les suppressions massives Query Builder et SQL direct contournent les événements des modèles : elles ne doivent pas être utilisées pour ces enregistrements.
- Les contraintes RESTRICT bloquent les suppressions de parents ayant des enfants financiers. Les liens utilisateur/produit en SET NULL sont conservés.

## Fichiers principaux

Deux nouvelles migrations, les modèles Client/Invoice/Quote/Payment et leur trait de conservation, les contrôleurs clients/documents/paiements/lignes et leur trait d’archivage, routes/api.php, les composants React clients/documents/paiements, Overview.jsx et les tests FinancialDataRetentionTest / FinancialRetentionMigrationTest. La CI ajoute un job MySQL 8 pour ces tests, en complément de SQLite.

## Avant toute migration de production

**Aucune migration de production n’est exécutée par ce travail.** Attention : le Dockerfile actuel lance déjà `php artisan migrate --force` au démarrage. Effectuer les étapes ci-dessous AVANT de déclencher un déploiement ou redémarrage avec cette version. Suspendre l’auto-déploiement le temps de préparer cette opération.

1. Identifier le moteur, la version, la base cible et l’espace libre sans afficher les secrets. Les tests ne doivent jamais viser la production.
2. Mettre l’application en maintenance et arrêter les écritures externes/jobs pendant sauvegarde et migration. Pour SQLite, le remplacement des contraintes reconstruit les tables ; prévoir suffisamment d’espace et une fenêtre de maintenance.
3. Inspecter les contraintes existantes. Pour MySQL, consulter `information_schema.KEY_COLUMN_USAGE` et `information_schema.REFERENTIAL_CONSTRAINTS` pour la base courante, ou `SHOW CREATE TABLE` sur clients, invoices, quotes, invoice_items, quote_items, payments. Pour SQLite, utiliser `PRAGMA foreign_key_list(nom_table)` et `PRAGMA foreign_key_check`.
4. Vérifier les huit relations ciblées : clients.company_id ; invoices.company_id/client_id ; quotes.company_id/client_id ; invoice_items.invoice_id ; quote_items.quote_id ; payments.invoice_id. Elles doivent référencer les tables attendues et leur clé id. Rechercher les orphelins. La migration inspecte toutes ces relations et les orphelins avant sa première modification de contraintes ; une relation absente ou inattendue arrête cette migration. Les migrations précédentes peuvent toutefois déjà avoir été appliquées.
5. Sauvegarder l’intégralité de la base AVANT migration et conserver la sauvegarde hors du conteneur/disque éphémère. Préférer le snapshot fourni par l’hébergeur. Pour MySQL/InnoDB, un exemple à adapter : `mysqldump --login-path=production --single-transaction --routines --triggers --events --hex-blob --result-file=/chemin/securise/avant-lot1.sql NOM_BASE`. Ne pas passer le mot de passe dans la commande. Pour MariaDB, utiliser l’outil de sauvegarde correspondant. Pour SQLite, utiliser l’API de sauvegarde SQLite ou `.backup` ; ne pas copier à chaud le seul fichier principal lorsqu’un WAL existe.
6. Restaurer cette sauvegarde dans une base isolée ; vérifier les volumes et relations, puis y exécuter les migrations et les contrôles de conservation. Une sauvegarde non restaurée n’est pas une validation suffisante.
7. Après validation sur la copie et succès de la CI MySQL, exécuter les migrations sur la base de production sauvegardée. Vérifier les volumes de chaque table, les relations RESTRICT, les documents avec leurs clients, paiements et lignes. Réchauffer/invalider uniquement les caches utiles et rouvrir les écritures après contrôle.

MySQL ne garantit pas une transaction couvrant toutes les DDL : une panne intermédiaire peut laisser une migration partiellement appliquée. Réinspecter le schéma avant reprise. La migration SQLite désactive temporairement les contraintes hors transaction, reconstruit les tables dans une transaction, vérifie `foreign_key_check`, puis réactive les contraintes.

## Retour arrière

Le down de la migration des contraintes rétablit les anciennes cascades : il réduit donc la protection et doit être exécuté uniquement en maintenance, sur sauvegarde validée. Le down des colonnes refuse de supprimer les marqueurs tant qu’un client ou document est archivé, afin de ne pas réactiver silencieusement des archives. Privilégier une correction en avant ; ne jamais lancer migrate:fresh en production.

## Vérification locale / CI

Depuis backend : `php artisan test`. Les tests standards utilisent SQLite en mémoire, cache array, mail array et ne contactent pas Redis de production.

Les tests Financial vérifient : conservation intégrale, restauration/idempotence, isolation des entreprises, validation des clients archivés, restrictions API/modèles/SQL, cache/compteurs, blocage des mutations d’archives, migration aller/retour avec données existantes, conservation des index et SET NULL, refus de préflight inattendu et garde du rollback des archives.

Le job GitHub Actions `Financial retention (MySQL 8)` exécute `php artisan test --filter=Financial` sur une base dédiée `retention_test`. Son résultat distant doit être vérifié après push ; la présence du job n’est pas un résultat de test MySQL.

Depuis frontEnd : `npm run lint` puis `npm run build`. Vérification manuelle recommandée : archiver/restaurer un client facturé ; modifier un document conservant ce client ; archiver/restaurer un brouillon ; consulter les lignes d’un devis archivé ; vérifier le dashboard après chaque opération.

## Limites assumées

Ce lot ne fournit pas une comptabilité immuable : les changements de montants, remplacements de lignes des documents actifs, corrections de paiements, transitions de statut et conditions de concurrence des paiements relèvent des lots suivants. Un statut repassé à draft n’est pas encore une preuve qu’un document n’a jamais été émis ; la machine à états sera traitée séparément. Aucun système d’avoirs, d’annulation comptable ou de permissions par rôle n’est ajouté ici. Les données déjà supprimées ne sont pas récupérées. Un administrateur SQL peut contourner la protection applicative ; conserver des sauvegardes et des droits SQL maîtrisés.

## Inventaire des fichiers modifiés ou ajoutés

- `.github/workflows/ci.yml`
- `backend/README.md`
- `backend/routes/api.php`
- `backend/app/Models/Client.php`
- `backend/app/Models/Invoice.php`
- `backend/app/Models/Quote.php`
- `backend/app/Models/Payment.php`
- `backend/app/Models/Concerns/PreventsPhysicalDeletion.php` (nouveau)
- `backend/app/Http/Controllers/ClientController.php`
- `backend/app/Http/Controllers/InvoiceController.php`
- `backend/app/Http/Controllers/QuoteController.php`
- `backend/app/Http/Controllers/PaymentController.php`
- `backend/app/Http/Controllers/QuoteItemController.php`
- `backend/app/Http/Controllers/DashboardController.php`
- `backend/app/Http/Controllers/Concerns/ArchivesDrafts.php` (nouveau)
- `backend/database/migrations/2026_11_06_000001_add_retention_archive_columns.php` (nouveau)
- `backend/database/migrations/2026_11_06_000002_restrict_financial_parent_deletions.php` (nouveau)
- `backend/tests/Feature/FinancialDataRetentionTest.php` (nouveau)
- `backend/tests/Feature/FinancialRetentionMigrationTest.php` (nouveau)
- `frontEnd/src/components/clients/Clients.jsx`
- `frontEnd/src/components/invoices/Invoices.jsx`
- `frontEnd/src/components/quotes/Quotes.jsx`
- `frontEnd/src/components/payments/Payments.jsx`
- `frontEnd/src/pages/dashboard/Overview.jsx`
- `docs/lot-1-conservation.md` (nouveau)
