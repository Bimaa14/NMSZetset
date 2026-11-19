<section class="ws-page" aria-label="Grup Pelanggan">
    <div class="page-heading ws-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <span>Grup Pelanggan</span>
            </div>
            <h1>Grup Pelanggan (VLAN)<span class="title-dot"></span></h1>
            <p class="muted">Pengelompokan pelanggan otomatis berdasarkan nomor VLAN ONU di OLT (contoh: Simaya Starbiz VLAN 97, Simaya Retail VLAN 95 &amp; 99).</p>
        </div>
        <div class="power-page-actions" x-show="db.permissions.operate">
            <button type="button" class="btn secondary" :disabled="groupSyncing" @click="syncGroupVlans()">
                <i data-lucide="refresh-cw" :class="{'spinning':groupSyncing}"></i>
                <span x-text="groupSyncing ? 'Menyinkronkan VLAN...' : 'Sync VLAN dari OLT'"></span>
            </button>
            <button type="button" class="btn primary" @click="newCustomerGroup()">
                <i data-lucide="plus"></i><span>Tambah Grup</span>
            </button>
        </div>
    </div>

    <div class="power-hero-grid is-page">
        <div class="power-kpi is-total">
            <div class="power-kpi-head">
                <span>Total Grup</span>
                <span class="power-kpi-icon"><i data-lucide="users-round"></i></span>
            </div>
            <strong x-text="customerGroupData.summary.total_groups ?? 0"></strong>
            <small class="muted">Segmen pelanggan aktif</small>
        </div>
        <div class="power-kpi is-up">
            <div class="power-kpi-head">
                <span><i class="dot green"></i> ONU Tergrup</span>
                <span class="power-kpi-icon is-up"><i data-lucide="circle-check"></i></span>
            </div>
            <strong class="green-text" x-text="customerGroupData.summary.grouped_onus ?? 0"></strong>
            <small class="muted" x-text="'dari ' + (customerGroupData.summary.total_onus ?? 0) + ' total ONU'"></small>
        </div>
        <div class="power-kpi is-down" :class="{'has-warning': (customerGroupData.summary.ungrouped_onus ?? 0) > 0}">
            <div class="power-kpi-head">
                <span><i class="dot amber"></i> Belum Tergrup</span>
                <span class="power-kpi-icon"><i data-lucide="alert-triangle"></i></span>
            </div>
            <strong class="amber-text" x-text="customerGroupData.summary.ungrouped_onus ?? 0"></strong>
            <small class="muted">VLAN lain / belum terbaca</small>
        </div>
        <div class="power-kpi is-latency">
            <div class="power-kpi-head">
                <span>ONU Punya VLAN</span>
                <span class="power-kpi-icon is-latency"><i data-lucide="layers-2"></i></span>
            </div>
            <strong class="cyan" x-text="customerGroupData.summary.with_vlan_onus ?? 0"></strong>
            <small class="muted" x-text="(customerGroupData.available_vlans || []).length + ' VLAN unik terdeteksi'"></small>
        </div>
    </div>

    <div class="notice" x-show="(customerGroupData.summary.total_onus ?? 0) > 0 && (customerGroupData.summary.with_vlan_onus ?? 0) === 0" style="margin-bottom:0">
        <div>
            <strong>Data VLAN ONU belum terisi dari OLT.</strong>
            <p class="muted small">Klik <strong>Sync VLAN dari OLT</strong> di pojok kanan atas untuk menarik VLAN seluruh ONU secara otomatis dari konfigurasi OLT ZTE.</p>
        </div>
        <button type="button" class="btn secondary" style="margin-left:auto" x-show="db.permissions.operate" :disabled="groupSyncing" @click="syncGroupVlans()">Sync VLAN Sekarang</button>
    </div>

    <div class="group-cards-grid">
        <template x-for="g in customerGroupData.groups || []" :key="g.id">
            <article class="panel group-card" :class="{'is-selected': selectedGroupFilter === String(g.id)}" :style="'--group-accent:' + (g.color_hex || '#38bdf8')" @click="if(!$event.target.closest('button')) selectCustomerGroup(String(g.id))">
                <div class="group-card-head">
                    <div class="group-card-title">
                        <span class="group-color-dot" :style="'background:' + (g.color_hex || '#38bdf8')"></span>
                        <div>
                            <h3 x-text="g.name"></h3>
                            <p class="muted small" x-show="g.description" x-text="g.description"></p>
                        </div>
                    </div>
                    <div class="group-card-actions" x-show="db.permissions.operate">
                        <button type="button" class="icon-button" @click.stop="editCustomerGroup(g)" title="Edit grup"><i data-lucide="pencil"></i></button>
                        <button type="button" class="icon-button red-text" @click.stop="deleteCustomerGroup(g)" title="Hapus grup"><i data-lucide="trash-2"></i></button>
                    </div>
                </div>
                <div class="group-vlan-pills">
                    <template x-for="vid in g.vlan_ids || []" :key="vid">
                        <span class="power-sn-pill mono" x-text="'VLAN ' + vid"></span>
                    </template>
                </div>
                <div class="group-metrics">
                    <div>
                        <span class="muted small">Total ONU</span>
                        <strong x-text="g.total_onus"></strong>
                    </div>
                    <div>
                        <span class="muted small">Online</span>
                        <strong class="green-text" x-text="g.online_onus"></strong>
                    </div>
                    <div>
                        <span class="muted small">LOS</span>
                        <strong class="red-text" x-text="g.los_onus"></strong>
                    </div>
                    <div>
                        <span class="muted small">Optik Buruk</span>
                        <strong class="amber-text" x-text="g.weak_onus"></strong>
                    </div>
                </div>
                <div class="group-progress">
                    <div class="onu-track"><span :style="'width:' + g.percent + '%;background:' + (g.color_hex || 'var(--green)')"></span></div>
                    <span class="small muted mono" x-text="g.percent + '% online'"></span>
                </div>
            </article>
        </template>

        <article class="panel group-card is-ungrouped" :class="{'is-selected': selectedGroupFilter === 'ungrouped'}" @click="selectCustomerGroup('ungrouped')">
            <div class="group-card-head">
                <div class="group-card-title">
                    <span class="group-color-dot" style="background:var(--muted)"></span>
                    <div>
                        <h3>Belum Tergrup</h3>
                        <p class="muted small">ONU dengan VLAN lain atau belum punya VLAN</p>
                    </div>
                </div>
            </div>
            <div class="group-vlan-pills">
                <span class="power-group-pill mono">VLAN lainnya / kosong</span>
            </div>
            <div class="group-metrics">
                <div>
                    <span class="muted small">Total ONU</span>
                    <strong class="amber-text" x-text="customerGroupData.summary.ungrouped_onus ?? 0"></strong>
                </div>
                <div>
                    <span class="muted small">Tanpa VLAN</span>
                    <strong class="muted" x-text="customerGroupData.summary.without_vlan_onus ?? 0"></strong>
                </div>
            </div>
        </article>
    </div>

    <div class="panel ws-filter-card" x-show="(customerGroupData.available_vlans || []).length">
        <div class="vlan-chips-head">
            <span class="small muted"><strong>VLAN Terdeteksi di OLT:</strong> klik untuk filter ONU per VLAN</span>
            <button type="button" class="text-button" x-show="selectedGroupFilter !== 'all'" @click="selectCustomerGroup('all')">Tampilkan Semua ONU</button>
        </div>
        <div class="vlan-chips-bar">
            <template x-for="item in customerGroupData.available_vlans || []" :key="item.vlan_id">
                <button type="button" class="vlan-chip mono" :class="{'is-active': selectedGroupFilter === 'vlan:' + item.vlan_id}" @click="selectCustomerGroup('vlan:' + item.vlan_id)">
                    <span x-text="'VLAN ' + item.vlan_id"></span>
                    <span class="power-filter-count" x-text="item.count"></span>
                </button>
            </template>
        </div>
    </div>

    <div class="power-filterbar">
        <div class="onu-quick-filters" role="group" aria-label="Filter status ONU grup">
            <button type="button" @click="groupOnuStatus='all';loadGroupOnus(1)" :aria-pressed="groupOnuStatus==='all'">Semua</button>
            <button type="button" @click="groupOnuStatus='working';loadGroupOnus(1)" :aria-pressed="groupOnuStatus==='working'"><span class="dot green"></span>Online</button>
            <button type="button" @click="groupOnuStatus='los';loadGroupOnus(1)" :aria-pressed="groupOnuStatus==='los'"><span class="dot red"></span>LOS</button>
            <button type="button" @click="groupOnuStatus='nonworking';loadGroupOnus(1)" :aria-pressed="groupOnuStatus==='nonworking'"><span class="dot amber"></span>Offline</button>
        </div>
        <div class="power-filterbar-inputs">
            <label class="modal-search power-search">
                <i data-lucide="search"></i>
                <input x-model="groupOnuQuery" @input.debounce.300ms="loadGroupOnus(1)" placeholder="Cari pelanggan, SN, atau deskripsi..." aria-label="Cari ONU dalam grup">
            </label>
            <select class="power-select" x-model="groupOnuOlt" @change="loadGroupOnus(1)" aria-label="Filter OLT">
                <option value="all">Semua OLT</option>
                <template x-for="olt in db.olts" :key="olt.id">
                    <option :value="String(olt.id)" x-text="olt.name"></option>
                </template>
            </select>
            <select class="power-select" x-model="selectedGroupFilter" @change="loadGroupOnus(1)" aria-label="Filter Grup Pelanggan">
                <option value="all">Semua Grup / ONU</option>
                <template x-for="g in customerGroupData.groups || []" :key="g.id">
                    <option :value="String(g.id)" x-text="g.name + ' (VLAN ' + (g.vlan_ids||[]).join(', ') + ')'"></option>
                </template>
                <option value="ungrouped">Belum Tergrup</option>
            </select>
        </div>
    </div>

    <div class="power-section-head">
        <span class="small muted" x-text="groupOnuLoading ? 'Memuat daftar ONU...' : (groupOnus.total + ' ONU ditemukan' + (selectedGroupTitle() ? ' · ' + selectedGroupTitle() : ''))"></span>
    </div>

    <div class="table-scroll power-table-wrap is-fullpage" x-show="(groupOnus.data || []).length">
        <table class="power-table">
            <thead>
                <tr>
                    <th>Pelanggan / Interface</th>
                    <th>OLT</th>
                    <th>Grup &amp; VLAN</th>
                    <th>Status</th>
                    <th>Redaman ONT</th>
                    <th>Serial</th>
                    <th style="text-align:right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="onu in groupOnus.data || []" :key="onu.id">
                    <tr class="onu-search-row" @click="inspectOnu(onu)">
                        <td>
                            <button type="button" class="text-button onu-name-link" @click.stop="inspectOnu(onu)" x-text="onuDisplayName(onu)"></button>
                            <small class="mono muted" x-text="onu.interface"></small>
                        </td>
                        <td x-text="oltName(onu.olt_id)"></td>
                        <td>
                            <div class="power-target-top">
                                <template x-for="cg in onu.customer_groups || []" :key="cg.id">
                                    <span class="tag"><span class="dot" :style="'background:' + cg.color_hex"></span><span x-text="cg.name"></span></span>
                                </template>
                                <template x-for="vid in onu.all_vlans || []" :key="vid">
                                    <span class="power-sn-pill mono" x-text="'VLAN ' + vid"></span>
                                </template>
                                <span class="muted small" x-show="!(onu.all_vlans || []).length">—</span>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge" :class="phaseClass(onu.phase_state)">
                                <span class="dot" :class="onu.phase_state==='working'?'green':(onu.phase_state==='los'?'red':'gray')"></span>
                                <span x-text="onuState(onu.phase_state)"></span>
                            </span>
                        </td>
                        <td class="mono" :class="signalClass(onu)" x-text="rx(onu.rx_power)"></td>
                        <td class="mono" x-text="onu.sn"></td>
                        <td style="text-align:right">
                            <button type="button" class="icon-button" @click.stop="inspectOnu(onu)" title="Detail ONU"><i data-lucide="eye"></i></button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div class="empty-state panel" x-show="!groupOnuLoading && !(groupOnus.data || []).length">
        <i data-lucide="search-x"></i>
        <strong>Tidak ada ONU pada grup / filter ini</strong>
        <p class="muted">Jika VLAN ONU belum terbaca, klik <strong>Sync VLAN dari OLT</strong> di kanan atas agar NMS mengambil VLAN dari konfigurasi OLT.</p>
    </div>

    <div class="pagination" style="border-top:0;margin-top:0" x-show="groupOnus.last_page > 1">
        <button type="button" class="btn secondary" @click="loadGroupOnus(groupOnus.current_page - 1)" :disabled="groupOnuLoading || groupOnus.current_page <= 1">Sebelumnya</button>
        <span class="small muted" x-text="'Halaman ' + groupOnus.current_page + ' / ' + groupOnus.last_page"></span>
        <button type="button" class="btn secondary" @click="loadGroupOnus(groupOnus.current_page + 1)" :disabled="groupOnuLoading || groupOnus.current_page >= groupOnus.last_page">Berikutnya</button>
    </div>
</section>
