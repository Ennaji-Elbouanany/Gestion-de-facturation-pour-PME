# Envoi d'e-mails par Resend HTTPS sur Render Free

## Pourquoi

Render Free interdit les connexions SMTP sortantes sur les ports 25, 465, 587. Les invitations Factura étaient enregistrées en base, puis \`Mail::send()\` pouvait échouer avec un HTTP 500. La nouvelle configuration fournit un transport Laravel \`resend_http\` qui utilise \`POST https://api.resend.com/emails\` (HTTPS/443) à travers le client HTTP Laravel. Aucun package Composer supplémentaire n'est nécessaire. Les mailables et notifications existantes passent toutes par le même transport lorsque \`MAIL_MAILER=resend_http\`.

## À faire avant le déploiement (par le propriétaire)

1. Créer un compte Resend sur https://resend.com et **vérifier un domaine ou sous-domaine d'envoi qui vous appartient**, via les enregistrements DNS demandés par Resend (SPF/DKIM). Pour des destinataires arbitraires, le domaine d'expédition doit être vérifié. Le compte/test sender Resend peut avoir des restrictions.
2. Créer une clé API Resend limitée à l'envoi d'e-mails et au domaine Factura si le fournisseur le permet. Ne jamais l'insérer dans GitHub, le code, les logs ni le frontend.
3. Dans **Render > backend > Environment**, définir :
   - \`MAIL_MAILER=resend_http\`
   - \`RESEND_API_KEY=\` (valeur secrète produite par Resend)
   - \`MAIL_FROM_ADDRESS=notifications@votre-domaine-verifie.example\`
   - \`MAIL_FROM_NAME=Factura\`
   - \`FRONTEND_URL=https://URL-REELLE-DU-FRONTEND\` (pour les liens d'invitation et de réinitialisation)
4. Laisser \`APP_DEBUG=false\`, redéployer **après** vérification des tests de la PR. Ne pas ajouter de secrets à \`.env.example\`.
5. Envoyer une seule invitation de test vers une boîte contrôlée, vérifier la réponse de l'API, la réception dans la boîte, et les événements de livraison dans Resend. Le statut « accepté par l'API » ne garantit pas à lui seul la livraison en boîte de réception.

## Sans effet sur Aiven

Cette PR n'a aucune migration et ne modifie ni tables ni données Aiven. Elle ne met pas de variables Render à jour automatiquement.

## Gestion d'incident

L'API Resend peut renvoyer 401/403 si la clé est invalide ou si le domaine/expéditeur n'est pas vérifié, 429 en cas de limites, et 5xx en cas d'incident fournisseur. Le transport ne met pas la réponse d'erreur Resend ni le secret dans l'exception visible. Les invitations déjà enregistrées avant un échec SMTP n'ont probablement pas pu être envoyées; après la correction, inviter à nouveau le destinataire génère un nouveau jeton et invalide l'ancien. Éviter les essais répétés tant que le transport n'est pas validé.

## Vérification isolée

Le test \`ResendHttpMailTest\` intercepte toutes les requêtes HTTP vers Resend avec \`Http::fake()\`; aucune connexion réelle ni clé de production n'est utilisée en CI. Les tests Laravel classiques continuent d'utiliser \`Mail::fake()\`.
