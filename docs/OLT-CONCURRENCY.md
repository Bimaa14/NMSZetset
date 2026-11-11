# Sesi OLT bersamaan

Monitoring background (inventory, optik, traffic, nama, sistem, backup) memakai satu sesi per OLT. Pembacaan interaktif detail/Traffic memakai satu sesi terpisah. Reboot dan konfigurasi layanan ONU memakai slot kontrol tersendiri, sehingga tidak menunggu monitoring selesai.

`OLT_CONTROL_SESSIONS=2` menyediakan dua kontrol bersamaan untuk ONU berbeda. Nilai didukung 1–4, dengan default 2: total anggaran jalur ini adalah satu monitoring + satu pembacaan interaktif + dua kontrol. Sesuaikan dengan kapasitas CLI perangkat dan sesi operator di luar aplikasi. Semua proses aplikasi harus memakai cache lock bersama dengan konfigurasi kapasitas yang sama.

Lock kontrol memakai tenant, OLT, interface PON dan indeks ONU, bukan hanya ID record. Reboot dan perubahan layanan pada alamat ONU yang sama tidak dapat berjalan berbarengan. Slot dan lock dilepas dalam `finally`, termasuk saat koneksi gagal. Respons `olt_busy` berarti belum ada perintah yang dijalankan; UI boleh menunggu maksimal 90 detik. Timeout atau hasil write tidak pasti tidak pernah diulang otomatis.

Operasi dengan cakupan OLT atau perubahan inventaris (edit/hapus OLT, Web CLI bebas, registrasi/hapus/rename/admin ONU melalui endpoint inventaris) tetap eksklusif terhadap semua jalur. Admission gate mencegah operasi eksklusif masuk bersamaan dengan sesi baru. Tidak ada force-unlock terhadap sesi aktif.

Setelah deploy, restart worker queue dan monitor dengan tertib agar semua proses memakai koordinator yang sama. Jangan mengubah jumlah slot hanya pada sebagian worker.

Validasi mencakup reboot dan setiap tab konfigurasi saat lock monitoring/pembacaan ditahan, batas dua kontrol, konflik alamat ONU yang sama, isolasi OLT, pelepasan saat gagal, serta cache file lintas proses PHP. Pengujian ini tidak me-reboot ONU pelanggan fisik.

Pemeriksaan laptop juga berhasil membuka empat sesi CLI ke OLT Monex pada waktu yang tumpang tindih: satu monitoring, satu baca, dan dua slot kontrol. Seluruh sesi berhasil menjalankan `show version-running`. Slot kontrol dalam pemeriksaan ini hanya menjalankan pembacaan; pengujian write memakai peer lokal.
