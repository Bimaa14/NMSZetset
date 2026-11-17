<template x-if="modal==='power'"><div class="power-workspace">
    <div class="power-hero-grid">
        <div class="power-kpi is-total">
            <div class="power-kpi-head">
                <span>Total Sensor</span>
                <span class="power-kpi-icon"><i data-lucide="zap"></i></span>
            </div>
            <strong x-text="powerData.summary.total ?? 0"></strong>
            <small class="muted">Lokasi terpantau</small>
        </div>
        <div class="power-kpi is-up">
            <div class="power-kpi-head">
                <span><i class="dot green"></i> Listrik Menyala</span>
                <span class="power-kpi-icon is-up"><i data-lucide="circle-check"></i></span>
            </div>
            <strong class="green-text" x-text="powerData.summary.up ?? 0"></strong>
            <small class="muted">Ping / ONU aktif</small>
        </div>
        <div class="power-kpi is-down" :class="{'has-down': (powerData.summary.down ?? 0) > 0, 'has-warning': (powerData.summary.down ?? 0) === 0 && (powerData.summary.warning ?? 0) > 0}">
            <div class="power-kpi-head">
                <span><i class="dot red"></i> Listrik Padam</span>
                <span class="power-kpi-icon is-down"><i data-lucide="alert-triangle"></i></span>
            </div>
            <strong class="red-text" x-text="powerData.summary.down ?? 0"></strong>
            <small class="muted" x-text="(powerData.summary.warning ?? 0) > 0 ? (powerData.summary.warning + ' sensor Ping RTO · ONU Working') : 'Perlu pengecekan'"></small>
        </div>
        <div class="power-kpi is-latency">
            <div class="power-kpi-head">
                <span>Rata-rata Ping</span>
                <span class="power-kpi-icon is-latency"><i data-lucide="activity"></i></span>
            </div>
            <strong class="cyan" x-text="powerData.summary.avg_latency_ms != null ? powerData.summary.avg_latency_ms + ' ms' : '—'"></strong>
            <small class="muted">Cek otomatis tiap 1 menit</small>
        </div>
    </div>

    <div class="notice" x-show="powerData.settings && !powerData.settings.has_gateway">
        <div>
            <strong>Gateway WhatsApp / Telegram belum aktif.</strong>
            <p class="muted small">Atur koneksi Gateway WhatsApp atau Bot Telegram utama terlebih dahulu agar alert padam listrik dapat terkirim.</p>
        </div>
        <button type="button" class="btn secondary" style="margin-left:auto" @click="open('whatsapp')">Atur WhatsApp</button>
    </div>

    <div class="power-tabs" role="tablist" aria-label="Menu Monitoring Listrik">
        <button type="button" role="tab" :aria-selected="powerTab==='monitors'" @click="powerTab='monitors'">
            <i data-lucide="zap"></i>
            <span>Daftar Sensor</span>
            <span class="count-pill" x-text="powerData.summary.total ?? 0"></span>
        </button>
        <button type="button" role="tab" :aria-selected="powerTab==='alerts'" @click="powerTab='alerts'">
            <i data-lucide="message-circle"></i>
            <span>Alert WA / TG &amp; Riwayat</span>
            <span class="power-tab-badge" :class="powerSettingsForm.power_notify_enabled ? 'is-on' : ''" x-text="powerSettingsForm.power_notify_enabled ? 'Aktif' : 'Nonaktif'"></span>
        </button>
    </div>

    <div x-show="powerTab==='monitors'" class="power-tab-panel">
        <div class="power-controls">
            <div class="power-controls-row">
                <label class="modal-search power-search">
                    <i data-lucide="search"></i>
                    <input x-model="powerFilter.q" placeholder="Cari lokasi, IP, SN ONU, atau group..." aria-label="Cari sensor listrik">
                </label>
                <select class="power-select" x-model="powerFilter.group" aria-label="Filter Device Group">
                    <option value="all">Semua Group</option>
                    <template x-for="grp in powerData.groups || []" :key="grp">
                        <option :value="grp" x-text="grp"></option>
                    </template>
                </select>
                <select class="power-select" x-model="powerFilter.status" aria-label="Filter Status Listrik">
                    <option value="all">Semua Status</option>
                    <option value="up">Menyala (UP)</option>
                    <option value="warning">Ping RTO · ONU Working</option>
                    <option value="down">Padam (DOWN)</option>
                    <option value="unknown">Belum Dicek</option>
                </select>
            </div>
            <div class="power-controls-actions">
                <div class="onu-quick-filters" role="group" aria-label="Filter cepat status listrik">
                    <button type="button" @click="powerFilter.status='all'" :aria-pressed="powerFilter.status==='all'">
                        <span>Semua</span>
                        <span class="power-filter-count" x-text="powerData.summary.total ?? 0"></span>
                    </button>
                    <button type="button" @click="powerFilter.status='up'" :aria-pressed="powerFilter.status==='up'">
                        <span class="dot green"></span>
                        <span>Menyala</span>
                        <span class="power-filter-count" x-text="powerData.summary.up ?? 0"></span>
                    </button>
                    <button type="button" @click="powerFilter.status='warning'" :aria-pressed="powerFilter.status==='warning'" x-show="(powerData.summary.warning ?? 0) > 0 || powerFilter.status === 'warning'">
                        <span class="dot amber"></span>
                        <span>Warning</span>
                        <span class="power-filter-count" x-text="powerData.summary.warning ?? 0"></span>
                    </button>
                    <button type="button" @click="powerFilter.status='down'" :aria-pressed="powerFilter.status==='down'">
                        <span class="dot red"></span>
                        <span>Padam</span>
                        <span class="power-filter-count" x-text="powerData.summary.down ?? 0"></span>
                    </button>
                </div>
                <div class="power-toolbar-buttons" x-show="db.permissions.operate">
                    <button type="button" class="btn secondary" :disabled="powerChecking" @click="checkAllPower()">
                        <i data-lucide="refresh-cw" :class="{'spinning':powerChecking}"></i>
                        <span x-text="powerChecking ? 'Mengecek Ping...' : 'Cek Ping Sekarang'"></span>
                    </button>
                    <button type="button" class="btn primary" @click="newPowerMonitor()">
                        <i data-lucide="plus"></i><span>Tambah Sensor</span>
                    </button>
                </div>
            </div>
        </div>

        <template x-if="powerForm">
            <form class="inline-editor power-editor-card form-stack" @submit.prevent="savePowerMonitor()">
                <div class="power-editor-head">
                    <div class="power-editor-title">
                        <span class="power-editor-badge" x-text="powerForm.id ? 'Edit Sensor' : 'Sensor Baru'"></span>
                        <h3 x-text="powerForm.id ? powerForm.name : 'Tambah Sensor Monitoring Listrik'"></h3>
                        <p class="muted small">Pantau listrik PLN via Ping IP, status ONU OLT, atau mode ganda (Ping RTO otomatis cek live ONU ke OLT).</p>
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
                        <input x-model="powerForm.location" maxlength="160" placeholder="OLT / POP / Lokasi Gardu">
                    </label>
                    <label>Mode Deteksi
                        <select x-model="powerForm.monitor_type" @change="onPowerTypeChange()">
                            <option value="both">Ping IP + Double-Check ONU OLT (Ganda)</option>
                            <option value="ping">Ping IP (ICMP) saja</option>
                            <option value="onu">Status ONU di OLT saja</option>
                        </select>
                    </label>
                    <label x-show="powerForm.monitor_type !== 'onu'">Alamat IP Target (Ping ICMP)
                        <input class="mono" x-model="powerForm.ip_address" :required="powerForm.monitor_type !== 'onu'" maxlength="45" placeholder="10.10.1.100">
                        <small class="red-text" x-show="fieldError('ip_address')" x-text="fieldError('ip_address')"></small>
                    </label>
                    <label x-show="powerForm.monitor_type !== 'ping'">Filter OLT (opsional)
                        <select x-model="powerForm.olt_id" @change="loadPowerOnus(powerForm.olt_id, powerOnuSearch)">
                            <option value="">Semua OLT</option>
                            <template x-for="olt in db.olts" :key="olt.id">
                                <option :value="String(olt.id)" x-text="olt.name"></option>
                            </template>
                        </select>
                    </label>
                    <label x-show="powerForm.monitor_type !== 'ping'">Cari ONU (SN / Nama)
                        <input x-model="powerOnuSearch" @input.debounce.250ms="loadPowerOnus(powerForm.olt_id, powerOnuSearch)" placeholder="Ketik SN ZTEG... atau nama ONU">
                    </label>
                    <label x-show="powerForm.monitor_type !== 'ping'" :class="{'full-width': powerForm.monitor_type === 'onu'}">ONU Terkait
                        <select x-model="powerForm.onu_id" :required="powerForm.monitor_type !== 'ping'">
                            <option value="">— Pilih ONU Sensor —</option>
                            <template x-for="onu in powerOnuOptions" :key="onu.id">
                                <option :value="String(onu.id)" x-text="oltName(onu.olt_id) + ' · ' + (onu.name && onu.name!==onu.sn ? onu.name + ' · ' : '') + onu.sn + ' (' + onu.interface + ')'"></option>
                            </template>
                        </select>
                        <small class="red-text" x-show="fieldError('onu_id')" x-text="fieldError('onu_id')"></small>
                    </label>
                    <label :class="{'full-width': powerForm.monitor_type !== 'ping'}">Override Grup WA Khusus Sensor Ini (opsional)
                        <input x-model="powerForm.whatsapp_recipients_override" maxlength="500" placeholder="Kosongkan untuk mengikuti grup WA Listrik global">
                    </label>
                </div>

                <div class="power-editor-footer">
                    <label class="checkbox-row">
                        <input type="checkbox" x-model="powerForm.is_enabled">
                        <span>Aktifkan pengecekan otomatis &amp; alert padam/menyala</span>
                    </label>
                    <div class="modal-actions" style="margin-top:0">
                        <button type="button" class="btn secondary" @click="powerForm=null" :disabled="busy">Batal</button>
                        <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan & Cek Status'"></button>
                    </div>
                </div>
            </form>
        </template>

        <div class="table-scroll power-table-wrap" x-show="filteredPowerMonitors.length">
            <table class="power-table">
                <thead>
                    <tr>
                        <th>Perangkat / Group</th>
                        <th>Target Ping &amp; ONU</th>
                        <th>Status Listrik</th>
                        <th>Latency</th>
                        <th>Terakhir Dicek</th>
                        <th x-show="db.permissions.operate" style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="m in filteredPowerMonitors" :key="m.id">
                        <tr :class="{'is-down-row': m.status==='down', 'is-warning-row': m.status==='warning'}">
                            <td>
                                <div class="power-device-cell">
                                    <span class="power-device-icon" :class="!m.is_enabled ? 'is-muted' : (m.status==='up' ? 'is-up' : (m.status==='down' ? 'is-down' : (m.status==='warning' ? 'is-warning' : 'is-muted')))">
                                        <i data-lucide="zap"></i>
                                    </span>
                                    <div class="power-device-meta">
                                        <strong x-text="m.name"></strong>
                                        <div class="power-subline">
                                            <span class="power-group-pill mono" x-text="m.group_name"></span>
                                            <span class="muted small" x-show="m.location" x-text="m.location"></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="power-target-cell">
                                    <div class="power-target-top">
                                        <span class="power-ip-pill mono" x-show="m.ip_address" x-text="m.ip_address"></span>
                                        <span class="tag" x-text="m.monitor_type === 'ping' ? 'Ping IP' : (m.monitor_type === 'onu' ? 'ONU OLT' : 'Ping + ONU')"></span>
                                    </div>
                                    <small class="mono muted" x-show="m.onu_label" x-text="m.onu_label"></small>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge" :class="!m.is_enabled ? 'offline' : (m.status === 'up' ? 'online' : (m.status === 'down' ? 'phase-los' : (m.status === 'warning' ? 'phase-reboot' : 'offline')))">
                                    <span class="dot" :class="!m.is_enabled ? 'gray' : (m.status === 'up' ? 'green' : (m.status === 'down' ? 'red' : (m.status === 'warning' ? 'amber' : 'gray')))"></span>
                                    <span x-text="!m.is_enabled ? 'Dijeda' : (m.status === 'up' ? 'Menyala (UP)' : (m.status === 'down' ? 'PADAM' : (m.status === 'warning' ? 'Ping RTO · ONU Working' : 'Belum dicek')))"></span>
                                </span>
                                <small :class="m.status === 'warning' ? 'amber-text' : 'red-text'" x-show="['down','warning'].includes(m.status) && m.last_error" x-text="m.last_error"></small>
                            </td>
                            <td class="mono">
                                <strong x-show="m.status === 'up' && m.last_latency_ms != null" class="green-text power-latency-val" x-text="m.last_latency_ms + ' ms'"></strong>
                                <strong x-show="['down','warning'].includes(m.status)" :class="m.status === 'warning' ? 'amber-text' : 'red-text'" class="power-latency-val" x-text="m.packet_loss != null ? m.packet_loss + '% loss' : 'Offline'"></strong>
                                <span x-show="m.status === 'unknown' || (m.status === 'up' && m.last_latency_ms == null)" class="muted">—</span>
                            </td>
                            <td>
                                <span x-text="m.last_checked_at ? dateTime(m.last_checked_at) : '—'"></span>
                                <small class="red-text" x-show="m.status === 'down' && m.last_down_at" x-text="'Padam sejak ' + time(m.last_down_at)"></small>
                                <small class="muted" x-show="m.status === 'up' && m.last_up_at" x-text="'Normal sejak ' + time(m.last_up_at)"></small>
                            </td>
                            <td class="row-actions power-row-actions" x-show="db.permissions.operate">
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

        <div class="empty-state panel" x-show="!(powerData.monitors || []).length">
            <i data-lucide="zap"></i>
            <strong>Belum ada sensor Monitoring Listrik</strong>
            <p class="muted">Tambahkan IP sensor PLN atau link ke ONU OLT untuk mengaktifkan Ping &amp; Double Check otomatis tiap menit.</p>
            <button type="button" class="btn primary" x-show="db.permissions.operate" @click="newPowerMonitor()">
                <i data-lucide="plus"></i><span>Tambah Sensor Pertama</span>
            </button>
        </div>

        <div class="empty-state panel" x-show="(powerData.monitors || []).length && !filteredPowerMonitors.length">
            <i data-lucide="search"></i>
            <strong>Tidak ada sensor sesuai filter</strong>
            <p class="muted">Ubah kata kunci pencarian, Device Group, atau filter status di atas.</p>
            <button type="button" class="btn secondary" @click="powerFilter={q:'',group:'all',status:'all'}">Reset Filter</button>
        </div>
    </div>

    <div x-show="powerTab==='alerts'" class="power-tab-panel">
        <form class="inline-editor power-editor-card form-stack" x-show="db.permissions.operate" @submit.prevent="savePowerSettings()">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge">Notifikasi</span>
                    <h3>Tujuan Alert Khusus Monitoring Listrik (WA &amp; Telegram)</h3>
                    <p class="muted small">Jika dikosongkan, alert mengikuti grup default dari menu WhatsApp / Telegram utama.</p>
                </div>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" x-model="powerSettingsForm.power_notify_enabled">
                <span><strong>Kirim notifikasi otomatis</strong> saat sensor listrik Padam maupun Menyala Kembali</span>
            </label>
            <div class="form-grid">
                <label>Nomor / Group JID WhatsApp Khusus Listrik
                    <input class="mono" x-model="powerSettingsForm.power_whatsapp_recipients" maxlength="1000" :placeholder="powerData.settings?.default_whatsapp_recipients ? 'Default: ' + powerData.settings.default_whatsapp_recipients : '120363xxxxxx@g.us, 62812xxxx'">
                </label>
                <label>Chat ID Telegram Khusus Listrik
                    <input class="mono" x-model="powerSettingsForm.power_telegram_chat_id" maxlength="255" :placeholder="powerData.settings?.default_telegram_chat_id ? 'Default: ' + powerData.settings.default_telegram_chat_id : '-1001234567890'">
                </label>
                <label>Thread / Topic ID Telegram (opsional)
                    <input class="mono" x-model="powerSettingsForm.power_telegram_thread_id" maxlength="64" placeholder="Kosongkan jika bukan grup topik">
                </label>
                <label>Jeda Anti-Spam Padam Berulang (menit)
                    <input type="number" min="0" max="1440" x-model.number="powerSettingsForm.power_cooldown_minutes" required>
                </label>
            </div>
            <div class="modal-actions" style="margin-top:4px">
                <button type="button" class="btn secondary" :disabled="busy" @click="testPowerAlert()">
                    <i data-lucide="zap"></i><span>Kirim Tes Alert Listrik</span>
                </button>
                <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Pengaturan Listrik'"></button>
            </div>
        </form>

        <section class="power-log-section">
            <div class="power-section-head">
                <h3>Riwayat Alert Monitoring Listrik Terakhir</h3>
                <span class="count-pill" x-text="(powerData.logs || []).length"></span>
            </div>
            <div class="table-scroll power-table-wrap" x-show="(powerData.logs || []).length">
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
            <div class="empty-state panel" x-show="!(powerData.logs || []).length">
                <i data-lucide="send"></i>
                <strong>Belum ada riwayat alert listrik</strong>
                <p class="muted">Log pengiriman WhatsApp/Telegram untuk event listrik padam, pulih, atau tes kirim akan muncul di sini.</p>
            </div>
        </section>
    </div>
</div></template>
