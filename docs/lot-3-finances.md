# Lot 3 — Paiements atomiques et états financiers

## Règles livrées

- Un paiement exige une facture émise, non annulée et non archivée. Un brouillon doit être émis avant encaissement.
- La facture est verrouillée en premier dans chaque création/correction de paiement, édition documentaire, transition et archivage. Les règlements sont relus sous verrou dans la transaction ; le solde n’est jamais validé depuis Redis.
- Le contrôle du solde, l’écriture du paiement, la révision et le recalcul de statut sont atomiques. Les deadlocks sont rejoués de manière bornée par Laravel ; un conflit persistant produit 409. Les autres erreurs SQL ne sont pas masquées et annulent les écritures de la transaction.
- Montants en DECIMAL(12,2), calculs PHP BigDecimal via brick/math déjà présent et désormais déclaré directement. Aucune version de dépendance n’a été mise à niveau. Entrées positives pour les paiements, deux décimales maximum, notation scientifique refusée, borne maximale 9999999999.99. Les quantités, prix, taux et totaux de lignes sont aussi bornés.
- Arrondi HALF_UP du HT par ligne, puis de sa taxe ; TTC = HT arrondi + taxe arrondie ; les totaux sont les sommes des lignes. Les aperçus React de factures et devis utilisent le même ordre avec des centimes BigInt.
- Les montants/taxes/données historiques ne sont jamais recalculés par migration.

## États et transitions

`document_status` est distinct de l’état financier : draft, issued, cancelled. Pour les anciennes lignes où il reste NULL, sa lecture interprète le statut historique sans écrire en base. Les nouvelles factures enregistrent explicitement leur état.

- draft → issued : admin/comptable, lignes et totaux cohérents, absence de règlements, archive inactive ; création de issued_at et issued_snapshot.
- issued → cancelled : admin/comptable et aucun règlement.
- issued/cancelled → draft : refusé.
- cancelled → issued : refusé.
- Le contenu d’une facture émise ou annulée est figé, avant même le premier paiement. Aucun changement de client, lignes, prix, taxes, remise, dates ou notes documentaires. Le modèle et le service protègent les champs de la facture ; les accès SQL directs restent hors protection applicative.
- Archivage/restauration : règles du lot 1 conservées, uniquement pour les brouillons sans règlement.
- `payment_status` est calculé : unpaid, partial, paid ou not_required pour un total nul. Le retard est calculé par date et solde ; il peut coexister avec un règlement partiel.
- `status` reste une projection de compatibilité draft/sent/partial/paid/overdue/cancelled. Pour une facture émise non soldée, overdue est prioritaire après échéance. Le statut brut demeure disponible aux rôles financiers sous legacy_status pour l’audit.

Les requêtes de création/édition n’acceptent plus paid/partial/overdue. Les transitions passent par le service, aussi sur POST/PUT/PATCH : masquer un sélecteur React ne constitue pas une protection.

## Historique et instantanés

À l’émission, un instantané enregistre le client, l’entreprise, les lignes, conditions, devise, dates et montants utilisés. Les consultations utilisent cet instantané pour les identités/lignes ; les relations SQL d’origine sont conservées. Un changement du carnet client ou des paramètres ne réécrit donc pas un nouveau document émis.

Les anciens documents n’ont pas d’instantané reconstitué automatiquement : les coordonnées d’origine ne peuvent pas être déduites avec certitude. L’audit les signale, sans inventer un historique.

Chaque correction de paiement exige `version` et `reason` (3 à 1000 caractères). Les valeurs avant/après, l’auteur et la date sont conservés dans payment_revisions au sein de la transaction. Une version obsolète reçoit 409. Les révisions sont consultables par admin/comptable et ne sont pas modifiables/supprimables par leurs modèles ou API.

L’annulation comptable d’un paiement n’est pas ajoutée : la suppression physique reste interdite. Un futur mécanisme d’annulation justifiée pourra s’appuyer sur cet historique sans effacer le paiement.

## Contrat API

- Création de facture : draft ou sent (émission), avec les permissions existantes. Une création directement annulée ne constitue pas une transition autorisée.
- Édition de facture : uniquement brouillon actif, sans règlements. Une édition complète d’une facture émise renvoie 409 pour un rôle autorisé, 403 si le rôle ne peut pas agir.
- PATCH /invoices/{id}/status : draft, sent ou cancelled uniquement, avec les transitions ci-dessus.
- POST /invoices/{id}/payments : amount, payment_date, payment_method et options reference/notes/idempotency_key (UUID).
- La clé d’idempotence est optionnelle pour compatibilité API ; le frontend en génère une stable pour une tentative et ses reprises. Même clé/même contenu retrouve le paiement (201 conservé pour compatibilité) ; même clé/autre contenu renvoie 409. Sans clé, seules les contraintes de solde sont garanties, pas la déduplication réseau.
- PUT/PATCH paiement : motif et version obligatoires. L’API restitue la nouvelle version et ses révisions.
- Les réponses financières donnent paid_amount, balance_due, payment_status, is_overdue, financial_issues et financial_review_required aux rôles autorisés. Les employés conservent la lecture des documents individuels et de leur statut de compatibilité, sans les détails/agrégats de règlements.
- 401 : authentification absente ; 403 : rôle interdit ; 404 : autre entreprise ou mauvais parent ; 409 : état, version ou concurrence incompatible ; 422 : données invalides, précision ou surpaiement.

Les sept Policies, Sanctum et les contraintes RESTRICT sont conservés. Aucune promotion de rôle ni modification de rattachement à une entreprise.

## Cache

Les listes/détails financiers de factures sont lus en base et présentés selon les permissions : l’ancien cache de liste ne peut plus retourner un solde/statut périmé. Les invalidations des clés de factures et compteurs du dashboard sont différées après commit ; un rollback n’invalide pas. Une panne d’invalidation après commit est signalée sans transformer un paiement enregistré en réponse d’échec.

Les compteurs du dashboard peuvent rester temporairement périmés pendant une panne de cache, jusqu’à leur TTL court. Ils ne servent jamais à autoriser une opération financière. Les factures et soldes restent lus depuis la base. Les brouillons sont exclus des indicateurs de facturation financière affichés par React.

## Migration et audit

Nouvelle migration : 2026_11_07_000001_add_financial_integrity_fields.php. Ajouts : état documentaire nullable, date/instantané d’émission, version/idempotence des paiements, table des corrections avec restrictions de relation. Aucune reprise automatique des statuts ou montants anciens.

Le rollback refuse de détruire des instantanés ou des corrections existants. Sous MySQL, il conserve/crée un index de soutien invoice_id avant de retirer l’index composite d’idempotence : MySQL peut avoir remplacé son index implicite de clé étrangère par celui-ci. Le test de migration aller/retour vérifie la conservation des références et montants existants.

`php artisan billing:audit` est une commande en lecture seule qui examine les totaux, lignes, règlements, statuts anciens et instantanés. Code 1 signifie des dossiers à examiner, pas des corrections automatiques.

Audit effectué sur la connexion distante configurée : **2 factures examinées, une à vérifier (facture ID 1, entreprise 2), uniquement pour absence d’instantané historique**. Aucune donnée distante modifiée et aucune migration distante exécutée.

## Vérifications et concurrence

Les tests SQLite restent fonctionnels, pas une preuve de verrouillage InnoDB. SQLite utilise une transaction IMMEDIATE avec délai d’attente borné. Les nouveaux tests MySQL lancent deux processus PHP, synchronisent leur démarrage, vérifient leur attente derrière le verrou de facture, puis contrôlent le résultat et les écritures finales :

1. Deux paiements de 70 sur un total de 100 : un seul accepté, solde final 30.
2. Deux corrections de 20 à 60 sur des règlements distincts : une seule acceptée, cumul final 80.
3. Deux corrections concurrentes du même règlement : une seule version acceptée, une seule révision.
4. Paiement contre annulation : une seule opération acceptée, aucune facture annulée encaissée.

Ces scénarios ont été exécutés sur un vrai conteneur MySQL 8 local isolé, pas sur la base distante. La CI MySQL rejoue aussi la suite complète. Le code de tests refuse les bases non locales ou sans suffixe _test, et la classe de base bloque les migrations de test hors SQLite en mémoire/MySQL local de test.

Autres tests : paiement partiel/total, limites monétaires, centimes, idempotence, version/motif, états annulé/archivé, statut payé falsifié, facture émise/payée figée, instantanés, rollback sur erreur SQL, cache après commit, panne de cache, audit non mutant et migration conservatrice. Les suites de conservation et RBAC sont rejouées avec des fixtures adaptées : un ancien statut financier est une donnée historique de test, plus une valeur créable librement par API.

Commandes locales : `php artisan test --compact`, `npm test`, `npm run lint`, `npm run build`, `composer validate --no-check-publish`. Les tests Node vérifient notamment les aperçus en centimes et les permissions ; ils ne remplacent pas une validation visuelle en navigateur.

## Avant déploiement Render

1. Examiner le dossier historique signalé et déterminer une stratégie explicite pour les instantanés anciens ; ne pas les fabriquer silencieusement.
2. Vérifier les migrations et contraintes réellement présentes, mettre les écritures en maintenance, sauvegarder la base entière et tester sa restauration sur une copie.
3. Appliquer les migrations à cette copie et vérifier les volumes, relations, montants, statuts et parcours des trois rôles.
4. Coordonner backend/frontend : motif/version deviennent obligatoires pour les corrections ; les statuts financiers ne sont plus des entrées libres ; l’émission précède les paiements.
5. Le Dockerfile lance migrate --force au démarrage : préparer sauvegarde et fenêtre de livraison AVANT tout déploiement/redémarrage de cette version. Aucune migration de production n’a été exécutée dans ce travail.
6. Confirmer les tests SQLite/MySQL et les tests/lint/build React en CI avant livraison.
7. Pour toute reprise après échec MySQL DDL, réinspecter le schéma : les migrations DDL ne sont pas globalement atomiques. Préférer une correction en avant ; le rollback protège l’historique et peut refuser une suppression de colonnes.

## Limites restantes

La numérotation globale et l’idempotence de conversion devis–facture restent du lot 4. Ce lot n’ajoute ni avoirs, ni remboursement, ni comptabilité générale, ni annulation de règlement. Les traitements SQL externes peuvent contourner les règles applicatives ; les droits SQL et sauvegardes restent nécessaires. Les données déjà perdues et les anciennes identités non historisées ne sont pas récupérées. Le verrouillage ne protège que les chemins d’écriture utilisant le protocole commun ; les nouvelles fonctionnalités devront passer par les services financiers.

## Fichiers du lot 3

- `.github/workflows/ci.yml`
- `backend/README.md`
- `backend/app/Console/Commands/AuditInvoiceFinances.php`
- `backend/app/Http/Controllers/Concerns/ArchivesDrafts.php`
- `backend/app/Http/Controllers/InvoiceController.php`
- `backend/app/Http/Controllers/PaymentController.php`
- `backend/app/Http/Controllers/QuoteController.php`
- `backend/app/Http/Controllers/QuoteItemController.php`
- `backend/app/Models/Invoice.php`
- `backend/app/Models/Payment.php`
- `backend/app/Models/PaymentRevision.php`
- `backend/app/Policies/InvoicePolicy.php`
- `backend/app/Services/FinancialAudit.php`
- `backend/app/Services/FinancialTransaction.php`
- `backend/app/Services/InvoiceService.php`
- `backend/app/Services/MoneyCalculator.php`
- `backend/app/Services/PaymentService.php`
- `backend/app/Support/InvoiceView.php`
- `backend/composer.json`
- `backend/composer.lock`
- `backend/config/database.php`
- `backend/database/migrations/2026_11_07_000001_add_financial_integrity_fields.php`
- `backend/tests/Feature/FinancialDataRetentionTest.php`
- `backend/tests/Feature/FinancialIntegrityMigrationTest.php`
- `backend/tests/Feature/FinancialPaymentsTest.php`
- `backend/tests/Feature/MySqlPaymentConcurrencyTest.php`
- `backend/tests/Feature/RolePermissionsTest.php`
- `backend/tests/Support/payment_worker.php`
- `backend/tests/TestCase.php`
- `docs/lot-3-finances.md`
- `frontEnd/src/components/invoices/Invoices.jsx`
- `frontEnd/src/components/quotes/Quotes.jsx`
- `frontEnd/src/pages/dashboard/Overview.jsx`
- `frontEnd/src/services/money.js`
- `frontEnd/src/services/permissions.js`
- `frontEnd/tests/money.test.js`
- `frontEnd/tests/permissions.test.js`

## Résultats finaux exécutés

| Vérification | Résultat |
| --- | --- |
| PHPUnit SQLite en mémoire | 235 réussis, 4 ignorés (concurrence MySQL), 0 échoué ; 1123 assertions |
| PHPUnit MySQL 8.0.46 local isolé | 239 réussis, 0 ignoré, 0 échoué ; 1150 assertions |
| Concurrence multi-processus MySQL (incluse ci-dessus) | 4 scénarios réussis ; 27 assertions |
| Tests frontend Node | 11 réussis, 0 échoué |
| ESLint | Réussi |
| Build React/Vite | Réussi |
| Composer validate | Réussi |
| Laravel Pint | Fichiers modifiés formatés |
| git diff --check | Réussi |

La première suite MySQL a révélé le problème d’index de clé étrangère au rollback ; il a été corrigé puis la suite complète a été relancée avec succès. Le conteneur temporaire a été arrêté/supprimé et Docker Desktop a été remis à l’arrêt, son état initial. Les variables de connexion locales ont été restaurées après chaque commande MySQL ; le fichier .env n’a pas été modifié. L’image MySQL téléchargée reste dans le cache Docker local.

La validation visuelle des interfaces dans un navigateur n’a pas été effectuée. Aucun déploiement, migration distante ou changement des données de production n’a été réalisé.


## Corrections après audit final du lot 3

Les cinq anomalies P1 et les deux anomalies P2 validées ont été corrigées sans migration ni modification des données de production.

- Les dates de paiement et de facture reçues par les API concernées exigent `Y-m-d`. Les formats textuels, ambigus et les timestamps sont refusés avec 422. Une correction sans nouvelle date conserve exactement la date précédente.
- Les PATCH de facture comparent les dates effectives, y compris les champs inchangés, après verrouillage de la facture. L'émission vérifie également leur cohérence. Une échéance nulle reste autorisée.
- L'archivage/restauration des brouillons utilise une seule invalidation protégée après commit. Toutes les clés concernées sont tentées même si l'une échoue ; le cache des devis est inclus. Un rollback n'invalide pas le cache.
- Le rollback de la migration financière refuse aussi de supprimer les clés, empreintes ou versions de paiement utilisées. Son `up()` reste inchangé : aucune nouvelle migration de schéma n'est nécessaire pour appliquer ces corrections à une base ayant déjà reçu le lot 3.
- React reconnaît le succès d'un paiement avant de rafraîchir la facture. En cas de panne de lecture, un avertissement confirme l'enregistrement et bloque les nouvelles opérations jusqu'à l'actualisation du solde. Une erreur de mutation conserve la saisie.
- Sur 409 pendant une correction, un bouton permet explicitement de recharger la version du règlement tout en conservant les champs saisis et le motif. L'utilisateur doit vérifier puis soumettre sa correction ; aucun renvoi automatique.
- Les badges d'échéance acceptent les dates ISO Laravel et comparent des jours calendaires. Les factures payées/annulées n'affichent pas de badge.
- Les aperçus de facture et de devis signalent les montants invalides et les dépassements par multiplication ou cumul. Ils affichent un tiret et une erreur, puis bloquent la soumission ; ils ne remplacent plus ces montants par zéro.

### Fichiers concernés par les corrections

Backend :

- `backend/app/Http/Controllers/Concerns/ArchivesDrafts.php`
- `backend/app/Http/Controllers/InvoiceController.php`
- `backend/app/Http/Controllers/PaymentController.php`
- `backend/app/Services/FinancialTransaction.php`
- `backend/app/Services/InvoiceService.php`
- `backend/app/Services/PaymentService.php`
- `backend/database/migrations/2026_11_07_000001_add_financial_integrity_fields.php`
- `backend/tests/Feature/FinancialDataRetentionTest.php`
- `backend/tests/Feature/FinancialIntegrityMigrationTest.php`
- `backend/tests/Feature/FinancialPaymentsTest.php`

Frontend et documentation :

- `frontEnd/src/components/invoices/Invoices.jsx`
- `frontEnd/src/components/quotes/Quotes.jsx`
- `frontEnd/src/services/money.js`
- `frontEnd/src/services/paymentWorkflow.js` (nouveau)
- `frontEnd/tests/money.test.js`
- `frontEnd/tests/paymentWorkflow.test.js` (nouveau)
- `docs/lot-3-finances.md`

### Vérifications après corrections

- SQLite en mémoire : 250 tests réussis, 4 ignorés (concurrence MySQL), 0 échec ; 1 238 assertions.
- MySQL 8.0.46 local isolé : 254 tests réussis, 0 ignoré, 0 échec ; 1 265 assertions. Les quatre scénarios de concurrence multiprocessus sont inclus.
- Frontend : 20 tests réussis, 0 échec.
- ESLint, build React dans un dossier temporaire, Composer validate, Pint et git diff --check : réussis.
- Le conteneur MySQL temporaire et ses volumes ont été supprimés ; Docker Desktop a été remis à l’arrêt. Les variables ont été limitées aux processus de test ; aucun fichier .env n’a été modifié.

Les 15 nouveaux cas backend couvrent les dates, les PATCH/émissions incohérents, les pannes de cache après archivage/restauration, le rollback transactionnel et la conservation des métadonnées de paiement. Les 9 nouveaux tests frontend couvrent les limites monétaires, les dates ISO, l'ordre mutation/rafraîchissement et la conservation du brouillon de correction.

### Précautions et validation manuelle restante

Les clients API doivent envoyer des dates calendrier `AAAA-MM-JJ` ; vérifier les intégrations existantes avant livraison coordonnée backend/frontend. La clé d'idempotence reste facultative pour préserver les anciens clients. Sa persistance au-delà d'un rechargement du navigateur et les optimisations volumétriques ne sont pas ajoutées par ces corrections.

Avant livraison, vérifier visuellement : création/émission, paiements partiel/complet, correction et historique, 403/422, deux onglets en conflit 409, paiement réussi suivi d'un GET échoué, échéances et dépassements de montants sur facture/devis. Les tests Node ne constituent pas des tests de navigateur.

Conserver la procédure de sauvegarde et de restauration sur copie décrite ci-dessus. Le Dockerfile exécute toujours les migrations au démarrage. Aucun ancien instantané n'a été fabriqué ; les anciens documents sans preuve historique conservent leur signalement. Aucune optimisation du lot 6, aucun travail du lot 4, aucun commit ni déploiement ne fait partie de ces corrections.
