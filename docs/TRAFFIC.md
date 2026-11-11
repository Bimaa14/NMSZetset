# Traffic ONU dan port PON

Tombol Traffic pada footer detail ONU dan baris Port GPON membuka panel yang sama: live, 30 menit, 1/6/24 jam, 7/30 hari, serta rentang tanggal atau bulan (WIB, maksimal 366 hari). Grafik RX/TX dapat diperiksa dengan pointer atau slider keyboard; tombol Jeda menghentikan live. Sampel tampil dengan sumber dan waktu terakhir, termasuk saat pembacaan berikutnya gagal.

## Collector

`ZteTrafficCollectorService` mendahulukan SNMP bila aktif. Nama interface dipetakan lewat IF-MIB, lalu membaca ifHCInOctets, ifHCOutOctets, ifCounterDiscontinuityTime dan sysUpTime. Timeout SNMP memakai probe singkat dan cooldown 5 menit, kemudian beralih ke Telnet/SSH. Mode real tidak beralih ke data simulator.

CLI C320 V2.1.0 telah diuji pada ARFIN-HOME `gpon-onu_1/2/14:3` dan PON `gpon-olt_1/2/14`, menggunakan `show interface ...`. Output menyediakan `Input rate`, `Output rate`, dan bagian total Input/Output dengan `Bytes` (ONU) atau `PassBytes` (PON). `Bps` berarti byte/detik: dikali 8; `bps` sudah bit/detik. Peak firmware bukan current rate. Arah selalu dari pelanggan: output OLT → RX/download ONU, input OLT → TX/upload pelanggan.

Jika hanya counter tersedia, baseline pertama menghasilkan rate null. Selisih Counter64 dihitung dengan BigInteger sebelum konversi ke bps; source, interface, identitas ONU, reset uptime, diskontinuitas, counter menurun dan jarak sampel diperiksa. Counter asli disimpan sebagai string desimal agar tidak kehilangan presisi. CLI/firmware yang tidak memberi statistik menghasilkan pesan belum tersedia, bukan angka nol buatan.

## Penyimpanan dan background

Migrasi 000015 memperluas tabel `onu_traffic_logs` yang sudah ada: tenant_id, olt_id, rx_bps, tx_bps, rx_bytes, tx_bytes, source, recorded_at, indeks ONU+waktu. Kolom download/upload/observed_at tetap ditulis agar pembaca lama kompatibel. Histori lama dibawa ke kolom baru. `port_traffic_logs` menyimpan format setara untuk PON.

Setiap live read berhasil menyimpan sampel. Request yang berulang dalam 3 detik memakai sampel terbaru. Browser memulai pembacaan berikut 4 detik setelah request sebelumnya selesai, tidak menumpuk request; pembacaan perangkat berhenti saat tab tersembunyi, dijeda atau modal ditutup. Timeout/koneksi sibuk dapat memperpanjang interval; waktu sampel ditampilkan secara eksplisit.

Worker `PollOlt` + `nms:monitor` menjadwalkan traffic walaupun SNMP dimatikan. Satu batch maksimal 40 interface / anggaran 20 detik (ditambah penyelesaian perintah aktif), berputar melalui PON dan ONU working. Interval batch traffic default 60 detik; inventaris penuh default 5 menit. Pada OLT besar satu ONU tidak selalu disampel setiap menit.

Live Traffic dan Detail ONU memakai satu sesi baca terpisah (`nms:olt-read`) yang boleh berjalan bersama sesi operasional/background (`nms:olt`). Jika sesi baca sedang dipakai, endpoint mengembalikan HTTP 202 dengan `skipped: true, reason: read_busy`. UI melewati siklus tanpa alert dan mempertahankan grafik/snapshot; pembukaan pertama tetap memuat histori database. Lock sesi baca berakhir setelah batas waktu sesi CLI ditambah 30 detik dan dilepas lewat `finally`. Job yang berbenturan dengan sesi operasional lain dijadwalkan ulang setelah 10 detik. Snapshot inventaris tidak diubah oleh collector traffic.

Penyimpanan baseline counter memakai lock singkat per interface (`nms:traffic-sample`) agar live dan background tidak menimpa baseline bersamaan. Lock ini hanya melindungi penyimpanan sampel, bukan koneksi CLI atau seluruh OLT; interface lain tetap dapat disimpan.

Setelah mengganti kode worker, restart worker dan monitor; `start.ps1` menjalankan keduanya. Database lama cukup dimigrasikan, jangan reseed. Pembaruan ini diterapkan setelah backup SQLite, tanpa mengubah 658 ONU yang tersimpan.

## Histori dan statistik

Endpoint sesi tenant-scoped: `GET /traffic/{onus|ports}/{id}`, `POST /traffic/{onus|ports}/{id}/live`. Role dengan izin read boleh membaca traffic. Live dibatasi 30 request/menit per pengguna.

Histori memakai agregasi SQL dan window LAG dalam snapshot baca konsisten, maksimal 150 bucket berdasarkan rentang data aktif; statistik bukan hanya dari 100 baris terakhir. Current adalah sampel terakhir dalam rentang, peak/minimum dari seluruh rate, rata-rata ditimbang durasi terukur. Volume memakai delta counter; bila counter tidak tersedia tetapi rate tersedia, menggunakan integral trapezoid. Pergantian source, counter turun dan jeda >10 menit tidak dijembatani. Volume dan durasi terukur bukan jaminan total pemakaian bulanan bila ada celah. Tidak ada backfill untuk masa sebelum collector aktif, dan tidak ada penghapusan histori otomatis.

Grafik Canvas memakai Chart.js dan plugin zoom yang sudah tersedia: download biru dengan area gradasi, upload abu-abu putus-putus, crosshair dan tooltip gelap, zoom tombol/seleksi, pan, reset, serta ekspor PNG/CSV. Reset mengikuti rentang data terbaru saat live bergerak. Ringkasan dua kolom menampilkan current, tertinggi, rata-rata, dan terendah. Rate bit memakai basis 1000; konversi byte/detik memakai basis 1024 sesuai referensi (18.96 Mbps menjadi 2.26 MB/s). Nilai kosong tetap kosong, bukan nol buatan.

## Verifikasi

- Suite penuh 160 tes PHP lulus; setelah tambahan tes counter-only, jadwal tanpa SNMP, dan antrean saat lock sibuk, 14 tes terkait traffic/background lulus.
- Browser: polling, pause, filter Oktober–Desember dalam WIB, akses PON, mobile 375px, Escape mengembalikan modal ONU, dan polling berhenti setelah ditutup.
- Service dan browser lokal benar-benar membaca CLI ARFIN-HOME. Dua sampel browser pada 17 Oktober 2026: RX 13.506.544 → 12.781.632 bps dan TX 897.480 → 1.098.624 bps; timestamp berbeda, tanpa error JavaScript. PON juga berhasil dibaca. Angka tersebut adalah observasi saat pengujian, bukan fixture atau jaminan kecepatan layanan.
- Pemeriksaan perangkat hanya membaca statistik; tidak mengubah konfigurasi ONU/OLT.

Job traffic background #1260 selesai pada 17 Oktober 2026 pukul 13.53 WIB tanpa error; inventory/optik tetap berjalan. Jika belum ada interval terukur, volume ditampilkan belum tersedia.

### Verifikasi perbaikan lock dan grafik, 17 Oktober 2026

- Tes terkait parser, Traffic, Detail ONU, Real OLT, background, antrean dan provisioning lulus. Fixture parser mencakup 32 port / 386 ONU dengan SyncMib, Logging, dan Auth. Peer Telnet loopback mengirim fragmen state/error terlambat beserta IAC; perintah sesudahnya tetap menerima respons yang benar.
- Tes lock memastikan live/detail berhasil ketika mutex operasional ditahan, sesi baca sibuk menghasilkan 202 tanpa mengubah snapshot, dan baseline traffic dilindungi per interface.
- Empat tes browser pada database terisolasi lulus: skip sibuk pertama/berikutnya, error lain tetap terlihat, progress manual/awal, toolbar, hover, zoom selection, pan, reset mengikuti rentang live terbaru, ekspor, filter WIB, pause dan mobile. Build Vite berhasil.
- Preview: [desktop dan tooltip](traffic-redesign-hover.png), [mobile](traffic-redesign-mobile.png). Tampilan mengikuti rincian teks; gambar referensi 2/3 tidak disertakan dalam lampiran.
- Pembacaan CLI OLT fisik berhasil kembali setelah restart worker. Kontensi lock diuji secara terkontrol dengan fixture; pembacaan fisik terakhir berlangsung saat mutex operasional bebas.

Untuk browser pengujian di laptop ini, `PLAYWRIGHT_CHANNEL=msedge` memakai Edge yang terpasang. Skrip `scripts/test-browser.ps1` menyiapkan database/cache terpisah.

### Perbaikan histori panjang dan performa, 17 Oktober 2026

- Domain X memakai keseluruhan `from`/`to` dari preset atau rentang custom; label tanggal + jam WIB untuk 24 jam dan tanggal untuk 7/30 hari. Tooltip tetap tanggal + jam. Garis menghubungkan jeda/null. Satu sampel valid divisualisasikan datar dengan marker tanpa menambah sampel database. Y maksimum = puncak asli (termasuk upload) ? 1,15, meskipun bucket memakai rata-rata.
- Modal langsung menampilkan histori tersimpan lalu mengambil sampel live, termasuk histori kosong/satu titik. Counter-only SNMP masih memerlukan interval berikutnya untuk menghitung rate; tidak dibuat nol palsu. Tidak ada backfill data sebelum pencatatan dimulai.
- SQL menghitung statistik seluruh rentang dan hanya mengirim ?150 titik. Delta uint64 SQLite memakai fungsi BigInteger agar counter di atas batas integer signed tetap tepat. Penghubungan garis bersifat visual: jeda panjang tetap tidak dihitung sebagai volume terukur.
- AbortController dan pembatalan timer bekerja saat modal ditutup, rentang diganti, atau tab tersembunyi. Chart diupdate di tempat. Tabel maksimal 50 baris, pencarian debounce 250 ms, blur backdrop dihapus.
- Progres aktif menggunakan `/dashboard/activity` tanpa daftar ONU. Dashboard lengkap diperbarui saat operasi selesai atau setiap 30 detik; interval cepat hanya untuk sinkronisasi/reboot/detail yang ditunggu.
- SQLite: WAL, synchronous NORMAL, busy_timeout 5000 ms. Index baru `(olt_id, recorded_at)` pada traffic dan `(tenant_id, olt_id, phase_state)` pada ONU. Index `(onu_id, recorded_at)` dan `(tenant_id, sn)` sudah tersedia. Kolom waktu optik di schema ini bernama `calculated_at`, sudah terindeks bersama `onu_id`; tidak dibuat kolom duplikat `recorded_at`.
- `start.ps1` menjalankan pool enam proses PHP lewat Node pada Windows; OS lain memakai PHP_CLI_SERVER_WORKERS=6. Semua listener hanya di loopback. Variabel worker bawaan PHP tidak didukung Windows: https://www.php.net/commandline.webserver.
- Verifikasi statistik: 2.000 sampel diringkas ?150 titik, puncak 80,82 Mbps dipertahankan dan delta uint64 tetap tepat. Pengujian enam proses membuktikan request cepat tetap selesai <1 detik saat lima request lambat berlangsung.
- Pembacaan database laptop ONU 383: 46 sampel menjadi 35 titik; query 7/30 hari sekitar 50/28 ms saat pemeriksaan. Angka ini observasi lokal, bukan jaminan semua ukuran database.
- Preview: [histori 30 hari dengan data jarang](traffic-sparse-30-days.png), [satu sampel](traffic-single-sample.png).

### Rentang penuh preset dan baseline sebelum perekaman

Preset selalu meminta waktu sekarang dikurangi durasi yang dipilih sampai waktu sekarang. Renderer mengunci domain ke `from`/`to` respons, termasuk label persis pada kedua ujung dan reset zoom. Preset 30 hari memiliki enam label merata, misalnya 17 Sep, 23 Sep, 29 Sep, 05 Okt, 11 Okt, 17 Okt.

API mengirim metadata `chart_baseline` hanya bila belum ada sampel sebelum awal rentang dan terdapat rekaman pertama di dalamnya. Renderer membentuk bucket nol merata hingga tepat sebelum rekaman pertama; tidak menyimpan bucket tersebut ke database. Maksimal 150 titik terukur tetap diringkas melalui SQL. Statistik, slider sampel, dan CSV memakai titik terukur saja, sehingga nol visual tidak menurunkan nilai minimum/rata-rata atau menambah volume. Bila rekaman sudah ada sebelum awal rentang, jeda observasi tidak diubah menjadi baseline nol.

Input Dari/Sampai otomatis mengikuti tanggal WIB `from`/`to` respons saat preset dipilih. Memilih preset mengembalikan mode input ke tanggal, sedangkan rentang bulan custom tetap mengikuti input pengguna. Preview: [30 hari dengan baseline dan tanggal lengkap](traffic-full-30-days.png).
