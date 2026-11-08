# Lot 7 — Factures et devis imprimables (MVP sans dépendance PDF)

L'interface Factures et Devis propose désormais **Imprimer / PDF**. Le clic ouvre une nouvelle fenêtre, récupère le document et l'entreprise via les routes existantes protégées par Sanctum et les Policies, puis affiche une page A4 imprimable.

## Utilisation

1. Ouvrir **Factures** ou **Devis** ; cliquer **Imprimer / PDF** sur la ligne souhaitée.
2. Dans la nouvelle fenêtre, cliquer **Imprimer / Enregistrer en PDF**.
3. Choisir la destination **Enregistrer au format PDF** dans le dialogue du navigateur. L'option PDF varie selon le navigateur / système.

Les données ne sont pas publiées dans une URL permanente. L'accès réutilise l'API protégée, y compris les contrôles d'entreprise. Pas de modification de la base, de migration, de modification de soldes ou de token dans l'URL.

**Limites de cette version :** le navigateur réalise la conversion PDF ; il n'y a pas encore de téléchargement direct depuis un endpoint Laravel. La prise en charge de polices, sauts de page et de l'impression des couleurs dépend du navigateur. Aucun PDF archivé ou signé n'est généré côté serveur. Pour les factures historiques sans instantané d'émission, une alerte explicite apparaît. Vérifier la conformité juridique des mentions sur les documents avec un professionnel compétent avant utilisation commerciale.

## Sécurité et logique métier

- PDF généré uniquement après les endpoints authentifiés `GET /api/invoices/{id}` et `GET /api/quotes/{id}`. La configuration de société vient de `GET /api/settings`.
- Les textes métier sont échappés en HTML : les noms, notes et descriptions ne peuvent pas injecter de balises ou de scripts.
- Pour une facture émise, les champs historiques (entreprise, client, lignes, devise, totaux, notes) proviennent de `issued_snapshot` lorsqu'il est fiable.
- Une facture ancienne sans instantané affiche une alerte ; aucune prétention à une reconstitution historique exacte.
- Aucune valeur financière manquante n'est remplacée par un zéro.
- Les images de logo non HTTPS sont ignorées. Les images HTTPS restent externes ; préférer un stockage de logo sous un domaine maîtrisé pour éviter des appels réseau à des tiers.
- La fonction de rendu est pure et testée par `node --test`.

## Vérification

Depuis `frontEnd`, exécuter `npm test`, `npm run lint`, `npm run build`. Tester ensuite manuellement un devis, une facture brouillon, une facture émise, une facture historique et un document de plus de 30 lignes dans le navigateur. Vérifier l'impression A4 et le retour des erreurs d'autorisation.

**Dépendance de livraison :** la PR #5 restaure `backend/phpunit.xml`. La CI backend sur `main` est en échec tant que cette restauration n'est pas fusionnée. Le changement du présent lot est limité au frontend et à la documentation.
