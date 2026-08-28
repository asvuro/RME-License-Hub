# RME-License-Hub — PHP-FPM application image.
#
# PHP 8.4 (bukan 8.5): image 8.5 pernah gagal build pada tooling tim
# (ekstensi pcntl belum stabil di 8.5), jadi 8.4 dipakai untuk produksi hub
# demi kompatibilitas ekstensi dan reverb/queue worker yang stabil.
FROM php:8.4-fpm

# Ekstensi yang dibutuhkan hub:
#  - pdo_mysql   : koneksi MariaDB/MySQL
#  - bcmath/intl : perhitungan lisensi/kuota & locale
#  - mbstring/zip: serialisasi token & archive
#  - opcache     : performa produksi
#  - pcntl       : signal handling worker (stabil di 8.4)
#  - redis (PECL): CACHE_STORE=redis & QUEUE_CONNECTION=redis (docker-compose)
#    butuh ekstensi phpredis — bukan bawaan docker-php-ext-install, dan
#    composer.json tidak punya predis sbg fallback pure-PHP. Tanpa ini,
#    SETIAP request yang menyentuh cache/queue Redis 500 "Class Redis not
#    found" — ditemukan lewat run container sungguhan, bukan review.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libicu-dev libonig-dev libpng-dev $PHPIZE_DEPS \
        default-mysql-client \
    && docker-php-ext-install \
        pdo_mysql bcmath mbstring intl zip opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Composer (binary resmi, tanpa plugin host).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Konfigurasi PHP produksi (overridden oleh php-overrides.ini bila ada).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-hub.ini
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-hub-opcache.ini

WORKDIR /var/www

# php-fpm listens on 9000 by default; nginx (container terpisah) memanggilnya.
# Sesuaikan pm.* untuk beban hub (bukan loadtest, jadi lebih rendah dari 20).
RUN sed -i 's/^pm.max_children = .*/pm.max_children = 40/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.start_servers = .*/pm.start_servers = 8/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 4/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 16/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^;catch_workers_output = .*/catch_workers_output = yes/' /usr/local/etc/php-fpm.d/www.conf

# Dependency diinstal di build agar image siap jalan (lihat docker-entrypoint).
# --no-scripts + --no-autoloader di sini SENGAJA: composer.json Laravel punya
# post-autoload-dump hook (`artisan package:discover`) yang butuh file
# `artisan` — belum ada di layer ini (baru composer.json/lock yang di-COPY,
# demi cache layer terpisah dari kode aplikasi). Menjalankan composer install
# penuh di sini gagal build total ("Could not open input file: artisan"),
# ditemukan lewat build Docker sungguhan, bukan review kode. Autoload +
# package:discover baru dijalankan SETELAH `COPY . .` di bawah.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts --no-autoloader

COPY . .

# Defense in depth alongside .dockerignore: force-clear any bootstrap cache
# that slipped through from the host (e.g. a stale packages.php referencing
# require-dev-only providers like Pail, which --no-dev never installs).
#
# BUILD-TIME-ONLY .env stub: package:discover boots the full app, which
# (via app/Providers/BroadcastServiceProvider) eagerly resolves the default
# broadcaster connection ("reverb") — this throws if REVERB_APP_KEY/SECRET
# are unset, and the REAL .env is deliberately NEVER baked into the image
# (see .dockerignore — it holds secrets and is injected at container
# RUNTIME instead, via docker-compose's `env_file: [.env]`). .env.example
# has placeholder values just to get boot() past this point; those values
# are irrelevant at runtime because real container environment variables
# take priority over a baked-in .env file (phpdotenv does not override
# already-set OS env vars). Found via a real Docker build failure, not
# review — RuntimeException "Failed to create broadcaster for connection
# reverb ... auth_key must be of type string, null given".
RUN rm -f bootstrap/cache/*.php \
    && cp .env.example .env \
    && composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && rm -f .env

# Direktori writable oleh php-fpm (user www-data).
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/framework/testing storage/logs \
        storage/keys bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

# Entrypoint menyalakan php-fpm (default) atau perintah lain (reverb/queue).
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]
