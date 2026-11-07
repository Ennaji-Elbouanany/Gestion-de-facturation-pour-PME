# Lot 4 — Numérotation et conversion commerciale

## Règles livrées

- Un compteur par entreprise, type (`invoice`/`quote`) et année UTC de l'allocation, pas par préfixe. Les préfixes existants des factures sont conservés ; les devis utilisent DEV-. Changer le préfixe ne réinitialise pas la séquence.
- La première allocation recherche le maximum numérique historique de cette entreprise et de cette année, tous préfixes et archives compris. Les numéros existants ne sont jamais modifiés. Le remplissage est de trois chiffres minimum : 999, 1000, 1001. La borne technique est 18 chiffres ; un historique hors borne bloque l'allocation avec 409.
- L'allocation et la création appartiennent à la même transaction. Un échec de ligne ou de conversion annule aussi la réservation. Les imports ultérieurs doivent passer par le protocole d'allocation ou faire l'objet d'une revue contrôlée des compteurs : aucun import SQL concurrent non coordonné n'est supporté.
- Toutes les écritures commerciales du lot 4 commencent par le verrou entreprise. Ensuite : devis existant, compteur si nécessaire, puis lignes. Une ligne nouvellement créée n'est pas partagée avant commit. Le protocole facture/paiements du lot 3 reste inchangé ; il ne tente pas de prendre ensuite le verrou entreprise.
- Les brouillons actifs restent modifiables. Après envoi, contenu, montants, numéros et lignes sont figés ; le parent d'une ligne et le lien de conversion sont permanents dans les écritures ORM. Les services restent obligatoires pour toute écriture métier ; du SQL administrateur ou des mises à jour bulk peuvent contourner des événements Eloquent.

## Transitions et rôles

| État | Suite autorisée |
| --- | --- |
| draft | sent |
| sent | accepted, rejected, expired |
| accepted | converted, exclusivement par conversion |
| rejected, expired, converted | terminal |

Une répétition du même état ordinaire est un no-op autorisé. `converted` est toujours refusé sur les endpoints génériques. Expiration seulement si valid_until est antérieur à aujourd'hui UTC ; une acceptation après cette échéance est refusée. Un devis déjà accepté ne devient pas expiré silencieusement. L'envoi exige des lignes et des totaux cohérents. Une création initiale accepte draft ou sent ; un autre état ordinaire à la création est un conflit 409.

Les rôles du lot 2 restent identiques : admin/employee créent, modifient les brouillons et gèrent les transitions ; admin/comptable convertissent ; les trois rôles peuvent consulter les documents de leur entreprise. L'état métier est recontrôlé après acquisition des verrous, en plus des Policies. Les archives/restaurations du lot 1 restent réservées aux brouillons.

## Contrat API

- POST /api/quotes/{id}/convert : 201 pour une nouvelle facture, 200 pour une répétition, même représentation InvoiceView et même ID.
- La facture créée est un brouillon. Son émission, ses instantanés, soldes et paiements passent toujours par les règles du lot 3.
- Les descriptions, quantités, prix, taux, sous-totaux, totaux, remises, notes, client et produits sont copiés sans recalcul des données historiques. La cohérence est vérifiée avant la copie ; une incohérence est bloquée, sans réparation automatique.
- L'échéance de conversion conserve le comportement existant de 30 jours ; aucun changement des préférences de facturation n'est inclus.
- Un ancien devis converted sans lien fiable renvoie 409. Aucun rapprochement par montant, client, date ou numéro n'est inventé.
- 401 : non authentifié ; 403 : rôle interdit ; 404 : ressource d'une autre entreprise ou mauvais parent ; 409 : état incompatible, document gelé, historique ambigu, verrouillage épuisé ; 422 : validation ou tentative directe de converted par un acteur autorisé. Une interdiction de rôle peut produire 403 avant la validation du statut.
- Après commit, les caches devis/factures/dashboard sont invalidés via FinancialTransaction. Un rollback n'invalide pas les caches et une panne de cache ne transforme pas une écriture validée en échec.
- React masque les actions selon l'état et les permissions, bloque la conversion pendant son exécution, accepte les réponses 200/201, conserve les erreurs explicites et permet de consulter la facture associée. Le panneau de lignes est synchronisé après changement de statut.

## Nouvelle migration uniquement

`2026_11_08_000001_add_document_integrity.php` :

1. Vérifie les index globaux existants et l'absence de doublons au sein d'une entreprise avant la première DDL. Un schéma inattendu ou partiellement migré est refusé.
2. Crée document_sequences, avec clé unique company_id/document_type/year et relation entreprise restrictive.
3. Remplace l'unicité globale par company_id/invoice_number et company_id/quote_number.
4. Ajoute invoices.source_quote_id nullable, unique, et une FK composite company_id/source_quote_id vers quotes.company_id/id avec RESTRICT. La base refuse deux factures par devis et les liens interentreprises.
5. Ne remplit aucun lien et ne renumérote aucune ligne. Les compteurs sont initialisés à la première allocation, pas par modification de l'historique dans la migration.

Le down refuse de détruire des compteurs ou liens utilisés, ou de restaurer une unicité globale incompatible avec les numéros désormais partagés entre entreprises. Les index nécessaires aux FK MySQL sont préservés. SQLite reconstruit ses tables avec vérification foreign_key_check ; les tests couvrent les cycles up/down inutilisés et les migrations précédentes.

## Audit conservateur et revue historique

`php artisan documents:audit` est une commande de lecture seule : elle décrit les index d'unicité, signale les références atypiques/hors borne, les anciennes conversions sans lien, les liens interentreprises éventuels et les compteurs inférieurs à l'historique. Elle n'alloue aucun numéro et ne modifie ni document ni statut. Elle fonctionne aussi avant la nouvelle migration.

Avant livraison, produire et conserver ce rapport sur une copie autorisée, avec billing:audit et rbac:audit-accounts. Examiner manuellement chaque conversion ancienne à partir de preuves. L'absence de lien doit rester signalée tant qu'une décision explicite n'est pas prise. Aucun audit de production n'a été exécuté dans ce travail.

## Livraison et retour arrière

Respecter docs/deploiement-securise.md : sauvegarde complète, restauration réellement vérifiée sur une copie isolée, schéma/contraintes/données comparés, puis plan de correction en avant relu et approuvé. Aucun script livré n'exécute de migration en production ; ne jamais utiliser migrate:fresh, refresh ou un rollback global en production.

Arrêter les écritures de toutes les anciennes instances et des traitements/imports avant de basculer sur les compteurs. Ne pas faire écrire simultanément l'ancien générateur MAX+1 et le nouveau service. L'unicité globale doit être remplacée avant l'utilisation des séquences par entreprise. /ready doit rester négatif tant que le schéma attendu n'est pas préparé.

Ne pas revenir à une version applicative antérieure au lot 4 une fois ses compteurs/liens utilisés : elle ignore ces invariants. Garder la maintenance et privilégier une correction en avant compatible avec le schéma courant. Aucun instantané existant, rôle, token Sanctum ou règlement n'a été modifié par ce lot.

## Tests et harnais de concurrence

Le harnais commun Tests\Support\ConcurrentProcesses lance des processus PHP et connexions MySQL indépendants. READY/ATTEMPT et le signal de démarrage passent par des pipes, sans fichiers workers exécutables ni suppression de marqueurs sur disque. Le coordinateur maintient le verrou parent, vérifie que les deux processus restent bloqués, puis les libère. L'ancien payment_worker.php est remplacé par ce harnais ; les quatre scénarios du lot 3 sont conservés.

Les cinq nouveaux scénarios exécutent le noyau HTTP Laravel : deux factures, deux devis, facture face à conversion, deux conversions identiques, conversion face à modification interdite. Le worker vérifie APP_ENV=testing et la connexion réellement résolue sur MySQL localhost avec une base *_test avant toute requête. SQLite ne prouve pas ces verrous : les neuf cas de concurrence y sont ignorés.

Les processus PHP de concurrence sous Windows ont été interrompus après un problème local de fichiers/processus. Aucun antivirus ni réglage de sécurité n'a été modifié. Les preuves complètes sont obtenues sous PHP Linux dans un conteneur isolé, comme en CI. Les suites normales SQLite et frontend restent exécutées localement.

Les tests de rôles/rétention ont été adaptés uniquement pour les nouvelles règles approuvées : l'ancien raccourci de conversion d'un brouillon est remplacé par une acceptation préalable ; les états historiques sont insérés explicitement dans les fixtures ; les statuts converted arbitraires et les éditions après envoi sont désormais refusés.

## Résultats finaux

| Vérification | Résultat |
| --- | --- |
| PHPUnit MySQL 8.0.46, PHP 8.2.35 Linux isolé | 282 réussis, 0 échec, 1470 assertions |
| PHPUnit SQLite local | 273 réussis, 0 échec, 9 ignorés (concurrence MySQL), 1403 assertions |
| Concurrence multiprocessus MySQL | 9 scénarios réussis : 5 documents et 4 paiements |
| Tests frontend | 24 réussis, 0 échec |
| Tests des protections de déploiement | 11 réussis, 0 échec |
| ESLint, build React, Composer validate | Réussis |
| Laravel Pint sur les fichiers PHP concernés | Réussi |
| actionlint et Docker build | Réussis |
| Tests HTTP du conteneur de production, limite mémoire 512 MiB | Réussis |

Avant ce lot, la référence locale mesurée était de 257 tests MySQL réussis, 253 SQLite réussis et 4 ignorés, et 20 tests frontend réussis. Les cas existants sont conservés ; les adaptations de fixtures correspondent aux règles commerciales validées. Les erreurs simulées de cache apparaissent volontairement dans les journaux des tests de résilience et ne constituent pas des échecs.

La concurrence native Windows n'est pas validée ; la preuve est celle du runner Linux isolé. La vérification visuelle des parcours React dans un navigateur reste à effectuer. Aucune sauvegarde/restauration de production ni revue de ses données n'a été exécutée. Les contraintes et les conversions historiques doivent être examinées sur une copie autorisée avant livraison.

Aucun commit, push, déploiement Render, changement de rôle ou modification de données de production n'est inclus. Les migrations historiques, les Policies, les services financiers du lot 3 et les workflows GitHub Actions restent inchangés.

## Fichiers livrés

Nouveaux fichiers :

- backend/app/Console/Commands/AuditDocuments.php
- backend/app/Services/DocumentNumberService.php
- backend/app/Services/QuoteConversionService.php
- backend/app/Services/QuoteService.php
- backend/database/migrations/2026_11_08_000001_add_document_integrity.php
- backend/tests/Feature/CommercialDocumentsTest.php
- backend/tests/Feature/DocumentIntegrityMigrationTest.php
- backend/tests/Feature/MySqlDocumentConcurrencyTest.php
- backend/tests/Support/ConcurrentProcesses.php
- frontEnd/src/services/quoteWorkflow.js
- frontEnd/tests/quoteWorkflow.test.js
- docs/lot-4-documents.md

Fichiers modifiés :

- backend/app/Http/Controllers/Concerns/ArchivesDrafts.php
- backend/app/Http/Controllers/InvoiceController.php
- backend/app/Http/Controllers/QuoteController.php
- backend/app/Http/Controllers/QuoteItemController.php
- backend/app/Models/Invoice.php
- backend/app/Models/Quote.php
- backend/app/Models/QuoteItem.php
- backend/tests/Feature/FinancialDataRetentionTest.php
- backend/tests/Feature/FinancialRetentionMigrationTest.php
- backend/tests/Feature/MySqlPaymentConcurrencyTest.php
- backend/tests/Feature/RolePermissionsTest.php
- frontEnd/src/components/quotes/Quotes.jsx
- frontEnd/src/services/permissions.js

Remplacement : backend/tests/Support/payment_worker.php est supprimé et remplacé par ConcurrentProcesses.php ; aucun scénario de paiement n'est supprimé.
