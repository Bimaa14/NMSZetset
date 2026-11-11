<template x-if="modal==='audit'">
    <div>
        @include('partials.skeleton',['show'=>'auditLoading&&!audit.data.length'])
        <p class="muted">Riwayat aktivitas workspace, termasuk perubahan perangkat, reboot, Tags, dan Staff. Waktu dalam WIB.</p>
        <div class="audit-filters">
            <label class="modal-search"><i data-lucide="search"></i><input x-model="auditQuery" @input.debounce.350ms="loadAudit()" placeholder="Cari aktor, aksi, atau target..." aria-label="Cari audit"></label>
            <label class="compact-field">Aksi<select x-model="auditAction" @change="loadAudit()" aria-label="Filter aksi audit"><option value="">Semua aksi</option><template x-for="action in ['olt.create','olt.update','olt.delete','olt.sync.demo','olt.sync.real','olt.sync.failed','olt.cli.demo','olt.cli.real','olt.cli.failed','olt.read.view','olt.read.ports','olt.read.failed','olt.connection.test','olt.connection.failed','onu.reboot.real','onu.reboot.reboot_uncertain','onu.reboot.demo','onu.tags.update','tag.create','tag.update','tag.delete','staff.create','staff.update','staff.delete','seed']"><option :value="action" x-text="action"></option></template></select></label>
            <label class="compact-field">Dari tanggal<input type="date" x-model="auditFrom" @change="loadAudit()" aria-label="Audit dari tanggal"></label>
            <label class="compact-field">Sampai tanggal<input type="date" x-model="auditTo" @change="loadAudit()" aria-label="Audit sampai tanggal"></label>
        </div>
        <div class="module-heading"><span class="small muted" aria-live="polite" x-text="auditLoading?'Memuat aktivitas...':audit.total+' aktivitas ditemukan'"></span><button class="text-button" @click="loadAudit(audit.current_page)" :disabled="auditLoading">Muat ulang</button></div>
        <div class="table-scroll" :aria-busy="auditLoading"><table><thead><tr><th>Waktu (WIB)</th><th>Aktor</th><th>Aksi</th><th>Target</th><th>IP</th></tr></thead><tbody><template x-for="log in audit.data" :key="log.id"><tr><td x-text="dateTime(log.created_at)"></td><td><strong x-text="log.actor_name || 'Akun tidak tersedia'"></strong><small x-text="log.actor_type+' #'+log.actor_id"></small></td><td class="mono" x-text="log.action"></td><td x-text="log.target"></td><td class="mono" x-text="log.ip_address"></td></tr></template></tbody></table></div>
        <p class="empty-state" x-show="!auditLoading && !audit.data.length">Tidak ada aktivitas sesuai filter.</p>
        <div class="pagination"><button class="btn secondary" @click="loadAudit(audit.current_page-1)" :disabled="auditLoading || audit.current_page<=1">Sebelumnya</button><span class="small muted" x-text="'Halaman '+audit.current_page+' / '+audit.last_page"></span><button class="btn secondary" @click="loadAudit(audit.current_page+1)" :disabled="auditLoading || audit.current_page>=audit.last_page">Berikutnya</button></div>
    </div>
</template>
