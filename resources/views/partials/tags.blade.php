<template x-if="modal==='tags'">
    <div>
        <p class="muted">Kelompokkan pelanggan dengan tag, lalu gunakan pada pencarian global atau tabel ONU.</p>
        <form class="inline-editor form-stack" @submit.prevent="saveTag()">
            <h3 x-text="tagForm.id?'Edit tag':'Tambah tag'"></h3>
            <div class="tag-form-grid"><label for="tag-name">Nama tag<input id="tag-name" x-model="tagForm.tag_name" required maxlength="50" :aria-invalid="!!fieldError('tag_name')" aria-describedby="tag-name-error"><small class="field-error" id="tag-name-error" x-show="fieldError('tag_name')" x-text="fieldError('tag_name')"></small></label><label for="tag-color">Warna<input id="tag-color" type="color" x-model="tagForm.color_hex" required aria-describedby="tag-color-error"><small class="field-error" id="tag-color-error" x-show="fieldError('color_hex')" x-text="fieldError('color_hex')"></small></label></div>
            <div class="modal-actions"><button class="btn secondary" type="button" @click="resetTag()" x-show="tagForm.id" :disabled="busy">Batal edit</button><button class="btn primary" :disabled="busy" x-text="busy?'Menyimpan...':'Simpan tag'"></button></div>
        </form>
        <div class="table-scroll"><table><thead><tr><th>Tag</th><th>Jumlah ONU</th><th>Aksi</th></tr></thead><tbody><template x-for="tag in db.tags" :key="tag.id"><tr><td><span class="tag"><span class="dot" :style="'background:'+tag.color_hex"></span><span x-text="tag.tag_name"></span></span></td><td x-text="tag.onus_count??0"></td><td><div class="row-actions"><button class="btn secondary" @click="query=tag.tag_name;closeModal();$refs.search.focus()">Cari ONU</button><button class="icon-button" @click="editTag(tag)" :aria-label="'Edit tag '+tag.tag_name" :disabled="busy"><i data-lucide="pencil"></i></button><button class="icon-button red-text" @click="confirmAction('tag',tag)" :aria-label="'Hapus tag '+tag.tag_name" :disabled="busy"><i data-lucide="trash-2"></i></button></div></td></tr></template></tbody></table></div>
        <p class="empty-state" x-show="!db.tags.length">Belum ada tag pelanggan.</p>
    </div>
</template>
