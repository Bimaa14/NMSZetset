<section class="ws-page" aria-label="Tags pelanggan">
    <div class="page-heading ws-page-heading">
        <div>
            <div class="breadcrumb">
                <button type="button" class="power-crumb-link" @click="showPage('dashboard')">Workspace</button>
                <i data-lucide="chevron-right"></i>
                <span>Tags Pelanggan</span>
            </div>
            <h1>Tags Pelanggan<span class="title-dot"></span></h1>
            <p class="muted">Kelompokkan pelanggan dengan tag, lalu gunakan pada pencarian global, tabel ONU, atau pemantauan listrik.</p>
        </div>
        <div class="power-page-actions">
            <span class="lan-badge"><i data-lucide="tags"></i> <span x-text="db.tags.length + ' tag terdaftar'"></span></span>
        </div>
    </div>

    <div class="ws-split-layout">
        <form class="panel ws-card form-stack" @submit.prevent="saveTag()">
            <div class="power-editor-head">
                <div class="power-editor-title">
                    <span class="power-editor-badge" x-text="tagForm.id ? 'Edit Tag' : 'Tag Baru'"></span>
                    <h3 x-text="tagForm.id?'Edit tag':'Tambah tag'"></h3>
                    <p class="muted small">Pilih warna identitas agar mudah dibedakan pada daftar ONU.</p>
                </div>
            </div>
            <div class="tag-form-grid">
                <label for="tag-name">Nama tag
                    <input id="tag-name" x-model="tagForm.tag_name" required maxlength="50" placeholder="Contoh: VIP / Monitoring Listrik" :aria-invalid="!!fieldError('tag_name')" aria-describedby="tag-name-error">
                    <small class="field-error" id="tag-name-error" x-show="fieldError('tag_name')" x-text="fieldError('tag_name')"></small>
                </label>
                <label for="tag-color">Warna
                    <input id="tag-color" type="color" x-model="tagForm.color_hex" required aria-describedby="tag-color-error">
                    <small class="field-error" id="tag-color-error" x-show="fieldError('color_hex')" x-text="fieldError('color_hex')"></small>
                </label>
            </div>
            <div class="modal-actions" style="margin-top:4px">
                <button class="btn secondary" type="button" @click="resetTag()" x-show="tagForm.id" :disabled="busy">Batal edit</button>
                <button class="btn primary" :disabled="busy" x-text="busy?'Menyimpan...':'Simpan tag'"></button>
            </div>
        </form>

        <div>
            <div class="table-scroll power-table-wrap is-fullpage" x-show="db.tags.length">
                <table class="power-table">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Jumlah ONU</th>
                            <th style="text-align:right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="tag in db.tags" :key="tag.id">
                            <tr>
                                <td><span class="tag"><span class="dot" :style="'background:'+tag.color_hex"></span><span x-text="tag.tag_name"></span></span></td>
                                <td class="mono" x-text="tag.onus_count??0"></td>
                                <td>
                                    <div class="row-actions power-row-actions">
                                        <button type="button" class="btn secondary" @click="query=tag.tag_name;showPage('dashboard');$nextTick(()=>$refs.search?.focus())">Cari ONU</button>
                                        <button type="button" class="icon-button" @click="editTag(tag)" :aria-label="'Edit tag '+tag.tag_name" :disabled="busy"><i data-lucide="pencil"></i></button>
                                        <button type="button" class="icon-button red-text" @click="confirmAction('tag',tag)" :aria-label="'Hapus tag '+tag.tag_name" :disabled="busy"><i data-lucide="trash-2"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="empty-state panel" x-show="!db.tags.length">
                <i data-lucide="tags"></i>
                <strong>Belum ada tag pelanggan</strong>
                <p class="muted">Buat tag pertama pada formulir di samping untuk mengelompokkan ONU.</p>
            </div>
        </div>
    </div>
</section>
