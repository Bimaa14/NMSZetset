<section class="ws-page" aria-label="Profil Anda">
    <div class="page-heading ws-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <span>Profil Anda</span>
            </div>
            <h1>Profil &amp; Keamanan Akun<span class="title-dot"></span></h1>
            <p class="muted">Perbarui identitas akun, username login, dan kata sandi akses workspace Anda.</p>
        </div>
    </div>

    <div class="ws-profile-grid">
        <aside class="panel ws-card ws-profile-summary">
            <div class="profile-detail" style="margin-bottom:0">
                <span class="avatar large" x-text="(db.user.name||'US').split(/\s+/).slice(0,2).map(w=>w[0]||'').join('').toUpperCase()"></span>
                <div>
                    <h3 x-text="db.user.name" style="margin-bottom:4px"></h3>
                    <p class="muted small" x-text="roleLabel + ' · ' + db.tenant.name"></p>
                    <span class="tag" style="margin-top:8px" x-text="db.user.username"></span>
                </div>
            </div>
            <dl class="detail-list" style="margin-top:16px">
                <div><dt>Workspace</dt><dd x-text="db.tenant.name"></dd></div>
                <div><dt>Role Akses</dt><dd x-text="roleLabel"></dd></div>
                <div><dt>Paket</dt><dd x-text="db.tenant.plan_type"></dd></div>
            </dl>
            <div class="notice" style="margin:16px 0 0">
                <i data-lucide="lock-keyhole"></i>
                <span>Masukkan password saat ini untuk menyimpan setiap perubahan profil atau mengganti kata sandi.</span>
            </div>
        </aside>

        <form class="panel ws-card form-stack" @submit.prevent="saveProfile()">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge">Akun</span>
                    <h3>Informasi &amp; Kata Sandi</h3>
                    <p class="muted small">Username dipakai untuk masuk ke dashboard LUMINA NMS.</p>
                </div>
            </div>
            <div class="form-grid">
                <label>Nama profil
                    <input x-model="profileForm.name" required maxlength="100">
                </label>
                <label>Username
                    <input type="text" x-model="profileForm.username" required minlength="2" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false">
                </label>
                <label class="full-width">Password saat ini
                    <input type="password" x-model="profileForm.current_password" autocomplete="current-password" required placeholder="Wajib diisi untuk verifikasi perubahan">
                </label>
                <label>Password baru
                    <input type="password" x-model="profileForm.password" autocomplete="new-password" minlength="6" placeholder="Kosongkan jika tidak ganti">
                    <small class="muted">Kosongkan untuk mempertahankan password saat ini. Minimal 6 karakter jika diisi.</small>
                </label>
                <label>Konfirmasi password baru
                    <input type="password" x-model="profileForm.password_confirmation" autocomplete="new-password" placeholder="Ulangi password baru">
                </label>
            </div>
            <div class="modal-actions" style="margin-top:6px">
                <button class="btn primary" :disabled="busy" x-text="busy?'Menyimpan...':'Simpan profil'"></button>
            </div>
        </form>
    </div>
</section>
