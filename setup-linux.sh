#!/usr/bin/env bash
set -e

echo "=================================================================="
echo "  AUTO-INSTALLER NMS OLT ZTE (LINUX / LAMPP DEPLOYMENT)"
echo "=================================================================="

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

# 1. Deteksi Binary PHP (Standar OS vs XAMPP/LAMPP)
if [ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ]; then
    PHP="$PHP_BIN"
elif command -v php >/dev/null 2>&1; then
    PHP="$(command -v php)"
elif [ -x "/opt/lampp/bin/php" ]; then
    PHP="/opt/lampp/bin/php"
    export PATH="/opt/lampp/bin:$PATH"
else
    echo "[ERROR] PHP 8.2+ tidak ditemukan di PATH maupun /opt/lampp/bin/php."
    echo "        Lihat SERVER-REQUIREMENTS.txt untuk panduan instalasi."
    exit 1
fi

echo "[INFO] Menggunakan PHP: $PHP ($($PHP -r 'echo PHP_VERSION;'))"

# Validasi versi PHP >= 8.2
if ! $PHP -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    echo "[ERROR] Versi PHP minimal adalah 8.2.0. Versi saat ini: $($PHP -r 'echo PHP_VERSION;')"
    exit 1
fi

# 2. Pemeriksaan Ekstensi Wajib (pdo_mysql & snmp)
MISSING_EXT=()
for ext in pdo_mysql mbstring xml curl zip bcmath openssl; do
    if ! $PHP -m | grep -qi "^${ext}$"; then
        MISSING_EXT+=("$ext")
    fi
done

if [ ${#MISSING_EXT[@]} -gt 0 ]; then
    echo "[ERROR] Ekstensi PHP berikut belum aktif: ${MISSING_EXT[*]}"
    echo "        Ubuntu/Debian: sudo apt install -y php-mysql php-mbstring php-xml php-curl php-zip php-bcmath"
    exit 1
fi

if ! $PHP -m | grep -qi "^snmp$"; then
    echo "[WARN] Ekstensi PHP 'snmp' belum terdeteksi. Fitur polling SNMP membutuhkan php-snmp."
    echo "       Ubuntu/Debian: sudo apt install -y snmp libsnmp-dev php-snmp"
fi

# 3. Pemeriksaan Composer & Node.js / NPM
if command -v composer >/dev/null 2>&1; then
    COMPOSER="$(command -v composer)"
elif [ -f "/usr/local/bin/composer" ]; then
    COMPOSER="/usr/local/bin/composer"
else
    echo "[ERROR] Composer 2.x belum terpasang."
    echo "        Instal dengan: curl -sS https://getcomposer.org/installer | sudo $PHP -- --install-dir=/usr/local/bin --filename=composer"
    exit 1
fi

if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    echo "[ERROR] Node.js 20+ dan NPM belum terpasang. Lihat SERVER-REQUIREMENTS.txt."
    exit 1
fi

echo "[INFO] Composer : $($PHP "$COMPOSER" --version --no-ansi 2>/dev/null | head -n 1)"
echo "[INFO] Node.js  : $(node -v) | NPM: $(npm -v)"

# 4. Instalasi Dependency PHP via Composer
echo ""
echo "[STEP 1/4] Menginstal paket PHP (Composer)..."
export COMPOSER_ALLOW_SUPERUSER=1
$PHP "$COMPOSER" install --optimize-autoloader --no-dev --no-interaction

# 5. Instalasi Dependency Frontend & Build Vite
echo ""
echo "[STEP 2/4] Membangun aset frontend (NPM & Vite)..."
if [ -f "package-lock.json" ]; then
    npm ci --no-audit --no-fund || npm install --no-audit --no-fund
else
    npm install --no-audit --no-fund
fi
npm run build

# 6. Persiapan File .env & APP_KEY
echo ""
echo "[STEP 3/4] Menyiapkan konfigurasi .env..."
if [ ! -f ".env" ]; then
    cp .env.example .env
    echo "[INFO] File .env berhasil dibuat dari .env.example."

    # Jika socket LAMPP terdeteksi, aktifkan baris DB_SOCKET otomatis
    if [ -S "/opt/lampp/var/mysql/mysql.sock" ]; then
        sed -i 's|^# *DB_SOCKET=/opt/lampp/var/mysql/mysql.sock|DB_SOCKET=/opt/lampp/var/mysql/mysql.sock|' .env
        echo "[INFO] Socket MySQL LAMPP (/opt/lampp/var/mysql/mysql.sock) terdeteksi & diaktifkan di .env."
    fi
fi

if ! grep -q "^APP_KEY=base64:" .env; then
    $PHP artisan key:generate --force --no-interaction
    echo "[INFO] APP_KEY berhasil di-generate."
fi

# 7. Pengaturan Permission Direktori Storage & Cache
echo ""
echo "[STEP 4/4] Mengatur permission storage & bootstrap/cache..."
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/private bootstrap/cache
chmod -R 775 storage bootstrap/cache

if [ "$(id -u)" -eq 0 ]; then
    if [ -d "/opt/lampp" ] && id "daemon" >/dev/null 2>&1; then
        chown -R daemon:daemon storage bootstrap/cache
        echo "[INFO] Ownership storage & bootstrap/cache diatur ke user LAMPP (daemon:daemon)."
    elif id "www-data" >/dev/null 2>&1; then
        chown -R www-data:www-data storage bootstrap/cache
        echo "[INFO] Ownership storage & bootstrap/cache diatur ke user Apache (www-data:www-data)."
    fi
fi

chmod +x setup-linux.sh update-linux.sh scripts/baileys-gateway.mjs 2>/dev/null || true

echo ""
echo "=================================================================="
echo "  INSTALASI DASAR SELESAI! LANGKAH SELANJUTNYA:"
echo "=================================================================="
echo "1. Edit kredensial database MySQL/MariaDB Anda di file .env:"
echo "   nano .env"
echo "   Pastikan variabel berikut sesuai:"
echo "     DB_CONNECTION=mysql"
echo "     DB_HOST=127.0.0.1"
echo "     DB_PORT=3306"
echo "     DB_DATABASE=nms_zetset"
echo "     DB_USERNAME=root"
echo "     DB_PASSWORD=password_mysql_anda"
echo "     (Untuk LAMPP: aktifkan DB_SOCKET=/opt/lampp/var/mysql/mysql.sock)"
echo ""
echo "2. Buat database di MySQL/LAMPP lalu jalankan migrasi & seeder:"
echo "   $PHP artisan migrate --seed --force"
echo ""
echo "3. Pasang konfigurasi Supervisor & Apache dari folder deploy/linux/:"
echo "   Lihat panduan lengkap di: deploy/linux/DEPLOY-PUTTY.md"
echo "=================================================================="
