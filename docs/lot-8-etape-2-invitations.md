# Lot 8 — Étape 2 : invitations sécurisées

## Périmètre

Les invitations sont **créées uniquement par un admin actif authentifié de l'entreprise**. Le rôle de l'invité est limité à \`employee\` ou \`comptable\`. Un collaborateur reçoit par e-mail un lien personnel expirant après 48 heures. La création du compte a lieu uniquement à l'acceptation du lien.

- GET \`/api/invitations\` — liste des invitations de l'entreprise courante (admin).
- POST \`/api/invitations\` — \`{"email":"...", "role":"employee|comptable"}\` (admin).
- DELETE \`/api/invitations/{id}\` — annulation d'une invitation non acceptée (admin, company scoped).
- GET \`/api/invitations/validate?token=...\` — métadonnées pour l'écran d'acceptation, limité par \`throttle:login\`.
- POST \`/api/invitations/accept\` — \`{"token":"...","name":"...","password":"...","password_confirmation":"..."}\` (public, limité par \`throttle:login\`).

Le token clair n'est jamais conservé en base, seulement son SHA-256. La transaction d'acceptation verrouille l'invitation, contrôle expiration/usage antérieur et attribue le \`company_id\`, l'e-mail et le rôle uniquement depuis les données enregistrées. L'adresse de l'invité est validée par la possession de son lien e-mail; le compte est créé actif sans token de session automatique. Le nouvel utilisateur se connecte ensuite normalement via \`/api/login\`.

La réinvitation à la même adresse annule les anciennes invitations non acceptées. L'annulation ne touche jamais les comptes existants. Les routes de gestion sont protégées par Sanctum, statut actif et vérification du rôle admin, avec vérification de l'entreprise pour les requêtes sur une invitation.

## Interface et e-mail

\`FRONTEND_URL\` doit correspondre au frontend réel sur Render, et \`MAIL_MAILER\` doit être configuré pour un transport réellement livré (SMTP en production ; \`log\` ne livre aucun e-mail). Le lien pointe vers \`/accept-invitation?token=...\`. **Cette page React reste à réaliser au Lot 8, étape 3**. Ne pas déployer une campagne d'invitations avant son implémentation et validation.

## Attention spéciale Aiven — historique migrations

La colonne \`users.is_active\` a été ajoutée manuellement à la base de production. Cette opération **ne marque pas** la migration correspondante comme exécutée.

Vérifier en lecture seule avant toute fusion sur \`main\` :

\`\`\`sql
SHOW COLUMNS FROM users LIKE 'is_active';
SELECT migration, batch
FROM migrations
WHERE migration LIKE '%add_is_active_to_users_table%';
SHOW TABLES LIKE 'user_invitations';
\`\`\`

Si \`is_active\` existe mais que le nom de sa migration est absent de \`migrations\`, **ne pas exécuter \`php artisan migrate --force\`** en production : elle peut échouer avec « Duplicate column ». Faire d'abord une sauvegarde/restauration isolée, examiner l'historique des migrations et régulariser uniquement la migration déjà appliquée manuellement. La procédure dépend de l'état réel de la base; aucune insertion arbitraire dans \`migrations\` n'est automatique ou comprise dans cette PR.

La nouvelle table \`user_invitations\` ne doit être créée que via la migration Laravel après validation. Pas d'opération sur Aiven par cette PR. \`docker/start.sh\` n'exécute aucune migration au démarrage.

## Tests

\`php artisan test --filter=CollaboratorInvitationTest\` sur une base **SQLite locale de test**, avec \`MAIL_MAILER=array\` ou \`Mail::fake()\`. CI teste également MySQL 8 sur une base temporaire isolée. Ne jamais utiliser les identifiants Aiven pour les tests.
