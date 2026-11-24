<section class="power-page" aria-label="Monitoring ONU">
    <div class="page-heading power-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <template x-if="selectedPowerGroup">
                    <button type="button" class="power-crumb-link" @click="closePowerGroup()">Monitoring ONU</button>
                </template>
                <template x-if="!selectedPowerGroup">
                    <span>Monitoring ONU</span>
                </template>
                <template x-if="selectedPowerGroup">
                    <i data-lucide="chevron-right"></i>
                </template>
                <template x-if="selectedPowerGroup">
                    <span x-text="activePowerGroupLabel()"></span>
                </template>
            </div>
            <h1>Monitoring ONU</h1>
            <p class="muted">Pemantauan IP &amp; live status ONU per kategori (contoh: Listrik, Backbone, VIP) dengan Ping otomatis dan Double Check OLT.</p>
        </div>
        <div class="power-page-actions" x-show="db.permissions.operate">
            <button type="button" class="btn secondary" :disabled="powerChecking" @click="checkAllPower()">
                <i data-lucide="refresh-cw" :class="{'spinning':powerChecking}"></i>
                <span x-text="powerChecking ? 'Mengecek Ping...' : 'Cek Ping Sekarang'"></span>
            </button>
            <button type="button" class="btn secondary" @click="newPowerGroup()">
                <i data-lucide="plus"></i><span>Tambah Kategori</span>
            </button>
            <button type="button" class="btn primary" @click="newPowerMonitor()">
                <i data-lucide="plus"></i><span>Tambah Target</span>
            </button>
        </div>
    </div>

    <div class="power-hero-grid is-page">
        <div class="power-kpi is-total">
            <div class="power-kpi-head">
                <span x-text="selectedPowerGroup ? ('Target · ' + activePowerGroupLabel()) : 'Total Target'"></span>
                <span class="power-kpi-icon"><i data-lucide="activity"></i></span>
            </div>
            <strong x-text="activePowerSummary.total ?? 0"></strong>
            <small class="muted" x-text="selectedPowerGroup ? 'IP & ONU dalam kategori ini' : ((powerData.monitor_groups || []).length + ' kategori monitoring')"></small>
        </div>
        <div class="power-kpi is-up">
            <div class="power-kpi-head">
                <span><i class="dot green"></i> Online / Normal</span>
                <span class="power-kpi-icon is-up"><i data-lucide="circle-check"></i></span>
            </div>
            <strong class="green-text" x-text="activePowerSummary.up ?? 0"></strong>
            <small class="muted">Ping / ONU aktif</small>
        </div>
        <div class="power-kpi is-down" :class="{'has-down': (activePowerSummary.down ?? 0) > 0, 'has-warning': (activePowerSummary.down ?? 0) === 0 && (activePowerSummary.warning ?? 0) > 0}">
            <div class="power-kpi-head">
                <span><i class="dot red"></i> Gangguan / Padam</span>
                <span class="power-kpi-icon is-down"><i data-lucide="alert-triangle"></i></span>
            </div>
            <strong class="red-text" x-text="activePowerSummary.down ?? 0"></strong>
            <small class="muted" x-text="(activePowerSummary.warning ?? 0) > 0 ? (activePowerSummary.warning + ' target Ping RTO · ONU Working') : 'Perlu pengecekan'"></small>
        </div>
        <div class="power-kpi is-latency">
            <div class="power-kpi-head">
                <span>Rata-rata Ping</span>
                <span class="power-kpi-icon is-latency"><i data-lucide="activity"></i></span>
            </div>
            <strong class="cyan" x-text="activePowerSummary.avg_latency_ms != null ? activePowerSummary.avg_latency_ms + ' ms' : '—'"></strong>
            <small class="muted">Cek otomatis tiap 1 menit</small>
        </div>
    </div>

    <div class="power-nav-strip">
        <div class="power-tabs" role="tablist" aria-label="Menu Monitoring ONU">
            <button type="button" role="tab" :aria-selected="powerTab==='monitors'" @click="powerTab='monitors'">
                <i data-lucide="layers-2"></i>
                <span>Kategori &amp; Target</span>
                <span class="count-pill" x-text="powerData.summary.total ?? 0"></span>
            </button>
            <button type="button" role="tab" :aria-selected="powerTab==='alerts'" @click="powerTab='alerts'">
                <i data-lucide="message-circle"></i>
                <span>Pengaturan Alert WA / TG</span>
                <span class="power-tab-badge" :class="powerData.settings && !powerData.settings.has_gateway ? 'is-warn' : (powerSettingsForm.power_notify_enabled ? 'is-on' : '')" x-text="powerData.settings && !powerData.settings.has_gateway ? 'Perlu Setup' : (powerSettingsForm.power_notify_enabled ? 'Aktif' : 'Nonaktif')"></span>
            </button>
            <button type="button" role="tab" :aria-selected="powerTab==='logs'" @click="powerTab='logs'">
                <i data-lucide="scroll-text"></i>
                <span>Riwayat Alert</span>
                <span class="count-pill" x-text="(powerData.logs || []).length"></span>
            </button>
        </div>
        <div class="power-live-meta small muted">
            <span class="dot green"></span>
            <span>Auto-check 1 menit · Ping RTO verifikasi live ONU OLT</span>
        </div>
    </div>

    <div x-show="powerTab==='monitors'" class="power-tab-panel">
        <!-- STEP 1: Kotak Pilihan Kategori Monitoring -->
        <template x-if="!selectedPowerGroup">
            <div class="ws-stack">
                <div class="power-section-head">
                    <div>
                        <h3>Pilih Kategori Monitoring</h3>
                        <p class="muted small" style="margin:2px 0 0">Klik kotak kategori (misal <strong>Listrik</strong>) untuk membuka daftar IP &amp; ONU di dalamnya.</p>
                    </div>
                    <button type="button" class="btn secondary" x-show="(powerData.monitors || []).length" @click="openPowerGroup('all')">
                        <i data-lucide="eye"></i>
                        <span x-text="'Lihat Semua Target (' + (powerData.summary.total ?? 0) + ')'"></span>
                    </button>
                </div>

                <div class="group-cards-grid">
                    <template x-for="g in powerData.monitor_groups || []" :key="g.id">
                        <article class="panel group-card" :style="'--group-accent:' + (g.color_hex || '#f59e0b')" @click="if(!$event.target.closest('button')) openPowerGroup(String(g.id))">
                            <div class="group-card-head">
                                <div class="group-card-title">
                                    <span class="group-color-dot" :style="'background:' + (g.color_hex || '#f59e0b')"></span>
                                    <div>
                                        <h3 x-text="g.name"></h3>
                                        <p class="muted small" x-show="g.description" x-text="g.description"></p>
                                    </div>
                                </div>
                                <div class="group-card-actions" x-show="db.permissions.operate">
                                    <button type="button" class="icon-button" @click.stop="editPowerGroup(g)" title="Edit kategori"><i data-lucide="pencil"></i></button>
                                    <button type="button" class="icon-button red-text" @click.stop="deletePowerGroup(g)" title="Hapus kategori"><i data-lucide="trash-2"></i></button>
                                </div>
                            </div>
                            <div class="group-vlan-pills" x-show="g.whatsapp_recipients || g.telegram_chat_id || g.down_message_template || g.up_message_template">
                                <span class="power-sn-pill mono" x-show="g.whatsapp_recipients" x-text="'WA: ' + g.whatsapp_recipients"></span>
                                <span class="group-keyword-pill mono" x-show="g.telegram_chat_id" x-text="'TG: ' + g.telegram_chat_id"></span>
                                <span class="tag" x-show="g.down_message_template || g.up_message_template">Redaksi Custom</span>
                            </div>
                            <div class="group-metrics">
                                <div>
                                    <span class="muted small">Total</span>
                                    <strong x-text="g.total"></strong>
                                </div>
                                <div>
                                    <span class="muted small">Online</span>
                                    <strong class="green-text" x-text="g.up"></strong>
                                </div>
                                <div>
                                    <span class="muted small">Warning</span>
                                    <strong class="amber-text" x-text="g.warning"></strong>
                                </div>
                                <div>
                                    <span class="muted small">Gangguan</span>
                                    <strong class="red-text" x-text="g.down"></strong>
                                </div>
                            </div>
                            <div class="group-progress">
                                <div class="onu-track"><span :style="'width:' + g.percent + '%;background:' + (g.color_hex || 'var(--green)')"></span></div>
                                <span class="small muted mono" x-text="g.percent + '% normal'"></span>
                            </div>
                            <div class="power-box-footer">
                                <span class="small muted" x-text="g.total > 0 ? (g.total + ' IP / ONU terpantau') : 'Belum ada target'"></span>
                                <span class="detail-link">Buka daftar <i data-lucide="arrow-right"></i></span>
                            </div>
                        </article>
                    </template>

                    <article class="panel group-card is-ungrouped" x-show="(powerData.ungrouped_count ?? 0) > 0" @click="openPowerGroup('ungrouped')">
                        <div class="group-card-head">
                            <div class="group-card-title">
                                <span class="group-color-dot" style="background:var(--muted)"></span>
                                <div>
                                    <h3>Tanpa Kategori</h3>
                                    <p class="muted small">Target IP &amp; ONU yang belum masuk kategori mana pun</p>
                                </div>
                            </div>
                        </div>
                        <div class="group-metrics">
                            <div>
                                <span class="muted small">Total</span>
                                <strong class="amber-text" x-text="powerData.ungrouped_count ?? 0"></strong>
                            </div>
                        </div>
                        <div class="power-box-footer">
                            <span class="small muted">Klik untuk melihat &amp; pindahkan</span>
                            <span class="detail-link">Buka daftar <i data-lucide="arrow-right"></i></span>
                        </div>
                    </article>

                    <button type="button" class="panel group-card group-add-card" x-show="db.permissions.operate" @click="newPowerGroup()">
                        <span class="group-add-icon"><i data-lucide="plus"></i></span>
                        <strong>Tambah Kategori Baru</strong>
                        <span class="muted small">Contoh: Listrik, Backbone, POP Utama, Pelanggan VIP</span>
                    </button>
                </div>
            </div>
        </template>

        <!-- STEP 2: Daftar IP + ONU di Dalam Kotak Kategori yang Diklik -->
        <template x-if="selectedPowerGroup">
            <div class="ws-stack">
                <div class="panel power-group-banner" :style="'--group-accent:' + (activePowerGroupObj()?.color_hex || 'var(--cyan)')">
                    <div class="power-group-banner-left">
                        <button type="button" class="btn secondary power-back-btn" @click="closePowerGroup()">
                            <i data-lucide="arrow-left"></i><span>Semua Kategori</span>
                        </button>
                        <div class="power-group-banner-meta">
                            <div class="power-group-banner-title">
                                <span class="group-color-dot" :style="'background:' + (activePowerGroupObj()?.color_hex || 'var(--cyan)')"></span>
                                <h3 x-text="activePowerGroupLabel()"></h3>
                                <span class="count-pill" x-text="scopedPowerMonitors.length + ' target'"></span>
                            </div>
                            <p class="muted small" x-show="activePowerGroupObj()?.description" x-text="activePowerGroupObj()?.description" style="margin:2px 0 0"></p>
                        </div>
                    </div>
                    <div class="power-group-banner-right">
                        <div class="power-group-switcher">
                            <template x-for="g in powerData.monitor_groups || []" :key="'sw-'+g.id">
                                <button type="button" class="vlan-chip" :class="{'is-active': selectedPowerGroup === String(g.id)}" @click="openPowerGroup(String(g.id))">
                                    <span class="dot" :style="'background:' + (g.color_hex || '#f59e0b')"></span>
                                    <span x-text="g.name"></span>
                                    <span class="power-filter-count" x-text="g.total"></span>
                                </button>
                            </template>
                            <button type="button" class="vlan-chip" :class="{'is-active': selectedPowerGroup === 'all'}" @click="openPowerGroup('all')">
                                <span>Semua</span>
                                <span class="power-filter-count" x-text="powerData.summary.total ?? 0"></span>
                            </button>
                        </div>
                        <button type="button" class="btn secondary power-back-btn" x-show="db.permissions.operate && activePowerGroupObj()" @click="editPowerGroup(activePowerGroupObj())" title="Edit kategori & redaksi pesan">
                            <i data-lucide="pencil"></i><span>Edit &amp; Redaksi</span>
                        </button>
                    </div>
                </div>

                <div class="power-filterbar">
                    <div class="onu-quick-filters" role="group" aria-label="Filter cepat status monitoring">
                        <button type="button" @click="powerFilter.status='all'" :aria-pressed="powerFilter.status==='all'">
                            <span>Semua</span>
                            <span class="power-filter-count" x-text="activePowerSummary.total ?? 0"></span>
                        </button>
                        <button type="button" @click="powerFilter.status='up'" :aria-pressed="powerFilter.status==='up'">
                            <span class="dot green"></span>
                            <span>Online</span>
                            <span class="power-filter-count" x-text="activePowerSummary.up ?? 0"></span>
                        </button>
                        <button type="button" @click="powerFilter.status='warning'" :aria-pressed="powerFilter.status==='warning'" x-show="(activePowerSummary.warning ?? 0) > 0 || powerFilter.status === 'warning'">
                            <span class="dot amber"></span>
                            <span>Warning</span>
                            <span class="power-filter-count" x-text="activePowerSummary.warning ?? 0"></span>
                        </button>
                        <button type="button" @click="powerFilter.status='down'" :aria-pressed="powerFilter.status==='down'">
                            <span class="dot red"></span>
                            <span>Gangguan</span>
                            <span class="power-filter-count" x-text="activePowerSummary.down ?? 0"></span>
                        </button>
                    </div>
                    <div class="power-filterbar-inputs">
                        <label class="modal-search power-search">
                            <i data-lucide="search"></i>
                            <input x-model="powerFilter.q" placeholder="Cari nama target, lokasi, IP, atau SN ONU..." aria-label="Cari target monitoring">
                        </label>
                    </div>
                </div>

                <div class="table-scroll power-table-wrap is-fullpage" x-show="filteredPowerMonitors.length">
                    <table class="power-table">
                        <thead>
                            <tr>
                                <th>Target / Kategori</th>
                                <th>IP &amp; ONU Terkait</th>
                                <th>Status</th>
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
                                                <i data-lucide="activity"></i>
                                            </span>
                                            <div class="power-device-meta">
                                                <strong x-text="m.name"></strong>
                                                <div class="power-subline">
                                                    <span class="tag" x-show="m.monitor_group_name">
                                                        <span class="dot" :style="'background:' + (m.monitor_group_color || '#f59e0b')"></span>
                                                        <span x-text="m.monitor_group_name"></span>
                                                    </span>
                                                    <span class="power-group-pill mono" x-show="!m.monitor_group_name && m.group_name" x-text="m.group_name"></span>
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
                                                <span class="power-sn-pill mono" x-show="m.onu?.sn" x-text="m.onu.sn" title="Serial Number ONU"></span>
                                            </div>
                                            <small class="mono muted" x-show="m.onu_label" x-text="m.onu_label"></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge" :class="!m.is_enabled ? 'offline' : (m.status === 'up' ? 'online' : (m.status === 'down' ? 'phase-los' : (m.status === 'warning' ? 'phase-reboot' : 'offline')))">
                                            <span class="dot" :class="!m.is_enabled ? 'gray' : (m.status === 'up' ? 'green' : (m.status === 'down' ? 'red' : (m.status === 'warning' ? 'amber' : 'gray')))"></span>
                                            <span x-text="!m.is_enabled ? 'Dijeda' : (m.status === 'up' ? 'Normal (UP)' : (m.status === 'down' ? 'OFFLINE / PADAM' : (m.status === 'warning' ? 'Ping RTO · ONU Working' : 'Belum dicek')))"></span>
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
                                        <small class="red-text" x-show="m.status === 'down' && m.last_down_at" x-text="'Down sejak ' + time(m.last_down_at)"></small>
                                        <small class="muted" x-show="m.status === 'up' && m.last_up_at" x-text="'Normal sejak ' + time(m.last_up_at)"></small>
                                    </td>
                                    <td x-show="db.permissions.operate">
                                        <div class="row-actions power-row-actions">
                                            <button type="button" class="icon-button" x-show="m.monitor_type !== 'onu'" @click="checkOnePower(m)" :disabled="powerCheckingId === m.id" title="Cek Ping sekarang">
                                                <i data-lucide="refresh-cw" :class="{'spinning':powerCheckingId === m.id}"></i>
                                            </button>
                                            <button type="button" class="icon-button" @click="editPowerMonitor(m)" title="Edit target">
                                                <i data-lucide="pencil"></i>
                                            </button>
                                            <button type="button" class="icon-button red-text" @click="deletePowerMonitor(m)" title="Hapus target">
                                                <i data-lucide="trash-2"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="empty-state panel" x-show="!scopedPowerMonitors.length">
                    <i data-lucide="activity"></i>
                    <strong x-text="'Belum ada target IP / ONU di kategori ' + activePowerGroupLabel()"></strong>
                    <p class="muted">Tambahkan IP target dan/atau pilih ONU dari OLT untuk memantau statusnya otomatis tiap menit.</p>
                    <button type="button" class="btn primary" x-show="db.permissions.operate" @click="newPowerMonitor()">
                        <i data-lucide="plus"></i><span x-text="'Tambah Target ke ' + activePowerGroupLabel()"></span>
                    </button>
                </div>

                <div class="empty-state panel" x-show="scopedPowerMonitors.length && !filteredPowerMonitors.length">
                    <i data-lucide="search"></i>
                    <strong>Tidak ada target sesuai filter</strong>
                    <p class="muted">Ubah kata kunci pencarian atau filter status di atas.</p>
                    <button type="button" class="btn secondary" @click="powerFilter={q:'',group:'all',status:'all'}">Reset Filter</button>
                </div>
            </div>
        </template>
    </div>

    <div x-show="powerTab==='alerts'" class="power-tab-panel">
        <div class="notice" x-show="powerData.settings && !powerData.settings.has_gateway">
            <div>
                <strong>Gateway WhatsApp / Telegram belum aktif.</strong>
                <p class="muted small">Atur koneksi Gateway WhatsApp atau Bot Telegram utama terlebih dahulu agar alert Monitoring ONU dapat terkirim.</p>
            </div>
            <button type="button" class="btn secondary" style="margin-left:auto" @click="open('whatsapp')">Atur WhatsApp</button>
        </div>

        <form class="panel power-settings-card form-stack" x-show="db.permissions.operate" @submit.prevent="savePowerSettings()">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge">Notifikasi Monitoring ONU</span>
                    <h3>Tujuan Alert Monitoring ONU (WA &amp; Telegram)</h3>
                    <p class="muted small">Kosongkan kolom grup bila ingin mengikuti grup default dari menu WhatsApp / Telegram utama.</p>
                </div>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" x-model="powerSettingsForm.power_notify_enabled">
                <span><strong>Kirim notifikasi otomatis</strong> saat target Monitoring ONU mengalami gangguan maupun pulih kembali</span>
            </label>
            <div class="form-grid">
                <label>Nomor / Group JID WhatsApp Monitoring ONU
                    <input class="mono" x-model="powerSettingsForm.power_whatsapp_recipients" maxlength="1000" :placeholder="powerData.settings?.default_whatsapp_recipients ? 'Default: ' + powerData.settings.default_whatsapp_recipients : '120363xxxxxx@g.us, 62812xxxx'">
                </label>
                <label>Chat ID Telegram Monitoring ONU
                    <input class="mono" x-model="powerSettingsForm.power_telegram_chat_id" maxlength="255" :placeholder="powerData.settings?.default_telegram_chat_id ? 'Default: ' + powerData.settings.default_telegram_chat_id : '-1001234567890'">
                </label>
                <label>Thread / Topic ID Telegram (opsional)
                    <input class="mono" x-model="powerSettingsForm.power_telegram_thread_id" maxlength="64" placeholder="Kosongkan jika bukan grup topik">
                </label>
                <label>Jeda Anti-Spam Alert Berulang (menit)
                    <input type="number" min="0" max="1440" x-model.number="powerSettingsForm.power_cooldown_minutes" required>
                </label>
            </div>

            <div class="power-redaksi-box">
                <div class="power-redaksi-head">
                    <div>
                        <strong>Custom Redaksi Pesan Alert Default (WA &amp; Telegram)</strong>
                        <p class="muted small">Atur format teks pesan saat terjadi gangguan (DOWN) dan saat pulih kembali (UP). Anda juga dapat membuat redaksi berbeda untuk tiap kategori melalui tombol <strong>Edit</strong> pada kotak kategori (misal: Listrik vs ERABLUE).</p>
                    </div>
                    <div class="power-redaksi-actions">
                        <button type="button" class="btn secondary vlan-toggle-btn" @click="fillDefaultGlobalPowerTemplates()">Isi Template Standar</button>
                        <button type="button" class="btn secondary vlan-toggle-btn" x-show="powerSettingsForm.power_down_template || powerSettingsForm.power_up_template" @click="powerSettingsForm.power_down_template='';powerSettingsForm.power_up_template=''">Reset Default</button>
                    </div>
                </div>

                <div class="power-token-bar">
                    <span class="muted small">Klik variabel untuk sisipkan:</span>
                    <template x-for="tk in powerTemplateTokens" :key="'stk-'+tk">
                        <button type="button" class="power-token-chip mono" @click="insertPowerToken(powerSettingsForm,'power_down_template','power_up_template',tk)" x-text="tk"></button>
                    </template>
                </div>

                <div class="form-grid">
                    <label>Redaksi Default Saat Gangguan / Padam (DOWN)
                        <textarea class="mono power-redaksi-textarea" rows="8" maxlength="3000" x-model="powerSettingsForm.power_down_template" @focus="powerActiveTemplateField='down'" :placeholder="defaultPowerTemplate('down', 'Listrik')"></textarea>
                    </label>
                    <label>Redaksi Default Saat Pulih / Online Kembali (UP)
                        <textarea class="mono power-redaksi-textarea" rows="8" maxlength="3000" x-model="powerSettingsForm.power_up_template" @focus="powerActiveTemplateField='up'" :placeholder="defaultPowerTemplate('up', 'Listrik')"></textarea>
                    </label>
                </div>

                <div class="form-grid power-preview-grid">
                    <div>
                        <span class="muted small">Preview Pesan Gangguan (DOWN)</span>
                        <pre class="power-preview-bubble mono" x-text="previewPowerTemplate(powerSettingsForm.power_down_template, 'down', 'Listrik')"></pre>
                    </div>
                    <div>
                        <span class="muted small">Preview Pesan Pulih (UP)</span>
                        <pre class="power-preview-bubble mono" x-text="previewPowerTemplate(powerSettingsForm.power_up_template, 'up', 'Listrik')"></pre>
                    </div>
                </div>
            </div>

            <div class="modal-actions" style="margin-top:4px">
                <button type="button" class="btn secondary" :disabled="busy" @click="testPowerAlert()">
                    <i data-lucide="send"></i><span>Kirim Tes Alert</span>
                </button>
                <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Pengaturan'"></button>
            </div>
        </form>
    </div>

    <div x-show="powerTab==='logs'" class="power-tab-panel">
        <div class="table-scroll power-table-wrap is-fullpage" x-show="(powerData.logs || []).length">
            <table class="power-table">
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
                            <td><span class="status-badge" :class="log.event_type==='power_down'?'phase-los':(log.event_type==='power_up'?'online':'offline')" x-text="log.event_type==='power_down'?'Gangguan / Padam':(log.event_type==='power_up'?'Pulih Kembali':'Tes Alert')"></span></td>
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
            <strong>Belum ada riwayat alert Monitoring ONU</strong>
            <p class="muted">Log pengiriman WhatsApp/Telegram untuk event gangguan, pulih, atau tes kirim akan muncul di sini.</p>
        </div>
    </div>
</section>
