<template x-if="modal==='power-group-form' && powerGroupForm">
    <form class="form-stack" @submit.prevent="savePowerGroup()">
        <p class="muted small" style="margin-top:-6px">Buat kotak kategori baru di halaman Monitoring ONU (misal: Listrik, ERABLUE, Backbone, VIP). Anda juga bisa mengatur tujuan Grup WA / Telegram khusus kategori ini agar seluruh target di dalamnya otomatis mengirim alert ke grup tersebut.</p>
        <div class="form-grid">
            <label>Nama Kategori Monitoring
                <input x-model="powerGroupForm.name" required maxlength="100" placeholder="Contoh: Listrik, ERABLUE, Backbone">
                <small class="red-text" x-show="fieldError('name')" x-text="fieldError('name')"></small>
            </label>
            <label>Warna Kotak
                <input type="color" x-model="powerGroupForm.color_hex" style="height:41px;padding:4px">
            </label>
            <label class="full-width">Keterangan (opsional)
                <input x-model="powerGroupForm.description" maxlength="255" placeholder="Contoh: Pemantauan site ERABLUE / listrik PLN tiap POP">
            </label>
            <label class="full-width">Grup / Nomor WhatsApp Khusus Kategori Ini (opsional)
                <input class="mono" x-model="powerGroupForm.whatsapp_recipients" maxlength="500" placeholder="Contoh: 120363xxxxxx@g.us (pisahkan koma jika lebih dari satu)">
                <small class="muted">Semua target IP &amp; ONU di kategori ini akan otomatis kirim alert WA ke grup ini (kecuali target memiliki override sendiri).</small>
                <small class="red-text" x-show="fieldError('whatsapp_recipients')" x-text="fieldError('whatsapp_recipients')"></small>
            </label>
            <label>Chat ID Telegram Khusus Kategori (opsional)
                <input class="mono" x-model="powerGroupForm.telegram_chat_id" maxlength="255" placeholder="Contoh: -1001234567890">
            </label>
            <label>Thread / Topic ID Telegram (opsional)
                <input class="mono" x-model="powerGroupForm.telegram_thread_id" maxlength="64" placeholder="Kosongkan jika bukan grup topik">
            </label>
        </div>
        <div class="modal-actions" style="margin-top:8px">
            <button type="button" class="btn secondary" @click="closeModal()" :disabled="busy">Batal</button>
            <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Kategori'"></button>
        </div>
    </form>
</template>
