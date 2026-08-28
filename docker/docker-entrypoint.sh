#!/usr/bin/env bash
set -euo pipefail

# Entrypoint hub. Menjalankan perintah dari CMD:
#   php-fpm              -> web (default, dipanggil nginx)
#   php artisan reverb:start       -> proses Reverb (service hub-reverb)
#   php artisan queue:work          -> queue worker (service hub-queue)
#   php artisan schedule:work       -> scheduler (service hub-scheduler)
#
# Semua proses berbagi image & env yang sama; pemisahan proses mengikuti
# anjuran Laravel (reverb & queue berjalan di proses terpisah).

cd /var/www

# Tunggu DB siap sebelum migrate otomatis (aman di-skip bila --no-migrate).
if [[ "${HUB_AUTO_MIGRATE:-0}" == "1" ]]; then
    echo "Menunggu database (${DB_HOST:-db}:${DB_PORT:-3306})..."
    until php artisan db:monitor 2>/dev/null || \
          php -r "new PDO('mysql:host=${DB_HOST:-db};port=${DB_PORT:-3306}', '${DB_USERNAME:-root}', '${DB_PASSWORD:-}'); exit(0);" 2>/dev/null; do
        sleep 2
    done
    echo "Migrating & seeding..."
    php artisan migrate --force
fi

# Cache konfigurasi di produksi untuk mempercepat boot.
if [[ "${APP_ENV:-production}" == "production" ]]; then
    php artisan config:cache || true
    php artisan route:cache || true
fi

exec "$@"
