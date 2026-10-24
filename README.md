# MakSpace API (Laravel 13 + JWT)

API REST stateless d'authentification pour l'app Flutter `makspace_app`.

## Démarrage

```bash
composer install
cp .env.example .env && php artisan key:generate && php artisan jwt:secret
# créer la base MySQL `makspace`, puis :
php artisan migrate --seed          # seed = comptes de démo (local uniquement, mot de passe 123456)
php artisan serve --host=0.0.0.0    # http://localhost:8000  (Android émulateur : http://10.0.2.2:8000)
php artisan test
php artisan makspace:create-admin admin@exemple.com   # créer un admin (non auto-inscriptible)
```

En dev, les e-mails (codes à 6 chiffres) sont écrits dans `storage/logs/laravel.log` (`MAIL_MAILER=log`).

## Endpoints — préfixe `/api/v1`

| Méthode     | Route                      | Auth                  | Description                                                      |
| ----------- | -------------------------- | --------------------- | ---------------------------------------------------------------- |
| POST        | `/auth/register`           | –                     | Inscription (201) — `role` choisi avant la création du compte   |
| POST        | `/auth/login`              | –                     | Connexion                                                        |
| POST        | `/auth/refresh`            | –                     | Rotation du refresh token                                        |
| POST        | `/auth/forgot-password`    | –                     | Envoie un code (202, réponse identique si l'e-mail n'existe pas) |
| POST        | `/auth/reset-password`     | –                     | `email`, `code`, `password`, `password_confirmation`             |
| POST        | `/auth/logout`             | Bearer                | Ferme la session courante                                        |
| POST        | `/auth/logout-all`         | Bearer                | Ferme toutes les sessions                                        |
| POST        | `/auth/email/verify`       | Bearer                | Valide le code reçu par e-mail                                   |
| POST        | `/auth/email/resend`       | Bearer                | Renvoie le code                                                  |
| GET / PATCH | `/me`                      | Bearer                | Profil courant / mise à jour partielle                           |
| PUT         | `/me/password`             | Bearer                | Changement de mot de passe                                       |
| GET         | `/admin/users`             | Bearer + `role:admin` | Liste paginée/filtrable                                          |
| PATCH       | `/admin/users/{id}/status` | Bearer + `role:admin` | Suspendre / réactiver                                            |

### Rôles et champs d'inscription

Les champs de profil requis sont enregistrés après la création du compte via `POST /me/profile` :

Le rôle (`client`, `artisan`, `driver`, etc.) est choisi dans l'application avant l'envoi de l'inscription et transmis dans `POST /auth/register`. Sans `role`, l'API conserve `client` par défaut pour les anciennes versions. Le nom et les champs propres au rôle sont complétés ensuite via `POST /me/profile`. `/me` expose `profile_completion` (0–100) et `profile_completed`. À 100 %, le profil prestataire devient visible sans validation administrative, mais reste hors ligne jusqu'à ce que la personne active sa disponibilité. L'API refuse le passage en ligne avant 100 %; la modération peut toujours masquer ou suspendre un compte.

| `role`              | Champs supplémentaires obligatoires                                                                                                                      |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `client`            | –                                                                                                                                                        |
| `driver`, `livreur` | `vehicle_model`, `vehicle_plate`                                                                                                                         |
| `artisan`           | `specialties[]` ∈ plomberie, maconnerie, electricite, jardinage, piscine, carrelage, nettoyage, peinture, menuiserie, climatisation, serrurerie, toiture |
| `boutique`          | `shop_name`, `specialties[]` ∈ epicerie, marche, wax, beaute, artisanat, saveurs                                                                         |
| `restaurant`        | `shop_name`, `specialties[]` ∈ senegalaise, ivoirienne, nigeriane, congolaise, ethiopienne, camerounaise                                                 |
| `changeur`          | Au moins un pays desservi                                                                                                                               |
| `hote`              | Au moins un type de logement proposé                                                                                                                     |
| `demarcheur`        | Au moins un quartier couvert                                                                                                                             |
| `medecin`           | Au moins une spécialité médicale                                                                                                                         |
| `beaute`            | Au moins une prestation                                                                                                                                  |
| `admin`             | non auto-inscriptible                                                                                                                                    |

Protéger une route par rôle : `Route::middleware(['auth:api', 'active', 'role:driver,livreur'])`.

## Service boutique — préfixe `/api/v1`

Prérequis : `php artisan storage:link` (images en disque `public`, passer à S3 via `MEDIA_URL`/filesystem).
Variables : `APP_TIMEZONE` (horaires d'ouverture), `MEDIA_URL`, `SHOP_COMMISSION_RATE` (défaut 10 %).
Autres variables : `SHOP_FORCE_OPEN_HOURS` (durée en h de l'ouverture exceptionnelle, défaut 4).
Images : chaque upload est ré-encodé (GD) en version 1280 px + miniature 480 px (`*_thumb`), exposées via `url`/`thumb_url`, `cover_thumb_url`, `logo_thumb_url`.
Ouverture : le boutiquier peut ouvrir hors horaires, ce qui déclenche une ouverture exceptionnelle temporaire (`force_open_until`) ; `next_opening_label` indique la prochaine ouverture.
Choix du livreur : à partir de « en préparation », la boutique peut soit laisser la recherche ouverte à tous les livreurs (`POST /merchant/orders/{id}/dispatch {mode:"open"}`), soit proposer la course à l'un de ses livreurs habituels (`{mode:"courier", livreur_id}`). Le livreur choisi a l'exclusivité pendant `SHOP_DIRECT_OFFER_MINUTES` (défaut 10) ; sans réponse, ou s'il décline (`POST /livreur/deliveries/{id}/decline`), la course redevient visible de tous. Livreurs habituels : `GET|POST /merchant/couriers`, `GET /merchant/couriers/search?q=`, `DELETE /merchant/couriers/{livreur}`.
Espace livreur : `GET /livreur/dashboard` (accueil : présence, chiffres du jour, cumul, note, courses à prendre/réservées), `PATCH /livreur/availability {is_available}` (hors service = aucune course proposée, acceptation refusée, non proposable par les boutiques), `GET /livreur/earnings?from=&to=` (total, moyenne, meilleur jour, série par jour, note). Le client peut noter le livreur dans `POST /orders/{id}/review` (`courier_rating`, 1–5, ignoré pour un retrait). Les courses disponibles exposent `trip_km` (trajet boutique → client) sans révéler l'adresse.
Favoris (client) : `GET /favorites`, `GET /favorites/ids`, `PUT|DELETE /favorites/{shop|product}/{id}` (idempotents).
Le seeder crée 6 boutiques de démo (`boutique@transport.app`, `plantain@transport.app`, …, mot de passe `123456`) et le code `BIENVENUE10`.

| Qui                                        | Routes                                                                                                                                                                                                                                        |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Public                                     | `GET /shops` (recherche, `category`, `open_now`, `lat/lng/radius_km`), `/shops/{id}`, `/shops/{id}/products`, `/shops/{id}/reviews`                                                                                                           |
| Client (`role:client`)                     | `POST /orders/quote`, `POST /orders` (en-tête `Idempotency-Key` obligatoire), `GET /orders[/{id}]`, `POST /orders/{id}/cancel`, `POST /orders/{id}/review`                                                                                    |
| Boutiquier (`role:boutique`) `/merchant/…` | `shop` (GET/PATCH), `shop/images/{logo\|cover}`, `products` CRUD, `products/{id}/stock`, `products/{id}/images`, `coupons` CRUD, `orders` + `orders/{id}/{accept\|reject\|prepare\|ready\|complete\|cancel}`, `stats`, `reviews` (+ `/reply`) |
| Livreur (`role:livreur`) `/livreur/…`      | `deliveries/available`, `deliveries`, `deliveries/{id}/{accept\|release\|pickup\|deliver}` (PIN client), `earnings`                                                                                                                           |

Règles : montants en centimes, prix recalculés côté serveur, stock verrouillé (`lockForUpdate`), paiement à la livraison/retrait, machine d'états `pending → accepted → preparing → ready → out_for_delivery → delivered` (+ `rejected`/`cancelled`).

## Format des réponses

Succès connexion/inscription : `{ "user": {...}, "tokens": { "access_token", "token_type": "Bearer", "expires_in", "refresh_token" } }`.

Erreurs : `{ "message", "code" }` (+ `errors: { champ: [..] }` pour les 422). Codes : `invalid_credentials`,
`account_suspended`, `forbidden_role`, `unauthenticated`, `invalid_refresh_token`, `too_many_requests` (header `Retry-After`), `not_found`.

## Sécurité

- Access token JWT court (15 min, `JWT_TTL`), claims `sub`, `role`, `sid` (session). Le rôle/statut réellement appliqué est lu en base à chaque requête.
- Refresh token opaque (64 car.), stocké **haché** (SHA-256), à usage unique ; la réutilisation d'un token déjà utilisé révoque toute la session.
- Logout : session révoquée + access token en liste noire (cache). Reset / changement de mot de passe : sessions révoquées.
- Codes OTP : HMAC lié à l'e-mail + motif, 15 min, 5 essais, un seul actif à la fois.
- Anti-énumération (login, forgot-password), temps de réponse constant au login, rate limiting par route (`AppServiceProvider`).
- `role` / `status` jamais mass-assignables ; `admin` impossible à l'inscription.
- Purge planifiée des tokens/codes expirés : `model:prune` (nécessite `php artisan schedule:run` en cron en production).

## Production

`APP_DEBUG=false`, HTTPS obligatoire, `CACHE_STORE`/`QUEUE_CONNECTION` sur redis ou database (+ `queue:work`), vrai `MAIL_MAILER`,
`JWT_SECRET` fort et propre à l'environnement, restreindre CORS (`config/cors.php`) si une app web consomme l'API.
