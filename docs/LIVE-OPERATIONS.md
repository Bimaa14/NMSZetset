# Operasional real-time dan modul workspace

Implementasi mengikuti PRD dan kontrak yang teramati pada [peninjauan Zetset](ZETSET-BACKEND-FLOW.md). Driver dan kode backend merupakan implementasi lokal; teknologi internal server Zetset tidak diasumsikan sama.

## Menjalankan aplikasi

```powershell
.\start.ps1
```

Skrip menjalankan migrasi tambahan, dua worker antrean `olt`, monitor perangkat, dan server web. Database serta APP_KEY yang ada dipertahankan. Jika server web sudah berjalan melalui proses lain, worker/monitor dapat dijalankan pada terminal terpisah:

```powershell
.\start.ps1 -WorkersOnly
```

Ctrl+C menghentikan proses yang dimulai skrip tersebut. Jangan menjalankan beberapa launcher untuk workspace yang sama. Untuk server produksi, jalankan `php artisan queue:work olt --queue=olt --sleep=1 --tries=1 --timeout=330` dan `php artisan nms:monitor` melalui process manager. Alternatif monitor: scheduler Laravel menjalankan `nms:tick` setiap menit.

Antrean `olt` menggunakan database dan mempunyai `retry_after=600`, lebih panjang daripada timeout job. Ini tetap berjalan ketika koneksi antrean default aplikasi memakai `sync`. SQLite lokal menggunakan WAL dan busy timeout; PostgreSQL/MySQL tetap tersedia melalui konfigurasi Laravel. Konfigurasi layanan PostgreSQL/MySQL/Redis produksi belum diuji dalam workspace ini.

## Waktu pembaruan

| Bagian | Perilaku |
| --- | --- |
| Layar dashboard | Membaca snapshot server tiap 10 detik; selama ada pekerjaan, tiap 2 detik. Polling layar dijeda saat tab tersembunyi. |
| Inventaris/status ONU | Default setiap 5 menit untuk OLT real dengan pemantauan aktif. `OLT_STATE_INTERVAL` minimal 300 detik; gunakan 600 untuk 10 menit. |
| Optik | Setiap 5 menit; hanya PON dengan ONU working; tidak menjalankan OLT-RX massal. |
| Nama ONU | Setiap 15 menit; ONU yang belum bernama didahulukan pada pembacaan per-interface. |
| CPU/memori/suhu/uptime | Setiap 5 menit dan saat pengguna membuka detail sistem. |
| Traffic | Batch tiap 60 detik melalui SNMP, fallback CLI; live ONU/PON dan filter bulan tersedia. Lihat [TRAFFIC.md](TRAFFIC.md). |
| Backup konfigurasi | Setiap 24 jam; hanya mode real dengan pemantauan aktif. |

Interval adalah jadwal yang diupayakan. Durasi respons perangkat, antrean, dan proses yang sedang berjalan dapat menambah jeda. Status dan metrik memiliki cap waktu tersendiri. Ini adalah pemantauan melalui polling; penerimaan SNMP trap belum diterapkan. Modal Traffic memiliki polling live setiap 4 detik setelah request selesai.

Polling rutin berlangsung tanpa progress bar pada kartu OLT. Progress hijau hanya tampil untuk Sync manual yang antre/berjalan dan inventaris awal. Migrasi 000016 menyimpan `is_initial_sync` saat operasi dibuat sehingga progress awal tidak hilang ketika checkpoint mengisi `last_sync_at`. Status `SyncMib`, `Logging`, dan `Auth` disimpan sebagai `sync-mib`, `logging`, dan `auth`; UI menandainya sebagai transisi, bukan mengarang status working.

Kegagalan metrik opsional mendapat jeda retry minimal 120 detik atau sepanjang interval metrik tersebut, mana yang lebih lama. SNMP yang tidak tersedia tidak menghalangi pembacaan nama, optik, dan sistem melalui Telnet.

Monitor berjalan terpisah dari browser. OLT simulator tidak dipoll otomatis. Mode real yang gagal tidak berubah menjadi simulator. Pemantauan per OLT dapat dijeda/diaktifkan oleh Owner melalui detail perangkat.

## Sync background

Tombol Sync membuat operasi `queued` dan segera mengembalikan HTTP 202. Tahapnya:

1. Inventaris: `show card`, state dan SN per port, validasi identitas/rekap, penyimpanan snapshot/checkpoint.
2. Nama: konfigurasi global, lalu fallback per-interface/detail-info untuk nama yang belum terbaca.
3. Optik: pembacaan ONU-RX pada port yang mempunyai ONU working. Port yang paling lama belum dicoba diprioritaskan, sehingga port lambat tidak terus menahan port lain. Metrik port yang belum sempat dicoba dipertahankan dengan cap waktunya.

Progres, tahap, waktu mulai/selesai, warning dan kegagalan disimpan dalam `olt_operations`. Satu operasi aktif diperbolehkan per OLT; permintaan dengan jenis sama digabung. Jenis berbeda menunggu giliran dan dipromosikan setelah operasi aktif selesai. Dengan demikian View yang sedang berjalan tidak menggantikan permintaan Sync.

Inventaris tersedia sebelum pembacaan optik selesai. Poll status tidak menghapus nilai optik lama; UI menyertakan umur pengamatannya. Kegagalan perintah menampilkan diagnostik yang disanitasi. Antrean yang belum berjalan dapat dibatalkan dari kartu OLT. Worker memeriksa kembali tenant/izin aktor saat mengeksekusi pekerjaan. Pekerjaan terhenti dapat ditandai gagal dan melepaskan reservasi tanpa menghapus snapshot.

Endpoint sesi tambahan: `POST/GET /olts/{id}/operations`, `PUT /olts/{id}/monitoring`, `GET /olts/{id}/names`, `/capabilities`, `GET /onus` (pagination/filter server), dan `GET /onus/{id}/history`. Endpoint sinkron lama dipertahankan untuk kompatibilitas; tombol UI memakai antrean baru.

## Tabel dan operasi ONU

Menu ONU membuka daftar lintas OLT dengan filter OLT. Tabel menyediakan filter PON/status/pencarian, sorting, pagination tampilan, pilihan kolom yang tersimpan pada akun, dan ekspor CSV hasil filter. Nama, posisi dan serial dipisahkan. Identitas ONU tetap OLT/port/index/sumber/SN sehingga SN yang sama pada posisi lain tidak tertimpa.

Detail memuat metadata, waktu pengamatan, riwayat perubahan phase, riwayat optik, pengelolaan tag dan lokasi pelanggan. Owner/NOC dapat mengubah nama di perangkat, mengaktifkan/nonaktifkan admin state, reboot, dan menghapus pendaftaran ONU. Nama/admin/hapus real memakai pemeriksaan identitas dan verifikasi balik dari CLI. Status working tidak dipalsukan setelah konfigurasi atau reboot. Penghapusan mengarsipkan entri lokal beserta riwayatnya, setelah pendaftaran pada perangkat berhasil diverifikasi terhapus.

Kehilangan respons setelah write ditandai sebagai hasil yang perlu diperiksa, tanpa retry write otomatis. Owner/NOC tetap dapat menggunakan Web CLI untuk perintah lanjutan. Aksi konfigurasi, PPPoE/bridge dan model ONU memerlukan profil yang cocok dengan firmware; keberhasilan pendaftaran belum membuktikan koneksi internet pelanggan.

## Provisioning dan profil layanan

Owner membuat profil: pendaftaran saja, bridge, PPPoE OMCI, atau template CLI. Nama tipe ONU, profil bandwidth, VLAN dan WAN profile diisi sesuai konfigurasi yang benar-benar tersedia pada perangkat. Template mendukung parameter posisi/SN/nama dan kredensial PPPoE; rahasia tidak disimpan dalam template.

Wizard Pasang ONU:

1. Pindai `show gpon onu uncfg`; parser sesuai contoh fisik pengguna, termasuk `gpon-onu_1/2/10:1 ZTEGCC4E11C3 unknown`.
2. Pilih ONU, posisi, ID tujuan yang kosong, nama dan profil. Indeks pada tabel unconfigured bukan bukti bahwa ID itu kosong pada inventaris terdaftar.
3. Pratinjau memeriksa ulang daftar unconfigured dan running-config PON. Rencana berlaku 10 menit, terikat akun/OLT/profil/koneksi, dan hanya dapat dikonfirmasi sekali.
4. Konfirmasi memeriksa ulang kondisi perangkat, mengirim konfigurasi dan memverifikasi SN pada running-config. Setelahnya inventaris dijadwalkan diperbarui.

Jika firmware menolak salah satu konfigurasi layanan setelah pendaftaran, hasil ditandai `uncertain`; tidak ada rollback penghapusan otomatis atau pengiriman ulang. Password PPPoE hanya berada pada request konfirmasi dan sesi perangkat; diagnostik disanitasi. Simulator merupakan mode eksplisit yang menambahkan data lokal tanpa klaim pendaftaran fisik.

## FTTH Maps

Leaflet menampilkan koordinat OLT dan pelanggan serta perangkat OTB/ODC/ODP/tiang. Perangkat dapat dibuat/diubah/dihapus, dihubungkan ke induk pada OLT yang sama, dan dialokasikan ke ONU melalui port ODP.

Server menolak hubungan siklus, induk lintas tenant/OLT, port ganda, port di luar kapasitas, serta pengecilan kapasitas atau penghapusan perangkat yang masih terpakai. Nama perangkat dan popup ditampilkan sebagai teks. Peta dasar memakai tile OpenStreetMap dengan atribusi; koneksi internet diperlukan untuk peta dasar. Perubahan data lokal tetap disimpan di server NMS. Posisi/zoom dipertahankan saat data peta diperbarui.

## API, profil, Staff, Tags dan Audit

Menu API dapat membuat token read-only/read-write dengan masa berlaku 1–365 hari serta mencabutnya. Token penuh tampil satu kali; database menyimpan SHA-256. Token terikat tenant dan Owner penerbit; Owner yang kehilangan akses membuat token tidak dapat dipakai. API memakai Bearer, batas permintaan, dan scope write untuk reboot.

Endpoint `/api/v1/olts`, `/onus/search`, `/onus/{id}/live`, `/operations/{id}`, `/monitoring/bad-rx` dan `/onus/{id}/reboot` sudah aktif. `/live` mengembalikan HTTP 202 dengan snapshot tersimpan dan URL progres; field `realtime=false` tetap jujur sampai pembacaan selesai. Panduan tersedia di `/api/docs`. Audit membedakan aktor API_Token dan pengguna.

Profil pengguna dapat mengubah nama/email/password dengan verifikasi password saat ini; role dan tenant tidak dapat diubah dari form profil. Staff, Tags dan Audit tetap menggunakan izin server sesuai PRD. Subscription, pembayaran/saldo, affiliate, VPN dan aktivasi Telegram ditunda atas instruksi pengguna.

## Traffic dan backup

Traffic memakai [IF-MIB RFC 2863](https://www.rfc-editor.org/info/rfc2863/): `ifName`, `ifHCInOctets`, `ifHCOutOctets` dan `ifCounterDiscontinuityTime`. Pemetaan harus cocok dengan nama interface GPON yang tersimpan. Tidak ada tebakan ifIndex/OID vendor. Dua sampel counter diperlukan; pergantian interface/ONU, reset uptime/counter atau discontinuity membatalkan delta. Pengurangan Counter64 memakai integer presisi penuh. Interface yang tidak dilaporkan tetap kosong. Arah keluar OLT ditampilkan sebagai download pelanggan, masuk OLT sebagai upload.

Backup membaca `show running-config` melalui jalur internal, menyimpannya dengan Laravel encrypted cast dan SHA-256, serta menyediakan unduhan khusus Owner. Respons daftar backup menyembunyikan konfigurasi; audit hanya mencatat identitas backup. Cadangan yang diunduh dapat memuat rahasia perangkat. Pemulihan otomatis seluruh konfigurasi belum disediakan.

## Verifikasi dan batas dukungan

Verifikasi lokal 16 Oktober 2026: build Vite berhasil, 147 tes PHP lulus (757 assertions), dan 15 tes browser Playwright lulus. Worker/monitor lokal aktif; pembacaan inventaris dan optik OLT real berhasil, sedangkan polling traffic SNMP pada koneksi yang tersimpan masih gagal/timeout. Nilai traffic tetap kosong sampai perangkat memberikan counter yang dapat dibaca.

Pengujian memakai database terisolasi, peer Telnet/SSH/SNMP loopback dan browser. Ini memverifikasi aplikasi, transport, parser, transaksi, izin dan alur operasi; pengujian write tidak dijalankan ke OLT produksi pengguna. Pembaruan 17 Oktober 2026 menambahkan pembacaan langsung WAN/WiFi/Ethernet/IP host dan form konfigurasi pada [modal detail ONU](ONU-DETAIL.md), dengan format C320 V2.1.0 yang telah diperiksa. Perubahan layanan dan reset tetap belum diuji pada ONU produksi. Variasi firmware lain, TR-069, trap receiver, serta restore massal memerlukan dukungan tambahan. Kode tidak mengiklankan metrik sebagai tersedia ketika belum dikoleksi.

Dokumentasi runtime mengikuti [Laravel queues](https://laravel.com/framework/docs/11.x/queues) dan [Leaflet](https://leafletjs.com/reference).
