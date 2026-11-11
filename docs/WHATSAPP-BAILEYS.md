# Integrasi Notifikasi WhatsApp (Node.js Baileys) & Telegram

NMS OLT ZTE mendukung pengiriman notifikasi otomatis melalui **WhatsApp (Node.js Baileys / Fonnte / Wablas / Custom Webhook)** dan **Telegram Bot** secara bersamaan maupun terpisah.

## 1. Kondisi Trigger Notifikasi Otomatis

Saat poller OLT berjalan, sistem mendeteksi transisi status dan kualitas optik ONU lalu mengirim notifikasi untuk 4 kondisi berikut:
1. **ONU LOSS (`los`)**: Saat kabel/optik putus (`working` ➔ `los`).
2. **Dying Gasp / Power Off (`dying-gasp`, `poweroff`, `offline`)**: Saat ONU kehilangan daya listrik atau mati.
3. **ONU Kembali Online (`working`)**: Saat ONU yang sebelumnya gangguan kembali normal (`Recovery`).
4. **Redaman Optik Buruk (`bad_rx`)**: Saat redaman ONT melewati batas dBm (default `<= -27.00 dBm`).

Dilengkapi fitur:
- **Anti-Spam Cooldown**: Mencegah pengiriman berulang untuk ONU yang flapping dalam rentang menit yang ditentukan.
- **Proteksi Gangguan Massal**: Jika lebih dari 5 ONU mengalami perubahan status bersamaan dalam satu siklus polling (misal kabel feeder PON putus), sistem otomatis menggabungkannya menjadi **1 pesan ringkasan massal** agar nomor WhatsApp tidak terkena limit/blokir.
- **Informasi Lokasi & ODP**: Menyertakan nama ODP dan tautan Google Maps jika koordinat ONU/ODP sudah dipetakan di FTTH Maps.

---

## 2. Menghubungkan Script Node.js Baileys Teman Anda

Jika Anda sudah memiliki script REST API Node.js Baileys sendiri, Anda **tidak perlu mengubah kode Baileys tersebut**.

Saat mengirim pesan, NMS mengirimkan HTTP `POST` JSON dengan field universal berikut:
```json
{
  "number": "6281234567890",
  "phone": "6281234567890",
  "to": "6281234567890",
  "target": "6281234567890",
  "jid": "6281234567890@s.whatsapp.net",
  "chatId": "6281234567890@s.whatsapp.net",
  "message": "🚨 *[NMS ALERT] ONU LOSS (PUTUS OPTIK)* ...",
  "text": "🚨 *[NMS ALERT] ONU LOSS (PUTUS OPTIK)* ...",
  "body": "🚨 *[NMS ALERT] ONU LOSS (PUTUS OPTIK)* ...",
  "session": "default",
  "sessionId": "default"
}
```
Dan jika Anda mengisi **API Key / Secret Token**, NMS mengirimkannya sekaligus pada header:
- `Authorization: Bearer <API_KEY>`
- `x-api-key: <API_KEY>`
- `apikey: <API_KEY>`

---

## 3. Menjalankan Baileys Gateway Bawaan di Ubuntu Linux

Proyek ini juga menyediakan microservice Baileys siap pakai di `scripts/baileys-gateway.mjs`.

### Langkah 1: Install Paket Baileys di Server Ubuntu
```bash
cd /var/www/nms
npm install @whiskeysockets/baileys pino qrcode-terminal
```

### Langkah 2: Jalankan Pertama Kali untuk Scan QR WhatsApp
```bash
WA_PORT=3001 node scripts/baileys-gateway.mjs
```
Scan QR Code yang muncul di terminal menggunakan aplikasi WhatsApp (**Perangkat Tertaut / Linked Devices**). Sesi login akan tersimpan otomatis di `storage/app/private/baileys-auth`.

Untuk melihat daftar **Group JID** (`...@g.us`) yang diikuti nomor WhatsApp tersebut:
```bash
curl http://127.0.0.1:3001/groups
```

### Langkah 3: Jalankan Permanen di Background dengan `systemd` (Ubuntu)
Buat file `/etc/systemd/system/nms-wa-gateway.service`:
```ini
[Unit]
Description=NMS WhatsApp Baileys Gateway
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/nms
Environment=NODE_ENV=production
Environment=WA_HOST=127.0.0.1
Environment=WA_PORT=3001
ExecStart=/usr/bin/node /var/www/nms/scripts/baileys-gateway.mjs
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Aktifkan service:
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nms-wa-gateway
sudo systemctl status nms-wa-gateway
```
