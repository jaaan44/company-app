# Local development image only — not a production deployment artifact.
# Provides the PHP-FPM runtime + extensions Laravel actually needs plus
# Composer; application source is bind-mounted at runtime (see
# docker-compose.yml), not copied in here.
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
    && docker-php-ext-install pdo_mysql bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY conf.d/local-dev.ini /usr/local/etc/php/conf.d/local-dev.ini

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
