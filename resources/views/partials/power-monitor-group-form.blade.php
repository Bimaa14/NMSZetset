<template x-if="modal==='power-group-form' && powerGroupForm">
    <form class="form-stack" @submit.prevent="savePowerGroup()">
        <p class="muted small" style="margin-top:-6px">Buat kotak kategori baru di halaman Monitoring ONU (misal: Listrik, Backbone, POP Utama, Pelanggan VIP) agar daftar IP &amp; ONU lebih rapi.</p>
        <div class="form-grid">
            <label>Nama Kategori Monitoring
                <input x-model="powerGroupForm.name" required maxlength="100" placeholder="Contoh: Listrik, Backbone, VIP">
                <small class="red-text" x-show="fieldError('name')" x-text="fieldError('name')"></small>
            </label>
            <label>Warna Kotak
                <input type="color" x-model="powerGroupForm.color_hex" style="height:41px;padding:4px">
            </label>
            <label class="full-width">Keterangan (opsional)
                <input x-model="powerGroupForm.description" maxlength="255" placeholder="Contoh: Pemantauan listrik PLN tiap POP / area">
            </label>
        </div>
        <div class="modal-actions" style="margin-top:8px">
            <button type="button" class="btn secondary" @click="closeModal()" :disabled="busy">Batal</button>
            <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Kategori'"></button>
        </div>
    </form>
</template>
