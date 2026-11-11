# Audit alur backend NMS dan referensi Zetset — 19 Oktober 2026

## Tindak lanjut prioritas tinggi

Tiga temuan P1 di bawah telah ditangani pada 19 Oktober 2026: supervisor monitor/worker + startup pengguna, validasi serial sebelum reboot, serta dispatch transaksional dan rekonsiliasi queued. Rincian operasi dan verifikasi ada di [MONITOR-RUNTIME.md](MONITOR-RUNTIME.md). Tindak lanjut P2/P3 kini tersedia: lifecycle provisioning, readback layanan, SNMP batch/coverage, pagination dashboard, analisis optik, capability model, receiver trap, retensi, dan restore layanan per ONU. Detail serta batas dukungannya ada di [OPERATIONS-IMPROVEMENTS.md](OPERATIONS-IMPROVEMENTS.md). Mapping status trap, sertifikasi firmware lain dan restore seluruh OLT tetap belum diaktifkan. Isi audit berikut dipertahankan sebagai bukti kondisi sebelum perbaikan; diagnostic probes lama mengharapkan cacat dan tidak lagi menjadi tes penerimaan.

## Kesimpulan
Belum setara sepenuhnya. Fondasi snapshot, background Sync, operasi paralel, provisioning terverifikasi, history dan isolasi tenant sudah tersedia. Ada cacat alur yang perlu diperbaiki sebelum memperluas penggunaan. Ini audit, bukan implementasi perbaikan.

Pembanding: `docs/ZETSET-BACKEND-FLOW.md` dan `docs/ZETSET-REFERENCE.md` (hasil observasi sesi sebelumnya), serta https://doc.zetset.id/about (dibaca pada audit ini). Tidak dilakukan login atau inspeksi ulang akun Zetset. Dokumen lama menjelaskan kondisi lokal saat peninjauan awal; klaim implementasi lokal di dalamnya sudah sebagian kedaluwarsa. Kode server internal Zetset tidak tersedia; kesamaan bahasa, queue, lock, database, CLI, atau jaminan keamanannya tidak dapat dinyatakan.

## Prioritas perbaikan

### P1 — Monitor/worker laptop tidak aktif pada waktu audit
Pada 19 Oktober 2026 11:08 WIB, heartbeat monitor dan worker kosong, tidak ditemukan proses `artisan queue:work`, `nms:monitor`, atau `schedule:work`, dan satu operasi masih berstatus running. OLT nyata masih monitoring_enabled=true. Observasi status terakhir 02:06:45 WIB, optik 02:04:19, traffic 02:07:43. Halaman web dapat melayani snapshot tanpa kolektor aktif.

Bukti: `.tools/audit-runtime.php` (read-only); pemeriksaan proses Windows. `start.ps1` mengelola worker/monitor selama launcher hidup, sedangkan compose.yaml hanya menyediakan database dan Redis. Usulan: supervisor/service otomatis start/restart, health check terpisah untuk web/monitor/worker, dan label usia data. Tidak memulai polling atau mengubah perangkat sebagai bagian audit.

### P1 — Reboot tidak memverifikasi serial fisik sebelum write
`app/Services/Olt/ZteTelnetDriver.php:329` langsung configure terminal → pon-onu-mng posisi → reboot. Controller memeriksa inventaris tersimpan, tetapi perangkat pada posisi itu mungkin sudah diganti lewat luar NMS sebelum Sync berikutnya. Akibatnya perintah bisa menuju ONU pengganti. Lock per posisi tidak membuktikan SN. Jalur konfigurasi lainnya sudah mempunyai pemeriksaan serial; reboot perlu memakai pola serupa.

Reproduksi mock CLI memastikan urutan command tidak mengandung pembacaan identitas. Tidak ada reboot fisik.

### P1 — Job queued yang kehilangan dispatch dapat memblokir OLT
`app/Services/Olt/OltOperationService.php:10-33` hanya memulihkan running yang heartbeat-nya tua. Queued dengan active_olt_id tetap terpasang tidak dipromosikan atau dikirim ulang. Ini relevan bila proses mati setelah transaksi penyimpanan operasi tetapi sebelum dispatch afterCommit, atau job hilang dari queue. Tick selanjutnya melewati OLT karena reservasi masih ada.

Reproduksi: enqueue dengan Queue::fake, kosongkan rekaman dispatch, majukan waktu dua jam, panggil tick; operasi tetap queued/aktif tanpa dispatch baru. Usulan: outbox/reconciliation dengan deduplikasi, lease dan recovery queued yang mempertimbangkan job reserved agar tidak menggandakan pekerjaan.

### P2 — Provisioning busy menghabiskan pratinjau dan berubah menjadi 502
`app/Http/Controllers/ProvisioningController.php:49-66` menandai plan running sebelum memperoleh lock. Ketika lock sibuk, catch mengubah plan menjadi failed dan mengembalikan 502 generik meskipun belum ada write. User harus membuat preview baru untuk konflik sementara.

Reproduksi pada simulator dan database tes: tahan lock monitoring, apply preview valid → 502, plan failed, tidak ada penambahan ONU. Usulan: admission lock dahulu, lalu konsumsi token; balas 409 reason=olt_busy tanpa menghabiskan plan selama belum ada write. Jangan retry otomatis setelah write yang hasilnya tidak pasti.

### P2 — Konfigurasi provisioning belum diverifikasi seluruhnya
`app/Services/Olt/ZteProvisioningService.php:42` memverifikasi SN registrasi setelah command, belum membandingkan seluruh T-CONT/GEM/service/VLAN/WAN hasil profile bridge/PPPoE/custom. Keberhasilan registrasi bukan bukti seluruh layanan sesuai intent. ONU Configuration Tool mempunyai readback verifikasi yang lebih lengkap. Usulan: validasi state layanan per jenis profile; custom perlu kontrak verifikasi eksplisit.

### P2 — Interval traffic adalah interval batch, bukan setiap ONU
`app/Services/Olt/ZteTrafficCollectorService.php:67-79` memutar maksimal 40 interface/batch dengan anggaran loop 20 detik, PON + ONU working; cap waktu OLT diperbarui sesudah batch. Dengan contoh 32 PON + 152 ONU online = 184 interface, dibutuhkan minimal lima batch jika semua batch mencapai 40. Dengan interval default 60 detik, satu putaran nominal setidaknya sekitar lima menit dan dapat lebih lama karena IO, job lain atau kegagalan. ONU offline tidak ikut batch ini.

Usulan: tampilkan coverage/umur sampel per interface, ukur keterlambatan nyata, batch SNMP yang cocok firmware, dan jangan menjanjikan setiap ONU mendapat sampel per menit. Jadwal monitor berurutan inventory → traffic → optics → names → system → backup (`OltOperationService.php:40`); fairness/SLA di beban besar belum diuji. Risiko penundaan bukan bukti starvation sudah terjadi di perangkat ini.

### P2 — Pagination backend ada, dashboard belum menggunakannya
`RealtimeController.php:43` sudah mempunyai endpoint terpaginasikan. Namun `DashboardController.php:31` tetap mengambil seluruh ONU tenant dan optical logs; `resources/js/app.js` menyaring dan memotong halaman di browser. Reload dashboard mengirim seluruh koleksi lagi. Zetset yang teramati mempunyai endpoint daftar lintas OLT dengan limit/offset serta ringkasan terpisah.

Usulan: hubungkan UI ke pagination/filter server dan pisahkan summary dari detail; tetap mendukung global search lintas OLT. Tidak ada benchmark skala puluhan ribu ONU pada audit ini.

### P2 — Redaman memburuk masih ambang absolut
`resources/js/app.js:62`: badOnus hanya rx_power <= threshold. Backend mengambil dua sampel terakhir, tetapi belum ada baseline berjendela, frekuensi penurunan, penanda stale atau korelasi satu PON seperti kontrak Zetset yang pernah diamati. ONU yang turun -18 ke -24 dBm dapat luput dari kategori meskipun mengalami penurunan besar; ONU stabil -28 tetap masuk kategori.

Usulan: bedakan sinyal lemah dari sinyal memburuk, baseline dan delta pada waktu yang jelas, freshness, lalu korelasi PON.

### P2 — Capability belum menjadi batas dukungan model/firmware
`RealtimeController.php:59` mengembalikan flags terutama dari permission dan connection_mode. Form OLT menerima C300/C320 (`OltController.php:23`), driver menggunakan interface GPON. Dukungan C6xx/EPON dan variasi firmware belum setara dengan cakupan produk Zetset yang dinyatakan publik. Jangan menambah model pada dropdown sebelum parser/command/capability diuji pada perangkat tersebut.

### P3 — Cakupan fitur operasional tambahan
- Tidak ditemukan receiver/normalizer SNMP trap; history status bergantung polling, sehingga kejadian singkat antarsampel bisa tidak terekam. Kontrak trap ada pada referensi Zetset, sebagian berlabel beta.
- Backup lokal sudah encrypted + download dan interval 86400 detik, bukan jadwal jam malam tertentu. Tidak ditemukan alur restore terarah. Backup config yang dibaca juga berbeda dari perintah menyimpan running-config ke startup-config.
- Tidak ditemukan kebijakan pruning/retensi terjadwal untuk traffic, optics, status, operasi dan backup; kebutuhan disk bertambah seiring waktu. Ini kebutuhan operasional lokal, bukan klaim implementasi internal Zetset.
- Telegram/VPN/affiliate masih placeholder pada UI. API lokal tersedia untuk baca OLT, pencarian, live terantre, bad RX, status operasi, dan reboot; tidak mewakili semua operasi konfigurasi.

## Fondasi yang sudah tersedia
- Tenant/role enforcement, token API hashed, kredensial perangkat dan snapshot sensitif terenkripsi.
- Sync asynchronous dengan checkpoint inventory/names/optics, progress persisten dan partial/error tanpa mengosongkan snapshot.
- Jalur monitoring, interactive read, dan dua slot kontrol terpisah; kontrol ONU yang sama dilindungi per alamat fisik, operasi inventaris mengunci OLT eksklusif.
- Status Sync MIB, logging/auth dibedakan; null metrik tidak otomatis dianggap nol.
- Traffic history dengan full time domain, statistik sampel nyata dan zero-fill sebelum perekaman sesuai permintaan pengguna (zero-fill bukan riwayat pengukuran).
- Konfigurasi tujuh tab dengan preview bertoken, expiry, pemeriksaan konfigurasi berubah, readback dan uncertain outcome.
- FTTH, tags, staff, audit dan API sudah mempunyai implementasi backend; kelengkapan produk tidak otomatis identik dengan Zetset.

## Validasi
- Tiga diagnostic probes pada `.tools/BackendAuditProbeTest.php`: 3 passed, 7 assertions. Passing berarti cacat berhasil direproduksi, bukan persyaratan keselamatan sudah lolos. Probe sengaja di luar suite regresi rutin.
- Suite backend standar: 209 passed, 1178 assertions, 300.53 detik. Suite yang ada lolos; tiga cacat di atas berada pada skenario yang belum dicakup acceptance tests rutin.
- Tidak dilakukan reboot, provisioning, perubahan konfigurasi, atau Sync ke perangkat fisik; perubahan berkas hanya artefak audit. Tidak mengubah kode produksi dalam audit ini.

## Urutan kerja yang disarankan
1. Hidupkan dan supervisi runtime, health check serta recovery queue.
2. Verifikasi identitas reboot dan perbaiki lifecycle provisioning busy/readback.
3. Coverage/fairness polling + pagination UI/server.
4. Analisis degradasi, capability model, trap/alert dan kebijakan retensi/backup.
