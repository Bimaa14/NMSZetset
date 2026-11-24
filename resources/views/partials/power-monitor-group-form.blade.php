<template x-if="modal==='power-group-form' && powerGroupForm">
    <form class="form-stack" @submit.prevent="savePowerGroup()">
        <p class="muted small" style="margin-top:-6px">Buat atau ubah kotak kategori di halaman Monitoring ONU (misal: Listrik, ERABLUE, Backbone, VIP). Anda juga bisa mengatur tujuan Grup WA / Telegram serta custom redaksi pesan alert khusus kategori ini.</p>
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

        <div class="power-redaksi-box">
            <div class="power-redaksi-head">
                <div>
                    <strong>Custom Redaksi Pesan Alert Kategori Ini (Opsional)</strong>
                    <p class="muted small">Kosongkan bila ingin memakai redaksi default. Klik variabel di bawah untuk menyisipkan otomatis ke kotak redaksi yang sedang aktif.</p>
                </div>
                <div class="power-redaksi-actions">
                    <button type="button" class="btn secondary vlan-toggle-btn" @click="fillDefaultGroupTemplates()">Isi Template Standar</button>
                    <button type="button" class="btn secondary vlan-toggle-btn" x-show="powerGroupForm.down_message_template || powerGroupForm.up_message_template" @click="powerGroupForm.down_message_template='';powerGroupForm.up_message_template=''">Reset Default</button>
                </div>
            </div>

            <div class="power-token-bar">
                <span class="muted small">Variabel:</span>
                <template x-for="tk in powerTemplateTokens" :key="'gtk-'+tk">
                    <button type="button" class="power-token-chip mono" @click="insertPowerToken(powerGroupForm,'down_message_template','up_message_template',tk)" x-text="tk"></button>
                </template>
            </div>

            <div class="form-grid">
                <label>Redaksi Saat Gangguan / Padam (DOWN)
                    <textarea class="mono power-redaksi-textarea" rows="7" maxlength="3000" x-model="powerGroupForm.down_message_template" @focus="powerActiveTemplateField='down'" :placeholder="defaultPowerTemplate('down', powerGroupForm.name || 'Listrik')"></textarea>
                    <small class="red-text" x-show="fieldError('down_message_template')" x-text="fieldError('down_message_template')"></small>
                </label>
                <label>Redaksi Saat Pulih / Online Kembali (UP)
                    <textarea class="mono power-redaksi-textarea" rows="7" maxlength="3000" x-model="powerGroupForm.up_message_template" @focus="powerActiveTemplateField='up'" :placeholder="defaultPowerTemplate('up', powerGroupForm.name || 'Listrik')"></textarea>
                    <small class="red-text" x-show="fieldError('up_message_template')" x-text="fieldError('up_message_template')"></small>
                </label>
            </div>

            <details class="power-preview-details">
                <summary class="small">Lihat Preview Hasil Pesan WhatsApp / Telegram</summary>
                <div class="form-grid power-preview-grid">
                    <div>
                        <span class="muted small">Preview Gangguan (DOWN)</span>
                        <pre class="power-preview-bubble mono" x-text="previewPowerTemplate(powerGroupForm.down_message_template, 'down', powerGroupForm.name || 'Listrik')"></pre>
                    </div>
                    <div>
                        <span class="muted small">Preview Pulih (UP)</span>
                        <pre class="power-preview-bubble mono" x-text="previewPowerTemplate(powerGroupForm.up_message_template, 'up', powerGroupForm.name || 'Listrik')"></pre>
                    </div>
                </div>
            </details>
        </div>

        <div class="modal-actions" style="margin-top:8px">
            <button type="button" class="btn secondary" @click="closeModal()" :disabled="busy">Batal</button>
            <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan Kategori'"></button>
        </div>
    </form>
</template>
