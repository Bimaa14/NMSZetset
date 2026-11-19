<section class="ws-page" aria-label="Profil layanan">
    <div class="page-heading ws-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <span>Profil Layanan</span>
            </div>
            <h1>Profil Layanan OLT<span class="title-dot"></span></h1>
            <p class="muted">Profil menggunakan nama tipe ONU, bandwidth, VLAN, dan WAN profile yang tersedia pada OLT. Kelola profil sebelum memasang pelanggan.</p>
        </div>
        <div class="power-page-actions">
            <button type="button" class="btn primary" @click="editServiceProfile()" x-show="db.permissions.manage">
                <i data-lucide="plus"></i><span>Tambah profil layanan</span>
            </button>
        </div>
    </div>

    <template x-if="serviceProfileForm">
        <form class="panel ws-card power-editor-card form-stack" @submit.prevent="saveServiceProfile()">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge" x-text="serviceProfileForm.id ? 'Edit Profil' : 'Profil Baru'"></span>
                    <h3 x-text="serviceProfileForm.id ? serviceProfileForm.name : 'Tambah Profil Layanan'"></h3>
                </div>
                <button type="button" class="icon-button" @click="serviceProfileForm=null" aria-label="Tutup form"><i data-lucide="x"></i></button>
            </div>
            <div class="form-grid">
                <label>Nama profil layanan
                    <input x-model="serviceProfileForm.name" required maxlength="100" placeholder="HOME-20M-PPPOE">
                </label>
                <label>Tipe ONU di OLT
                    <input x-model="serviceProfileForm.onu_type" required maxlength="40" placeholder="F609">
                </label>
                <label>Mode layanan
                    <select aria-label="Mode layanan" x-model="serviceProfileForm.mode">
                        <option value="registration">Pendaftaran dan nama saja</option>
                        <option value="bridge">Bridge</option>
                        <option value="pppoe">PPPoE melalui OMCI</option>
                        <option value="custom">Template CLI</option>
                    </select>
                </label>
                <label x-show="['bridge','pppoe'].includes(serviceProfileForm.mode)">Profil upstream
                    <input x-model="serviceProfileForm.upstream_profile" placeholder="Nama profil TCONT di OLT">
                </label>
                <label x-show="['bridge','pppoe'].includes(serviceProfileForm.mode)">Profil downstream
                    <input x-model="serviceProfileForm.downstream_profile" placeholder="Nama profil downstream di OLT">
                </label>
                <label x-show="['bridge','pppoe'].includes(serviceProfileForm.mode)">VLAN layanan
                    <input type="number" x-model.number="serviceProfileForm.vlan_id" min="1" max="4094">
                </label>
                <label x-show="serviceProfileForm.mode==='pppoe'">WAN VLAN profile
                    <input x-model="serviceProfileForm.wan_profile" placeholder="Nama VLAN profile OMCI di OLT">
                </label>
            </div>
            <label x-show="serviceProfileForm.mode==='custom'">Perintah setelah nama ONU
                <textarea x-model="serviceProfileForm.commandsText" rows="7" placeholder="Satu perintah per baris"></textarea>
                <small class="muted">Placeholder: &#123;&#123;interface&#125;&#125;, &#123;&#123;port&#125;&#125;, &#123;&#123;onu_index&#125;&#125;, &#123;&#123;sn&#125;&#125;, &#123;&#123;name&#125;&#125;, &#123;&#123;pppoe_user&#125;&#125;, &#123;&#123;pppoe_password&#125;&#125;. Mulai dari konteks interface ONU. Rahasia diisi saat pemasangan.</small>
            </label>
            <div class="form-grid" x-show="serviceProfileForm.mode==='custom'">
                <label>Baris hasil pada interface ONU
                    <textarea x-model="serviceProfileForm.interfaceVerificationText" rows="4" placeholder="tcont 1 profile UP-1M"></textarea>
                </label>
                <label>Baris hasil pada pengelolaan ONU
                    <textarea x-model="serviceProfileForm.managementVerificationText" rows="4" placeholder="service internet gemport 1 vlan 123"></textarea>
                </label>
            </div>
            <p class="muted small" x-show="serviceProfileForm.mode==='custom'">Isi hasil konfigurasi yang harus terbaca kembali, satu baris per entri. Gunakan placeholder untuk rahasia. Minimal satu baris verifikasi wajib diisi.</p>
            <div class="modal-actions" style="margin-top:4px">
                <button type="button" class="btn secondary" @click="serviceProfileForm=null">Batal</button>
                <button class="btn primary" :disabled="busy" x-text="busy?'Menyimpan...':'Simpan profil layanan'"></button>
            </div>
        </form>
    </template>

    <div class="table-scroll power-table-wrap is-fullpage" x-show="serviceProfiles.length">
        <table class="power-table">
            <thead>
                <tr>
                    <th>Profil</th>
                    <th>Mode</th>
                    <th>Tipe ONU</th>
                    <th>VLAN</th>
                    <th x-show="db.permissions.manage" style="text-align:right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="profile in serviceProfiles" :key="profile.id">
                    <tr>
                        <td><strong x-text="profile.name"></strong></td>
                        <td><span class="tag" x-text="profile.mode"></span></td>
                        <td class="mono" x-text="profile.onu_type"></td>
                        <td class="mono" x-text="profile.vlan_id??'—'"></td>
                        <td x-show="db.permissions.manage">
                            <div class="row-actions power-row-actions">
                                <button type="button" class="btn secondary" @click="editServiceProfile(profile)">Edit profil</button>
                                <button type="button" class="btn secondary red-text" @click="archiveServiceProfile(profile)">Arsipkan</button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <div class="empty-state panel" x-show="!serviceProfiles.length">
        <i data-lucide="layers-2"></i>
        <strong>Belum ada profil layanan</strong>
        <p class="muted">Buat profil pemasangan (Registration, Bridge, PPPoE, atau Template CLI) untuk mempercepat aktivasi ONU baru.</p>
    </div>
</section>
