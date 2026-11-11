# NMS OLT ZTE - Real Drivers C300/C320

YOTC C1600 EPON tersedia untuk monitoring melalui web management (HTTP/HTTPS): inventaris PON/ONU, status, sistem, dan optik bertahap. Traffic agregat PON dapat dibaca melalui Telnet terpisah setelah sampling PON diaktifkan di perangkat. [Cakupan, batas firmware, dan pengamanan koneksi C1600](docs/YOTC-C1600.md).

Fondasi dashboard jaringan FTTH sesuai `prd.txt`: Laravel 11 + Blade + Tailwind CSS 4 + Alpine.js. Implementasi berjalan lokal, menggunakan database demo persisten dan autentikasi sesi.

## Koneksi OLT nyata

Driver Laravel Telnet native socket, SSH (phpseclib), SNMP v2c, parser, dan penyimpanan snapshot atomik sudah tersedia. Pilih mode real pada Edit/Tambah OLT lalu Test Connection. Simulator tetap opsional; tidak ada fallback diam-diam saat real gagal. Field kredensial PRD tetap dipakai dan terenkripsi.

[Commissioning, konfigurasi timeout/SSH/SNMP, format parser, dan batas verifikasi](docs/REAL-OLT.md). Pengujian memakai socket TCP/UDP loopback; belum menguji perangkat C300/C320 fisik milik pengguna. Root seeder tetap simulator dan tidak otomatis memindai IP kantor.

## Menjalankan di workspace ini (Windows)

PHP portable, Composer, dependensi, database seeder, dan aset frontend sudah disiapkan. Dari folder proyek:

```powershell
.\start.ps1
```

Buka **http://127.0.0.1:8000**. Untuk port lain: `.\start.ps1 -Port 8080`. Hentikan dengan Ctrl+C. Server hanya bind localhost. Di Windows, Node menjalankan enam proses PHP melalui `scripts/local-web.mjs` agar pembacaan OLT tidak menahan request UI lain; `PHP_CLI_SERVER_WORKERS` bawaan PHP hanya dipakai pada OS yang mendukungnya.

Pada konfigurasi lokal demo, kunjungan pertama langsung membuka akun ISP Owner. Setelah Keluar, halaman tetap meminta login. Di `/login`, gunakan tombol role demo atau akun berikut:

| Role | Email |
| --- | --- |
| ISP Owner | isp_owner@bima.test |
| NOC | noc@bima.test |
| CS | cs@bima.test |
| Teknisi | teknisi@bima.test |
| Owner tenant kedua (uji isolasi) | owner@nusantara.test |

Kata sandi seluruh akun demo: `NmsDemo2026!`. Akun tenant kedua hanya dapat diakses melalui form email/password. Pergantian role demo juga tersedia pada dialog Profil. Mode demo hanya bekerja pada `APP_ENV=local` dan `NMS_DEMO=true`; seeder tidak membuat akun demo pada production.

## Yang sudah berfungsi

- 2 tenant terisolasi; 4 role tenant, Laravel policy, pemeriksaan akses di server, CSRF, autentikasi dan logout.
- 22 OLT tenant utama (9 nama referensi PRD), 88 port, 352 ONU, 64 peringatan redaman. Data disimpan ke database, bukan hanya state browser.
- Navbar lengkap, Light/Dark/System tersimpan, pencarian langsung nama/SN/deskripsi/tag, banner kuota 22/22 dan 28 Okt.
- Ringkasan equipment expandable, detail redaman searchable, grid OLT 3/2/1 kolom.
- View, Port, ONU, informasi perangkat; tambah/edit/hapus persisten, validasi IP/port, pembatasan kuota, konfirmasi hapus, audit aktivitas.
- Operasi demo persisten: Sync menghitung ulang rekap port/ONU dan cap waktu; CLI interaktif dengan riwayat perintah; reboot ONU dengan status sementara dan pemulihan otomatis.
- Form OLT lengkap dengan username, password Telnet, dan SNMP community terenkripsi; edit kosong mempertahankan rahasia lama.
- Tabel ONU: filter working/los/dying-gasp/rebooting, PPPoE, VLAN, indikator Rx Normal/Waspada/Buruk, dan pengaturan Tags.
- CRUD Tags dan Staff (NOC/CS/Teknisi), audit dengan pencarian/filter/pagination, profil, saldo demo, dan peta skematis koordinat ONU.
- Menu integrasi lainnya membuka panel penjelasan status. API token, payment, Telegram, VPN, Affiliate, dan Leaflet belum aktif pada tahap UI ini.

## Instalasi dari checkout baru

Perlu PHP 8.2+, Composer, Node.js 20+ dan npm. Ekstensi PHP: openssl, mbstring, fileinfo, curl, PDO dan driver database, DOM/XML. PHP portable workspace ada di `.tools/php/php.exe` (tidak dilacak Git).

```powershell
Copy-Item .env.example .env
composer install
npm ci
php artisan key:generate
```

Untuk demo lokal: atur `APP_ENV=local`, `NMS_DEMO=true`, `NMS_SUBSCRIPTION=true`, `DB_CONNECTION=sqlite`, `SESSION_DRIVER=file`, dan `CACHE_STORE=file` di `.env`, lalu:

```powershell
New-Item database/database.sqlite -ItemType File -ErrorAction SilentlyContinue
php artisan migrate --seed
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

`php artisan db:seed` aman dijalankan ulang: data yang sudah ada tidak direset. Untuk pengembangan frontend dengan HMR, jalankan `npm run dev` di terminal lain. Jangan gunakan `migrate:fresh` pada data yang ingin dipertahankan.

## PostgreSQL / MySQL dan Redis

SQLite adalah penyimpanan mock lokal agar dashboard dapat langsung direview tanpa layanan database tambahan. Skema migrasi memakai Laravel Schema untuk PostgreSQL/MySQL sebagaimana BAB 5 PRD. Untuk PostgreSQL dan Redis lokal opsional:

```powershell
$env:NMS_DB_PASSWORD = '<kata-sandi-lokal-Anda>'
docker compose up -d
```

Atur `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nms
DB_USERNAME=nms
DB_PASSWORD=<sama-dengan-NMS_DB_PASSWORD>
```

Kemudian jalankan `php artisan config:clear` dan `php artisan migrate --seed`. Untuk MySQL gunakan `DB_CONNECTION=mysql`, port 3306, dan database/kredensial layanan MySQL Anda. Driver PHP PDO yang sesuai harus tersedia. Redis disediakan pada compose untuk tahap worker; dashboard demo tetap memakai file cache/session dan belum memerlukan Redis. Integrasi worker akan menggunakan `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, dengan ekstensi phpredis atau paket predis.

Untuk mode internal tanpa ekosistem SaaS: `NMS_SUBSCRIPTION=false`. Menu VPN/Saldo/Affiliate dan banner paket disembunyikan, serta kuota subscription tidak membatasi penambahan OLT.

## Verifikasi

```powershell
# Runtime portable workspace
$env:MIBS = ';'
.\.tools\php\php.exe artisan test
npm run build
npm audit

# Browser (jalankan start.ps1 di terminal lain)
npx playwright install chromium
npx playwright test

# Direkomendasikan: database browser terpisah, server otomatis dihentikan
.\scripts\test-browser.ps1
```

Pengujian PHP memakai SQLite in-memory terpisah. Skrip `scripts/test-browser.ps1` membuat database SQLite terpisah di `storage/framework/testing`, menjalankan migrasi/seeder dan server port 8011, lalu menjalankan Playwright dan menghentikan server. Database/log pengujian disimpan untuk diagnosis. Uji browser mencakup CRUD OLT dengan kredensial, siklus reboot, CRUD/assignment tag, role staff pada sesi aktif, audit, pencarian, tema, dan overflow 375/768/1024 px. `npx playwright test` langsung memakai server port 8000 dan memodifikasi data demo; gunakan skrip terisolasi untuk pengujian lengkap. Screenshot desktop diambil pada 1440 px.

- [Screenshot desktop](docs/dashboard-desktop.png)
- [Screenshot mobile](docs/dashboard-mobile.png)
- [Tabel operasional ONU](docs/operations-onu-desktop.png)
- [Staff pada mobile](docs/operations-staff-mobile.png)
- [Panduan operasional](docs/OPERATIONS.md)
- [Panduan driver nyata](docs/REAL-OLT.md)
- [Form koneksi nyata](docs/real-connection-form.png)
- [Pemetaan PRD, aturan RBAC, dan batas implementasi](docs/IMPLEMENTATION.md)

## Struktur

```text
app/Http/Controllers/       Dashboard, autentikasi, CRUD/simulasi OLT
app/Http/Middleware/        Sesi demo khusus lingkungan lokal
app/Models/                 Entitas tenant, user, OLT, port, ONU, tag, audit
app/Policies/               Izin aksi OLT/ONU berdasarkan role dan tenant
app/Services/               Operasi demo dan pencatatan audit tanpa rahasia
database/migrations/        Seluruh tabel inti BAB 5
database/seeders/            Seeder idempotent 2 tenant
resources/views/            Layout, login, dashboard dan dialog
resources/js/app.js         State/interaksi Alpine dan request CSRF
resources/css/app.css       Tailwind + token tema dan komponen responsif
tests/Feature/              Uji keamanan tenant/RBAC, kuota, CRUD, sesi
tests/Browser/              Uji UI Playwright desktop/mobile
docs/                       Screenshot dan catatan implementasi
```

## Catatan versi Laravel

Laravel 11 dipertahankan sesuai PRD. Composer menemukan advisory keamanan pada versi 11 yang tersedia; detail ada di `docs/IMPLEMENTATION.md`. Proyek ini untuk pengembangan lokal. Sebelum deployment produksi, tangani hasil `composer audit`, gunakan versi framework yang didukung, matikan demo/debug, dan ganti akun/kredensial contoh. Tidak ada deployment publik yang dilakukan.

## Operasional real-time dan modul tambahan

Jalankan `./start.ps1` untuk web, dua worker dan monitor perangkat. Bila web sudah berjalan, gunakan `./start.ps1 -WorkersOnly` pada terminal terpisah. Subscription dimatikan pada konfigurasi lokal sesuai permintaan pengguna. [Panduan lengkap](docs/LIVE-OPERATIONS.md) memuat Sync/progres, interval polling, provisioning ONU, FTTH, token API, profil dan backup.
