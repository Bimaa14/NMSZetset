# Modal detail ONU

Modal dibuka dari nama atau ikon mata pada daftar ONU semua OLT maupun per OLT. Lima tab: Info perangkat, WAN, Port Ethernet, IP host, WiFi. Footer selalu tersedia sesuai hak akses; Escape menutup detail dan mengembalikan fokus ke daftar.

## Pembacaan perangkat

Snapshot per ONU/tab tersimpan terenkripsi pada `onu_detail_snapshots`. Modal menampilkan snapshot lebih dahulu kemudian menyegarkan tab aktif, secara otomatis setiap 30 detik bila tab browser terlihat. Toggle Otomatis menghentikan polling detail. Lock OLT yang sama mencegah benturan dengan Sync; pesan sibuk tidak menghapus snapshot.

Identitas diverifikasi menggunakan serial pada detail-info, dengan fallback konfigurasi PON. Serial berbeda membatalkan pembacaan. Serial dalam description/extid tidak digunakan sebagai identitas. Snapshot lama dipertahankan dengan status stale bila firmware tidak memberikan data baru. Jumlah port/SSID berasal dari respons, tidak dipaksakan menjadi empat.

Perintah yang terbukti terbaca dari C320 V2.1.0 pengguna pada 17 Oktober 2026:

| Data | Perintah, diikuti interface `gpon-onu_...` |
|---|---|
| Status, jarak, riwayat koneksi | `show gpon onu detail-info` |
| Model dan versi | `show gpon remote-onu equip` |
| ONU RX, ONU TX, OLT RX | `show pon power attenuation` |
| WAN dan IP aktual | `show gpon remote-onu wan-ip` |
| Port Ethernet | `show gpon remote-onu interface eth` |
| SSID dan keamanan | `show gpon remote-onu ssid info` |
| Admin/operasional WiFi | `show gpon remote-onu interface wifi` |
| IP host | `show gpon remote-onu ip-host` |
| Konfigurasi OMCI | `show onu running config` |

Fallback `equip-info`, `eth-port`, `wifi-info` tersedia untuk varian lain. Parser mengenali `Current IP`, `Current mask`, `Operate status`, indeks `eth_0/n`/`wifi_0/n`, serta pembungkusan baris `vlan-profile MGMT-O` + `NU host 1`. Angka RX/TX diambil dari arah up/down; Software memakai versi yang dilaporkan perangkat. Waktu riwayat perangkat ditampilkan dalam WIB sesuai waktu CLI yang dilaporkan, tanpa menganggap jam OLT sudah tersinkronisasi.

Pembacaan contoh ARFIN-HOME melalui service aplikasi berhasil untuk seluruh lima tab: 1 WAN, 4 port Ethernet, 10 IP host, 4 SSID, tanpa warning. Nama SSID kosong dan password `use ONU default` ditampilkan sebagai belum tersedia; nilainya tidak ditebak.

## Aksi

- Edit nama memakai verifikasi konfigurasi yang sudah tersedia. Edit deskripsi, PPPoE, nama/password SSID, dan admin Ethernet memeriksa serial terlebih dahulu dan memverifikasi hasil lewat pembacaan ulang.
- Sintaks C320: `interface eth eth_0/n state lock|unlock`, `ssid ctrl wifi_0/n name ...`, `ssid auth wpa wifi_0/n key ...`, dan `wan-ip n mode pppoe ... vlan-profile ... host n`.
- Reset Pabrik mengirim `restore factory` hanya setelah pengguna mengonfirmasi; hasil yang ditampilkan adalah penerimaan perintah, bukan klaim layanan kembali online. Reboot/admin/hapus menggunakan izin Owner/NOC. Tidak ada retry otomatis untuk write yang hasilnya tidak pasti.
- Prompt dengan posisi dan spasi, seperti `(gpon-onu-mng 1/2/14:3)#`, dikenali oleh Telnet dan SSH. Jangan mengirim perintah write lengkap diikuti `?` dan CRLF untuk preflight: firmware dapat mengeksekusi perintah tersebut.
- Tag dan hubungan ODP menggunakan endpoint tenant-scoped yang sudah ada. Riwayat posisi berasal dari inventaris, bukan log event perangkat; waktu hilangnya ONU tidak ditebak.
- [Traffic ONU/PON](TRAFFIC.md) memakai SNMP dengan fallback CLI, polling live, histori dan filter bulan. Riwayat optik/status berasal dari observasi tersimpan.

Password tidak ikut respons snapshot biasa, hanya indikator ketersediaannya. Tombol mata memanggil endpoint khusus Owner/NOC, dengan respons `no-store` dan audit akses tanpa isi rahasia. Rahasia dibersihkan ketika pindah tab/ONU, refresh, atau menutup modal. Show Config dibatasi izin CLI dan menyamarkan rahasia.

## Verifikasi

Verifikasi 17 Oktober 2026: build Vite berhasil; 155 tes PHP lulus (808 assertions), suite penuh 16 tes browser lulus, lalu 8 tes browser terkait dijalankan ulang setelah penyempurnaan fokus dan penanganan respons terlambat. Delapan tes terakhir juga lulus, termasuk tes baru agar respons password ONU sebelumnya tidak tampil pada ONU lain.

Tes mencakup format CLI nyata yang sudah disanitasi, pemetaan arah optik, serial berubah, tenant/RBAC, enkripsi snapshot, akses password, kegagalan firmware, dan verifikasi write melalui peer/mock terisolasi. Tes browser mencakup lima tab, keyboard, tampilan mobile, footer, tag, edit nama/lokasi, serta aksi ONU. Pemeriksaan langsung ke OLT produksi mencakup pembacaan dan bantuan CLI; pengujian perubahan layanan dan reset tidak dijalankan ke ONU produksi.
