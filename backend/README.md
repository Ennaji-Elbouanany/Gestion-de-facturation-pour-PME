<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Production Redis cache on Render

Local development remains database-backed (`CACHE_STORE=database`). Production uses
Render's managed Redis-compatible service and does not put its URL in this repository.

1. In the Render dashboard, create a Redis-compatible Key Value/Redis service in the
   same region as the Laravel web service. Copy its internal Redis URL for a private
   connection; use the external URL only when the services are not on the same
   private network.
2. In the Laravel web service's Environment settings, add
   `CACHE_STORE=redis`, `REDIS_CLIENT=predis`, and `REDIS_URL=<the Render Redis URL>`.
   Keep `REDIS_PASSWORD`, `REDIS_HOST`, and `REDIS_PORT` unset when the URL contains
   all connection details. Set `CACHE_PREFIX=gestion-facturation` if the Redis
   service is shared with another application.
3. Do not add the Redis URL to `.env`, frontend environment variables, GitHub Actions,
   logs, or committed files. Render environment variables are injected at runtime
   and are not exposed to the React build.
4. Deploy the backend after setting the variables. The Docker image uses Predis, so
   the PHP Redis extension is not required. `REDIS_CLIENT=phpredis` is an alternative
   only if the image is changed to install the `phpredis` extension.
5. After deployment, run this Render shell command:
   `php artisan tinker --execute="cache()->put('redis-healthcheck', 'ok', 60); dump(cache()->get('redis-healthcheck'));"`
   Confirm it prints `ok`, inspect the service logs for Redis connection errors, and
   call the authenticated dashboard and list endpoints.

Cached responses are scoped by `company_id`: clients (5 minutes), products
(10 minutes), invoices and quotations (2 minutes), and dashboard counts (1 minute).
Writes invalidate the affected company keys, including invoice status changes caused
by payments and quote-to-invoice conversion. A Predis connection failure is reported
and read requests fall back to their database query; invalidation failures do not
block a successful write. Tests use the array cache and never contact production Redis.

Run focused checks locally from `backend`:

```powershell
php artisan test --filter=CacheIntegrationTest
php artisan cache:clear
php artisan tinker --execute="cache()->put('cache-test', 'ok', 60); dump(cache()->get('cache-test'));"
```

For production, verify the configured store without printing secrets:

```powershell
php artisan config:clear
php artisan about
php artisan tinker --execute="dump(config('cache.default')); cache()->put('redis-healthcheck', 'ok', 60); dump(cache()->get('redis-healthcheck'));"
```

Run `php artisan config:cache` only after the Render variables are configured.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
