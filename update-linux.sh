#!/usr/bin/env bash
set -e

echo "=================================================================="
echo "  UPDATE CEPAT NMS OLT ZTE (GIT PULL + MIGRATE)"
echo "=================================================================="

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

if [ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ]; then
    PHP="$PHP_BIN"
elif command -v php >/dev/null 2>&1; then
    PHP="$(command -v php)"
elif [ -x "/opt/lampp/bin/php" ]; then
    PHP="/opt/lampp/bin/php"
    export PATH="/opt/lampp/bin:$PATH"
else
    echo "[ERROR] Binary PHP tidak ditemukan."
    exit 1
fi

if command -v composer >/dev/null 2>&1; then
    COMPOSER="$(command -v composer)"
elif [ -f "/usr/local/bin/composer" ]; then
    COMPOSER="/usr/local/bin/composer"
else
    echo "[ERROR] Composer tidak ditemukan."
    exit 1
fi

BRANCH="${1:-main}"

echo "[1/5] Menarik pembaruan kode terbaru dari GitHub ($BRANCH)..."
git fetch origin "$BRANCH"
git pull origin "$BRANCH"

echo "[2/5] Memperbarui paket Composer..."
export COMPOSER_ALLOW_SUPERUSER=1
$PHP "$COMPOSER" install --optimize-autoloader --no-dev --no-interaction

echo "[3/5] Menjalankan migrasi database..."
$PHP artisan migrate --force --no-interaction

echo "[4/5] Membersihkan cache aplikasi..."
$PHP artisan optimize:clear
chmod -R 775 storage bootstrap/cache

echo "[5/5] Me-restart worker queue & poller Supervisor..."
$PHP artisan queue:restart || true
if command -v supervisorctl >/dev/null 2>&1; then
    if [ "$(id -u)" -eq 0 ]; then
        supervisorctl restart nms-worker:* nms-monitor nms-scheduler 2>/dev/null || true
    elif command -v sudo >/dev/null 2>&1; then
        sudo supervisorctl restart nms-worker:* nms-monitor nms-scheduler 2>/dev/null || true
    fi
fi

echo "=================================================================="
echo "  UPDATE SELESAI! NMS OLT ZTE sudah menggunakan versi terbaru."
echo "=================================================================="
