# ONU Configuration Tool

Buka Detail ONU ? **Konfigurasi Lanjutan**. Tool memakai identitas OLT dan posisi ONU yang sedang dibuka. Tujuh tab: T-CONT, GEM Port, Service, Ports, ONU Service, WAN IP, TR069. WAN IP menyediakan Static IP, PPPoE, DHCP, dan Remote Access. Profil upstream/downstream/VLAN/IP dibaca dari OLT, bukan daftar contoh.

## Alur perubahan

1. Muat konfigurasi tersimpan, lalu baca konfigurasi perangkat. Refresh manual memakai sesi baca terpisah dari polling operasional. Bila sesi baca sibuk, snapshot terakhir dipertahankan.
2. Form Tambah/Edit/Hapus membuat pratinjau tanpa menulis ke perangkat. Server membaca ulang identitas serial dan konfigurasi, memvalidasi profil dan dependensi, lalu menyimpan rencana serta backup konfigurasi sebelumnya dengan enkripsi Laravel.
3. **Terapkan ke OLT** memakai token sekali pakai, terikat pengguna, tenant, ONU, koneksi, dan fingerprint konfigurasi. Masa berlaku 5 menit. Koneksi atau konfigurasi yang berubah memerlukan pratinjau baru.
4. Eksekusi mengambil mutex operasional dan sesi baca. Tidak ada transaksi database yang ditahan selama IO jaringan. Hasil dibaca ulang sebelum sukses ditampilkan; kegagalan setelah perintah tulis ditandai uncertain dan tidak diulang otomatis. Prompt destruktif yang tidak diharapkan tidak dijawab otomatis.
5. Password tidak dikirim pada snapshot, audit, atau pratinjau. Password baru di form dibersihkan setelah pratinjau tersimpan. Kosong saat edit mempertahankan password sebelumnya. Karakter CLI yang tidak didukung ditolak sebelum write.

Perubahan berlaku pada **running configuration** OLT. Tool tidak menjalankan `write` global otomatis dan tidak melakukan rollback otomatis terhadap hasil parsial. Backup terenkripsi tersimpan pada rencana perubahan untuk pemeriksaan.

## Konteks CLI

| Tab | Konteks | Perintah utama |
| --- | --- | --- |
| T-CONT | interface gpon-onu | tcont ID name NAME profile PROFILE / no tcont ID |
| GEM Port | interface gpon-onu | gemport ID name NAME tcont ID; gemport ID traffic-limit downstream PROFILE |
| Service | interface gpon-onu | service-port ID vport GEM user-vlan VLAN vlan VLAN; service-port ID description TEXT |
| Ports | pon-onu-mng | vlan port eth_0/N atau wifi_0/N mode tag/trunk/hybrid/transparent |
| ONU Service | pon-onu-mng | service NAME gemport GEM [iphost ID atau ethuni PORT] cos PRIORITY vlan VLAN |
| WAN IP | pon-onu-mng | wan-ip ID mode static/pppoe/dhcp; wan-ip ID ping-response ... |
| Remote Access | pon-onu-mng | security-mgmt ID state STATE mode forward ingress-type INGRESS protocol PROTOCOL |
| TR069 | pon-onu-mng | tr069-mgmt ID tag/untag; acs URL validate ...; state lock/unlock |

T-CONT maksimal 8. T-CONT yang dipakai GEM, serta GEM yang dipakai Service/ONU Service, tidak dapat dihapus. ID duplikat ditolak; Edit diperlukan untuk resource yang sudah ada. VLAN valid 1?4094 karena VLAN 4095 dicadangkan. UNI IP Host 1?255; ETH/WLAN yang dapat dipilih 1?4. Port tambahan GEM/multicast/VEIP ditampilkan bila ada dalam konfigurasi dan tetap baca-saja melalui form port ini. Port yang belum memiliki aturan ditampilkan N/A; daftar tersebut tidak menyatakan semua ONU memiliki empat WLAN fisik.

Pilihan Multicast IPTV pada GEM menyiapkan GEM/T-CONT/profil downstream untuk layanan IPTV. VLAN dan IGMP multicast global harus sudah tersedia. Tool tidak membuat MVLAN, uplink multicast, atau grup IGMP global secara otomatis.

## Endpoint

- GET `/onus/{id}/configuration-tool`: snapshot publik tersimpan.
- POST `/onus/{id}/configuration-tool/refresh`: pembacaan perangkat dan profil.
- POST `/onus/{id}/configuration-tool/preview`: validasi dan rencana terenkripsi.
- POST `/onus/{id}/configuration-tool/apply`: penerapan serta verifikasi hasil.

Endpoint scoped tenant dan ONU current. Pembacaan memerlukan read; preview/apply memerlukan operate. Semua respons no-store, route memiliki CSRF/auth/session-release dan throttle. Mode simulator tidak menulis ke perangkat.

## Verifikasi

- Parser menangani profil terbungkus antarbaris, beberapa baris konfigurasi per ID, default ping, service description, VLAN mode/tag/hybrid, WAN/IP Host, remote access, serta TR069.
- Tes API mencakup semua tab, sesi Telnet nyata ke peer loopback, token sekali pakai, koneksi/stale config/expiry, enkripsi, dependensi, input injection, pembatasan role/tenant, dan kegagalan parsial/readback.
- Pembacaan fisik pada CONTOH, ONU SMY-ERABLUE (Slot 1 / PON 3 / ONU 6): T-CONT STARBIZ-150, GEM internet, VLAN 97, profil VLAN STARZONE. Daftar profil: 83 upstream, 72 downstream, 8 VLAN, 1 IP. Password tidak dikirim ke browser.
- Help interaktif perangkat dibaca menggunakan `?` tanpa CRLF, tanpa mengeksekusi kandidat write. `service ... iphost ID cos PRIORITY vlan VLAN` dan perintah dasar lain dikonfirmasi dari help firmware. Sintaks TR069/WAN/VLAN juga dirujuk dari [ZTE C300/C320 Command Reference, Bab 13](https://get.ipnetwork.com.br/files/ZTE/OLT_ZTE/C3XX/CONFIGURA%C3%87%C3%95ES/Manual_ZTE_C3xx.pdf).
- Uji tulis dilakukan pada fixture loopback dan klien tiruan; tidak ada perubahan provisioning yang diterapkan pada ONU pelanggan hanya untuk pengujian modul.

Hasil pemeriksaan akhir: 32 tes PHP terkait tool/detail ONU lulus (213 assertions); lima skenario browser terkait tool, inspector, dan pencarian lulus setelah perbaikan. Tes browser mencakup tujuh tab, form semua mode WAN, pratinjau/hapus, penerapan eksplisit, penanganan read busy, navigasi keyboard/Escape, dan viewport mobile 390px. Build Vite berhasil.

Preview: [T-CONT](onu-tool-tcont.png), [TR069](onu-tool-tr069.png), [mobile](onu-tool-mobile.png).
