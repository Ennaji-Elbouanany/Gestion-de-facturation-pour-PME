# Livraison contrôlée sur Render

## Périmètre et interdictions

Les lots 1 à 3, Sanctum, les Policies, les archives, les calculs et les données financières sont conservés. Aucun outil de livraison ne lance de migration, de seed, de purge générale de cache, de changement de rôle ou de rollback SQL. Aucun déploiement n'a été exécuté pour valider ces changements locaux.

Le seul point d'entrée ajouté pour appliquer les migrations est `backend/bin/migrate-isolated.php --apply-isolated`. Il exige APP_ENV=testing avant le bootstrap, puis vérifie la connexion réellement résolue, y compris DB_URL et le cache de configuration. Il n'accepte que SQLite en mémoire, le fichier de test `/tmp/billing_test.sqlite`, ou MySQL sur localhost/127.0.0.1/::1 avec une base finissant par `_test`. Il refuse la production avant toute migration.

**Ne jamais utiliser Artisan migrate en production.** Si une livraison exige un changement de schéma, préparer séparément un plan SQL de correction en avant, relu, testé sur copie et exécuté uniquement par un opérateur explicitement autorisé. Aucun plan SQL générique n'est livré : le schéma réel et les données ne sont pas connus ici. Ne jamais lancer migrate:fresh, migrate:refresh ou un rollback global en production.

## Avant de publier ces changements

1. Désactiver dans l'interface GitHub l'ancien workflow Deploy Production le temps de préparer la transition, et désactiver Auto-Deploy sur les deux services Render. Aucun réglage distant n'est changé par ce travail.
2. Vérifier que les deux services ciblent ce dépôt et main. Backend : Root Directory `backend`, Dockerfile `Dockerfile`, contexte `.`, sans surcharge Docker Command et sans Pre-Deploy Command. Ne pas conserver une ancienne commande qui contient migrate, artisan serve ou une purge de cache.
3. Backend : configurer le contrôle HTTP Render sur `/ready`. Le conteneur possède aussi un contrôle local `/up` qui vérifie seulement le démarrage HTTP.
4. Vérifier séparément les variables de production, les certificats MySQL et la persistance nécessaire aux fichiers utilisateur. Les variables ne sont jamais modifiées automatiquement.
5. Publier uniquement après autorisation du propriétaire. La nouvelle livraison est exclusivement workflow_dispatch ; la CI reste automatique sur push/PR main et master.

## Paramètres à configurer manuellement

Dans l'environnement GitHub `production` :

- Secret `RENDER_API_KEY` : accès minimal aux deux services, à leur configuration en lecture et à leurs déploiements. Le contrôleur lit les variables en mémoire pour valider les prérequis ; il ne les affiche ni ne les écrit sur disque.
- Variables `RENDER_BACKEND_SERVICE_ID`, `RENDER_FRONTEND_SERVICE_ID`, `BACKEND_URL`, `FRONTEND_URL` : les URLs doivent être des origines HTTPS canoniques sans chemin, identifiant ou query string.
- `REQUIRE_PRODUCTION_REVIEW` : true par défaut. Configurer des reviewers et une restriction de branche pour production. Pour un mainteneur seul ou un plan sans reviewers, une dérogation explicite false permet de conserver la validation manuelle par dispatch ; les restrictions de branche et les contrôles CI restent obligatoires. Ne pas activer Prevent self-review si aucun autre approbateur n'est disponible.

`GH_TOKEN` utilise le jeton éphémère github.token avec les permissions de lecture du workflow ; ne pas créer de PAT pour ce besoin. Les anciens hooks ne sont plus utilisés par le code, mais ne sont ni supprimés ni régénérés automatiquement.

Côté backend Render, vérifier explicitement APP_ENV=production, APP_DEBUG=false, APP_KEY existante et stable, DB_CONNECTION=mysql, DB_URL ou DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD, APP_URL et FRONTEND_URL. Vérifier aussi CA/TLS MySQL, CACHE_STORE, SESSION_DRIVER, QUEUE_CONNECTION et stockage selon les services réellement utilisés. Ne pas régénérer APP_KEY lors d'une livraison.

Côté Static Site : Root Directory `frontEnd`, build `npm ci && npm run build`, publication `dist`, VITE_API_URL égal à l'origine backend. Conserver la réécriture SPA vers index.html et vérifier que `/release.json` sert le vrai fichier JSON. RENDER_GIT_COMMIT est fourni par Render au build/runtime ; ne pas inventer sa valeur. Les données release.json (SHA et origine API publique) ne sont pas des secrets.

## Contrôles du workflow

Le dispatch exige le SHA complet choisi et la confirmation DEPLOY, depuis main. Le tooling exécuté est celui du SHA du dispatch sur main, jamais un script téléchargé depuis un ref fourni par l'utilisateur.

Avant le premier POST Render, le contrôleur vérifie :

- les paramètres et secrets nécessaires sont présents ;
- le SHA est un ancêtre de main et la branche principale du dépôt est main ;
- la dernière CI push trouvée pour ce SHA et main est terminée avec succès ;
- les quatre jobs nommés Backend Laravel, Backend finance/concurrency/RBAC, Frontend React/Vite et Deployment safeguards and Docker ont réellement réussi ; un job ignoré ne suffit pas ;
- les protections production sont accessibles et présentes selon la règle ci-dessus ;
- les deux services ont les types, dépôt, branche, runtime et réglages attendus, Auto-Deploy off, aucune livraison en attente détectée ;
- les paramètres d'origine frontend/backend et les prérequis backend sont cohérents.

La CI des anciens commits sans le nouveau job Docker ne suffit pas à autoriser leur déploiement par ce workflow. Une première livraison doit donc utiliser un commit contenant ces protections et ayant passé la nouvelle CI.

Un groupe de concurrence production interdit deux exécutions simultanées et cancel-in-progress reste false. Les changements directs dans Render ou un autre système de déploiement ne sont pas couverts par ce verrou : limiter ces accès pendant une livraison.

Le contrôleur demande le SHA exact à l'API Render, attend que CE déploiement soit live, puis vérifie `/ready` et son SHA. Le frontend n'est demandé qu'après ce succès. Il est ensuite suivi jusqu'à live, et release.json doit correspondre au SHA et à l'origine API attendus. Les deux livraisons ne sont pas une transaction atomique : conserver une compatibilité temporaire backend/frontend ou une maintenance partagée entre instances.

Les POST de déploiement ne sont jamais renvoyés automatiquement. Un timeout ou une erreur réseau après un POST peut masquer une demande déjà acceptée : examiner Render avant de recommencer. Le suivi est limité dans le temps et ne lance aucune annulation ni rollback automatique. Une livraison peut continuer côté Render après l'arrêt du suivi GitHub.

Les hooks seuls ne permettent pas ce suivi complet. La documentation Render permet de fixer un ref dans un hook, mais une réponse peut simplement annoncer une mise en attente. L'API a été retenue pour obtenir et suivre un identifiant de déploiement. Elle ne désactive pas Auto-Deploy : le précontrôle l'exige déjà désactivé.

## Sauvegarde et restauration AVANT tout changement de schéma

Cette procédure est à préparer par l'opérateur ; aucune commande ci-dessous n'a été exécutée contre la production.

1. Inventorier version MySQL, moteurs (InnoDB), charset/collation, timezone, GTID, volumes, migrations et contraintes réellement présentes. Vérifier la capacité de stockage et les permissions de sauvegarde, routines/événements compris.
2. Suspendre toutes les écritures, jobs et clients API concernés. Une simple fermeture du frontend est insuffisante. Un fichier artisan down dans un conteneur éphémère ne protège pas nécessairement les nouvelles instances ; préférer une maintenance partagée ou un blocage externe maîtrisé.
3. Faire une sauvegarde complète cohérente, avec une copie hors du conteneur, accès restreint et chiffrement. Pour InnoDB, exemple à adapter après validation des droits :

```sh
mysqldump --defaults-extra-file=/secure/source.cnf --ssl-mode=VERIFY_IDENTITY \
  --single-transaction --routines --triggers --events --hex-blob \
  --no-tablespaces --set-gtid-purged=OFF \
  --result-file=/secure/avant-livraison.sql NOM_BASE
```

Le fichier client protégé contient les informations d'accès et le CA approprié ; aucun mot de passe ne doit figurer dans une commande ou un journal. Ne pas supprimer silencieusement une option parce que les permissions manquent : une sauvegarde incomplète doit bloquer la migration. Ne pas modifier le schéma pendant le dump ; single-transaction ne protège pas toutes les opérations DDL ni les tables non transactionnelles.

4. Conserver le code de sortie, l'empreinte SHA-256, la taille, les dates, le SHA applicatif et la preuve de restauration. Un fichier non vide n'est pas une preuve suffisante.
5. Restaurer réellement sur un autre serveur MySQL 8 isolé, de version compatible, sans identifiants ni accès sortant vers la production. Événements, emails, jobs et webhooks doivent rester désactivés. Exemple de restauration, avec un fichier client ciblant exclusivement ce serveur isolé :

```sh
mysql --defaults-extra-file=/secure/restauration-locale.cnf billing_restore_test < /secure/avant-livraison.sql
```

Créer préalablement la base de restauration sur cette seule cible isolée. Vérifier routines, triggers, événements, DEFINER et éventuels privilèges gérés par l'hébergeur ; arrêter en cas d'erreur.

6. Comparer les volumes et empreintes de toutes les tables, les montants décimaux et relations, company_id, archives, instantanés, révisions, versions et clés d'idempotence. Examiner SHOW CREATE TABLE, index et information_schema pour vérifier les RESTRICT attendus et l'absence d'orphelins.
7. Répéter le plan SQL candidat sur cette copie ; vérifier à nouveau données, contraintes, audits financiers et RBAC et parcours des trois rôles. Aucun recalcul ou correctif automatique de l'historique n'est autorisé.
8. Obtenir une validation séparée du plan, de la sauvegarde restaurée et de la fenêtre de maintenance. L'application de SQL en production n'est pas automatisée par ces outils.
9. En cas de DDL partiellement appliqué, conserver la maintenance, relever l'état exact et les dernières instructions réussies. Comparer au schéma attendu et préparer un correctif en avant, testé sur une copie de cet état partiel. Ne pas marquer artificiellement une migration comme exécutée, supprimer une colonne utilisée ou relancer aveuglément un fichier.

La migration financière du lot 3 refuse de détruire instantanés, corrections et métadonnées utilisées. Le rollback des contraintes du lot 1 réintroduit des cascades : il n'est pas une stratégie de retour arrière acceptable pour cette livraison.

## Répétition locale de sauvegarde

`scripts/ops/mysql_restore_drill.py` ne supporte qu'un conteneur mysql:8.0 identifié par son ID complet, nommé billing-*, exposé uniquement en loopback et contenant la base locale billing_cicd_test vide de factures et déjà migrée. LOCAL_MYSQL_TEST_PASSWORD doit correspondre à ce seul serveur de test. Il insère des archives et règlements fictifs, effectue un dump complet, restaure billing_restore_test et compare toutes les lignes et les règles FK. Il ne supporte aucun hostname MySQL distant. Le conteneur entier doit être supprimé après le test.

Cette répétition valide les mécanismes sur des données synthétiques. Elle ne remplace pas la restauration d'une vraie sauvegarde de production et n'apporte pas la preuve que cette dernière est disponible.

## Retour arrière applicatif et données historiques

Conserver avant livraison le couple de déploiements backend/frontend précédemment opérationnels et leur configuration. Un retour arrière Render restaure l'application, pas la base. Ne revenir qu'à une version compatible avec le schéma courant et les protections des lots 1 à 3 ; ne pas revenir à une image qui réintroduit les migrations au démarrage. Si le frontend échoue après le backend, garder la maintenance ou la compatibilité prévue et décider manuellement de la correction en avant/du retour applicatif. Ne pas restaurer une sauvegarde par-dessus de nouveaux règlements sans plan de reprise explicitement approuvé.

L'ancienne facture sans instantané et les entreprises sans administrateur signalées dans les rapports précédents doivent être revues par leur propriétaire. Ne reconstruire aucun instantané à partir des fiches actuelles et ne promouvoir aucun compte automatiquement. Ces données de production n'ont pas été relues ici.

## Validation locale et limites

Résultats finaux consignés dans le rapport remis à l'utilisateur. Les suites métier initiales de 254 tests MySQL et 20 tests frontend sont conservées. Les tests supplémentaires vérifient uniquement la préparation, les refus de livraison et l'exécution HTTP du conteneur.

Le test HTTP utilise un port dynamique, un conteneur plafonné à 512 Mo et une base SQLite interne avec comptes fictifs. Il confirme /up, /ready, absence de migration au démarrage, refus des fichiers privés, 401 sans token, accès réel via token Sanctum, cache et données conservés après redémarrage et refus du script de migration en production. Ce n'est pas un test de charge : mesurer la mémoire sous trafic réel avant d'augmenter les quatre workers Apache ou les limites PHP.

Les builds utilisent les digests vérifiés des images PHP/Composer pour limiter la dérive entre CI et Render. Planifier des mises à jour de sécurité testées. Un secret BuildKit optionnel composer_ca permet un CA de confiance local pour Composer sans l'intégrer dans l'image ni désactiver TLS ; les builds ordinaires n'en ont pas besoin.

Aucun test local ne prouve les paramètres Render réels, les droits du futur token, la réécriture SPA, le CA MySQL effectif, la disponibilité d'une vraie sauvegarde, ni une livraison de bout en bout. La vérification publique GitHub a montré que production ne possède actuellement ni reviewers ni restriction de branche ; aucun réglage distant n'a été modifié.

Références : https://render.com/docs/deploys ; https://render.com/docs/deploy-hooks ; https://render.com/docs/environment-variables ; https://render.com/docs/rollbacks ; https://api-docs.render.com/reference/create-deploy .


## Résultats de cette implémentation locale

- PHPUnit SQLite : 253 réussis, 4 ignorés (concurrence MySQL), 0 échec ; 1 251 assertions.
- PHPUnit MySQL 8.0.46 isolé : 257 réussis, 0 échec ; 1 278 assertions. Les 254 cas de référence et les quatre scénarios multiprocessus sont préservés.
- Frontend : 20 réussis ; ESLint et build avec métadonnées SHA/origine API fictive réussis.
- Tests du contrôleur de livraison : 11 réussis, avec APIs simulées ; aucun POST vers Render.
- Composer validate, Pint, actionlint et git diff --check : réussis.
- Docker build PHP 8.2 Apache : réussi. Le premier essai a échoué sur un certificat réseau local ; la reprise avec les autorités publiques approuvées par Windows, montées temporairement via BuildKit, a réussi sans désactiver TLS.
- Test HTTP réel du conteneur sous plafond de 512 Mo : réussi, y compris token Sanctum, port dynamique, fichiers privés, préparation du schéma et redémarrage sans purge.
- Dump/restauration MySQL 8 synthétique : réussi ; toutes les lignes des tables et les règles FK comparées après restauration. Ce résultat ne valide aucune sauvegarde réelle de production.

Fichiers modifiés ou ajoutés : .gitattributes ; .gitignore ; .github/workflows/ci.yml ; .github/workflows/deploy.yml ; backend/.dockerignore ; backend/Dockerfile ; backend/bootstrap/app.php ; backend/config/deployment.php ; backend/app/Http/Controllers/ReadinessController.php ; backend/bin/migrate-isolated.php ; backend/docker/apache.conf ; backend/docker/php.ini ; backend/docker/start.sh ; backend/tests/Feature/DeploymentReadinessTest.php ; frontEnd/vite.config.js ; scripts/ops/deploy.py ; scripts/ops/smoke_container.py ; scripts/ops/mysql_restore_drill.py ; scripts/ops/tests/test_deploy.py ; docs/deploiement-securise.md.
