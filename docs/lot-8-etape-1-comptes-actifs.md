# Lot 8 — Étape 1 : comptes actifs et désactivés

## Fonctionnement

- La migration ajoute `users.is_active` (booléen, par défaut `true`). Les rôles `admin`, `employee` et `comptable` restent inchangés.
- La connexion refuse les comptes inactifs avec la même réponse 401 que pour un couple e-mail/mot de passe invalide (pas d'énumération de comptes).
- Après `auth:sanctum`, le middleware `EnsureActiveUser` vérifie le statut actuel en base avant **toutes** les routes API authentifiées, y compris `/api/user`, `/api/settings` et `/api/logout`. Un compte désactivé obtient 401 avec `code=ACCOUNT_DISABLED`, même si un ancien token existe.
- Le service `AccountActivationService::deactivate($userId)` verrouille l'utilisateur, désactive le compte et supprime ses tokens dans une transaction. Les relations métier sont conservées. **Ce service ne constitue pas une route publique.** L'étape suivante devra contrôler explicitement le rôle administrateur, le `company_id` et la protection du dernier administrateur avant d'appeler ce service.
- Les tests `CollaboratorSecurityTest` vérifient les trois rôles, les anciens tokens, la création initiale d'admin et l'absence d'accès avec un compte désactivé.

## Sécurité et livraison

Cette étape n'ajoute **aucun endpoint permettant de désactiver un compte**, aucune interface collaborateurs et aucune invitation. Ne pas appeler directement le service de désactivation depuis un endpoint non autorisé.

La migration est testée par la CI sur SQLite et sur la base MySQL **de test** `retention_test`. Elle n'a pas été appliquée à Aiven. Le déploiement automatique Render vers `main` reste activé : ne fusionner qu'après les tests et après avoir vérifié le plan de sauvegarde/restauration Aiven et la procédure de migration production. Une migration directe sur Aiven n'est pas autorisée dans cette étape.

## Vérifications avant fusion

1. CI SQLite + MySQL + React + Docker au vert.
2. Vérification de sauvegarde Aiven et restauration isolée avant migration production.
3. Revue de l'ordre et du lancement des migrations sur Render.
4. Contrôle des utilisateurs déjà présents en production (tous doivent garder l'accès).
