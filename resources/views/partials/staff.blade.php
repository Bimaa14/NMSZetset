<section class="ws-page" aria-label="Staff workspace">
    <div class="page-heading ws-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <span>Staff Workspace</span>
            </div>
            <h1>Staff &amp; Hak Akses<span class="title-dot"></span></h1>
            <p class="muted">Kelola akun dan role anggota workspace sesuai tanggung jawab operasional tim.</p>
        </div>
        <div class="power-page-actions">
            <button type="button" class="btn primary" @click="editStaff()" :disabled="busy">
                <i data-lucide="plus"></i><span>Tambah Staff</span>
            </button>
        </div>
    </div>

    <div class="role-guide ws-role-guide">
        <span><strong>NOC</strong>Kelola OLT/ONU, Monitoring Listrik, Profil Layanan, CLI, Tags, Maps</span>
        <span><strong>CS</strong>Pencarian dan baca data jaringan pelanggan</span>
        <span><strong>Teknisi</strong>Baca data perangkat dan FTTH Maps</span>
    </div>

    <template x-if="staffForm">
        <form class="panel ws-card power-editor-card form-stack" @submit.prevent="saveStaff()">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge" x-text="staffForm.id ? 'Edit Akun' : 'Staff Baru'"></span>
                    <h3 x-text="staffForm.id?'Edit Staff':'Tambah Staff'"></h3>
                </div>
                <button type="button" class="icon-button" @click="staffForm=null" aria-label="Tutup form"><i data-lucide="x"></i></button>
            </div>
            <div class="form-grid">
                <label for="staff-name">Nama staff
                    <input id="staff-name" x-model="staffForm.name" required maxlength="80" :aria-invalid="!!fieldError('name')" aria-describedby="staff-name-error">
                    <small class="field-error" id="staff-name-error" x-show="fieldError('name')" x-text="fieldError('name')"></small>
                </label>
                <label for="staff-username">Username
                    <input id="staff-username" type="text" x-model="staffForm.username" required minlength="2" maxlength="60" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Contoh: aditya_noc" :aria-invalid="!!fieldError('username')" aria-describedby="staff-username-error">
                    <small class="field-error" id="staff-username-error" x-show="fieldError('username')" x-text="fieldError('username')"></small>
                </label>
                <label for="staff-role">Role staff
                    <select id="staff-role" aria-label="Role staff" x-model="staffForm.role">
                        <option value="noc">NOC</option>
                        <option value="cs">CS</option>
                        <option value="teknisi">Teknisi</option>
                    </select>
                    <small class="field-error" x-show="fieldError('role')" x-text="fieldError('role')"></small>
                </label>
                <label for="staff-password">Password staff
                    <input id="staff-password" type="password" x-model="staffForm.password" :required="!staffForm.id" minlength="6" maxlength="128" autocomplete="new-password" :placeholder="staffForm.id?'Kosongkan untuk mempertahankan':'Minimal 6 karakter'" :aria-invalid="!!fieldError('password')" aria-describedby="staff-password-error">
                    <small class="field-error" id="staff-password-error" x-show="fieldError('password')" x-text="fieldError('password')"></small>
                </label>
            </div>
            <div class="modal-actions" style="margin-top:4px">
                <button class="btn secondary" type="button" @click="staffForm=null" :disabled="busy">Batal edit staff</button>
                <button class="btn primary" :disabled="busy" x-text="busy?'Menyimpan...':'Simpan staff'"></button>
            </div>
        </form>
    </template>

    <div class="table-scroll power-table-wrap is-fullpage">
        <table class="power-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th style="text-align:right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="staff in db.staff" :key="staff.id">
                    <tr>
                        <td><strong x-text="staff.name"></strong></td>
                        <td class="mono" x-text="staff.username"></td>
                        <td><span class="tag" x-text="roleNames[staff.role]"></span></td>
                        <td style="text-align:right">
                            <div class="row-actions power-row-actions" x-show="staff.role!=='isp_owner'">
                                <button type="button" class="btn secondary" @click="editStaff(staff)" :disabled="busy" :aria-label="'Edit staff '+staff.name"><i data-lucide="pencil"></i>Edit role</button>
                                <button type="button" class="icon-button red-text" @click="confirmAction('staff',staff)" :disabled="busy" :aria-label="'Hapus staff '+staff.name"><i data-lucide="trash-2"></i></button>
                            </div>
                            <span class="muted small" x-show="staff.role==='isp_owner'"><i data-lucide="lock-keyhole"></i> Owner terlindungi</span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</section>
