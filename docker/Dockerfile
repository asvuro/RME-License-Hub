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
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libicu-dev libonig-dev libpng-dev \
    && docker-php-ext-install \
        pdo_mysql bcmath mbstring intl zip opcache pcntl \
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
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader \
    || composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

COPY . .

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
