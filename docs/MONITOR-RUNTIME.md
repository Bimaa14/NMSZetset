# Runtime monitor laptop

Windows: `./scripts/enable-monitor-startup.ps1` menjalankan supervisor tersembunyi dan memasang shortcut Startup pengguna saat ini. Tidak memerlukan password atau administrator. Mulai otomatis **saat pengguna login**, bukan sebelum login. Laptop harus menyala dan memiliki akses jaringan ke OLT; sleep/shutdown menghentikan polling.

`./start.ps1` tetap membuka web lokal, tetapi sekarang memulai supervisor monitor terpisah. Menutup terminal web tidak mematikan monitor. `./start.ps1 -WorkersOnly` memulai supervisor dan langsung kembali.

Supervisor `scripts/monitor-supervisor.ps1`:

- Satu monitor dan dua queue worker; memeriksa proses setiap 5 detik dan memulai ulang child yang berhenti.
- Named mutex per workspace mencegah dua supervisor dalam sesi Windows yang sama.
- PID, waktu mulai proses dan executable diverifikasi sebelum mengambil alih child dari supervisor lama. PID saja tidak dipercaya.
- State: `.tools/runtime/monitor-supervisor.json`; log stdout/stderr per proses di direktori yang sama.
- Heartbeat aplikasi berasal dari monitor dan loop queue, termasuk ketika worker idle; dashboard tetap dapat membedakan web aktif dari monitor tertunda.
- Jika supervisor sendiri dihentikan paksa, jalankan kembali enable-monitor-startup/start.ps1; child yang masih hidup diambil alih. Login Windows berikutnya juga memulainya kembali. Ini supervisor lokal, bukan Windows service yang aktif sebelum login.

Untuk menghentikan monitoring dan melepas autostart: `./scripts/disable-monitor-startup.ps1`. Ini tidak mengubah konfigurasi OLT atau menghapus data. Menjalankan start.ps1 kembali akan mengaktifkan monitor untuk sesi tersebut.

## Pemulihan antrean

Koneksi queue `olt` memakai tabel jobs pada database aplikasi yang sama. Status operasi dan job disimpan dalam transaksi yang sama, termasuk perpindahan tahap Sync dan penjadwalan ulang akibat lock busy. Jangan memindahkan queue olt ke database terpisah tanpa mengganti mekanisme ini dengan outbox.

Tick memeriksa operasi queued aktif yang heartbeat-nya lebih tua dari dua menit. Jika job benar-benar hilang, job dikirim ulang untuk tahap saat ini; payload queue hanya diperiksa sebagai JSON/string, tidak di-unserialize. Job delayed maupun reserved yang masih ada tidak digandakan. Worker juga memeriksa tahap expectedStage dan mengklaim status queued secara atomik. Operasi running yang heartbeat-nya lebih tua dari 420 detik tetap ditandai gagal dengan snapshot terakhir dipertahankan.

## Reboot

Di dalam sesi kontrol, sebelum command konfigurasi, driver membaca baseinfo PON dan membandingkan serial pada alamat ONU yang tepat. Running-config menjadi fallback hanya jika baseinfo ditolak/tidak dapat diparse. Serial hilang/berubah membatalkan reboot (`identity_changed`). Timeout pembacaan tidak diteruskan menjadi write. Hasil reboot yang tidak pasti tetap tidak diulang otomatis.

## Verifikasi 19 Oktober 2026

- Tes identitas matching/changed/missing, rollback operasi+job, recovery dispatch hilang, job reserved/delayed, serta delivery tahap lama.
- Monitor dihentikan lalu otomatis diganti; supervisor kedua tidak menggandakan child; restart supervisor mengambil alih PID child yang sama.
- Heartbeat monitor/worker kembali aktif, observasi status 11:21:49 WIB, Traffic 11:22:12 WIB dan optik 11:22:28 WIB diperbarui dari polling nyata. Tidak melakukan reboot fisik sebagai pengujian.
