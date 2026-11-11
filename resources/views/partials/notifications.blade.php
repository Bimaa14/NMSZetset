<template x-if="['telegram','whatsapp'].includes(modal)"><div class="form-stack">
    <p class="muted">Kirim notifikasi otomatis ke WhatsApp (Node.js Baileys / Gateway) dan Telegram saat terjadi gangguan ONU LOSS, mati listrik, redaman memburuk, atau ONU kembali online.</p>

    <div class="filter-tabs" role="tablist" aria-label="Pilih channel notifikasi">
        <button type="button" role="tab" :class="{selected:notificationTab==='whatsapp'}" :aria-selected="notificationTab==='whatsapp'" @click="notificationTab='whatsapp'">
            <span class="dot" :class="notificationForm.whatsapp_enabled?'green':'gray'"></span>
            <span>WhatsApp (Baileys / Gateway)</span>
        </button>
        <button type="button" role="tab" :class="{selected:notificationTab==='telegram'}" :aria-selected="notificationTab==='telegram'" @click="notificationTab='telegram'">
            <span class="dot" :class="notificationForm.telegram_enabled?'green':'gray'"></span>
            <span>Telegram Bot</span>
        </button>
    </div>

    <form class="form-stack" @submit.prevent="saveNotifications()">
        <div x-show="notificationTab==='whatsapp'" class="form-stack">
            <label class="checkbox-row">
                <input type="checkbox" x-model="notificationForm.whatsapp_enabled" :disabled="!db.permissions.operate">
                <span><strong>Aktifkan notifikasi WhatsApp</strong> — kirim alert otomatis ke nomor WA teknisi / grup NOC</span>
            </label>

            <div class="form-grid">
                <label>Tipe Gateway WhatsApp
                    <select x-model="notificationForm.whatsapp_provider" :disabled="!db.permissions.operate" @change="if(notificationForm.whatsapp_provider==='baileys'&&!notificationForm.whatsapp_endpoint_url)notificationForm.whatsapp_endpoint_url='http://127.0.0.1:3001/send-message';if(notificationForm.whatsapp_provider==='fonnte')notificationForm.whatsapp_endpoint_url='https://api.fonnte.com/send'">
                        <option value="baileys">Node.js Baileys (Lokal / Ubuntu Server)</option>
                        <option value="fonnte">Fonnte API (api.fonnte.com)</option>
                        <option value="wablas">Wablas API</option>
                        <option value="custom">Custom HTTP Webhook</option>
                    </select>
                </label>
                <label>Session / Device ID (Baileys)
                    <input x-model="notificationForm.whatsapp_session_id" :disabled="!db.permissions.operate" maxlength="100" placeholder="default">
                </label>
                <label>Endpoint URL Gateway WhatsApp
                    <input type="url" x-model="notificationForm.whatsapp_endpoint_url" :disabled="!db.permissions.operate" maxlength="500" placeholder="http://127.0.0.1:3001/send-message">
                    <small class="red-text" x-show="fieldError('whatsapp_endpoint_url')" x-text="fieldError('whatsapp_endpoint_url')"></small>
                </label>
                <label>API Key / Secret Token (opsional untuk Baileys)
                    <input type="password" x-model="notificationForm.whatsapp_api_key" :disabled="!db.permissions.operate" maxlength="255" :placeholder="notificationMeta.has_whatsapp_api_key ? '•••••••• (sudah tersimpan, isi untuk mengganti)' : 'Kosongkan jika Baileys lokal tanpa token'">
                    <small class="red-text" x-show="fieldError('whatsapp_api_key')" x-text="fieldError('whatsapp_api_key')"></small>
                </label>
            </div>

            <label>Nomor Tujuan WhatsApp / Group JID (pisahkan dengan koma)
                <input x-model="notificationForm.whatsapp_recipients" :disabled="!db.permissions.operate" maxlength="1000" placeholder="6281234567890, 12036302938475@g.us">
                <small class="muted">Mendukung format nomor <code>08xx</code> / <code>628xx</code> maupun ID Grup WhatsApp Baileys (<code>...@g.us</code>).</small>
                <small class="red-text" x-show="fieldError('whatsapp_recipients')" x-text="fieldError('whatsapp_recipients')"></small>
            </label>

            <div class="notice">
                <div>
                    <strong>Kompatibilitas Node.js Baileys (Windows & Ubuntu Linux)</strong>
                    <p class="muted small">Payload JSON dikirim otomatis dengan field <code>number</code>, <code>phone</code>, <code>to</code>, <code>jid</code>, <code>chatId</code>, <code>message</code>, <code>text</code>, dan <code>sessionId</code> sehingga langsung kompatibel dengan berbagai script REST API Baileys maupun service bawaan <code>node scripts/baileys-gateway.mjs</code>.</p>
                </div>
            </div>
        </div>

        <div x-show="notificationTab==='telegram'" class="form-stack">
            <label class="checkbox-row">
                <input type="checkbox" x-model="notificationForm.telegram_enabled" :disabled="!db.permissions.operate">
                <span><strong>Aktifkan notifikasi Telegram</strong> — kirim alert otomatis melalui Bot Telegram</span>
            </label>

            <div class="form-grid">
                <label>Telegram Bot Token (@BotFather)
                    <input type="password" x-model="notificationForm.telegram_bot_token" :disabled="!db.permissions.operate" maxlength="255" :placeholder="notificationMeta.has_telegram_bot_token ? '•••••••• (sudah tersimpan, isi untuk mengganti)' : '123456789:ABCdefGHIjklMNOpqrSTUvwxYZ'">
                    <small class="red-text" x-show="fieldError('telegram_bot_token')" x-text="fieldError('telegram_bot_token')"></small>
                </label>
                <label>Chat ID / Group ID (pisahkan dengan koma)
                    <input x-model="notificationForm.telegram_chat_id" :disabled="!db.permissions.operate" maxlength="255" placeholder="-1001234567890, 987654321">
                    <small class="red-text" x-show="fieldError('telegram_chat_id')" x-text="fieldError('telegram_chat_id')"></small>
                </label>
                <label>Topic / Thread ID (opsional untuk Supergroup)
                    <input x-model="notificationForm.telegram_thread_id" :disabled="!db.permissions.operate" maxlength="64" placeholder="Kosongkan jika bukan topik">
                </label>
            </div>
        </div>

        <section class="form-stack" style="border-top:1px solid var(--line);padding-top:16px">
            <h3>Kondisi Trigger Notifikasi (WhatsApp & Telegram)</h3>
            <div class="form-grid">
                <label class="checkbox-row">
                    <input type="checkbox" x-model="notificationForm.notify_on_los" :disabled="!db.permissions.operate">
                    <span>Saat ONU terkena <strong>LOSS</strong> (putus kabel/optik)</span>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" x-model="notificationForm.notify_on_dying_gasp" :disabled="!db.permissions.operate">
                    <span>Saat ONU <strong>Dying Gasp / Power Off</strong> (mati listrik)</span>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" x-model="notificationForm.notify_on_recovery" :disabled="!db.permissions.operate">
                    <span>Saat ONU kembali <strong>Online (Recovery)</strong></span>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" x-model="notificationForm.notify_on_bad_rx" :disabled="!db.permissions.operate">
                    <span>Saat <strong>Redaman Optik buruk</strong> (melewati batas dBm)</span>
                </label>
            </div>

            <div class="form-grid">
                <label>Batas Redaman Buruk (dBm)
                    <input type="number" step="0.1" min="-45" max="-10" x-model.number="notificationForm.bad_rx_threshold" :disabled="!db.permissions.operate" required>
                </label>
                <label>Jeda Anti-Spam per ONU (menit)
                    <input type="number" min="0" max="1440" x-model.number="notificationForm.cooldown_minutes" :disabled="!db.permissions.operate" required>
                </label>
            </div>
        </section>

        <div class="modal-actions" x-show="db.permissions.operate">
            <button type="button" class="btn secondary" :disabled="busy" @click="testNotification('whatsapp')">
                <i data-lucide="message-circle"></i><span>Kirim Tes WhatsApp</span>
            </button>
            <button type="button" class="btn secondary" :disabled="busy" @click="testNotification('telegram')">
                <i data-lucide="send"></i><span>Kirim Tes Telegram</span>
            </button>
            <button type="submit" class="btn primary" :disabled="busy" x-text="busy?'Menyimpan...':'Simpan pengaturan notifikasi'"></button>
        </div>
    </form>

    <section class="form-stack" style="border-top:1px solid var(--line);padding-top:16px">
        <h3>Riwayat Pengiriman Notifikasi Terakhir</h3>
        <div class="table-scroll" x-show="notificationLogs.length">
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Channel</th>
                        <th>Event</th>
                        <th>Tujuan</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="log in notificationLogs" :key="log.id">
                        <tr>
                            <td x-text="dateTime(log.sent_at)"></td>
                            <td><span class="tag" x-text="log.channel==='whatsapp'?'WhatsApp':'Telegram'"></span></td>
                            <td class="mono" x-text="log.event_type"></td>
                            <td class="mono" x-text="log.recipient"></td>
                            <td><span class="status-badge" :class="log.status==='sent'?'online':'phase-los'" x-text="log.status==='sent'?'Terkirim':'Gagal'"></span></td>
                            <td x-text="log.error || 'OK'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p class="muted small" x-show="!notificationLogs.length">Belum ada riwayat pengiriman notifikasi.</p>
    </section>
</div></template>
