# Operasional OLT / ONU

## Driver nyata dan simulator

OLT kini mempunyai pilihan real/simulator. Jalur real memakai driver Laravel Telnet/SSH/SNMP langsung; lihat [commissioning driver nyata](REAL-OLT.md). Mode simulator tidak dipilih otomatis setelah error jaringan. Bagian siklus reboot delapan detik di bawah hanya berlaku untuk simulator.

## Pembaruan operasional real-time

Tombol Sync/View/Port kini memakai antrean background dan snapshot tersimpan. `start.ps1` menjalankan worker serta monitor bersama server; untuk server yang sudah berjalan gunakan `start.ps1 -WorkersOnly`. Jadwal, modul provisioning/FTTH/API/backup dan batas firmware dijelaskan pada [LIVE-OPERATIONS.md](LIVE-OPERATIONS.md). Endpoint sinkron lama tetap tersedia untuk kompatibilitas.

## Menjalankan versi ini

Pada workspace ini migrasi operasional sudah diterapkan dan aset sudah dibangun. Jalankan `./start.ps1`, kemudian buka `http://127.0.0.1:8000`.

Untuk checkout/instalasi yang sudah memiliki database Tahap 1:

```powershell
php artisan migrate
npm ci
npm run build
```

Migrasi menambahkan `olts.firmware_version` dan metadata siklus reboot ONU. Tidak ada reset data, perubahan nama field BAB 5, atau perubahan kuota. Pada mode simulator, OLT lama mendapatkan firmware simulasi saat Sync berikutnya; mode real hanya memakai hasil perangkat. Seeder baru memiliki sampel working, los, dan dying-gasp; seeder tidak mengubah status ONU yang sudah ada.

## Tambah dan edit OLT

Form menyediakan Nama OLT, chassis C320/C300, IP private/public (IPv4/IPv6), port Telnet, username, password, port SNMP, community, dan lokasi.

- Tambah membutuhkan username, password, dan community. Nilai IP/port dan keunikan nama/koneksi diperiksa di server.
- Edit mengambil konfigurasi melalui endpoint khusus Owner. Password/community tidak pernah dibaca kembali ke browser. Kolom kosong mempertahankan nilai tersimpan; nilai baru menggantinya.
- Field request `telnet_password` dipetakan ke database `telnet_pass_enc`; `snmp_community` ke `snmp_community_enc`. Kedua kolom memakai Laravel encrypted cast. Username tetap `telnet_user`.
- Rahasia tidak masuk audit, JSON dashboard, settings response, localStorage, atau old-input session. Spasi dalam rahasia dipertahankan.
- Form Tambah tetap dapat dibuka ketika kuota penuh, dengan penjelasan kuota. Penyimpanan tetap dibatasi server.
- Tenant Bima Network berisi 22/22 OLT. Untuk mencoba tambah tanpa menghapus sampel, masuk sebagai `owner@nusantara.test` (password demo yang sama); tenant kedua memiliki 1/2 OLT. Mode internal dapat memakai `NMS_SUBSCRIPTION=false`.

## Lima aksi perangkat

**View:** uptime, CPU load, suhu chassis, firmware, lokasi/IP/port, rekap ONU, dan `last_sync_at`. Pada mode real data diambil langsung dari perangkat; pada simulator merupakan snapshot demo.

**Port:** daftar interface GPON, slot, deskripsi, total ONU, dan ONU online. Angka port diselaraskan dengan state ONU pada sync/reboot.

**Sync real:** menyimpan inventaris port/ONU/status lebih dulu, lalu melengkapi redaman ONU working. Port kosong dan optik port offline dilewati; olt-rx tidak dipanggil pada Sync massal. Timeout optik menghasilkan warning dan nilai null tanpa membatalkan inventaris. Inventaris parsial tidak mengarsipkan port/ONU yang belum dibaca. Lihat detail timeout dan checkpoint pada panduan driver nyata. **Sync simulator:** tombol loading mencegah permintaan ganda. Server menghitung ulang rekap port/ONU dari database lokal, menyelesaikan reboot demo yang waktunya habis, mengisi firmware demo jika belum tersedia, menyimpan `last_sync_at`, dan mencatat `olt.sync.demo`. Grid dan ringkasan diperbarui tanpa reload halaman. Nilai optik/status jaringan tidak diacak dan tidak diklaim berasal dari perangkat nyata.

**ONU:** tabel ringkas mengikuti referensi operasional dengan Nama, Status, Posisi, Redaman ONT, Terakhir online, Serial, Traffic, dan Aksi. Header dapat diklik untuk sorting; pencarian nama/SN/deskripsi/tag dan filter status tetap aktif. Tombol mata/opsi membuka detail berisi nama OLT, interface lengkap, PPPoE, VLAN, deskripsi, dan Tags. Tombol reboot tetap mengarah ke ID ONU yang dipilih. Nama yang belum terbaca tidak disamarkan sebagai nama pelanggan; jalankan Sync untuk mengambil nama per-interface dari OLT. Traffic yang belum dikoleksi ditampilkan kosong. Indikator optik:

| Rx | Label |
| --- | --- |
| ≥ -25 dBm | Normal (hijau) |
| > -27 dan < -25 dBm | Waspada (kuning) |
| <= -27 dBm | Buruk (merah), sesuai threshold PRD |
| null | Belum tersedia |

Label teks mendampingi warna. Batas waspada -25 dBm adalah konvensi UI demo; ambang buruk tetap mengikuti PRD.

**Hapus:** hanya Owner, melalui dialog konfirmasi. Port/ONU/log optik/pivot terkait dihapus lewat foreign key; `olt.delete` tersimpan, dengan olt_id null setelah OLT dihapus.

## Reboot ONU

Hanya Owner/NOC. Pada mode real urutan konfigurasi/reboot dikirim ke perangkat; phase_state tetap observasi terakhir hingga Sync. Respons ambigu diberi reboot_uncertain dan tidak dicoba ulang otomatis. Pada simulator, tombol meminta konfirmasi, kemudian POST menghasilkan 202 dan `audit_log_id`. State menjadi `rebooting`, tombol dinonaktifkan, dan rekap ONU online turun sementara. Metadata `reboot_requested_at`, `reboot_complete_at`, dan `reboot_restore_state` mempertahankan siklus di database.

Browser meminta pembaruan setiap 2 detik selama ada reboot; permintaan polling tidak dilakukan saat tab tersembunyi. Setelah sekitar 8 detik, pembacaan dashboard/sync berikutnya memulihkan state asal. LOS tetap LOS, dying-gasp tetap dying-gasp, dan redaman tidak diubah. Ini tidak memperbaiki serat terputus atau modem tanpa listrik. Reboot ganda menghasilkan 409; OLT offline menghasilkan 422. CS/Teknisi ditolak oleh server, bukan hanya UI.

Penyelesaian simulasi terjadi ketika dashboard/sync dibaca. Di luar environment local + NMS_DEMO=true, operasi simulator menghasilkan 503. Mode real tidak memerlukan demo dan memakai driver Laravel langsung.

## Web CLI

Mode real mengirim perintah satu baris ke perangkat melalui sesi baru untuk setiap request; hanya Owner/NOC. Mode simulator tetap interaktif dengan prompt ZXAN#, history ↑/↓, clear/Ctrl+L, output auto-scroll, dan penanganan perintah tidak dikenal. Perintah yang tersedia:

```text
help / ?
show version
show card
show processor
show temperature
show gpon onu state
show gpon onu optical-power
clear
```

Pada simulator, server melakukan pemetaan perintah yang diizinkan. Pada real, input dikirim ke CLI perangkat tanpa OS shell; karakter kontrol/multiline ditolak. Akses khusus Owner/NOC dan permintaan CLI server dicatat sebagai `olt.cli.demo` atau `olt.cli.real` sesuai mode.

## Tags, Staff, Audit

**Tags:** Owner/NOC dapat membuat, mengedit nama/warna, menghapus dengan konfirmasi, mencari ONU berdasarkan tag, dan mengatur multi-tag per ONU dari tabel ONU. Penghapusan tag melepas relasi tanpa menghapus ONU. Seluruh ID tag divalidasi terhadap tenant yang sama.

**Staff:** Owner dapat membuat akun NOC/CS/Teknisi, mengubah identitas/role/password, serta menghapus akun dengan konfirmasi. Password minimal 12 karakter dan di-hash. Password edit kosong mempertahankan password lama. Akun Owner tidak dapat diubah atau dihapus di modul Staff; role `isp_owner` tidak dapat diberikan lewat modul ini. Role baru berlaku pada permintaan berikutnya, termasuk sesi yang sudah aktif. Akun yang dihapus kehilangan akses. Auto-login demo hanya berlaku pada awal sesi baru, sehingga penghapusan staff tidak beralih otomatis ke Owner.

**Audit:** khusus Owner, data tenant sendiri, 20 baris per halaman. Mendukung pencarian aktor/aksi/target, filter aksi, rentang tanggal WIB, refresh, dan pagination. Nama aktor tersedia selama akun masih ada; actor_type/id tetap tersimpan setelah akun dihapus. Audit tidak dapat diedit atau dihapus melalui UI/API ini.

## Pengujian

```powershell
.\.tools\php\php.exe artisan test
npm run build
.\scripts\test-browser.ps1
```

PHP tests menggunakan SQLite in-memory dan fixture TCP/UDP loopback untuk driver nyata, termasuk enkripsi, kredensial kosong, kuota, izin lintas tenant, siklus reboot, counter port, perubahan role sesi aktif, Tags, Staff, dan audit pagination/filter. Skrip browser memakai SQLite terpisah dan server lokal sementara; inventaris database utama tetap utuh. PostgreSQL/MySQL belum diuji dengan layanan hidup pada lingkungan ini.
