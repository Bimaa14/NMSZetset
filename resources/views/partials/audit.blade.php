<section class="ws-page" aria-label="Audit aktivitas">
    <div class="page-heading ws-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <span>Audit Aktivitas</span>
            </div>
            <h1>Audit Aktivitas<span class="title-dot"></span></h1>
            <p class="muted">Riwayat aktivitas workspace, termasuk perubahan perangkat, reboot, Tags, dan Staff. Waktu dalam WIB.</p>
        </div>
        <div class="power-page-actions">
            <span class="lan-badge" aria-live="polite" x-text="auditLoading?'Memuat aktivitas...':audit.total+' aktivitas ditemukan'"></span>
            <button type="button" class="btn secondary" @click="loadAudit(audit.current_page)" :disabled="auditLoading">
                <i data-lucide="refresh-cw" :class="{'spinning':auditLoading}"></i><span>Muat ulang</span>
            </button>
        </div>
    </div>

    @include('partials.skeleton',['show'=>'auditLoading&&!audit.data.length'])

    <div class="panel ws-filter-card">
        <div class="audit-filters" style="margin:0">
            <label class="modal-search power-search">
                <i data-lucide="search"></i>
                <input x-model="auditQuery" @input.debounce.350ms="loadAudit()" placeholder="Cari aktor, aksi, atau target..." aria-label="Cari audit">
            </label>
            <label class="compact-field">Aksi
                <select x-model="auditAction" @change="loadAudit()" aria-label="Filter aksi audit">
                    <option value="">Semua aksi</option>
                    <template x-for="action in ['olt.create','olt.update','olt.delete','olt.sync.demo','olt.sync.real','olt.sync.failed','olt.cli.demo','olt.cli.real','olt.cli.failed','olt.read.view','olt.read.ports','olt.read.failed','olt.connection.test','olt.connection.failed','onu.reboot.real','onu.reboot.reboot_uncertain','onu.reboot.demo','onu.tags.update','tag.create','tag.update','tag.delete','staff.create','staff.update','staff.delete','seed']">
                        <option :value="action" x-text="action"></option>
                    </template>
                </select>
            </label>
            <label class="compact-field">Dari tanggal
                <input type="date" x-model="auditFrom" @change="loadAudit()" aria-label="Audit dari tanggal">
            </label>
            <label class="compact-field">Sampai tanggal
                <input type="date" x-model="auditTo" @change="loadAudit()" aria-label="Audit sampai tanggal">
            </label>
        </div>
    </div>

    <div class="table-scroll power-table-wrap is-fullpage" :aria-busy="auditLoading" x-show="audit.data.length">
        <table class="power-table">
            <thead>
                <tr>
                    <th>Waktu (WIB)</th>
                    <th>Aktor</th>
                    <th>Aksi</th>
                    <th>Target</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="log in audit.data" :key="log.id">
                    <tr>
                        <td x-text="dateTime(log.created_at)"></td>
                        <td>
                            <strong x-text="log.actor_name || 'Akun tidak tersedia'"></strong>
                            <small x-text="log.actor_type+' #'+log.actor_id"></small>
                        </td>
                        <td><span class="tag mono" x-text="log.action"></span></td>
                        <td x-text="log.target"></td>
                        <td class="mono" x-text="log.ip_address"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div class="empty-state panel" x-show="!auditLoading && !audit.data.length">
        <i data-lucide="scroll-text"></i>
        <strong>Tidak ada aktivitas sesuai filter</strong>
        <p class="muted">Ubah kata kunci pencarian, aksi, atau rentang tanggal di atas.</p>
    </div>

    <div class="pagination" style="border-top:0;margin-top:0">
        <button type="button" class="btn secondary" @click="loadAudit(audit.current_page-1)" :disabled="auditLoading || audit.current_page<=1">Sebelumnya</button>
        <span class="small muted" x-text="'Halaman '+audit.current_page+' / '+audit.last_page"></span>
        <button type="button" class="btn secondary" @click="loadAudit(audit.current_page+1)" :disabled="auditLoading || audit.current_page>=audit.last_page">Berikutnya</button>
    </div>
</section>
