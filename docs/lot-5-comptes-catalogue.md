# Lot 5 — Comptes, validations et cohérence multi-entreprises

## Périmètre et conservation

Implémentation locale à partir de main c89f14e. Aucun commit, push, déploiement Render, changement de configuration distante ou accès à MySQL Aiven. Les seules migrations exécutées l'ont été sur SQLite en mémoire, sur le MySQL 8 de test en loopback et dans le conteneur HTTP isolé. Aucun lot suivant n'est commencé.

Les services financiers du lot 3, les services de numérotation/conversion/transitions du lot 4, les Policies, Sanctum, les migrations historiques et les workflows de livraison ne sont pas modifiés. Les nouvelles validations s'appliquent aux requêtes ; aucune réécriture, fusion ou suppression automatique des données historiques n'est effectuée.

## Comptes et sessions

AccountSecurityService centralise connexion, changement de mot de passe, réinitialisation et modification du profil. Chaque opération prend un verrou utilisateur dans une transaction, sans prendre ensuite de verrou entreprise. Connexion et changement de secret sont sérialisés : une connexion avec l'ancien secret qui précède le changement voit son token révoqué ; celle qui suit est refusée. Le broker valide et consomme le lien de réinitialisation sous le même verrou utilisateur, ce qui interdit sa réutilisation simultanée.

Un changement ou une réinitialisation du mot de passe enregistre le hash, renouvelle remember_token et révoque tous les tokens de ce seul utilisateur dans la même transaction. Une panne de révocation annule aussi le changement du mot de passe. La réponse de changement contient reauthenticate=true ; React supprime son token et revient à la connexion avec un message explicite. Les tokens des autres comptes restent inchangés. La déconnexion conserve son comportement : seul le token utilisé est révoqué.

Les nouveaux tokens de connexion/inscription conservent leur expiration de douze heures. Aucun token historique sans expires_at n'est automatiquement modifié ou supprimé. Leur nombre est signalé par l'audit et leur traitement nécessite une décision distincte. Le stockage Bearer en sessionStorage et l'absence de repli vers les cookies sont conservés.

Le changement d'adresse e-mail exige le mot de passe actuel et efface email_verified_at. Une modification du nom avec la même adresse conserve la vérification. L'unicité des comptes reste globale, avec gestion de la collision SQL en 422. Le nouveau lien est envoyé après validation de l'écriture ; une panne de messagerie ne transforme pas une modification enregistrée en échec ambigu et le bouton de renvoi reste disponible. Les anciennes signatures de vérification ne correspondent plus à la nouvelle adresse.

POST /api/email/verification-notification est authentifié et limité à trois requêtes par minute et par utilisateur. Les modifications du profil et du mot de passe partagent une limite de cinq requêtes par minute et par utilisateur. Le parcours normal « mot de passe oublié » répond de manière identique pour une adresse connue ou inconnue ; les erreurs techniques de messagerie restent journalisées côté serveur.

## Vérification d'e-mail progressive

config/accounts.php introduit ACCOUNTS_REQUIRE_VERIFIED_EMAIL, désactivé par défaut. Aucune variable Render n'est modifiée. Son activation n'est pas une étape automatique de ce lot : elle exige une revue des comptes existants, une messagerie opérationnelle et une validation de livraison séparée.

Si l'option est activée, les routes métier renvoient 403 avec code EMAIL_UNVERIFIED pour un compte non vérifié. /api/user, /api/settings, les parcours de profil/sécurité, le renvoi et la déconnexion restent accessibles. Les opérations de paramètres gardent leurs Policies et leurs rôles : ce parcours de récupération n'accorde aucune autorisation supplémentaire. Les comptes existants ne sont pas marqués vérifiés automatiquement.

## Clients et conservation

Les nouvelles adresses sont normalisées en minuscules avec suppression des espaces périphériques ; une adresse facultative vide devient NULL. Une même adresse est autorisée dans plusieurs entreprises mais doit être unique dans une entreprise, y compris pour ses clients archivés. Un client archivé conserve donc la réservation de son adresse : restaurer cette fiche plutôt que créer un doublon. Plusieurs adresses NULL restent autorisées.

La validation à la modification ignore uniquement l'ID chargé et autorisé. Les écritures de clients prennent le verrou entreprise puis le verrou client si nécessaire ; la contrainte unique assure également la protection contre les doublons SQL. Les collisions produisent une erreur de champ 422. Les contrôles company_id, les 404 interentreprises, SoftDeletes, restauration et références historiques sont conservés.

Le téléphone admet chiffres et séparateurs internationaux usuels, avec au moins un chiffre et une longueur de 5 à 30 caractères. Les identifiants fiscaux admettent lettres, chiffres et séparateurs usuels jusqu'à 100 caractères. Il ne s'agit pas d'une certification fiscale nationale ; aucun format ICE national strict n'est imposé rétroactivement. Les textes libres sont bornés. L'adresse et l'identifiant fiscal sont désormais disponibles dans le formulaire client.

## Produits et paramètres

Prix : 0 à 9999999999.99, deux décimales maximum, selon MoneyCalculator::rules. TVA : 0 à 100, deux décimales maximum. Les prix négatifs, dépassements, précision excessive et notation exponentielle sont refusés avant MySQL. Une TVA absente à la création ou explicitement vide/null vaut zéro ; un champ TVA absent en modification ne change pas la taxe existante.

La suppression d'un produit référencé par une ligne de devis ou facture renvoie 409. Les clés étrangères RESTRICT protègent aussi les liens face au SQL direct et aux écritures concurrentes. Un produit inutilisé reste supprimable par admin ; les rôles ne changent pas. Modifier le catalogue ne recalcule aucune ligne ni aucun instantané existant.

Les paramètres non nullables refusent null avec 422. La TVA par défaut reste nullable et le formulaire conserve réellement cette absence au lieu de la remplacer par 20. Le délai est un entier de 0 à 365 ; les devises admises restent MAD/EUR/USD. Les nouveaux préfixes sont limités à vingt caractères parmi lettres ASCII, chiffres, tiret et underscore ; les numéros existants restent inchangés et le compteur du lot 4 n'est pas réinitialisé. Les URL de logo sont limitées à HTTP/HTTPS. Les écritures de paramètres verrouillent la ligne entreprise ; les autorisations existantes sont inchangées.

Les écritures clients/produits invalident les caches de l'entreprise après commit via le mécanisme financier existant. Un rollback n'invalide pas les caches ; les tests de résilience et de rétention précédents sont rejoués.

## Limites fonctionnelles explicitement conservées

Ce lot ne met pas en place une comptabilité multidevise : des affichages de documents restent en DH, même lorsque la préférence d'entreprise est EUR/USD. Les instantanés existants ne sont jamais réinterprétés. La conversion du lot 4 conserve son échéance de trente jours et les préférences ne recalculent pas rétroactivement les documents. Le texte des paramètres précise cette limite. Une évolution complète de ces règles demanderait un cadrage et une validation distincts.

La vérification visuelle des parcours React sur navigateur et mobile reste à effectuer. Les tests frontend portent sur les contrats des services, les validations, les erreurs et le verrou de soumission ; ils ne constituent pas une suite de tests de composants ou de bout en bout. La concurrence est prouvée sous Linux/MySQL 8, pas par SQLite ni par le PHP Windows natif.

## Contrat frontend et erreurs HTTP

Les codes 401 sont traités avant le décodage du corps pour nettoyer un token invalide même si un proxy renvoie du HTML. Les erreurs 403, 404, 409, 422, 429 et 5xx gardent leur statut. Les détails techniques des erreurs 5xx ne sont pas présentés à l'utilisateur. Les erreurs de validation restent disponibles par champ et sont reliées aux inputs via aria-invalid/aria-describedby. Les labels et dispositions responsive existants sont conservés.

Un verrou synchrone de soumission complète l'état des boutons : clients, produits, paramètres et notifications ne déclenchent pas deux écritures qui se chevauchent dans le même composant. Une erreur libère le verrou pour une nouvelle tentative. Une erreur 403 ne supprime pas le token.

## Migrations nouvelles et audit préalable

1. 2026_11_09_000001_add_client_email_tenant_uniqueness.php ajoute clients_company_email_unique sur company_id/email. Avant toute DDL, elle refuse doublons intra-entreprise, e-mails historiques non normalisés, unicité globale inattendue ou index cible déjà présent. Aucun e-mail existant n'est normalisé par la migration. Les anomalies doivent être résolues par une décision manuelle explicitement approuvée ; ne pas supprimer ou fusionner des fiches pour forcer son passage.
2. 2026_11_09_000002_restrict_historical_product_deletions.php inspecte les deux clés étrangères produit et l'absence d'orphelins avant toute DDL, puis remplace SET NULL par RESTRICT. Un état inattendu ou partiel est refusé. SQLite reconstruit les tables avec foreign_key_check dans une transaction ; MySQL peut valider implicitement des étapes DDL.

Commande de lecture seule : php artisan accounts-catalog:audit. Elle rapporte des IDs et compteurs, sans afficher les adresses, tokens ou mots de passe et sans modifier de données. Elle décrit les index/contraintes, les e-mails clients incompatibles, les prix/taxes et les paramètres de facturation invalides ; elle compte les comptes non vérifiés et les tokens sans date d'expiration. Une absence de doublon ne prouve pas à elle seule que les contacts ou données fiscales sont exacts.

## Procédure de livraison

Suivre docs/deploiement-securise.md et docs/lot-4-documents.md. Aucun script nouveau ne migre une base de production.

- Effectuer une sauvegarde complète MySQL et en vérifier réellement la restauration sur une base isolée de version compatible ; conserver empreinte, volumes, contraintes et résultat de restauration.
- Sur cette copie, exécuter accounts-catalog:audit, documents:audit, billing:audit et rbac:audit-accounts. Examiner les anomalies avec leur propriétaire, sans correction ou promotion automatique.
- Vérifier SHOW CREATE TABLE, les index, la collation effective des e-mails, les clés étrangères et les données. Examiner aussi les anciens téléphones, identifiants fiscaux, adresses et URL de logo face aux nouvelles règles : la commande ne certifie pas tous ces champs. Les anciennes valeurs ne sont pas corrigées automatiquement ; un formulaire envoyant tous ses champs peut demander une correction manuelle validée. Tester le plan candidat sur la copie, y compris les archives et les montants historiques. La normalisation applicative est testée sur SQLite et MySQL ; les différences de collation de la base réelle doivent être revues.
- Arrêter les écritures/imports pendant l'application du schéma et empêcher une ancienne instance, qui pourrait écrire des e-mails non normalisés, de continuer à servir les mutations. Livrer backend et frontend compatibles via le processus manuel existant.
- Vérifier après préparation du schéma : index tenant des clients, deux FK produit RESTRICT, volumes/relations/archives, /ready et SHA applicatifs. Le SHA utilisé par le smoke local est une étiquette de test basée sur HEAD, pas la preuve d'un futur commit contenant les modifications non commitées.
- Réserver une validation séparée à toute opération de production. Ne pas exécuter migrate:fresh, refresh ou rollback global sur Aiven. Les down des migrations réduisent les protections et ne constituent pas une stratégie de rollback de production.
- En cas d'échec partiel MySQL, conserver la maintenance, inspecter les DDL réellement appliquées et préparer un correctif en avant testé sur une copie de cet état. Ne pas relancer aveuglément une migration, marquer artificiellement son exécution ou restaurer par-dessus de nouveaux règlements.

## Résultats de validation

- PHPUnit MySQL 8.0.46 / PHP 8.2.35 Linux isolé : 326 réussis, 0 échec, 1763 assertions.
- PHPUnit SQLite en mémoire / PHP 8.2.12 local : 314 réussis, 0 échec, 12 ignorés réservés à MySQL, 1677 assertions.
- Concurrence multiprocessus MySQL : 12 scénarios réussis, dont les neuf des lots précédents et trois nouveaux (connexion/changement de secret, double reset, double création client).
- Frontend : 36 tests réussis, 0 échec ; ESLint et build React réussis.
- Protections de déploiement : 11 tests réussis ; Composer validate, Pint, actionlint et Docker build réussis.
- Tests HTTP du conteneur plafonné à 512 MiB : réussis (port dynamique, readiness, Sanctum, fichiers privés, redémarrage et garde des migrations).
- Vérification ciblée finale après formatage : 44 tests MySQL réussis, 293 assertions ; ces tests sont inclus dans la suite de 326 et ne sont pas additionnés à son total.
- CLI sur MySQL isolé préparé : audit exécuté avec succès ; 54 routes API inspectées, dont les trois endpoints de profil/sécurité/renvoi et leurs limitations.

Les premières passes ont révélé un appel JSON incorrect introduit pendant l'édition, des attentes de fixtures devenues incompatibles avec les règles validées, et un ajustement du nettoyage de données de test face aux archives protégées. Ces points ont été corrigés avant les suites complètes ci-dessus. Les tests de panne de cache et de révocation produisent volontairement des erreurs simulées ; leurs transactions et protections sont vérifiées.

La référence du lot 4 était 282 tests MySQL, 273 SQLite réussis avec 9 ignorés, et 24 frontend. Aucun scénario existant n'a été supprimé. Les fixtures de profil ajoutent la confirmation du mot de passe, les tests RBAC respectent les fenêtres de limitation et le test de reconstruction des tables attend désormais la conservation du lien produit.

## Fichiers concernés

- backend/app/Console/Commands/AuditAccountsCatalog.php
- backend/app/Http/Controllers/AuthController.php
- backend/app/Http/Controllers/ClientController.php
- backend/app/Http/Controllers/ProductController.php
- backend/app/Http/Controllers/SettingsController.php
- backend/app/Http/Middleware/EnsureVerifiedAccount.php
- backend/app/Models/Client.php
- backend/app/Models/Company.php
- backend/app/Providers/AppServiceProvider.php
- backend/app/Services/AccountSecurityService.php
- backend/app/Support/AccountCatalogAudit.php
- backend/app/Support/BusinessValidation.php
- backend/config/accounts.php
- backend/database/migrations/2026_11_09_000001_add_client_email_tenant_uniqueness.php
- backend/database/migrations/2026_11_09_000002_restrict_historical_product_deletions.php
- backend/routes/api.php
- backend/tests/Feature/AccountCatalogMigrationTest.php
- backend/tests/Feature/AccountSessionsTest.php
- backend/tests/Feature/CatalogValidationTest.php
- backend/tests/Feature/FinancialRetentionMigrationTest.php
- backend/tests/Feature/MySqlAccountConcurrencyTest.php
- backend/tests/Feature/RolePermissionsTest.php
- backend/tests/Feature/SettingsTest.php
- backend/tests/Support/ConcurrentProcesses.php
- docs/lot-5-comptes-catalogue.md
- frontEnd/src/app/App.jsx
- frontEnd/src/components/clients/Clients.jsx
- frontEnd/src/components/products/Products.jsx
- frontEnd/src/components/settings/Settings.jsx
- frontEnd/src/components/shared/FieldError.jsx
- frontEnd/src/hooks/useSubmission.js
- frontEnd/src/services/auth.js
- frontEnd/src/services/formValidation.js
- frontEnd/src/styles/FormErrors.css
- frontEnd/tests/auth.test.js
- frontEnd/tests/formValidation.test.js
