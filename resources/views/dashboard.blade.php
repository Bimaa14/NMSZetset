@extends('layout')
@section('body')
<div x-data="dashboard" @keydown.escape.window.prevent="if($refs.onuToolDialog.open)closeOnuTool();else if($refs.trafficDialog.open)closeTraffic();else if($refs.onuDialog.open)closeOnuInspector();else closeModal()" @keydown.ctrl.k.window.prevent="$refs.search.focus()" @keydown.meta.k.window.prevent="$refs.search.focus()">
    <a class="skip-link" href="#main">Lewati ke konten</a>
    <header class="site-header">
        <div class="top-row">
            <a class="brand" href="/" aria-label="NMS OLT ZTE dashboard"><span class="brand-mark"><i data-lucide="server"></i></span><span>NMS OLT <b>ZTE</b><small>NETWORK MANAGEMENT SYSTEM</small></span></a>
            <div class="topbar-context"><span class="workspace-icon"><i data-lucide="layers-2"></i></span><span x-text="db.tenant.name"></span><i data-lucide="chevron-right"></i><strong>Overview</strong></div>
            <div class="header-right"><span class="refresh-state" :class="{'is-refreshing':pollBusy}"><span class="dot" :class="runtimeHealthy?'green':'amber'"></span><span x-text="pollBusy?'Memperbarui…':runtimeHealthy?'Monitoring aktif':'Monitor tertunda'"></span></span>
                <div class="theme-switch" role="group" aria-label="Tema tampilan">
                    <button @click="setTheme('light')" :class="{active:theme==='light'}" :aria-pressed="theme==='light'" title="Light" aria-label="Tema Light"><i data-lucide="sun"></i></button>
                    <button @click="setTheme('dark')" :class="{active:theme==='dark'}" :aria-pressed="theme==='dark'" title="Dark" aria-label="Tema Dark"><i data-lucide="moon"></i></button>
                    <button @click="setTheme('system')" :class="{active:theme==='system'}" :aria-pressed="theme==='system'" title="System" aria-label="Tema System"><i data-lucide="monitor"></i></button>
                </div>
                <span class="header-divider"></span>
                <button class="profile-button" @click="open('profile')"><span class="avatar">BP</span><span class="profile-copy"><strong x-text="db.user.name"></strong><small x-text="roleLabel + (db.user.role === 'isp_owner' ? ' • ' + db.tenant.plan_type : '')"></small></span><i data-lucide="chevron-down"></i></button>
                <form action="/logout" method="post">@csrf<button class="btn logout" type="submit"><i data-lucide="log-out"></i><span>Keluar</span></button></form>
            </div>
        </div>
        <nav class="nav-row" aria-label="Navigasi utama">
            <span class="nav-caption">Workspace</span>
            <a class="nav-item active" href="/" aria-current="page"><i data-lucide="layout-dashboard"></i>Dashboard</a>
            <button class="nav-item" @click="open('onus')"><i data-lucide="router"></i>ONU</button>
            <span class="nav-divider"></span>
            <button class="nav-item" @click="open('profile')"><i data-lucide="circle-user-round"></i>Profil</button>
            <button class="nav-item" @click="open('whatsapp')"><i data-lucide="message-circle"></i>WhatsApp</button>
            <button class="nav-item" @click="open('telegram')"><i data-lucide="send"></i>Telegram</button>
            <button class="nav-item" x-show="db.permissions.api" @click="open('api')"><i data-lucide="code-xml"></i>API</button>
            <button class="nav-item" x-show="db.permissions.operate" @click="open('profiles')"><i data-lucide="layers-2"></i>Profil layanan</button>
            <button class="nav-item" x-show="db.permissions.tags" @click="open('tags')"><i data-lucide="tags"></i>Tags</button>
            <template x-if="db.subscription && db.permissions.subscription"><div class="nav-group">
                <span class="nav-divider"></span>
                <button class="nav-item" @click="open('vpn')"><i data-lucide="shield-check"></i>VPN</button>
                <button class="nav-item" @click="open('balance')"><i data-lucide="wallet"></i>Saldo</button>
                <button class="nav-item" @click="open('affiliate')"><i data-lucide="users-round"></i>Affiliate</button>
            </div></template>
            <span class="nav-divider" x-show="db.permissions.staff"></span>
            <button class="nav-item" x-show="db.permissions.staff" @click="open('staff')"><i data-lucide="contact-round"></i>Staff</button>
            <button class="nav-item" x-show="db.permissions.audit" @click="open('audit')"><i data-lucide="scroll-text"></i>Audit</button>
            <span class="nav-tenant"><span class="dot green"></span><span x-text="db.tenant.name"></span></span>
        </nav>
    </header>

    <main id="main" class="main-container">
        <div class="page-heading">
            <div><div class="breadcrumb">Workspace <i data-lucide="chevron-right"></i> <span>Dashboard</span></div><h1>Dashboard Jaringan<span class="title-dot"></span></h1><p class="muted">Pantau konektivitas. Kelola perangkat. Jaga jaringan tetap optimal.</p></div>
            <div class="workspace-status"><span class="lan-badge"><i data-lucide="network"></i> Jaringan lokal kantor</span><span class="muted small" x-show="db.demo"><span class="dot amber"></span> Lingkungan demo</span></div>
        </div>





        @include('partials.skeleton',['show'=>'!uiReady','boot'=>true])
        <div class="dashboard-content" x-show="uiReady" x-cloak>
        <section class="overview-stack" aria-label="Ringkasan jaringan">
            <div class="panel equipment-panel">
                <button class="overview-bar" @click="equipmentOpen=!equipmentOpen" :aria-expanded="equipmentOpen" aria-controls="equipment-details"><span class="bar-icon cyan"><i data-lucide="server-cog"></i></span><strong>Ringkasan Equipment</strong><span class="bar-time">diperbarui <span x-text="db.equipment_updated_at"></span></span><i data-lucide="chevron-down" class="chevron" :class="{'rotated':equipmentOpen}"></i></button>
                <div id="equipment-details" class="equipment-grid" x-show="equipmentOpen">
                    <button type="button" class="equipment-stat stat-card-olt" @click="statusFilter='all';query='';$nextTick(() => document.getElementById('olt-title').scrollIntoView({block:'center'}))" aria-label="Lihat semua OLT"><span class="stat-symbol cyan"><i data-lucide="server"></i></span><div><span class="muted small">Total chassis OLT</span><strong x-text="db.olts.length"></strong></div><span class="stat-note"><span class="dot green"></span><span x-text="onlineOlts.length+' online'"></span></span></button>
                    <button type="button" class="equipment-stat stat-card-slot" @click="open('equipment')" aria-label="Lihat card dan slot semua OLT"><span class="stat-symbol purple"><i data-lucide="cpu"></i></span><div><span class="muted small">Card / Slot aktif</span><strong x-text="slotCount"></strong></div><span class="stat-note"><span class="dot purple-dot"></span><span x-text="portCount+' port PON'"></span></span></button>
                    <button type="button" class="equipment-stat stat-card-online" @click="open('onus');onuStatus='working'" aria-label="Lihat ONU online semua OLT"><span class="stat-symbol green"><i data-lucide="radio-tower"></i></span><div><span class="muted small">ONU online</span><strong x-text="db.summary.online_onus"></strong></div><span class="stat-note"><span class="dot green"></span><span x-text="'dari '+db.summary.total_onus+' ONU'"></span></span></button>
                    <button type="button" class="equipment-stat stat-card-offline" @click="open('onus');onuStatus='nonworking'" aria-label="Lihat semua ONU yang tidak online"><span class="stat-symbol amber"><i data-lucide="unplug"></i></span><div><span class="muted small">ONU offline</span><strong class="amber-text" x-text="db.summary.total_onus-db.summary.online_onus"></strong></div><span class="stat-note"><span class="dot amber"></span><span>Perlu pemeriksaan</span></span></button>
                    <button type="button" class="equipment-stat equipment-stat-loss stat-card-loss" :class="{'has-loss-alert': (db.summary.los_onus ?? 0) > 0}" @click="open('onus');onuStatus='los'" aria-label="Lihat ONU LOSS dari semua OLT"><span class="stat-symbol red"><i data-lucide="alert-triangle"></i></span><div><span class="muted small">ONU LOSS</span><strong class="red-text" x-text="db.summary.los_onus ?? 0"></strong></div><span class="stat-note">Lihat LOS semua OLT <i data-lucide="arrow-right"></i></span></button>
                </div>
            </div>
            <button class="panel overview-bar degradation-bar" @click="open('degradation')"><span class="bar-icon red"><i data-lucide="activity"></i></span><strong>Redaman Memburuk</strong><span class="alert-count" x-text="db.summary.degraded_onus"></span><span class="degradation-description">ONU memerlukan perhatian</span><span class="bar-time">dihitung <span x-text="db.optical_calculated_at"></span></span><span class="detail-link">Lihat detail<i data-lucide="arrow-right"></i></span></button>
        </section>

        <section class="search-section" aria-label="Pencarian global ONU">
            <div class="global-search"><i data-lucide="search"></i><label class="sr-only" for="global-search">Cari ONU berdasarkan nama, SN, deskripsi, atau tag</label><input id="global-search" x-ref="search" x-model.debounce.250ms="query" type="search" autocomplete="off" placeholder="Cari ONU berdasarkan nama, SN, deskripsi, atau tag..."><kbd>Ctrl K</kbd></div>
            <div class="search-results panel" x-show="query.trim()" x-cloak>
                <div class="results-heading"><strong>Hasil pencarian ONU <span class="count-pill" x-text="searchTotal"></span></strong><button class="text-button" @click="query=''">Bersihkan</button></div>
                <div class="table-scroll"><table><thead><tr><th>Pelanggan / Serial number</th><th>OLT / Interface</th><th>Status</th><th>Rx optik</th><th>Tag</th></tr></thead><tbody><template x-for="onu in filteredOnus" :key="onu.id"><tr class="onu-search-row" @click="inspectOnu(onu)"><td><button type="button" class="text-button onu-search-link" aria-haspopup="dialog" @click.stop="inspectOnu(onu)" x-text="onuDisplayName(onu)"></button><small class="mono" x-text="onu.sn"></small><small x-text="onu.description"></small></td><td><span x-text="oltName(onu.olt_id)"></span><small class="mono" x-text="onu.interface"></small></td><td><span class="status-badge" :class="onu.phase_state==='working'?'online':'offline'" x-text="onu.phase_state==='working'?'Online':'Offline'"></span></td><td class="mono" :class="onu.rx_power <= db.threshold?'red-text':''" x-text="rx(onu.rx_power)"></td><td><template x-for="tag in onu.tags"><span class="tag" x-text="tag.tag_name"></span></template></td></tr></template></tbody></table></div>
                <p class="muted small" x-show="searchTotal>100">Menampilkan 100 hasil pertama. Persempit pencarian atau gunakan direktori ONU untuk seluruh hasil.</p><div class="empty-state" x-show="!filteredOnus.length"><i data-lucide="search-x"></i><strong>ONU tidak ditemukan</strong><p>Coba nama, serial number, deskripsi, atau tag lainnya.</p></div>
            </div>
        </section>

        <p class="small muted live-summary"><span class="dot" :class="runtimeHealthy?'green':'amber'"></span><span class="amber-text" x-show="!runtimeHealthy">Pemantauan perangkat tertunda. </span> Layar diperbarui otomatis &middot; status {{ (int) ceil(config('olt.state_interval') / 60) }} menit &middot; optik {{ (int) ceil(config('olt.optical_interval') / 60) }} menit <span class="amber-text" x-show="liveError" x-text="liveError"></span></p>
        <section class="olts-section" aria-labelledby="olt-title">
            <div class="section-heading"><div class="section-title"><h2 id="olt-title">OLT Anda</h2><span class="count-pill" x-text="db.olts.length"></span><span class="section-caption">Semua perangkat, satu kendali.</span></div><div class="section-actions"><button class="btn secondary" @click="open('maps')" x-show="db.permissions.maps"><i data-lucide="map"></i>FTTH Maps</button><button class="btn primary" @click="addOlt()" x-show="db.permissions.manage"><i data-lucide="plus"></i>Tambah OLT</button></div></div>
            <div class="olt-toolbar"><div class="filter-tabs" role="group" aria-label="Filter status OLT"><button :class="{selected:statusFilter==='all'}" @click="statusFilter='all'" :aria-pressed="statusFilter==='all'">Semua OLT<span x-text="db.olts.length"></span></button><button :class="{selected:statusFilter==='Online'}" @click="statusFilter='Online'" :aria-pressed="statusFilter==='Online'"><span class="dot green"></span>Online<span x-text="onlineOlts.length"></span></button><button :class="{selected:statusFilter==='Offline'}" @click="statusFilter='Offline'" :aria-pressed="statusFilter==='Offline'"><span class="dot gray"></span>Offline<span x-text="db.olts.length-onlineOlts.length"></span></button></div><label class="sort-label">Urutkan:<select x-model="sort"><option value="default">Urutan perangkat</option><option value="name">Nama A–Z</option><option value="onu">ONU terbanyak</option></select></label></div>
            <div class="olt-grid">
                <template x-for="(olt,cardIndex) in visibleOlts" :key="olt.id">
                    <article class="olt-card" :class="{'olt-card-online':olt.status==='Online','olt-card-offline':olt.status!=='Online','olt-card-error':!!olt.last_connection_error}" :aria-label="olt.name" :style="'--card-index:'+cardIndex" @click="if(!$event.target.closest('button'))open('onus',olt)">
                        <div class="card-header"><span class="device-icon"><i data-lucide="server"></i></span><div class="card-shortcuts"><button class="icon-button" @click="open('info',olt)" :aria-label="'Info '+olt.name" title="Informasi OLT"><i data-lucide="info"></i></button><button class="icon-button" @click="editOlt(olt)" x-show="db.permissions.manage" :aria-label="'Edit '+olt.name" title="Edit OLT"><i data-lucide="pencil"></i></button><button type="button" class="icon-button cli-shortcut" @click.stop="openCli(olt)" x-show="db.permissions.cli && supportsFeature(olt,'cli')" :aria-label="'Web CLI '+olt.name" title="Web CLI"><i data-lucide="terminal"></i></button></div></div>
                        <h3 x-text="olt.name"></h3><p class="card-location"><i data-lucide="map-pin"></i><span x-text="olt.location||'Lokasi belum diatur'"></span></p>
                        <div class="card-connection"><span class="status-badge" :class="olt.status==='Online'?'online':'offline'"><span class="dot" :class="olt.last_connection_error?'red':olt.status==='Online'?'green':'gray'"></span><span x-text="olt.status+' ('+olt.chassis_type+')'"></span></span><span class="ip-address"><i data-lucide="ethernet-port"></i><span x-text="olt.ip_address+':'+olt.telnet_port"></span></span></div>
                        <div class="olt-mini-rack" x-show="olt.ports && olt.ports.length" aria-hidden="true">
                            <span class="mini-rack-label" x-text="olt.chassis_type"></span>
                            <div class="mini-rack-leds">
                                <template x-for="p in (olt.ports || []).slice(0, 16)" :key="p.id">
                                    <span class="mini-port-led" :class="p.online_onu > 0 ? 'led-on' : (p.total_onu > 0 ? 'led-warn' : 'led-idle')" :title="p.interface_name + ': ' + p.online_onu + '/' + p.total_onu + ' ONU'"></span>
                                </template>
                            </div>
                            <span class="mini-rack-more" x-show="(olt.ports || []).length > 16" x-text="'+' + (olt.ports.length - 16)"></span>
                        </div>
                        <div class="card-telemetry"><div><span class="muted">ONU online</span><strong><span x-text="olt.online_onu"></span><span class="muted" x-text="' / '+olt.onus_count"></span></strong></div><div class="onu-track"><span :style="'width:'+(olt.onus_count?olt.online_onu/olt.onus_count*100:0)+'%'"></span></div><span class="port-count"><i data-lucide="git-fork"></i><span x-text="olt.ports_count+' port'"></span></span></div>
                        <p class="muted small" style="padding:0 22px" x-show="isYotc(olt)&&olt.cli_transport==='http'">HTTP: koneksi tidak terenkripsi</p>
                        <p class="muted small" style="padding:0 22px" x-show="olt.connection_mode==='real' && supportsFeature(olt,'traffic')" x-text="trafficCoverageLabel(olt)"></p><div class="card-actions"><button class="action view" @click="open('view',olt)"><i data-lucide="eye"></i>View</button><button class="action port" @click="open('ports',olt)"><i data-lucide="git-fork"></i>Port</button><button class="action sync" @click="sync(olt)" :disabled="syncing.includes(olt.id)" x-show="db.permissions.operate"><i data-lucide="refresh-cw" :class="{'spinning':syncing.includes(olt.id)}"></i>Sync</button><button class="action onu" @click="open('onus',olt)"><i data-lucide="router"></i>ONU</button><button class="action delete" @click="open('delete',olt)" x-show="db.permissions.manage"><i data-lucide="trash-2"></i>Hapus</button></div>
                        <template x-if="visibleOperation(olt)"><div class="operation-progress" :class="{'red-text':visibleOperation(olt).status==='failed'}"><div><span x-text="visibleOperation(olt).current_step"></span><span x-show="isActive(visibleOperation(olt))" x-text="visibleOperation(olt).progress+'%'"></span></div><button class="text-button" x-show="visibleOperation(olt).status==='queued'&&db.permissions.operate" @click="cancelOperation(olt)">Batalkan antrean</button><progress x-show="isActive(visibleOperation(olt))" max="100" :value="visibleOperation(olt).progress" :aria-label="'Progres '+olt.name"></progress></div></template>
                        <button class="provision-shortcut text-button" @click="open('provision',olt)" x-show="db.permissions.operate && supportsFeature(olt,'provision')"><i data-lucide="plus"></i>Pasang ONU</button>
                        <div class="card-footer"><span class="dot" :class="olt.last_connection_error?'red':olt.status==='Online'?'green':'gray'"></span><span x-text="olt.last_connection_error ? 'Koneksi gagal - snapshot terakhir dipertahankan' : olt.last_sync_at ? 'Sync terakhir '+time(olt.last_sync_at) : 'Belum pernah sinkronisasi'"></span><span class="device-family" x-text="olt.connection_mode==='real'?'REAL':'SIMULATOR'"></span></div>
                    </article>
                </template>
            </div>
            <div class="empty-state panel" x-show="!visibleOlts.length"><i data-lucide="server-off"></i><strong>Tidak ada OLT <span x-text="statusFilter==='all'?'':statusFilter.toLowerCase()"></span></strong><p x-text="query.trim()?'Tidak ada OLT yang memiliki ONU sesuai pencarian Anda.':'Perangkat akan muncul di sini setelah ditambahkan.'"></p><button class="btn secondary" @click="statusFilter='all';query=''">Tampilkan semua OLT</button></div>
            <div class="grid-caption"><span>Menampilkan <strong x-text="visibleOlts.length"></strong> dari <span x-text="db.olts.length"></span> perangkat</span><span><i data-lucide="lock-keyhole"></i>Data terisolasi untuk <span x-text="db.tenant.name"></span></span></div>
        </section>
        <section class="quota-banner" x-show="db.subscription" aria-label="Kuota langganan OLT">
            <span class="quota-icon"><i data-lucide="layers-2"></i></span><div class="quota-plan"><strong>Paket <span x-text="db.tenant.plan_type"></span><span class="premium-label" x-show="db.tenant.plan_type==='Premium'">PREMIUM</span></strong><span class="muted small">Kelola lebih banyak. Jangkau lebih luas.</span></div>
            <div class="quota-details"><div><span class="muted small">Kapasitas paket</span><strong><span x-text="db.tenant.olt_quota"></span> OLT</strong></div><div><span class="muted small">Aktif hingga</span><strong x-text="expiry"></strong></div><div class="quota-usage"><div><span class="muted small">Kuota terpakai</span><strong><span x-text="db.olts.length"></span><span class="muted">/<span x-text="db.tenant.olt_quota"></span></span></strong></div><div class="progress-track"><span :style="'width:'+Math.min(100,db.olts.length/db.tenant.olt_quota*100)+'%'"></span></div></div></div>
            <button class="btn upgrade" @click="open('upgrade')" x-show="db.permissions.subscription"><i data-lucide="zap"></i>Upgrade<i data-lucide="arrow-up-right"></i></button>
        </section>
        <footer class="page-footer"><span><strong>NMS OLT ZTE</strong> <span class="muted">/</span> Network Management System</span><span>Internal & SaaS Ready<span class="footer-version">v1.0</span></span></footer>
        </div>
    </main>

    <dialog x-ref="dialog" @click="if($event.target===$refs.dialog)closeModal()" @cancel.prevent="closeModal()" :class="{'onu-dialog':['onus','degradation'].includes(modal),'wide-dialog':['degradation','onus','ports','view','audit','maps','staff','tags','provision','profiles','telegram','whatsapp'].includes(modal)}" aria-labelledby="modal-title">
        <div class="modal-header"><div><span class="eyebrow" x-text="selected?selected.name:'WORKSPACE · '+db.tenant.name"></span><h2 id="modal-title" x-text="modalTitle"></h2></div><button class="icon-button" @click="closeModal()" aria-label="Tutup dialog"><i data-lucide="x"></i></button></div>
        <div class="modal-body">
            <div class="notice danger" x-ref="errorSummary" tabindex="-1" x-show="error" role="alert" x-text="error"></div>
            <template x-if="modal==='equipment'"><section><h3>Card / Slot semua OLT</h3><div class="table-scroll"><table><thead><tr><th>OLT</th><th>Card / Slot</th><th>Port PON</th><th>Detail</th></tr></thead><tbody><template x-for="olt in db.olts" :key="olt.id"><tr><td x-text="olt.name"></td><td x-text="new Set(olt.ports.map(p=>p.slot_no)).size"></td><td x-text="olt.ports_count"></td><td><button type="button" class="btn secondary" @click="open('ports',olt)">Lihat port</button></td></tr></template></tbody></table></div><p class="empty-state" x-show="!db.olts.length">Belum ada perangkat OLT.</p></section></template>
<div class="notice" x-show="detailLoading">Penyegaran berjalan di background. Snapshot tersimpan tetap ditampilkan.</div>
            <div class="notice" x-show="isYotc(selected) && ['view','info','ports','onus'].includes(modal)">YOTC C1600: monitoring saja. Perubahan konfigurasi perangkat belum tersedia.</div>
            <div class="notice" x-show="isYotc(selected)&&selected?.cli_transport==='http'">HTTP tidak mengenkripsi password dan sesi. Akses perangkat perlu dibatasi di jaringan manajemen internal.</div>
            <div class="snapshot-meta" x-show="selected && ['view','info','ports','onus'].includes(modal)"><span x-text="selected?.connection_mode==='real'?'Data tersimpan dari perangkat':'Data simulator'"></span><span x-text="selected?.state_observed_at?'Status: '+dateTime(selected.state_observed_at):'Status belum diamati'"></span><span x-text="selected?.optical_observed_at?'Optik: '+dateTime(selected.optical_observed_at):'Optik belum diamati'"></span><button class="text-button" x-show="db.permissions.manage && selected?.connection_mode==='real'" @click="toggleMonitoring()" x-text="selected?.monitoring_enabled?'Jeda pemantauan':'Aktifkan pemantauan'"></button></div>
            <div class="notice danger" x-show="selected?.connection_mode==='real' && selected?.last_connection_error"><span x-text="selected?.last_connection_error"></span></div>
            @include('partials.confirm-action')
            <template x-if="['info','view','ports'].includes(modal) && selected">
                <div class="olt-chassis-rack" aria-label="Visualisasi sasis perangkat">
                    <div class="rack-ear"><span class="rack-screw"></span><span class="rack-screw"></span></div>
                    <div class="rack-body">
                        <div class="rack-topbar">
                            <div class="rack-brand">
                                <span class="rack-vendor" x-text="isYotc(selected) ? 'YOTC' : 'ZTE ZXAN'"></span>
                                <strong x-text="selected.chassis_type"></strong>
                                <span class="rack-ip mono" x-text="selected.ip_address"></span>
                            </div>
                            <div class="rack-sys-leds">
                                <span class="sys-led"><span class="led-bulb green"></span>PWR</span>
                                <span class="sys-led"><span class="led-bulb" :class="selected.status==='Online'?'green pulse':'gray'"></span>RUN</span>
                                <span class="sys-led"><span class="led-bulb" :class="selected.last_connection_error?'red pulse':'gray'"></span>ALM</span>
                            </div>
                        </div>
                        <div class="rack-slots-grid" x-show="selected.ports && selected.ports.length">
                            <template x-for="(port, pIdx) in selected.ports" :key="port.id">
                                <button type="button" class="sfp-cage" :class="port.online_onu > 0 ? 'sfp-active' : (port.total_onu > 0 ? 'sfp-warn' : 'sfp-empty')" @click="open('onus', selected); portFilter = String(port.id)" :title="port.interface_name + ' — ' + port.online_onu + ' online / ' + port.total_onu + ' total ONU'">
                                    <span class="sfp-led"></span>
                                    <span class="sfp-num" x-text="pIdx + 1"></span>
                                    <small class="sfp-count" x-text="port.online_onu + '/' + port.total_onu"></small>
                                </button>
                            </template>
                        </div>
                    </div>
                    <div class="rack-ear"><span class="rack-screw"></span><span class="rack-screw"></span></div>
                </div>
            </template>
            <template x-if="['info','view'].includes(modal) && selected"><div><div class="detail-status"><span class="status-badge" :class="selected.status==='Online'?'online':'offline'" x-text="selected.status+' ('+selected.chassis_type+')'"></span><span class="muted small" x-text="selected.connection_mode==='real'?'Mode REAL - snapshot perangkat':'Snapshot data demo'"></span></div><div class="detail-metrics"><div><i data-lucide="cpu"></i><span>CPU</span><strong x-text="selected.cpu == null ? 'Belum tersedia' : selected.cpu+'%'"></strong></div><div><i data-lucide="thermometer"></i><span>Suhu</span><strong x-text="selected.temperature == null ? 'Belum tersedia' : selected.temperature+' °C'"></strong></div><div><i data-lucide="clock-3"></i><span>Uptime</span><strong x-text="selected.uptime||'—'"></strong></div></div><dl class="detail-list"><div><dt>Alamat koneksi</dt><dd class="mono" x-text="selected.ip_address+':'+selected.telnet_port"></dd></div><div><dt>Firmware version</dt><dd x-text="selected.firmware_version || 'Belum tersedia'"></dd><small class="muted" x-text="selected.capabilities?.firmware_verified?'Firmware terverifikasi':isYotc(selected)?'Profil EPON C1600 untuk monitoring; firmware belum terverifikasi':'Profil GPON C3xx; firmware memerlukan preview dan verifikasi hasil'"></small></div><div><dt>Memory usage</dt><dd x-text="selected.memory_usage == null ? 'Belum tersedia' : selected.memory_usage+'%'"></dd></div><div><dt>Board / Slot</dt><dd><template x-for="board in selected.boards?.items || []"><div x-text="board.shelf+'/'+board.slot+' - '+board.type+' - '+board.status"></div></template><span x-show="!selected.boards?.items?.length">Belum tersedia</span></dd></div><div><dt>SNMP port</dt><dd x-text="selected.snmp_port"></dd></div><div><dt>Lokasi</dt><dd x-text="selected.location||'—'"></dd></div><div><dt>Sinkronisasi terakhir</dt><dd x-text="selected.last_sync_at?dateTime(selected.last_sync_at):'Belum sinkronisasi'"></dd></div><div><dt>ONU terdaftar</dt><dd x-text="selected.onus_count+' ONU / '+selected.online_onu+' online'"></dd></div></dl><div class="modal-actions"><button type="button" class="btn secondary" x-show="db.permissions.manage && db.permissions.cli && selected.connection_mode==='real' && supportsFeature(selected,'backup')" @click="open('backups',selected)">Backup konfigurasi</button><button type="button" class="btn secondary" x-show="db.permissions.cli && supportsFeature(selected,'cli')" @click.stop="openCli(selected)"><i data-lucide="terminal"></i>Buka Web CLI (&gt;_)</button></div></div></template>
            <template x-if="modal==='ports' && selected"><div><p class="muted" x-text="'Card / slot dan port '+ponLabel()+' pada perangkat ini.'"></p><div class="table-scroll"><table><thead><tr><th>Slot</th><th x-text="'Interface '+ponLabel()"></th><th>Deskripsi</th><th>ONU online / total</th><th>Traffic download / upload</th></tr></thead><tbody><template x-for="port in selected.ports" :key="port.id"><tr><td x-text="port.slot_no"></td><td class="mono" x-text="port.interface_name"></td><td x-text="port.description"></td><td><span class="green-text" x-text="port.online_onu"></span> / <span x-text="port.total_onu"></span></td><td><button class="btn secondary" x-show="portTrafficSupported(port)" @click="openTraffic('ports',port)"><i data-lucide="activity"></i><span x-text="traffic(port)"></span> Traffic</button><span class="muted" x-show="!portTrafficSupported(port)" x-text="isYotc(selected)?'Traffic belum diaktifkan':'Belum tersedia'"></span></td></tr></template></tbody></table></div><p class="empty-state" x-show="!selected.ports.length">Belum ada data port. Hubungkan poller untuk mengambil data.</p></div></template>
            @include('partials.onu-table')
            @include('partials.olt-form')
            @include('partials.provisioning')
            <template x-if="modal==='delete' && selected"><div><div class="delete-symbol"><i data-lucide="trash-2"></i></div><p>Hapus <strong x-text="selected.name"></strong> dari jaringan Anda?</p><p class="muted">Seluruh port, ONU, tag relasi, dan log optik perangkat ini akan ikut terhapus. Riwayat audit tetap disimpan.</p><div class="modal-actions"><button class="btn secondary" @click="closeModal()">Batal</button><button class="btn danger-solid" @click="deleteOlt()" :disabled="busy" x-text="busy?'Menghapus...':'Ya, hapus OLT'"></button></div></div></template>
            <template x-if="modal==='cli'"><div><div class="notice"><i data-lucide="info"></i><span x-text="selected?.connection_mode==='real'?'CLI nyata. Setiap perintah membuka sesi baru. Jangan kirim password melalui terminal; gunakan form kredensial.':'Simulator Web CLI. Ketik help untuk melihat perintah.'"></span></div><pre class="terminal-output" x-text="terminalOutput" aria-live="polite"></pre><form class="terminal-input" @submit.prevent="runCommand()"><span>ZXAN#</span><label class="sr-only" for="cli-command">Perintah CLI</label><input id="cli-command" x-model="command" @keydown.up.prevent="history(-1)" @keydown.down.prevent="history(1)" @keydown.ctrl.l.prevent="terminalOutput=''" maxlength="512" autocomplete="off" spellcheck="false" placeholder="show version-running" required :disabled="busy"><button class="icon-button" type="submit" :disabled="busy" aria-label="Jalankan perintah"><i data-lucide="corner-down-left"></i></button></form></div></template>
            @include('partials.profile')
            @include('partials.staff')
            @include('partials.audit')
            @include('partials.tags')
            @include('partials.ftth')
            @include('partials.backups')
            @include('partials.notifications')
            <template x-if="modal==='upgrade'"><div><div class="plan-detail"><span class="quota-icon"><i data-lucide="zap"></i></span><h3>Paket <span x-text="db.tenant.plan_type"></span></h3><p class="muted">Terpakai <strong x-text="db.olts.length+'/'+db.tenant.olt_quota"></strong> OLT · aktif hingga <span x-text="expiry"></span></p></div><div class="notice">Kuota paket hanya dapat diubah oleh Super Admin. Hubungi pengelola platform untuk menambah kapasitas. Pembayaran dan aktivasi paket belum tersedia pada tahap ini.</div><div class="modal-actions"><button class="btn primary" @click="closeModal()">Mengerti</button></div></div></template>
            <template x-if="modal==='balance'"><div><span class="eyebrow">SALDO WORKSPACE</span><h3 class="balance-amount" x-text="money(db.tenant.balance)"></h3><p class="muted">Saldo data demo. Persetujuan top-up dikelola Super Admin.</p><div class="notice">Top-up dan riwayat transaksi tersedia setelah modul pembayaran diaktifkan.</div></div></template>
            @include('partials.api-tokens')
            <template x-if="['vpn','affiliate'].includes(modal)"><div><div class="module-symbol"><i data-lucide="plug-zap"></i></div><h3 x-text="modalTitle+' belum terhubung'"></h3><p class="muted" x-text="moduleDescription"></p><div class="notice">Modul ini disiapkan dalam navigasi sesuai PRD. Aktivasi integrasi dilanjutkan pada tahap berikutnya.</div></div></template>
        </div>
    </dialog>
    @include('partials.onu-detail')
    @include('partials.traffic')
    @include('partials.onu-config-tool')
    <div class="toast" x-show="toast.message" x-cloak x-transition role="status" :class="{error:toast.error}"><i data-lucide="circle-check"></i><span x-text="toast.message"></span><button class="icon-button" @click="toast.message=''" aria-label="Tutup notifikasi"><i data-lucide="x"></i></button></div>
</div>
<script id="initial-data" type="application/json">{!! json_encode($initial, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection
