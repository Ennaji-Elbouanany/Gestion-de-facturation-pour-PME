# Lot 2 — Policies et permissions par entreprise

## Périmètre

Sept Policies Laravel natives, aucun package RBAC et aucune migration de schéma. Sanctum reste en Bearer token avec son expiration et son comportement de déconnexion existants. Aucun rôle existant n’est promu ou rétrogradé automatiquement. Les règles financières et transitions métier des lots 3 à 10 ne sont pas implémentées.

## Matrice réellement implémentée

Toutes les opérations concernent exclusivement l’entreprise de l’utilisateur.

| Action | admin | employee | comptable |
| --- | --- | --- | --- |
| Consulter clients, produits, factures, devis et archives | Oui | Oui | Oui |
| Consulter règlements, historique des paiements et statistiques financières | Oui | Non | Oui |
| Consulter les compteurs opérationnels (clients, produits, factures, devis) | Oui | Oui | Oui |
| Créer/modifier un client actif | Oui | Oui | Oui |
| Archiver/restaurer un client | Oui | Non | Non |
| Créer/modifier un produit | Oui | Non | Oui |
| Supprimer un produit (comportement préexistant) | Oui | Non | Non |
| Créer une facture en brouillon | Oui | Oui | Oui |
| Créer une facture avec un autre statut autorisé existant | Oui | Non | Oui |
| Modifier une facture en brouillon actif | Oui | Oui | Oui |
| Modifier une facture hors brouillon | Oui | Non | Oui |
| Changer le statut d’une facture, y compris via PUT/PATCH | Oui | Non | Oui |
| Archiver/restaurer un brouillon de facture sans règlement | Oui | Oui | Oui |
| Créer/modifier un devis actif et ses lignes | Oui | Oui | Non |
| Changer le statut commercial d’un devis | Oui | Oui | Non |
| Attribuer nouvellement le statut converted via édition | Oui | Non | Non |
| Convertir un devis en facture via l’action dédiée | Oui | Non | Oui |
| Archiver/restaurer un brouillon de devis | Oui | Oui | Non |
| Enregistrer/modifier un paiement | Oui | Non | Oui |
| Supprimer physiquement facture, devis ou paiement | Non | Non | Non |
| Consulter les paramètres d’entreprise | Oui | Oui | Oui |
| Modifier identité/coordonnées d’entreprise | Oui | Non | Non |
| Modifier les paramètres de facturation | Oui | Non | Oui |
| Modifier les documents/préférences de notification de l’entreprise | Oui | Non | Non |
| Modifier son propre profil/mot de passe | Oui | Oui | Oui |

Les montants et statuts des factures/devis individuels restent visibles pour les employés afin de préserver leur mission commerciale. Ils ne reçoivent pas les enregistrements de paiement via les endpoints de paiements ou via le détail d’une facture. Leur dashboard charge seulement les compteurs opérationnels ; la réponse serveur applique une liste blanche même si le cache partagé contient d’autres indicateurs. Les données de cache ne contiennent pas de permissions utilisateur.

L’employé peut conserver un statut converted déjà présent lors d’une édition de devis autorisée ; il ne peut pas attribuer ce statut à un autre devis ni appeler la conversion. Les changements de statut restent les règles préexistantes pour les rôles autorisés : ce lot ne prétend pas rendre les documents émis immuables ou la conversion idempotente.

## Inscription et compte

L’inscription crée une nouvelle entreprise et attribue admin côté serveur. role, company_id et permissions sont interdits dans le corps public de l’inscription. Le sélecteur React est retiré. Les paramètres du compte rejettent aussi ces champs ; User ne les accepte plus par mass assignment. Seul le code serveur de création utilise une affectation explicite.

Aucun endpoint de gestion des collaborateurs, invitation ou changement de rôle n’est ajouté. Le rôle d’un token existant est contrôlé à partir de l’utilisateur en base à chaque requête ; aucune permission n’est figée dans le token.

## Contrôles et réponses HTTP

- Authentification Sanctum : 401 si absente/invalide.
- Les contrôles company_id existants restent en place, et les Policies vérifient également l’appartenance des ressources.
- Les listes restent filtrées par entreprise ; viewAny ne constitue pas un filtre SQL.
- Une ressource d’une autre entreprise, un enfant sous un mauvais parent ou une autre cible utilisateur renvoie 404.
- Une action interdite par le rôle sur sa propre entreprise renvoie 403 avant validation métier, écriture ou invalidation du cache.
- Pour un utilisateur autorisé, les restrictions du lot 1 continuent à renvoyer 409 : archive non modifiable, suppression financière interdite, archivage non applicable.
- Les champs interdits ou données invalides renvoient 422.
- Pas de Gate::before donnant des privilèges universels à admin.
- Un rôle inconnu ou une entreprise absente refuse les opérations métier. La consultation de son propre compte et la déconnexion restent possibles ; les paramètres métier exigent une entreprise valide.

Les routes publiques de connexion, inscription et récupération de mot de passe conservent leur limiteur. Les routes personnelles /user et /logout restent réservées à l’utilisateur authentifié. Toutes les opérations métier, y compris les routes personnalisées et ressources imbriquées, ont un contrôle explicite.

## Frontend

/api/user conserve les champs existants et ajoute un dictionnaire de permissions avec des clés telles que payments.view ou invoices.updateDraft. PermissionsContext et usePermissions exposent ces capacités. Les permissions sont refusées pendant le chargement. Les actions non autorisées sont masquées ; les paramètres consultables sans droit d’écriture utilisent des formulaires désactivés. Les accès directs à /payments sont également refusés dans l’interface pour les employés.

Une erreur 403 affiche Accès refusé sans supprimer le token Sanctum ni déclencher la reconnexion. Le backend reste l’autorité même si le navigateur ou ses permissions affichées sont manipulés. Les permissions d’interface peuvent nécessiter un rechargement après une modification externe de rôle ; la restriction API est immédiate.

## Vérification des comptes existants

Commande en lecture seule : `php artisan rbac:audit-accounts`.

Elle affiche uniquement les IDs d’entreprise, rôles et nombres de comptes ; elle ne modifie aucune ligne. Code de sortie 0 si la situation est cohérente, 1 si une entreprise n’a pas d’admin ou qu’un compte n’a pas de rôle/entreprise valide (ou si l’audit ne peut aboutir).

L’audit exécuté sur la connexion distante configurée a relevé quatre entreprises sans administrateur : **2, 4, 6, 11**, chacune avec un compte employee. Aucun rôle n’a été changé. Avant déploiement, identifier avec le propriétaire les droits légitimes de ces comptes et décider séparément d’une éventuelle correction explicitement autorisée. Ne pas promouvoir automatiquement tous les employés.

## Tests

- RolePermissionsTest : matrice par rôle pour chaque endpoint métier, y compris PUT/PATCH, archives, restauration, conversion et lignes ; refus sans modification des données ni invalidation du cache ; 401/403/404/409 ; isolement des entreprises et parents ; inscription protégée ; identité non modifiable ; données de paiement absentes des réponses employé ; statistiques filtrées après lecture du cache ; changement de rôle sur token existant ; commande d’audit non mutante.
- Les tests du lot 1 sont rejoués, avec les acteurs autorisés explicitement admin dans les fixtures concernées. La factory reste employee par défaut.
- `php artisan test --compact` utilise SQLite en mémoire via phpunit.xml. Ne jamais lancer les tests sur une base de production.
- `npm test` utilise le runner natif Node, sans nouvelle dépendance : permission des brouillons/archives, restriction des capacités financières et comportement API 401/403/422.
- `npm run lint` et `npm run build` vérifient le frontend. Ces tests ne remplacent pas une validation visuelle des trois rôles dans un navigateur.
- La CI MySQL 8 exécute désormais toute la suite backend, en complément de SQLite. Docker local n’étant pas démarré, aucun test destructif MySQL n’est exécuté sur la base distante de production ; son audit de comptes est uniquement une lecture.

## Précautions avant déploiement Render

1. Examiner les quatre entreprises sans admin et valider séparément toute correction des comptes. Aucun changement de rôle n’est livré implicitement.
2. Exiger les résultats CI SQLite, MySQL 8, tests frontend, lint et build avant livraison.
3. Coordonner backend et frontend : l’ancien formulaire d’inscription envoyant role sera rejeté par la nouvelle API. Prévoir une fenêtre de livraison cohérente.
4. Ce lot n’ajoute aucune migration, mais le Dockerfile existant exécute migrate --force au démarrage. Vérifier les migrations déjà appliquées et celles en attente avant tout redémarrage/déploiement ; si celles du lot 1 restent en attente, appliquer d’abord leur procédure de sauvegarde et de validation sur copie. Aucune migration de production n’a été lancée dans ce travail.
5. Conserver une sauvegarde récente et un plan de retour à la version précédente. Ne pas utiliser migrate:fresh sur la base réelle.
6. Vérifier avec des comptes de test autorisés : admin/comptable voient les règlements, employee reçoit 403 sur leurs APIs et ne voit pas les statistiques financières ; une ressource d’une autre entreprise retourne 404 pour tous les rôles ; archives et restauration du lot 1 fonctionnent.
7. Ne pas traiter les limites restantes de cohérence financière (statuts, modifications de montants, concurrence, numérotation) comme corrigées : elles appartiennent aux lots suivants.

## Inventaire des fichiers du lot 2

- `.github/workflows/ci.yml`
- `backend/README.md`
- `backend/app/Console/Commands/AuditRbacAccounts.php`
- `backend/app/Enums/UserRole.php`
- `backend/app/Http/Controllers/AuthController.php`
- `backend/app/Http/Controllers/ClientController.php`
- `backend/app/Http/Controllers/Concerns/ArchivesDrafts.php`
- `backend/app/Http/Controllers/Controller.php`
- `backend/app/Http/Controllers/DashboardController.php`
- `backend/app/Http/Controllers/InvoiceController.php`
- `backend/app/Http/Controllers/PaymentController.php`
- `backend/app/Http/Controllers/ProductController.php`
- `backend/app/Http/Controllers/QuoteController.php`
- `backend/app/Http/Controllers/QuoteItemController.php`
- `backend/app/Http/Controllers/SettingsController.php`
- `backend/app/Models/User.php`
- `backend/app/Policies/ClientPolicy.php`
- `backend/app/Policies/CompanyPolicy.php`
- `backend/app/Policies/Concerns/ChecksCompany.php`
- `backend/app/Policies/InvoicePolicy.php`
- `backend/app/Policies/PaymentPolicy.php`
- `backend/app/Policies/ProductPolicy.php`
- `backend/app/Policies/QuotePolicy.php`
- `backend/app/Policies/UserPolicy.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/app/Support/UserPermissions.php`
- `backend/database/factories/UserFactory.php`
- `backend/routes/api.php`
- `backend/tests/Feature/AuthSecurityTest.php`
- `backend/tests/Feature/CacheIntegrationTest.php`
- `backend/tests/Feature/CompanyReferenceValidationTest.php`
- `backend/tests/Feature/FinancialDataRetentionTest.php`
- `backend/tests/Feature/RolePermissionsTest.php`
- `backend/tests/Feature/SettingsTest.php`
- `docs/lot-2-rbac.md`
- `frontEnd/package.json`
- `frontEnd/src/components/clients/Clients.jsx`
- `frontEnd/src/components/invoices/Invoices.jsx`
- `frontEnd/src/components/products/Products.jsx`
- `frontEnd/src/components/quotes/Quotes.jsx`
- `frontEnd/src/components/settings/Settings.css`
- `frontEnd/src/components/settings/Settings.jsx`
- `frontEnd/src/contexts/PermissionsContext.js`
- `frontEnd/src/hooks/usePermissions.js`
- `frontEnd/src/pages/auth/RegisterForm.jsx`
- `frontEnd/src/pages/dashboard/Dashboard.jsx`
- `frontEnd/src/pages/dashboard/EmployeeOverview.jsx`
- `frontEnd/src/services/auth.js`
- `frontEnd/src/services/permissions.js`
- `frontEnd/tests/auth.test.js`
- `frontEnd/tests/permissions.test.js`
