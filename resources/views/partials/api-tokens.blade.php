<section class="ws-page" aria-label="Open API">
    <div class="page-heading ws-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <span>Open API</span>
            </div>
            <h1>Open API &amp; Token Akses</h1>
            <p class="muted">Token tenant untuk integrasi AI WhatsApp, billing, dan aplikasi teknisi. Token lengkap hanya ditampilkan sekali.</p>
        </div>
        <div class="power-page-actions">
            <a class="btn secondary" :href="apiInfo.documentation_url" target="_blank" rel="noopener">
                <i data-lucide="code-xml"></i><span>Dokumentasi endpoint API</span>
            </a>
        </div>
    </div>

    <div class="ws-two-col">
        <div class="panel ws-card form-stack">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge">Endpoint</span>
                    <h3>API Base URL</h3>
                    <p class="muted small">Gunakan header <code>Authorization: Bearer &lt;token&gt;</code> pada setiap permintaan API.</p>
                </div>
            </div>
            <div class="copy-field" style="margin-bottom:0">
                <code x-text="apiInfo.base_url"></code>
                <button type="button" class="icon-button" @click="copy(apiInfo.base_url)" aria-label="Salin API Base URL"><i data-lucide="copy"></i></button>
            </div>
        </div>

        <form class="panel ws-card form-stack" @submit.prevent="createApiToken()">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge">Token Baru</span>
                    <h3>Buat Token Integrasi</h3>
                    <p class="muted small">Pilih izin Read-only untuk monitoring atau Read &amp; Write untuk otomasi.</p>
                </div>
            </div>
            <div class="form-grid">
                <label class="full-width">Nama integrasi
                    <input x-model="tokenForm.name" required maxlength="100" placeholder="Bot AI WhatsApp">
                </label>
                <label>Masa berlaku (hari)
                    <input type="number" x-model.number="tokenForm.days" min="1" max="365" required>
                </label>
                <label>Izin token
                    <select x-model="tokenForm.scope">
                        <option value="read">Read-only</option>
                        <option value="write">Read dan Write</option>
                    </select>
                </label>
            </div>
            <div class="modal-actions" style="margin-top:4px">
                <button class="btn primary" :disabled="busy" x-text="busy?'Membuat...':'Buat token API'"></button>
            </div>
        </form>
    </div>

    <div class="notice" x-show="newToken" style="margin-bottom:0">
        <div style="flex:1">
            <strong>Salin token sekarang — tidak akan ditampilkan ulang</strong>
            <code class="token-value" x-text="newToken"></code>
        </div>
        <button type="button" class="btn secondary" @click="copy(newToken)"><i data-lucide="copy"></i><span>Salin token</span></button>
    </div>

    <div class="table-scroll power-table-wrap is-fullpage" x-show="apiInfo.tokens.length">
        <table class="power-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Izin</th>
                    <th>Kedaluwarsa</th>
                    <th>Terakhir dipakai</th>
                    <th style="text-align:right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="token in apiInfo.tokens" :key="token.id">
                    <tr>
                        <td><strong x-text="token.name"></strong></td>
                        <td><span class="tag" x-text="token.abilities.join(', ')"></span></td>
                        <td x-text="token.expires_at?dateTime(token.expires_at):'Tanpa batas'"></td>
                        <td x-text="token.last_used_at?dateTime(token.last_used_at):'Belum dipakai'"></td>
                        <td style="text-align:right">
                            <button type="button" class="btn secondary red-text" style="min-height:34px;padding:6px 12px;font-size:11px" @click="revokeApiToken(token)">Cabut token</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <div class="empty-state panel" x-show="!apiInfo.tokens.length">
        <i data-lucide="code-xml"></i>
        <strong>Belum ada token API aktif</strong>
        <p class="muted">Buat token API pertama Anda pada formulir di atas untuk menghubungkan aplikasi eksternal.</p>
    </div>
</section>
