#!/usr/bin/env bash
# Perbarui aplikasi Tanggungan Siswa ke versi terbaru di GitHub (jalankan sebagai root di VM).
set -euo pipefail
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"  # bawaan: folder tempat skrip ini berada
cd "$APP_DIR"
artisan() { runuser -u www-data -- php "$APP_DIR/artisan" "$@"; }

artisan down --retry=30 || true
trap 'artisan up || true' EXIT

# vendor/ ikut di repo; composer bisa mengubah berkas autoload -> kembalikan dulu agar pull tidak bentrok
git ls-files -m -- vendor | xargs -r git checkout --
git pull --ff-only

# Pengerasan & kompresi Nginx (idempotent; aman dijalankan berulang).
if [ -d /etc/nginx/conf.d ]; then
    # Tiap baris ditulis hanya bila belum aktif di nginx.conf: Ubuntu sudah menyalakan "gzip on;",
    # dan direktif ganda membuat nginx -t gagal.
    ada() { grep -Eqs "^\s*$1\s" /etc/nginx/nginx.conf; }
    {
        echo "# Dikelola oleh deploy/update.sh"
        ada server_tokens || echo "server_tokens off;"
        ada gzip || echo "gzip on;"
        ada gzip_comp_level || echo "gzip_comp_level 5;"
        ada gzip_min_length || echo "gzip_min_length 512;"
        ada gzip_vary || echo "gzip_vary on;"
        ada gzip_types || echo "gzip_types text/css application/javascript text/javascript application/json image/svg+xml;"
    } > /etc/nginx/conf.d/tanggungan-hardening.conf
    if galat=$(nginx -t 2>&1); then systemctl reload nginx; else rm -f /etc/nginx/conf.d/tanggungan-hardening.conf; echo "PERINGATAN: konfigurasi hardening Nginx dilewati (nginx -t gagal):"; echo "$galat" | grep -E "emerg|error" | head -3 || true; fi
fi
# Backup harian database + berkas (pukul 01:45), simpan 14 hari di /var/backups/tanggungan. Tidak menimpa jadwal yang sudah diatur.
if [ ! -f /etc/cron.d/tanggungan-backup ]; then
    echo "45 1 * * * root APP_DIR=$APP_DIR bash $APP_DIR/deploy/backup.sh >> /var/log/tanggungan-backup.log 2>&1" > /etc/cron.d/tanggungan-backup
    chmod 644 /etc/cron.d/tanggungan-backup
fi
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
chown -R www-data:www-data storage bootstrap/cache
artisan migrate --force
artisan config:cache
artisan route:cache
artisan view:cache
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
systemctl reload "php${PHPV}-fpm"
artisan tanggungan:periksa || echo "PERHATIAN: ada masalah di atas — perbaiki sebelum dipakai."
echo "Selesai diperbarui: $(git log --oneline -1)"
