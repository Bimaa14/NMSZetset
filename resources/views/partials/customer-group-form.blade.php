<template x-if="modal==='customer-group-form' && customerGroupForm">
    <form class="form-stack" @submit.prevent="saveCustomerGroup()">
        <p class="muted small" style="margin-top:-6px">Pelanggan / ONU dengan nomor VLAN yang cocok otomatis masuk ke grup ini.</p>
        <div class="form-grid">
            <label>Nama Grup Pelanggan
                <input x-model="customerGroupForm.name" required maxlength="100" placeholder="Contoh: Simaya Starbiz">
                <small class="red-text" x-show="fieldError('name')" x-text="fieldError('name')"></small>
            </label>
            <label>Warna Badge
                <input type="color" x-model="customerGroupForm.color_hex" style="height:41px;padding:4px">
            </label>
            <label class="full-width">Daftar VLAN (pisahkan dengan koma)
                <input class="mono" x-model="customerGroupForm.vlans_text" required placeholder="Contoh: 97 atau 95, 99">
                <small class="muted">Masukkan satu atau beberapa nomor VLAN (1–4094), misal <code>97</code> untuk Starbiz atau <code>95, 99</code> untuk Retail.</small>
                <small class="red-text" x-show="fieldError('vlan_ids')" x-text="fieldError('vlan_ids')"></small>
            </label>
            <label class="full-width">Keterangan (opsional)
                <input x-model="customerGroupForm.description" maxlength="255" placeholder="Contoh: Paket bisnis dedicated / corporate">
            </label>
        </div>
        <div class="modal-actions" style="margin-top:8px">
            <button type="button" class="btn secondary" @click="closeModal()" :disabled="busy">Batal</button>
            <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Grup'"></button>
        </div>
    </form>
</template>
