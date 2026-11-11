# Driver nyata ZTE ZXA10 C300 / C320

## Batas verifikasi

Kode menghubungi socket TCP Telnet/SSH dan SNMP UDP, bukan simulator ketika `connection_mode=real`. Pengujian otomatis menggunakan peer Telnet dan agen SNMP pada loopback. Belum ada koneksi ke OLT fisik milik pengguna; alamat, kredensial, rute, dan output firmware aktual belum diberikan. Format tambahan harus diverifikasi melalui output yang sudah disanitasi sebelum parser diperluas. Monitor hanya menjadwalkan OLT dengan mode real dan pemantauan aktif. IP sampel pada seeder tetap simulator.

## Commissioning

1. Jalankan `composer install`, `php artisan migrate`, `npm ci`, dan `npm run build` untuk memperbarui instalasi lama. Pada workspace ini dependensi, migrasi dan build telah disiapkan. Jangan mengganti APP_KEY karena kredensial lama memakai kunci tersebut.
2. Jalankan `./start.ps1`. Server NMS harus memiliki rute ke IP OLT, dan ACL/firewall perangkat harus mengizinkan koneksi dari server tersebut. IP private/public IPv4/IPv6 diterima; tidak diperlukan IP public untuk LAN kantor.
3. Sebagai ISP Owner, buka Edit OLT atau Tambah OLT. Masukkan model C300/C320, IP, port CLI, username/password, port SNMP/community, dan timeout koneksi.
4. Pilih transport Telnet atau SSH dan `connection_mode=real`. Nama kolom port tetap `telnet_port`, termasuk jika transport SSH; gunakan port SSH yang dikonfigurasi, umumnya 22. Port Telnet umumnya 23. Transport dipilih eksplisit, tidak ditebak dari nomor port.
5. Klik **Test Connection**. Pengujian selalu nyata, bahkan saat pilihan mode masih simulator. Pilih CLI, SNMP, atau keduanya. Pengujian tidak menyimpan form/inventaris dan dapat dilakukan meskipun kuota penuh.
6. Simpan, lalu jalankan Sync. View/Info menyajikan snapshot lalu menjadwalkan metrik sistem; Port menjadwalkan inventaris tanpa menunggu optik. Jika OLT besar, sesuaikan batas waktu aplikasi/reverse proxy dengan `OLT_OPERATION_TIMEOUT`.

Mode simulator tetap opsional, melalui pilihan eksplisit. Fitur simulator lama membutuhkan `APP_ENV=local` dan `NMS_DEMO=true`; real driver tidak membutuhkan mode demo. **Tidak ada fallback otomatis dari kegagalan real ke keberhasilan simulator.** Ini juga berlaku untuk reboot: tidak pernah mencoba ulang write secara otomatis.

Saat target IP, port, transport atau mode berubah, timestamp/kepercayaan snapshot dibatalkan dan inventaris lama diarsipkan (`is_present=false`), bukan dihapus. Pemilik harus Sync untuk memverifikasi target baru. Mengubah mode kembali tidak membangkitkan data palsu atau memulihkan arsip otomatis. Data demo tetap tersedia pada OLT lain yang masih menggunakan simulator.

## Kredensial dan transport

- Database mempertahankan field PRD: `telnet_user`, `telnet_pass_enc`, `snmp_port`, `snmp_community_enc`. Request `telnet_username` juga diterima sebagai alias `telnet_user`; tidak menambah kolom username duplikat.
- Password/community memakai encrypted cast dan hanya didekripsi di server. Edit/Test Connection dengan rahasia kosong menggunakan nilai tersimpan, khusus OLT milik tenant yang sama. Rahasia tidak disimpan di audit/old-input dan tidak dikirim pada settings/dashboard.
- Telnet memakai PHP `stream_socket_client`, negosiasi IAC, login Username/Login → Password → prompt `#`/`>`, `terminal length 0`, dan penanganan paging `--More--`. Input satu baris, penulisan parsial, timeout baca/tulis, respons terpotong, batas ukuran, dan putus koneksi ditangani.
- Telnet tidak mengenkripsi lalu lintas jaringan. Gunakan LAN/VPN yang sesuai atau pilih SSH bila perangkat mendukungnya.
- SSH memakai phpseclib 3, shell interaktif, dan verifikasi host key sebelum login. Pada tes pertama, fingerprint SHA256 dikembalikan sebagai error bila belum tersimpan. Verifikasi fingerprint melalui sumber tepercaya/pengelola perangkat lalu simpan. Perubahan key ditolak; tidak ada trust otomatis.
- Output CLI meredaksi kecocokan password/community yang tersimpan; bagian lain ditampilkan sebagai teks, tidak dirender sebagai HTML. CLI tidak menjalankan OS shell.

## Perintah dan parser

Setelah login, client mengirim baris kosong untuk menyelaraskan prompt. Telnet juga menguras buffer banner/IAC hingga jeda baca singkat. Prompt `>` diterima; client mencoba `enable`, memakai password login satu kali jika diminta. Penolakan enable yang kembali ke prompt user tidak menggagalkan sesi. `terminal length 0` bersifat best-effort: penolakan sintaks/hak akses diabaikan, dengan paging `--More--` otomatis melalui spasi pada Telnet maupun SSH. Timeout dan putus koneksi tetap menghasilkan error.

Test Connection CLI menguji autentikasi dan prompt, lalu mencoba `show version-running`, `show system-group`, `show card`, dan terakhir `show version` sampai satu mengembalikan output. Jika semuanya ditolak tetapi prompt sesi tetap valid, hasil tetap berhasil dengan `authenticated=true`, `probe_command=null`, dan rincian penolakan dalam `results.cli.warnings`, sesuai kontrak pengujian login. Keberhasilan ini membuktikan login, bukan kompatibilitas seluruh perintah Sync. Timeout/putus koneksi tetap gagal.

Pesan penolakan CLI mencantumkan nama perintah dan cuplikan baris error asli OLT (maksimal 400 byte). Password/community tersimpan disamarkan sebelum output diperiksa atau pesan error dibuat; nilai berlabel password/community/secret pada diagnostik juga disamarkan. Pesan API diteruskan ke UI sebagai teks.

View/Sync membaca `show version-running`, `show system-group`, lalu `show card`; `show version` hanya dicoba terakhir bila firmware belum ditemukan. `%Error`, termasuk 20204 Ambiguous command, menjadi warning untuk informasi sistem yang opsional. View tetap berhasil jika minimal satu sumber informasi memberikan output; firmware/board yang tidak tersedia ditampilkan kosong. Sync tetap membutuhkan daftar board yang valid agar inventaris lama tidak diarsipkan akibat kegagalan membaca board. `show processor` bersifat opsional. Suhu memakai urutan `show card-temperature`, `show temp`, `show environment`, lalu `show temperature`; perintah yang ditolak atau tidak memiliki nilai suhu tidak menghasilkan alert merah, dan suhu ditampilkan Belum tersedia jika semua sumber tidak tersedia. Timeout/putus sesi saat View tetap dilaporkan sebagai kegagalan koneksi. Tidak memakai nilai simulator sebagai pengganti.

Parser firmware memprioritaskan Software Version agar BootROM tidak dianggap firmware. Tabel processor diringkas sebagai CPU 5 detik dan memory tertinggi antar board; suhu tabel memakai suhu slot tertinggi yang tersedia.

Parser show card mendukung baris Rack/Shelf/Slot dan Shelf/Slot dengan CfgType/RealType/Port/status. Semua card berprefix GTG/GFG yang INSERVICE/ONLINE/IS dipoll, termasuk GTGHK, GTGOG, GTGHG, GFGH, GFGL, dan GFGO. Kapasitas dari kolom Port dipakai; profil GTGO 8 / GTGH 16 menjadi cadangan jika angka tidak tersedia. Varian lain tanpa angka Port membutuhkan profil kapasitas terverifikasi di `olt.board_ports`; driver tidak menebak jumlah port. Daftar board/status tersimpan untuk View.

Sync menggunakan state **per port secara sekuensial**, mengikuti hasil uji firmware fisik: `show gpon onu state gpon-olt_{shelf}/{slot}/{port}` untuk setiap port pada board GPON aktif. Query state global tidak digunakan pada alur Sync. Port yang tidak memiliki ONU dilewati setelah state: tidak ada query baseinfo ataupun optik. Pada port yang memiliki ONU, baseinfo (atau fallback running-config) tetap dibaca, termasuk bila seluruh ONU offline.

Inventaris port, identitas ONU/SN, dan status dikumpulkan dan di-commit **sebelum** pembacaan optik. Hanya port dengan ONU `working` menjalankan `show pon power onu-rx gpon-olt_{shelf}/{slot}/{port}`. Port dengan semua ONU LOS/dying-gasp/offline tidak membaca optik. Sync massal tidak mengirim `show pon power olt-rx`; `olt_rx_power` bernilai null agar nilai lama tidak disajikan sebagai observasi baru.

State menerima indeks `1/1/1:7` dan `gpon-onu_1/1/1:7`, termasuk baris tanpa header dengan kolom tambahan `1(GPON)`. State diurai menjadi `admin_state`, `omcc_state`, `phase_state`; OffLine dinormalisasi `offline`, DyingGasp menjadi `dying-gasp`, dan Online menjadi `working`, tanpa membedakan huruf besar/kecil. Rekap `ONU Number: 0/13` berarti 0 online dari **13 ONU terdaftar**: validasi jumlah baris memakai 13. `Total ONU: 13` dan `Total ONU: 0/13` juga didukung. Selisih total, index duplikat, port yang tidak sesuai, dan phase yang tidak dikenal membatalkan inventaris sebelum commit. Respons state kosong setelah prompt valid, `ONU Number: 0/0`, dan total nol diterima sebagai port kosong. Nama host default `ZXAN` hanya dipakai untuk mendeteksi prompt, tidak mengganti atau memvalidasi nama OLT pada database. SN ASCII vendor+serial dan SN hex 16 digit didukung. Nama/tipe dari baseinfo disimpan; metadata pelanggan lokal pada record real yang sudah ada (PPPoE, VLAN, Tags, koordinat, dan nama/catatan yang diedit lokal) dipertahankan. Perintah di atas tidak menarik konfigurasi PPPoE/VLAN baru; field tersebut tetap metadata lokal bila tidak tersedia dari sumber lain.

Respons kosong eksplisit `%Code 32318: No related information to show.`, `No related information`, dan `%Error: Noonu` diterima pada perintah polling state/baseinfo/optik yang sesuai. State/baseinfo kosong menghasilkan 0 ONU; power kosong menghasilkan null untuk ONU yang terdaftar. Pesan yang bercampur error lain tetap gagal. Ketidaksesuaian state dan identitas ONU tetap membatalkan snapshot.

Parser baseinfo mengutamakan kolom SN/Serial Number yang ditentukan header tabel; tipe ONU atau nama pelanggan yang menyerupai SN tidak dipilih sebagai serial. Jika kolom SN tidak ditemukan dan terdapat beberapa kandidat serial, hasil dianggap ambigu. Jika baseinfo ditolak, ambigu, atau gagal diurai, driver membaca `show running-config interface gpon-olt_{shelf}/{slot}/{port}` dan mengurai `onu {index} type {model} sn {sn}`. Interface, index, dan SN harus valid; baris yang rusak tetap menggagalkan Sync tanpa perubahan inventaris. Nama awal menggunakan SN bila belum ada label dari perangkat. Setelah checkpoint inventaris, satu pembacaan `show running-config` mencoba mengumpulkan `name` dan `description` dari setiap blok `interface gpon-onu_...`, dipetakan menurut interface dan index (termasuk ONU offline dan SN ganda). Baris name pada gemport/profil, deskripsi port GPON, dan interface uplink diabaikan. Hanya label yang diambil; running-config lengkap tidak disimpan atau dikirim ke dashboard. Penolakan/timeout query label tidak membatalkan inventaris yang sudah di-commit.

Nilai Rx/OLT Rx berupa angka dBm atau null untuk N/A/tidak ada sinyal. Nilai tidak tersedia tidak diubah menjadi 0 dBm. Ambang Redaman Memburuk sekarang **<= -27.0 dBm**, mengikuti instruksi real driver terbaru, menggantikan perbandingan strict `<` tahap sebelumnya. Selisih memakai dua log optik terakhir dari sumber observasi yang sama, bukan snapshot simulator atau log paling tua.

Inventaris diverifikasi sebelum commit: format/header/baris, jumlah tabel, state/baseinfo, dan index ONU ganda tetap diperiksa. SN yang sama diperbolehkan pada beberapa indeks/port/OLT, sesuai kebutuhan operasional terbaru. Identitas penyimpanan adalah OLT + port + index ONU + sumber observasi + SN; serial tidak diubah atau diberi suffix. Sync berulang memperbarui record yang sama pada alamat tersebut. Nama pelanggan, PPPoE, tags, log optik, dan target reboot tetap terpisah berdasarkan ID ONU. Jika SN pada alamat yang sama berubah, identitas lama diarsipkan dan identitas baru dibuat agar metadata pelanggan lama tidak ikut berpindah. Kegagalan identitas/parser tidak diubah menjadi inventaris kosong. Jika timeout menghentikan inventaris setelah beberapa port selesai, port yang sudah diverifikasi disimpan; ONU/port yang belum dibaca tetap dipertahankan, disertai warning inventaris parsial. Hanya snapshot lengkap yang mengarsipkan seluruh inventaris yang tidak lagi terlihat.

Optik bersifat pelengkap: timeout, penolakan, atau format optik yang tidak dikenali tidak membuang inventaris. Redaman yang belum berhasil dibaca tetap null, bukan nilai lama atau 0 dBm. Setelah timeout socket ditutup agar output terlambat tidak tertukar dengan perintah berikutnya; optik yang belum dibaca dihentikan untuk sesi tersebut. Warning menyebut perintah terakhir, misalnya `Timeout pada perintah 'show pon power onu-rx gpon-olt_1/1/5'`. Pembacaan inventaris dan optik final memakai transaksi terpisah; log optik ditulis sekali pada tahap final, bukan dua kali untuk checkpoint dan hasil akhir.

Sync memakai `set_time_limit(300)`, idle timeout umum 10 detik, idle timeout optik default 30 detik, dan anggaran total default 300 detik dengan cadangan 10 detik untuk penyimpanan. Idle timeout direset setiap byte socket baru; batas operasi total tetap mencegah proses tanpa akhir. Saat anggaran hampir habis, optik dilewati dan inventaris yang terkumpul tetap disimpan.

Kegagalan sebelum inventaris terverifikasi tidak memajukan `last_sync_at`. Keberhasilan inventaris dengan optik yang tidak lengkap memajukan timestamp dan membawa warning. Lock per tenant/OLT melindungi seluruh tahap koneksi/persistensi; konfigurasi diperiksa kembali pada tiap commit. Tidak ada fallback ke simulator atau retry otomatis untuk write perangkat.

## Reboot dan Web CLI

Reboot hanya Owner/NOC, terhadap ONU hasil Sync nyata yang masih terdaftar. Interface diturunkan dari `olt_port_id` dan `onu_index`, kemudian dikirim berurutan:

```text
configure terminal
pon-onu-mng gpon-onu_{shelf}/{slot}/{port}:{onu_index}
reboot
yes
exit
```

`yes` hanya dikirim bila OLT meminta konfirmasi `[yes/no]` atau `[y/n]`. Respons harus menunjukkan penerimaan, misalnya success/OK/rebooting. Bila timeout/putus setelah pengiriman atau firmware diam tanpa bukti penerimaan, hasil ditandai **reboot_uncertain** dan tercatat di audit. Periksa Sync sebelum mencoba lagi. Pada penerimaan sukses, hanya `reboot_requested_at` diubah; phase_state tetap observasi terakhir hingga Sync, tidak dipulihkan lewat timer simulator.

Tombol Web CLI pada card dan pintasan Buka Web CLI (>_) pada View tersedia bagi Owner/NOC tanpa syarat last_sync_at. Membuka terminal tidak menunggu Sync; operasi perangkat yang sedang berlangsung tetap dilindungi lock.

Web CLI menerima perintah nyata maksimal 512 karakter, satu baris, tanpa karakter kontrol. Contoh `show gpon onu uncfg`, `show card`, `show running-config interface gpon-olt_1/1/1`. Setiap request membuka sesi baru dan menutupnya sesudah output; konteks configure tidak bertahan antar request. Reboot memakai sesi berurutan khusus. Tidak ada WebSocket terminal persisten pada implementasi ini. Audit mencatat operasi dan target, bukan teks perintah yang mungkin mengandung rahasia.

## SNMP nyata

`ZteSnmpDriver` menggunakan ext-snmp / Net-SNMP, SNMPv2c, port UDP dan community per OLT. Driver memiliki get/walk, pembacaan sysDescr/sysUpTime/sysName, serta IF-MIB interfaces. Centang SNMP pada OLT untuk melengkapi uptime jika CLI tidak menyediakannya; kegagalan pelengkap ditampilkan sebagai warning, bukan data simulator.

OID sistem numerik:

```text
sysDescr.0   .1.3.6.1.2.1.1.1.0
sysUpTime.0  .1.3.6.1.2.1.1.3.0
sysName.0    .1.3.6.1.2.1.1.5.0
ifName      .1.3.6.1.2.1.31.1.1.1.1
ifOperStatus .1.3.6.1.2.1.2.2.1.8
```

Tidak menebak vendor OID atau encoding index/redaman. Power ONU pada Sync memakai CLI sebagaimana spesifikasi. Bila MIB firmware sudah diverifikasi, konfigurasi `olt.snmp_metrics` dapat diisi numeric OID dan scale untuk cpu/temperature/memory_usage; angka di luar jangkauan ditolak. Tanpa profil tersebut metrik diperoleh dari CLI, atau ditampilkan belum tersedia.

PHP portable workspace sudah mengaktifkan ext-snmp. `start.ps1` menyiapkan daftar MIB kosong Windows (`MIBS=;`) karena driver menggunakan OID numerik. Untuk PHP lain aktifkan extension SNMP dan library Net-SNMP yang sesuai. Linux memakai `MIBS=:` bila tidak memuat file MIB. SNMP tidak otomatis berpindah ke mock bila extension/community/rute tidak tersedia.

## Konfigurasi dan verifikasi

```dotenv
OLT_COMMAND_TIMEOUT=10
OLT_OPERATION_TIMEOUT=300
OLT_OPTICAL_IDLE_TIMEOUT=30
```

`connection_timeout` per OLT 1-30 detik (default 5). Batas output per command 4 MiB, port 256, ONU 32768 di `config/olt.php`. Atur nilai sesuai inventaris dan kapasitas server. Banyak OLT besar sebaiknya dipoll melalui queue/worker terjadwal; implementasi ini memakai request sinkron dengan deadline.

```powershell
$env:MIBS = ';'
.\.tools\php\php.exe artisan test
npm run build
.\scripts\test-browser.ps1
```

Uji mencakup socket Telnet asli terhadap fixture loopback, UDP SNMP asli terhadap agen fixture, login gagal, timeout, parser gagal, rollback snapshot, mode/source, RBAC, urutan reboot, dan jalur UI. Fixture bukan perangkat fisik; SSH, variasi firmware riil, serta PostgreSQL/MySQL hidup belum diuji pada lingkungan ini.

Referensi protokol: [PHP stream_socket_client](https://www.php.net/manual/en/function.stream-socket-client.php), [PHP SNMP sessions](https://www.php.net/manual/en/snmp.construct.php), [RFC 854 Telnet](https://datatracker.ietf.org/doc/html/rfc854), [phpseclib SSH](https://phpseclib.com/docs/connect/). Format processor mengacu pada [ZTE C300/C320 Command Reference, bagian Hardware Management](https://get.ipnetwork.com.br/files/ZTE/OLT_ZTE/C3XX/CONFIGURA%C3%87%C3%95ES/Manual_ZTE_C3xx.pdf). Perintah ZTE mengikuti instruksi tugas dan harus dicocokkan dengan command reference firmware perangkat saat commissioning.

Migrasi `2026_10_16_000005_allow_duplicate_onu_serials` mengganti unique tenant/SN menjadi indeks pencarian non-unik dan batas unik per identitas observasi. Tidak menghapus record lama. Rollback ditolak bila masih ada SN ganda yang tidak memenuhi batas unik lama, tanpa menghapus data otomatis.

Nama/deskripsi perangkat disimpan sebagai device_name/device_description (migrasi 000006). Nama tampilan berisi SN akan diisi dengan nama ONU dari perangkat; jika hanya deskripsi yang tersedia, deskripsi menjadi label. Nama/deskripsi hasil impor diperbarui saat perangkat berubah; nilai yang berbeda karena edit lokal dipertahankan. Tabel ONU memakai kolom Nama, Status, Posisi (slot/port:index), Redaman ONT, Terakhir online, Serial, Traffic, dan Aksi. Nama OLT dan interface lengkap tersedia pada detail baris. Nama yang belum terbaca diberi label Nama belum terbaca; serial tetap tampil terpisah. Kolom dapat diurutkan; tabel dapat digeser pada layar kecil. Traffic belum diambil sehingga ditampilkan kosong, tanpa angka buatan. Referensi alias: ZTE C300/C320 Command Reference bagian 13.3.15 name, pada tautan manual di atas.

Jika konfigurasi global tidak memuat nama ONU, Sync membaca `show running-config interface gpon-onu_{index}`, lalu fallback `show gpon onu detail-info gpon-onu_{index}`. Parser detail memverifikasi interface dan Serial number; SN dalam Description bukan identitas ONU. Contoh dari perangkat pengguna (SIDSMP-PAS1-BDG, interface 1/1/10:31) diuji melalui fixture TCP dan browser. Tidak ada koneksi otomatis dari pengujian ke IP OLT pengguna.

Pembacaan nama per-interface memiliki anggaran 90 detik dan idle timeout 5 detik (`olt.name_poll_budget`, `olt.name_idle_timeout`). ONU yang belum memiliki nama diprioritaskan. Nama yang diperoleh di-commit sebelum optik; bila anggaran habis, inventaris tetap tersimpan dan Sync berikutnya mendahulukan nama yang belum diperoleh. Pembacaan nama mencakup ONU offline; tidak mengandalkan respons OMCI ONT yang hidup.

`last_online_at` (migrasi 000007) mencatat waktu terakhir ONU teramati working oleh Sync, bukan estimasi tanggal koneksi. Nilai yang tidak pernah teramati tetap null, termasuk saat detail firmware hanya memberi timestamp 0000-00-00. Tidak menggunakan last_seen_at ONU offline sebagai waktu online.

## Mesin polling terbaru

Baca [LIVE-OPERATIONS.md](LIVE-OPERATIONS.md) untuk antrean database, proses monitor, jadwal status/nama/optik, traffic IF-MIB, provisioning, backup dan endpoint API. Output fisik yang diberikan pengguna sudah menjadi fixture; pengujian write memakai loopback, bukan perangkat produksi.
