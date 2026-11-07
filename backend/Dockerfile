FROM php:8.2-apache-bookworm@sha256:46804ea2b61eaf25ca47fa8aafc917015b8dad6b7a64c4364bb051a9dff275d5

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl pdo_mysql zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 /usr/bin/composer /usr/bin/composer
WORKDIR /app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr PORT=10000
ARG RELEASE_SHA=development
RUN printf '%s' "$RELEASE_SHA" > /app/RELEASE_SHA
COPY . .
RUN --mount=type=secret,id=composer_ca \
    if [ -f /run/secrets/composer_ca ]; then export COMPOSER_CAFILE=/run/secrets/composer_ca; fi; \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/production.ini
COPY docker/start.sh /usr/local/bin/billing-start
RUN chmod +x /usr/local/bin/billing-start
EXPOSE 10000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --fail --silent --max-time 4 "http://127.0.0.1:${PORT:-10000}/up" > /dev/null || exit 1
CMD ["billing-start"]
