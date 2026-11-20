<template x-if="modal==='customer-group-form' && customerGroupForm">
    <form class="form-stack" @submit.prevent="saveCustomerGroup()">
        <p class="muted small" style="margin-top:-6px">Pelanggan / ONU yang cocok dengan <strong>VLAN</strong> dan/atau <strong>kata kunci nama ONU</strong> otomatis masuk ke grup ini. Anda bisa mengisi salah satu atau keduanya.</p>
        <div class="form-grid">
            <label>Nama Grup Pelanggan
                <input x-model="customerGroupForm.name" required maxlength="100" placeholder="Contoh: Simaya Starbiz atau LocalLoop">
                <small class="red-text" x-show="fieldError('name')" x-text="fieldError('name')"></small>
            </label>
            <label>Warna Badge
                <input type="color" x-model="customerGroupForm.color_hex" style="height:41px;padding:4px">
            </label>
            <label class="full-width">Filter VLAN (opsional, pisahkan dengan koma)
                <input class="mono" x-model="customerGroupForm.vlans_text" placeholder="Contoh: 97 atau 95, 99 (kosongkan jika hanya filter nama ONU)">
                <small class="muted">Nomor VLAN 1–4094, misal <code>97</code> untuk Starbiz atau <code>95, 99</code> untuk Retail.</small>
                <small class="red-text" x-show="fieldError('vlan_ids')" x-text="fieldError('vlan_ids')"></small>
            </label>
            <label class="full-width">Filter Kata Kunci Nama / Deskripsi ONU (opsional, pisahkan dengan koma)
                <input x-model="customerGroupForm.keywords_text" @input.debounce.300ms="previewGroupKeywords()" placeholder="Contoh: LOCALLOOP, DEDICATED, PT.">
                <small class="muted">Setiap ONU yang nama atau deskripsinya mengandung kata kunci ini (tidak peka huruf besar/kecil) akan otomatis tergabung.</small>
                <small class="red-text" x-show="fieldError('name_keywords')" x-text="fieldError('name_keywords')"></small>
            </label>
            <div class="full-width group-preview-box" x-show="(customerGroupForm.keywords_text || '').trim().length >= 2">
                <div class="group-preview-head">
                    <span class="small muted" x-show="groupKeywordPreview.loading">Mencari ONU yang cocok dengan kata kunci...</span>
                    <span class="small" x-show="!groupKeywordPreview.loading">
                        <strong x-text="groupKeywordPreview.total"></strong>
                        <span class="muted"> ONU cocok untuk kata kunci pertama </span>
                        <code x-text="groupKeywordPreview.term"></code>
                    </span>
                </div>
                <div class="group-preview-items" x-show="!groupKeywordPreview.loading && (groupKeywordPreview.samples || []).length">
                    <template x-for="o in groupKeywordPreview.samples || []" :key="o.id">
                        <span class="power-group-pill" x-text="onuDisplayName(o) + ' · ' + oltName(o.olt_id)"></span>
                    </template>
                </div>
            </div>
            <label class="full-width">Keterangan (opsional)
                <input x-model="customerGroupForm.description" maxlength="255" placeholder="Contoh: Pelanggan localloop / paket bisnis corporate">
            </label>
        </div>
        <div class="modal-actions" style="margin-top:8px">
            <button type="button" class="btn secondary" @click="closeModal()" :disabled="busy">Batal</button>
            <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Grup'"></button>
        </div>
    </form>
</template>
