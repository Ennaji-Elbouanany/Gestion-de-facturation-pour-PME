# Stabilisation des tests et déploiements — novembre 2026

## Correctif CI

- Restaurer `backend/phpunit.xml` : la configuration PHPUnit avait été supprimée alors que les workflows utilisaient `php artisan test`.
- CI SQLite : exiger le fichier PHPUnit, une connexion SQLite, un `DB_URL` absent et un fichier SQLite local.
- CI MySQL : exiger `APP_ENV=testing`, `DB_HOST=127.0.0.1`, `DB_DATABASE=retention_test` et aucun `DB_URL`.
- Ces contrôles ciblent **GitHub Actions**, pas les exécutions manuelles sur une machine dont le `.env` pointe potentiellement vers Aiven. Ne jamais lancer `php artisan test`, un seeder ou des migrations sans confirmer l'environnement.

## Bloquer les déploiements non validés

Vérification observée lors de l'audit : les deux services Render principaux ont `autoDeploy=yes`, `branch=main`. Le workflow `.github/workflows/deploy.yml` est manuel, mais **n'empêche pas Render de déployer directement les commits `main`**.

Un administrateur Render doit :

1. Dans **Frontend Static Site → Settings → Build & Deploy**, désactiver **Auto-Deploy**.
2. Dans **Backend Web Service → Settings → Build & Deploy**, désactiver **Auto-Deploy**.
3. Sur GitHub, imposer une protection de branche `main` avec pull request, validation des jobs CI et interdiction de bypass non autorisé.
4. Vérifier que le workflow manuel de livraison fonctionne, que les variables/secrets Render existent et que le SHA déployé est bien celui dont **tous** les jobs CI ont réussi.
5. Avant une livraison backend, vérifier `/ready`, les migrations nécessaires, les sauvegardes et les règles de retour arrière. Ne pas migrer automatiquement Aiven.

**Important :** ces réglages de plateforme ne sont pas modifiés par la présente pull request. Ne pas fusionner sur `main` tant que la politique de déploiement automatique n'est pas maîtrisée.

## Contrôler une sauvegarde Aiven (aucune sauvegarde créée par ce correctif)

1. Identifier la base et confirmer son caractère production, sans afficher le mot de passe dans un terminal partagé.
2. Effectuer une sauvegarde complète via l'interface Aiven ou un outil MySQL compatible avec les contraintes TLS et les droits du compte. Protéger et chiffrer le fichier obtenu.
3. Sur **une instance MySQL isolée et jetable**, restaurer réellement cette sauvegarde. Jamais sur la base de production.
4. Comparer le nombre de lignes par table, les index, clés étrangères, contraintes et la table `migrations`; inspecter les journaux de restauration.
5. Conserver une date, une empreinte cryptographique, la taille et le résultat de l'essai de restauration. Aucune restauration n'est validée simplement parce qu'un fichier dump existe.
6. Sur la copie, exécuter les commandes d'audit en lecture seule (`documents:audit`, `billing:audit`, `accounts-catalog:audit`, `performance:audit`, si disponibles dans la version installée).

Ne jamais exécuter `migrate:fresh`, `migrate:refresh`, `db:wipe` ou `rollback` sur Aiven. Une sauvegarde vérifiée est une condition préalable aux migrations de production.

## Critères de fin

- Tous les jobs de la pull request en succès (SQLite, MySQL, React, Docker/smoke).
- Auto-déploiements Render désactivés et branche `main` protégée (vérification de configuration effective).
- Sauvegarde Aiven restaurée avec succès sur une base isolée (preuve de restauration).
- Livraison manuelle validée, surveillée et sans migration non approuvée.
