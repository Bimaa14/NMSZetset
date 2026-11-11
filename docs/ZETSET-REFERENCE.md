# Referensi hasil peninjauan Zetset

Sumber: [Zetset tampilan baru](https://baru.zetset.id/dashboard), melalui sesi yang diloginkan langsung oleh pemilik akun. Peninjauan menggunakan browser dan DOM halaman yang tampil; tidak mencakup kode backend, skema database internal, atau cara kerja poller Zetset.

Pemetaan request API, struktur respons, nama ONU dan alur Sync tersedia pada [peninjauan alur backend/API](ZETSET-BACKEND-FLOW.md).

## Halaman dan alur yang diperiksa

| Bagian | Hasil pengamatan |
| --- | --- |
| Dashboard | Ringkasan redaman, equipment, kuota OLT, jumlah ONU belum terkonfigurasi, serta pilihan Kartu/Daftar/Daftar rinci. Kartu tersusun empat kolom pada desktop yang diperiksa dan satu kolom pada lebar 390 px. |
| Kartu OLT | Nama, chassis, IP:port, total ONU, offline, LOS, dying gasp. Tombol Buka menuju tabel ONU; Port menuju Port Manager; Sync menjalankan sinkronisasi; + ONU menuju pemasangan ONU baru. |
| Menu kartu | Informasi OLT, Edit OLT, Terminal SSH, Hapus OLT. Menu ini berbeda dari tiga ikon pintas yang diminta pada PRD NMS kita. |
| Ringkasan equipment | Rekap status seluruh ONU, homepass/homeconnect, ONU terpetakan dan menunggu pemetaan, serta waktu pembaruan. |
| Redaman memburuk | Kategori kritis/serius/waspada, baseline dibanding pembacaan kini, besar perubahan dB, frekuensi deteksi, serta pengelompokan beberapa ONU yang memburuk pada PON yang sama. |
| Informasi OLT | Ringkasan dan Alarm, model/firmware, uptime, daya/tegangan/arus/suhu shelf, serta tabel kartu berisi slot, status, CPU, memori, suhu, daya dan serial. Data diberi penanda tersimpan/live, umur data, dan status penyegaran. |
| Port Manager | Kartu per slot dan PON, tipe board, nama PON, jumlah online/terdaftar, download/upload. Ada bagian uplink, database VLAN, dan hubungan ODP. |
| Tabel ONU per OLT | Filter PON, pencarian, chip status beserta jumlah/persentase, indikator sinyal lemah/kritis, pengaturan kolom, pilihan banyak ONU, sorting dan menu aksi per baris. |
| ONU semua OLT | Filter OLT/status, ringkasan ONU bermasalah, OLT paling banyak bermasalah, pagination, ekspor CSV, serta kolom OLT tambahan. |
| Detail ONU | Tab Info perangkat, WAN, Port Ethernet, IP host, WiFi. Ringkasan optik RX/TX/RX di OLT, koneksi, identitas/model/software/jarak/kartu, deskripsi dan ODP. Tersedia pintasan riwayat redaman/status/posisi dan tindakan lanjutan. |
| Pilihan kolom ONU | Preset Ringkas/Optik/Perangkat/Pelanggan/Teknisi. Kolom tambahan meliputi tag, model/software/vendor, jarak, RX OLT, status, PPPoE, ODP, paket dan alamat. UI menyatakan pilihan disimpan pada akun. Tidak ada pilihan yang diubah dalam peninjauan. |
| Tambah OLT | Nama, IP/hostname, port/community SNMP, akses SSH opsional, pilihan keluarga C3xx/C6xx, uji koneksi, bantuan skrip dan VPN. Form diperiksa tanpa dikirim. |
| Tags | Halaman pengelolaan tag ONU dan pencarian tag; akun yang diperiksa sedang tidak mempunyai tag. |
| Staff | Status, level, login terakhir, izin, filter dan detail. Form tambah berupa tiga langkah: informasi dasar, izin akses, pemeriksaan. Tidak ada staf yang dibuat. |
| Audit | Waktu, status, aktivitas, pengguna, objek dan IP; pencarian, filter modul/status, jumlah baris, filter lanjutan, statistik dan ekspor CSV. |
| Peta FTTH | Panel jaringan/pelanggan/lapisan, pemilihan OLT, pencarian perangkat, OTB/ODC/ODP, kapasitas port dan hubungan pelanggan. |
| API | Halaman token API dengan masa berlaku dan keterangan, daftar token, alamat API dan petunjuk pemakaian. Tidak ada token yang dibuat atau disalin. |

Navigasi tambahan yang terlihat: Profil, Telegram, VPN, Saldo/Wallet dan Affiliate. Isi halaman tersebut tidak termasuk peninjauan ini.

## Verifikasi nama ONU yang sebelumnya dibahas

Pencarian `SIDSMP` pada OLT01-MONEX menampilkan **SIDSMP-PAS1-BDG** pada posisi **1/10:31**, dengan serial **ZTEGCCA26B50**. Ini cocok dengan `Name`, interface `gpon-onu_1/1/10:31`, dan `Serial number` pada output CLI yang diberikan pengguna.

Nama pelanggan berada pada kolom Nama. Nama OLT adalah konteks perangkat induk. Serial berada di kolom tersendiri. Nilai `extid` di dalam deskripsi bukan pengganti serial perangkat. Saat diperiksa, ONU contoh sedang offline dan beberapa metrik tidak tersedia.

Temuan ini memverifikasi referensi tampilan dan pemetaan contoh tersebut; tidak membuktikan perintah internal yang digunakan Zetset untuk membaca nama. Driver NMS kita tetap menggunakan output CLI perangkat sebagai sumber sinkronisasi.

## Detail visual yang diamati

- Font antarmuka: Plus Jakarta Sans.
- Latar gelap: `#020617`; permukaan kartu: `#0f172a`; border/header tabel: `#1e293b`; aksi utama: biru muda sekitar `#60a5fa`.
- Tabel padat dengan nama di kiri, status dan posisi terpisah, serial berukuran kecil dengan font monospace, serta aksi di kanan.
- Status diberi teks dan warna. Status administratif nonaktif ditampilkan terpisah dari phase offline/LOS/dying gasp.
- Desktop memakai modal detail berukuran besar; pada HP navigasi diringkas dan tabel dapat digeser horizontal.
- Nilai yang tidak terbaca memiliki label tersendiri. Angka dashboard dapat berubah karena penyegaran otomatis dan tidak dijadikan data seeder.

## Pemetaan ke NMS lokal

PRD tetap menjadi acuan arsitektur, tenant dan role. Perbedaan label/fitur Zetset tidak otomatis mengganti ketentuan PRD.

| Bagian | Kondisi implementasi lokal dan arah penyesuaian |
| --- | --- |
| Nama ONU | Parser dan fallback per-interface sudah tersedia. Gunakan nama hasil perangkat; jangan mengganti dengan nama OLT atau menyalin daftar pelanggan dari screenshot. |
| Tabel padat | Kolom nama/status/posisi/RX/terakhir online/serial/traffic/aksi dan sorting dasar sudah tersedia. Filter PON, chip jumlah status dan pilihan kolom dapat menjadi pengembangan berikutnya. |
| Navigasi ONU | NMS lokal membuka daftar lewat tombol ONU sesuai PRD. Di Zetset, daftar dibuka lewat Buka, sedangkan + ONU untuk provisioning. Perbedaan ini perlu disengaja bila label diubah. |
| Detail sistem | Pembacaan nyata sudah tersedia; referensi memperlihatkan peluang menampilkan metrik per kartu serta umur setiap kelompok data. |
| Optik dan traffic | Jangan mengisi metrik yang belum dikoleksi. Traffic lokal masih kosong; timestamp terakhir online lokal adalah observasi NMS, bukan otomatis riwayat autentikasi asli dari OLT. |
| Dashboard/Port | Alternatif tampilan daftar, halaman Port Manager khusus, informasi traffic PON dan kapasitas ODP merupakan fitur tambahan di luar tampilan grid dasar saat ini. |
| Riwayat dan peta | Riwayat status/posisi, wizard provisioning, peta jaringan penuh dan pengaturan WAN/WiFi memerlukan pekerjaan backend tersendiri. Keberadaan tombol pada referensi belum berarti fungsi tersebut tersedia di NMS lokal. |

Peninjauan ini menghasilkan dokumentasi dan tangkapan layar. Kode aplikasi, database NMS lokal, serta konfigurasi perangkat tidak diubah.

## Bukti lokal

Tangkapan layar berada di folder `.tools/zetset-reference/`, yang diabaikan oleh Git. Berkas dapat memuat informasi jaringan/pelanggan akun pengguna dan disimpan sebagai referensi lokal.

- [Dashboard gelap](../.tools/zetset-reference/dashboard-dark.png)
- [Dashboard HP](../.tools/zetset-reference/dashboard-mobile.png)
- [Tampilan daftar rinci](../.tools/zetset-reference/dashboard-list.png)
- [Ringkasan equipment](../.tools/zetset-reference/equipment.png)
- [Redaman memburuk](../.tools/zetset-reference/degradation.png)
- [Informasi OLT](../.tools/zetset-reference/olt-info.png)
- [Port Manager](../.tools/zetset-reference/ports.png)
- [Tabel ONU per OLT](../.tools/zetset-reference/onu-table.png)
- [Pencarian nama contoh](../.tools/zetset-reference/onu-name-search.png)
- [Detail ONU contoh](../.tools/zetset-reference/onu-detail.png)
- [ONU semua OLT](../.tools/zetset-reference/onu-global.png)
- [Tambah OLT](../.tools/zetset-reference/add-olt.png)
- [Staff](../.tools/zetset-reference/staff.png)
- [Audit](../.tools/zetset-reference/audit-logs.png)
- [Tags](../.tools/zetset-reference/tags.png)
- [Peta FTTH](../.tools/zetset-reference/ftth-maps.png)

Tidak dilakukan Sync, reboot, provisioning, perubahan konfigurasi, penghapusan, atau pengiriman form. Membuka sebagian halaman detail memicu pembacaan/penyegaran data otomatis yang disediakan situs.
