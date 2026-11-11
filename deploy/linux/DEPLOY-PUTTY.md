# Panduan Deployment Server Linux (LAMPP / Ubuntu) via PuTTY

Panduan praktis langkah demi langkah (siap *copy-paste* ke terminal PuTTY) untuk men-deploy **NMS OLT ZTE (`NMSZetset`)** di server Linux kantor.

---

## Tahap 1: Persiapan Paket Sistem (Jalankan Sekali)

Login ke server Linux melalui **PuTTY**, lalu pastikan paket sistem, ekstensi SNMP, Composer, Node.js, dan Supervisor sudah terpasang:

```bash
sudo apt update && sudo apt install -y \
  git curl wget unzip zip snmp libsnmp-dev supervisor acl \
  php-cli php-mysql php-sqlite3 php-snmp php-mbstring php-xml php-curl php-zip php-bcmath php-intl
```

> **Catatan pengguna XAMPP/LAMPP (`/opt/lampp`)**: Jika menggunakan PHP bawaan LAMPP, tambahkan `/opt/lampp/bin` ke `PATH`:
> ```bash
> export PATH="/opt/lampp/bin:$PATH"
> ```

---

## Tahap 2: Clone Repository & Jalankan Auto-Installer

### Opsi A — Jika Menggunakan Apache Standar (`/var/www`)
```bash
cd /var/www
sudo git clone https://github.com/Bimaa14/NMSZetset.git
cd /var/www/NMSZetset
sudo bash setup-linux.sh
```

### Opsi B — Jika Menggunakan XAMPP / LAMPP (`/opt/lampp/htdocs`)
```bash
cd /opt/lampp/htdocs
sudo git clone https://github.com/Bimaa14/NMSZetset.git
cd /opt/lampp/htdocs/NMSZetset
sudo bash setup-linux.sh
```

---

## Tahap 3: Buat Database MySQL & Atur `.env`

1. **Buat database `nms_zetset` di MySQL / MariaDB**:
   - **Standar Ubuntu MySQL**:
     ```bash
     sudo mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS nms_zetset CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
     ```
   - **XAMPP / LAMPP MySQL**:
     ```bash
     /opt/lampp/bin/mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS nms_zetset CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
     ```

2. **Edit file `.env`**:
   ```bash
   sudo nano .env
   ```
   Sesuaikan bagian database:
   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://IP_SERVER_KANTOR

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=nms_zetset
   DB_USERNAME=root
   DB_PASSWORD=password_mysql_anda
   # Aktifkan baris di bawah ini khusus jika memakai MySQL bawaan /opt/lampp:
   # DB_SOCKET=/opt/lampp/var/mysql/mysql.sock
   ```
   Simpan dengan `Ctrl+O` ➔ `Enter` ➔ `Ctrl+X`.

3. **Jalankan Migrasi Tabel & Akun Awal**:
   ```bash
   php artisan migrate --seed --force
   php artisan optimize:clear
   ```

---

## Tahap 4: Aktifkan VirtualHost Apache

### Opsi A — Apache Standar Ubuntu
```bash
sudo cp deploy/linux/apache-vhost.conf /etc/apache2/sites-available/nms-zetset.conf
sudo a2enmod rewrite headers
sudo a2dissite 000-default.conf
sudo a2ensite nms-zetset.conf
sudo systemctl reload apache2
```

### Opsi B — XAMPP / LAMPP (`/opt/lampp`)
Pastikan `DocumentRoot` di `/opt/lampp/etc/httpd.conf` atau `/opt/lampp/etc/extra/httpd-vhosts.conf` mengarah ke `/opt/lampp/htdocs/NMSZetset/public`, lalu restart LAMPP:
```bash
sudo /opt/lampp/lampp restart
```

---

## Tahap 5: Aktifkan Supervisor (Auto-Poller OLT & Queue Worker 24/7)

Agar proses **Sync OLT**, **Monitoring Redaman/Status ONU**, **Kolektor Grafik Traffic**, dan **Notifikasi WhatsApp/Telegram** berjalan otomatis di background meskipun PuTTY ditutup:

```bash
sudo cp deploy/linux/supervisor-nms.conf /etc/supervisor/conf.d/supervisor-nms.conf
```

> **Khusus pengguna LAMPP (`/opt/lampp`)**: Sesuaikan path di `/etc/supervisor/conf.d/supervisor-nms.conf` menggunakan perintah otomatis berikut:
> ```bash
> sudo sed -i 's|/var/www/NMSZetset|/opt/lampp/htdocs/NMSZetset|g; s|/usr/bin/php|/opt/lampp/bin/php|g; s|user=www-data|user=daemon|g' /etc/supervisor/conf.d/supervisor-nms.conf
> ```

Muat ulang dan jalankan Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```
Pastikan `nms-worker:nms-worker_00`, `nms-worker:nms-worker_01`, `nms-scheduler`, dan `nms-monitor` berstatus **`RUNNING`**.

---

## Tahap 6: Cara Update Aplikasi ke Depannya dari PuTTY

Setiap kali ada pembaruan kode di GitHub, cukup jalankan **1 perintah** berikut di PuTTY:

```bash
sudo bash update-linux.sh
```
