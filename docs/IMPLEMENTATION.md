# Pemetaan implementasi ke PRD

Seluruh `prd.txt` dibaca sebelum implementasi. Lingkup pekerjaan mengikuti permintaan **Fondasi Dashboard, Operasional OLT/ONU, dan Driver Nyata C300/C320**, bukan seluruh roadmap BAB 6.

| Acuan | Hasil tahap ini |
| --- | --- |
| BAB 1 | Aplikasi lokal yang mendukung IP private tanpa port forwarding. Mode subscription dapat dimatikan lewat konfigurasi. |
| BAB 2 | Autentikasi sesi; policy OLT/ONU dan Gates Staff/Tags/Audit dengan pemeriksaan tenant dan kemampuan; Owner, NOC, CS, Teknisi. Tenant kedua disediakan untuk pengujian isolasi. |
| BAB 3.1 | Navbar, profil role aktif, logout, Light/Dark/System, seluruh menu Owner. Menu SaaS bergantung mode subscription. |
| BAB 3.2 | Pencarian instan nama/SN/deskripsi/tag; kuota dari database; ringkasan expandable; tabel detail redaman, threshold <= -27 dBm (instruksi real driver terbaru). |
| BAB 3.3 | Grid 3/2/1 kolom; 22 OLT termasuk 9 referensi; info, edit, CLI; View/Port/Sync/ONU/Hapus; form kredensial terenkripsi; tambah dengan validasi kuota server; tabel ONU, reboot, CRUD Tags/Staff, audit filter/pagination. |
| BAB 4 | Tabel api_tokens disiapkan; menu API menjelaskan kontrak dan URL rencana. Generasi/cabut token, autentikasi Bearer dan endpoint integrasi sudah aktif; panduan tersedia di /api/docs. |
| BAB 5 | Laravel 11, Blade, Tailwind 4, Alpine; migrasi seluruh tabel inti. Konfigurasi Laravel mendukung PostgreSQL/MySQL serta Redis. SQLite dan file session/cache hanya untuk demo lokal. |

## Data contoh yang konsisten

- Tenant utama Bima Network: Premium, 22/22 OLT, kedaluwarsa 28 Oktober 2026.
- 22 OLT online; model C300 dan C320; IP `10.10.1.11` sampai `10.10.1.32`, Telnet 23, SNMP 161.
- 22 slot aktif, 88 port, 352 ONU; 344 online, 8 offline (LOS/DyingGasp pada seeder baru).
- 64 ONU dengan Rx < -27 dBm, dibandingkan dua optical snapshots pukul 14.00 dan 15.00 WIB.
- Timestamp awal snapshot 15 Oktober 2026 15.48 WIB sesuai contoh PRD. UI menampilkan cap waktu, bukan mengklaim telemetri live.
- Data tambahan adalah simulasi, bukan inventaris perangkat atau pelanggan sebenarnya. Seeder idempotent dan hanya aktif pada environment local/testing.

## Aturan hak akses

| Kemampuan | Owner | NOC | CS | Teknisi |
| --- | --- | --- | --- | --- |
| Cari ONU, View, Port, ONU, redaman | Ya | Ya | Ya | Ya |
| Sync / CLI / Reboot ONU | Ya | Ya | Tidak | Tidak |
| Tambah / Edit / Hapus OLT | Ya | Tidak | Tidak | Tidak |
| FTTH Maps | Ya | Ya | Tidak | Ya |
| Tags operasional | Ya | Ya | Tidak | Tidak |
| Staff, Audit, API, SaaS | Ya | Tidak | Tidak | Tidak |

Kebijakan berlaku pada server. ID OLT selalu dicari dalam tenant pengguna; ID tenant lain menghasilkan 404. Password/kredensial OLT tidak dikirim ke browser. Kredensial perangkat pada model menggunakan encrypted casts. Mutasi menggunakan CSRF dan audit log; hapus melalui dialog konfirmasi. Relasi perangkat memakai foreign key cascade; audit tetap tersimpan dengan olt_id null setelah hapus.

## Driver dan batas integrasi

Mode koneksi per OLT: real atau simulator. `ZteTelnetClient`, `ZteTelnetDriver`, `ZteSshClient`, `ZteSnmpDriver`, parser, dan `RealOltOperations` menjalankan koneksi nyata di Laravel sesuai instruksi terbaru, menggantikan kebutuhan worker Python untuk jalur operasi ini. Simulator lama tetap tersedia secara eksplisit pada environment lokal demo. Kegagalan real tidak pernah diubah menjadi keberhasilan simulator.

Sync nyata menarik board, port, state ONU, SN, dan power; menyimpan inventaris terverifikasi terlebih dahulu, kemudian melengkapi optik best-effort beserta optical logs. State per-port sekuensial mengikuti format perangkat fisik dan rekap ONU Number online/total; port kosong dan optik port offline dilewati; timeout optik tidak membatalkan inventaris. View menarik metrik sistem; Port menarik snapshot. Reboot memakai sesi konfigurasi berurutan dan audit hasil; tidak memalsukan phase_state dengan timer. CLI nyata satu baris membuka sesi baru per request. SNMP v2c menyediakan system OIDs/IF-MIB dan profil metrik numerik opsional; vendor OID optik tidak ditebak. [Panduan commissioning dan batas format](REAL-OLT.md).

Field PRD tetap dipakai. SN ONU boleh sama pada beberapa interface/OLT; penyimpanan dan aksi menggunakan identitas OLT/port/index/sumber/SN serta ID ONU, sehingga entri tidak saling menimpa. Tambahan: connection_mode/transport/timeout, SSH fingerprint, memory/boards, status pemeriksaan koneksi, flag inventaris teramati, dan sumber log optik. Data yang tidak lagi teramati diarsipkan, bukan dihapus. Metadata pelanggan pada record real dipertahankan. Histori simulator tidak dipakai sebagai pembanding redaman nyata. Batas buruk <= -27 dBm mengikuti instruksi terbaru.

FTTH Maps kini memakai Leaflet dengan CRUD topologi dan alokasi port ODP; API Bearer token sudah aktif. Super Admin platform, Telegram, VPN, Affiliate, pembayaran, dan terminal WebSocket persisten belum aktif. Subscription dan integrasi akun eksternal ditunda atas instruksi pengguna. Tidak ada deployment publik. Pengujian driver memakai peer TCP/UDP loopback; OLT fisik dan variasi firmware aktual belum diverifikasi.

## Catatan versi

Laravel 11 dipertahankan karena diwajibkan BAB 5 PRD. Composer mendeteksi advisory pada framework 11; versi terpasang 11.57.0. Resolusi awal memakai flag `--no-security-blocking` untuk memenuhi versi tersebut, tanpa mematikan audit pada konfigurasi global/proyek. Sebelum penggunaan produksi, PRD perlu diperbarui untuk versi Laravel yang didukung dan hasil `composer audit` harus ditangani. Frontend menggunakan Tailwind 4 dan hasil npm audit saat verifikasi: 0 kerentanan.

Referensi implementasi: [Laravel 11 installation](https://laravel.com/framework/docs/11.x/installation), [Laravel authorization](https://laravel.com/framework/docs/11.x/authorization), [Tailwind Vite](https://tailwindcss.com/docs/installation/using-vite).

## Operasional background dan modul workspace

Lihat [LIVE-OPERATIONS.md](LIVE-OPERATIONS.md) untuk worker, jadwal polling perangkat, Sync tiga tahap, riwayat, traffic IF-MIB, provisioning dengan verifikasi, token API, FTTH, profil dan backup terenkripsi. Fitur yang teramati di Zetset dipetakan pada [ZETSET-BACKEND-FLOW.md](ZETSET-BACKEND-FLOW.md); kode server internal Zetset tidak diakses.
