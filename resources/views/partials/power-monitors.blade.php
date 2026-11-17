<template x-if="modal==='power'"><div class="form-stack">
    <div class="power-hero-grid">
        <div class="power-kpi">
            <span>Total Sensor</span>
            <strong x-text="powerData.summary.total ?? 0"></strong>
            <small class="muted">Lokasi terpantau</small>
        </div>
        <div class="power-kpi is-up">
            <span><i class="dot green"></i> Listrik Menyala</span>
            <strong class="green-text" x-text="powerData.summary.up ?? 0"></strong>
            <small class="muted">Ping / ONU aktif</small>
        </div>
        <div class="power-kpi is-down" :class="{'has-down': (powerData.summary.down ?? 0) > 0}">
            <span><i class="dot red"></i> Listrik Padam</span>
            <strong class="red-text" x-text="powerData.summary.down ?? 0"></strong>
            <small class="muted">Perlu pengecekan</small>
        </div>
        <div class="power-kpi">
            <span>Rata-rata Ping</span>
            <strong class="cyan" x-text="powerData.summary.avg_latency_ms != null ? powerData.summary.avg_latency_ms + ' ms' : '—'"></strong>
            <small class="muted">Cek otomatis 1 menit</small>
        </div>
    </div>

    <div class="notice" x-show="powerData.settings && !powerData.settings.has_gateway">
        <div>
            <strong>Gateway WhatsApp / Telegram belum aktif.</strong>
            <p class="muted small">Atur koneksi Gateway WhatsApp atau Bot Telegram utama terlebih dahulu agar alert padam listrik dapat terkirim.</p>
        </div>
        <button type="button" class="btn secondary" style="margin-left:auto" @click="open('whatsapp')">Atur WhatsApp</button>
    </div>

    <div class="module-toolbar">
        <label class="modal-search" style="flex:1;min-width:200px;margin:0">
            <i data-lucide="search"></i>
            <input x-model="powerFilter.q" placeholder="Cari nama lokasi, IP, atau group..." aria-label="Cari sensor listrik">
        </label>
        <label class="compact-field">Group
            <select x-model="powerFilter.group">
                <option value="all">Semua Group</option>
                <template x-for="grp in powerData.groups || []" :key="grp">
                    <option :value="grp" x-text="grp"></option>
                </template>
            </select>
        </label>
        <label class="compact-field">Status
            <select x-model="powerFilter.status">
                <option value="all">Semua Status</option>
                <option value="up">Menyala (UP)</option>
                <option value="down">Padam (DOWN)</option>
                <option value="unknown">Belum Dicek</option>
            </select>
        </label>
        <button type="button" class="btn secondary" x-show="db.permissions.operate" :disabled="powerChecking" @click="checkAllPower()">
            <i data-lucide="refresh-cw" :class="{'spinning':powerChecking}"></i>
            <span x-text="powerChecking ? 'Mengecek Ping...' : 'Cek Ping Sekarang'"></span>
        </button>
        <button type="button" class="btn primary" x-show="db.permissions.operate" @click="newPowerMonitor()">
            <i data-lucide="plus"></i><span>Tambah Sensor</span>
        </button>
    </div>

    <template x-if="powerForm">
        <form class="inline-editor form-stack" @submit.prevent="savePowerMonitor()">
            <div class="module-heading" style="margin-bottom:0">
                <div>
                    <h3 x-text="powerForm.id ? 'Edit Sensor Listrik' : 'Tambah Sensor Monitoring Listrik'"></h3>
                    <p class="muted small">Pantau ketersediaan listrik PLN via Ping IP (seperti LibreNMS), status ONU OLT, atau kombinasi keduanya.</p>
                </div>
                <button type="button" class="icon-button" @click="powerForm=null" aria-label="Tutup form"><i data-lucide="x"></i></button>
            </div>
            <div class="form-grid">
                <label>Nama Perangkat / Lokasi
                    <input x-model="powerForm.name" required maxlength="120" placeholder="MONITORING LISTRIK CIPAKU">
                    <small class="red-text" x-show="fieldError('name')" x-text="fieldError('name')"></small>
                </label>
                <label>Device Group
                    <input x-model="powerForm.group_name" maxlength="120" placeholder="MONITORING-LISTRIK-ONU-BANDUNG">
                </label>
                <label>Area / Keterangan Lokasi (opsional)
                    <input x-model="powerForm.location" maxlength="160" placeholder="Cipaku, Bandung">
                </label>
                <label>Mode Deteksi
                    <select x-model="powerForm.monitor_type" @change="onPowerTypeChange()">
                        <option value="ping">Ping IP (ICMP) — persis LibreNMS</option>
                        <option value="onu">Status ONU di OLT (Dying Gasp / Power Off)</option>
                        <option value="both">Ping IP + Status ONU OLT (Ganda)</option>
                    </select>
                </label>
                <label x-show="powerForm.monitor_type !== 'onu'">Alamat IP Target (Ping ICMP)
                    <input x-model="powerForm.ip_address" :required="powerForm.monitor_type !== 'onu'" maxlength="45" placeholder="172.16.155.253">
                    <small class="red-text" x-show="fieldError('ip_address')" x-text="fieldError('ip_address')"></small>
                </label>
                <label x-show="powerForm.monitor_type !== 'ping'">Pilih OLT (untuk ambil daftar ONU)
                    <select x-model="powerForm.olt_id" @change="loadPowerOnus(powerForm.olt_id)">
                        <option value="">— Pilih OLT —</option>
                        <template x-for="olt in db.olts" :key="olt.id">
                            <option :value="String(olt.id)" x-text="olt.name"></option>
                        </template>
                    </select>
                </label>
                <label x-show="powerForm.monitor_type !== 'ping'">ONU Terkait
                    <select x-model="powerForm.onu_id" :required="powerForm.monitor_type !== 'ping'">
                        <option value="">— Pilih ONU Sensor —</option>
                        <template x-for="onu in powerOnuOptions" :key="onu.id">
                            <option :value="String(onu.id)" x-text="(onu.name && onu.name!==onu.sn ? onu.name + ' · ' : '') + onu.sn + ' (' + onu.interface + ')'"></option>
                        </template>
                    </select>
                    <small class="red-text" x-show="fieldError('onu_id')" x-text="fieldError('onu_id')"></small>
                </label>
                <label>Override Grup WA Khusus Sensor Ini (opsional)
                    <input x-model="powerForm.whatsapp_recipients_override" maxlength="500" placeholder="Kosongkan untuk pakai grup WA Listrik global">
                </label>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" x-model="powerForm.is_enabled">
                <span>Aktifkan pengecekan otomatis & alert padam/menyala</span>
            </label>
            <div class="modal-actions" style="margin-top:8px">
                <button type="button" class="btn secondary" @click="powerForm=null" :disabled="busy">Batal</button>
                <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan & Cek Status'"></button>
            </div>
        </form>
    </template>

    <div class="table-scroll" x-show="filteredPowerMonitors.length">
        <table>
            <thead>
                <tr>
                    <th>Perangkat / Group</th>
                    <th>Mode &amp; Target</th>
                    <th>Status Listrik</th>
                    <th>Latency / Sinyal</th>
                    <th>Terakhir Dicek</th>
                    <th x-show="db.permissions.operate">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="m in filteredPowerMonitors" :key="m.id">
                    <tr>
                        <td>
                            <strong x-text="m.name"></strong>
                            <small class="mono" x-text="m.group_name + (m.location ? ' · ' + m.location : '')"></small>
                        </td>
                        <td>
                            <span class="tag" x-text="m.monitor_type === 'ping' ? 'Ping IP' : (m.monitor_type === 'onu' ? 'ONU OLT' : 'Ping + ONU')"></span>
                            <small class="mono" x-show="m.ip_address" x-text="m.ip_address"></small>
                            <small x-show="m.onu_label" x-text="m.onu_label"></small>
                        </td>
                        <td>
                            <span class="status-badge" :class="!m.is_enabled ? 'offline' : (m.status === 'up' ? 'online' : (m.status === 'down' ? 'phase-los' : 'offline'))">
                                <span class="dot" :class="!m.is_enabled ? 'gray' : (m.status === 'up' ? 'green' : (m.status === 'down' ? 'red' : 'amber'))"></span>
                                <span x-text="!m.is_enabled ? 'Dijeda' : (m.status === 'up' ? 'Menyala (UP)' : (m.status === 'down' ? 'PADAM' : 'Belum dicek'))"></span>
                            </span>
                            <small class="red-text" x-show="m.status === 'down' && m.last_error" x-text="m.last_error"></small>
                        </td>
                        <td class="mono">
                            <span x-show="m.status === 'up' && m.last_latency_ms != null" class="green-text" x-text="m.last_latency_ms + ' ms'"></span>
                            <span x-show="m.status === 'down'" class="red-text" x-text="m.packet_loss != null ? m.packet_loss + '% loss' : 'Offline'"></span>
                            <span x-show="m.status === 'unknown' || (m.status === 'up' && m.last_latency_ms == null)" class="muted">—</span>
                        </td>
                        <td>
                            <span x-text="m.last_checked_at ? dateTime(m.last_checked_at) : '—'"></span>
                            <small class="red-text" x-show="m.status === 'down' && m.last_down_at" x-text="'Padam sejak ' + time(m.last_down_at)"></small>
                            <small class="muted" x-show="m.status === 'up' && m.last_up_at" x-text="'Normal sejak ' + time(m.last_up_at)"></small>
                        </td>
                        <td class="row-actions" x-show="db.permissions.operate">
                            <button type="button" class="icon-button" x-show="m.monitor_type !== 'onu'" @click="checkOnePower(m)" :disabled="powerCheckingId === m.id" title="Cek Ping sekarang">
                                <i data-lucide="refresh-cw" :class="{'spinning':powerCheckingId === m.id}"></i>
                            </button>
                            <button type="button" class="icon-button" @click="editPowerMonitor(m)" title="Edit sensor">
                                <i data-lucide="pencil"></i>
                            </button>
                            <button type="button" class="icon-button red-text" @click="deletePowerMonitor(m)" title="Hapus sensor">
                                <i data-lucide="trash-2"></i>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div class="empty-state panel" x-show="!filteredPowerMonitors.length">
        <i data-lucide="zap"></i>
        <strong>Belum ada sensor Monitoring Listrik</strong>
        <p class="muted">Tambahkan daftar IP perangkat pemantau PLN (seperti di LibreNMS) atau link ke ONU OLT untuk menerima alert otomatis saat listrik padam/menyala.</p>
        <p class="muted small">Tips: ONU di OLT yang diberi tag mengandung kata <strong>Listrik</strong> atau <strong>PLN</strong> juga otomatis dipantau oleh sistem ini.</p>
    </div>

    <form class="inline-editor form-stack" x-show="db.permissions.operate" @submit.prevent="savePowerSettings()">
        <div class="module-heading" style="margin-bottom:0">
            <div>
                <h3>Tujuan Alert Khusus Monitoring Listrik (WA &amp; Telegram)</h3>
                <p class="muted small">Kosongkan bila ingin mengikuti tujuan default dari menu WhatsApp / Telegram utama.</p>
            </div>
        </div>
        <label class="checkbox-row">
            <input type="checkbox" x-model="powerSettingsForm.power_notify_enabled">
            <span><strong>Kirim notifikasi otomatis</strong> saat sensor listrik Padam maupun Menyala Kembali</span>
        </label>
        <div class="form-grid">
            <label>Nomor / Group JID WhatsApp Khusus Listrik
                <input x-model="powerSettingsForm.power_whatsapp_recipients" maxlength="1000" :placeholder="powerData.settings?.default_whatsapp_recipients ? 'Default: ' + powerData.settings.default_whatsapp_recipients : '120363xxxxxx@g.us, 62812xxxx'">
            </label>
            <label>Chat ID Telegram Khusus Listrik
                <input x-model="powerSettingsForm.power_telegram_chat_id" maxlength="255" :placeholder="powerData.settings?.default_telegram_chat_id ? 'Default: ' + powerData.settings.default_telegram_chat_id : '-1001234567890'">
            </label>
            <label>Thread / Topic ID Telegram (opsional)
                <input x-model="powerSettingsForm.power_telegram_thread_id" maxlength="64" placeholder="Kosongkan jika bukan grup topik">
            </label>
            <label>Jeda Anti-Spam Padam Berulang (menit)
                <input type="number" min="0" max="1440" x-model.number="powerSettingsForm.power_cooldown_minutes" required>
            </label>
        </div>
        <div class="modal-actions" style="margin-top:8px">
            <button type="button" class="btn secondary" :disabled="busy" @click="testPowerAlert()">
                <i data-lucide="zap"></i><span>Kirim Tes Alert Listrik</span>
            </button>
            <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Pengaturan Listrik'"></button>
        </div>
    </form>

    <section class="form-stack" style="border-top:1px solid var(--line);padding-top:14px">
        <h3>Riwayat Alert Monitoring Listrik Terakhir</h3>
        <div class="table-scroll" x-show="(powerData.logs || []).length">
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
                    <template x-for="log in powerData.logs || []" :key="log.id">
                        <tr>
                            <td x-text="dateTime(log.sent_at)"></td>
                            <td><span class="tag" x-text="log.channel==='whatsapp'?'WhatsApp':'Telegram'"></span></td>
                            <td><span class="status-badge" :class="log.event_type==='power_down'?'phase-los':(log.event_type==='power_up'?'online':'offline')" x-text="log.event_type==='power_down'?'Listrik Padam':(log.event_type==='power_up'?'Menyala Kembali':'Tes Alert')"></span></td>
                            <td class="mono" x-text="log.recipient"></td>
                            <td><span class="status-badge" :class="log.status==='sent'?'online':'phase-los'" x-text="log.status==='sent'?'Terkirim':'Gagal'"></span></td>
                            <td x-text="log.error || 'OK'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p class="muted small" x-show="!(powerData.logs || []).length">Belum ada riwayat alert listrik.</p>
    </section>
</div></template>
