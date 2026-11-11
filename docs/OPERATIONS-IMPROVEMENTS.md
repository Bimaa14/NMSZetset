# Perbaikan operasional 19 Oktober 2026

## Provisioning dan restore

OLT busy tidak mengonsumsi preview provisioning. Token yang masih berlaku dapat dicoba lagi. Setelah command dikirim, hasil yang tidak cocok tetap `uncertain`, tidak diulang otomatis. Bridge memeriksa T-CONT, GEM, VLAN, service-port dan ONU service; PPPoE juga memeriksa WAN dan kredensial. Firmware yang menyamarkan password tidak dapat diverifikasi penuh dan hasilnya tetap uncertain. Profil custom wajib mempunyai aturan readback eksplisit per konteks.

Menu Backup menyediakan **Pulihkan layanan ONU**: pilih backup, cari ONU, buat preview, lalu Apply. Preview mencocokkan checksum, OLT, tenant, posisi/SN, profil perangkat, dan fingerprint konfigurasi saat ini. Apply memeriksa ulang identitas/fingerprint dan membaca kembali hasil. Hanya entri layanan yang dikenali parser yang dipulihkan; entri tambahan saat ini tidak dihapus. Registrasi, nama pelanggan, akses manajemen dan konfigurasi seluruh OLT tidak direstore. Port/fitur yang tidak didukung akan menolak preview. Restore memerlukan izin manage dan operate. Tidak ada restore atau provisioning ke perangkat fisik dalam pengujian implementasi ini.

## Polling, direktori dan analisis optik

Traffic SNMP membaca maksimal 20 interface per request (3 counter/interface + uptime), dengan anggaran satu putaran 20 detik. CLI tetap dibatasi 40 interface/20 detik dan cursor berputar. Scheduler mengutamakan pekerjaan berdasarkan keterlambatan relatif; traffic yang belum lengkap dapat dijadwalkan lagi setelah 15 detik, tunduk pada backoff error dan antrean. Ini bukan jaminan seluruh ONU mendapat sampel tiap 60 detik. Kartu OLT menampilkan jumlah interface dengan rate segar terhadap target. Sampel counter awal yang belum menghasilkan rate tidak dihitung segar. Hanya ONU working dan PON aktif masuk cakupan.

Dashboard memakai payload compact serta pencarian, filter, sort dan pagination server. Export dan peta mengambil halaman tambahan bila diperlukan. Endpoint dashboard lama tetap tersedia untuk kompatibilitas.

Redaman memburuk memakai selisih terhadap rata-rata 24 jam sebelum 15 menit terakhir, minimal 3 sampel dari observation_source yang sama. Sampel terakhir harus berumur maksimal 15 menit; penurunan minimal 3 dB ditandai warning dan 6 dB critical. Dua atau lebih ONU yang memburuk pada PON sama ditandai berkorelasi; korelasi bukan bukti penyebab gangguan. Sinyal lemah absolut tetap metrik terpisah. Analisis di-cache 60 detik; belum melakukan inferensi penyebab atau persistensi penurunan beberapa siklus.

## Trap receiver

Supervisor menjalankan `php artisan nms:trap-listen`. Default **127.0.0.1:1162/UDP** hanya menerima dari laptop sendiri. Untuk menerima OLT, atur `NMS_TRAP_BIND` ke alamat jaringan yang sesuai, restart listener, dan konfigurasi tujuan trap pada OLT serta firewall jaringan. Tidak ada perubahan firewall atau tujuan trap OLT otomatis.

Receiver menerima v1/v2c trap, mencocokkan IP sumber dan community dengan tepat satu OLT real, membatasi 120 paket/menit/IP, lalu menyimpan payload terenkripsi di `snmp_trap_events`. Community tidak disimpan. NAT/DNS dan SNMPv3/informs belum didukung. Endpoint `GET /olts/{id}/traps` menampilkan metadata dengan izin audit dan tenant scope. **Tidak mengubah status ONU dari trap**, sesuai keputusan pengguna karena MIB/payload firmware belum tersedia.

Referensi format protokol: [RFC 3416](https://www.rfc-editor.org/rfc/rfc3416). Referensi pembatasan penerimaan trap: [Net-SNMP snmptrapd.conf](https://www.net-snmp.org/docs/man/snmptrapd.conf.html).

## Retensi dan capability

Monitor menjalankan pruning sekali per hari. `php artisan nms:prune` hanya menampilkan jumlah; `--apply` menghapus histori kedaluwarsa per 1000 baris. Default: traffic 45 hari, optik 90, status 180, operasi terminal 90, trap 30, backup 90. Backup terbaru tiap OLT tetap disimpan meskipun tua; operasi aktif dan audit tidak dipangkas. Atur `config/operations.php`; retensi traffic minimal 31 hari agar rentang 30 hari tetap tersedia.

Capability membatasi command ke keluarga C300/C320 GPON. Firmware terdeteksi ditampilkan, tetapi tidak dianggap tersertifikasi tanpa entri exact `model:firmware` di `operations.verified_firmware`. Daftar tersebut sengaja kosong sampai uji firmware tersedia. C6xx, EPON dan full-OLT restore tidak diaktifkan.

## Validasi implementasi

Batch regresi backend: 82 tes, 533 assertion lolos (Dashboard, ONU Configuration Tool, Operational Improvements, Physical Provisioning, Realtime Workspace, Traffic). Build Vite berhasil. Tes browser mencakup pencarian, pagination/cache, tutup modal saat read, tujuh tab, tema dan skeleton. Cek halaman laptop memakai database nyata secara read-only: filter online menghasilkan halaman 50 baris, tidak ada error aplikasi setelah perbaikan bentrok nama ikon Map, serta tidak ada overflow pada viewport 390 px. Monitor/worker direstart dan listener UDP terikat di localhost:1162; cap waktu polling perangkat kembali bergerak. Preview retensi database laptop menghasilkan nol baris kedaluwarsa pada saat aktivasi.
