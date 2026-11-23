<template x-if="modal==='power-form' && powerForm">
    <form class="form-stack" @submit.prevent="savePowerMonitor()">
        <p class="muted small" style="margin-top:-6px">Pantau target IP, status ONU OLT, atau mode ganda (Ping RTO otomatis cek live status ONU ke OLT sebelum mengirim notifikasi WA/Telegram).</p>
        <div class="form-grid">
            <label>Nama Target / Lokasi
                <input x-model="powerForm.name" required maxlength="120" placeholder="Contoh: LISTRIK CIPAKU / POP-01">
                <small class="red-text" x-show="fieldError('name')" x-text="fieldError('name')"></small>
            </label>
            <label>Kategori Monitoring
                <select x-model="powerForm.power_monitor_group_id">
                    <template x-for="g in powerData.monitor_groups || []" :key="g.id">
                        <option :value="String(g.id)" x-text="g.name"></option>
                    </template>
                    <option value="">— Tanpa Kategori —</option>
                </select>
                <small class="red-text" x-show="fieldError('power_monitor_group_id')" x-text="fieldError('power_monitor_group_id')"></small>
            </label>
            <label>Area / Keterangan Lokasi (opsional)
                <input x-model="powerForm.location" maxlength="160" placeholder="OLT / POP / Lokasi Gardu">
            </label>
            <label>Mode Deteksi
                <select x-model="powerForm.monitor_type" @change="onPowerTypeChange()">
                    <option value="both">Ping IP + Double-Check ONU OLT (Ganda)</option>
                    <option value="ping">Ping IP (ICMP) saja</option>
                    <option value="onu">Status ONU di OLT saja</option>
                </select>
            </label>
            <label x-show="powerForm.monitor_type !== 'onu'">Alamat IP Target (Ping ICMP)
                <input class="mono" x-model="powerForm.ip_address" :required="powerForm.monitor_type !== 'onu'" maxlength="45" placeholder="10.10.1.100">
                <small class="red-text" x-show="fieldError('ip_address')" x-text="fieldError('ip_address')"></small>
            </label>
            <label x-show="powerForm.monitor_type !== 'ping'">Filter OLT (opsional)
                <select x-model="powerForm.olt_id" @change="loadPowerOnus(powerForm.olt_id, powerOnuSearch)">
                    <option value="">Semua OLT</option>
                    <template x-for="olt in db.olts" :key="olt.id">
                        <option :value="String(olt.id)" x-text="olt.name"></option>
                    </template>
                </select>
            </label>
            <label x-show="powerForm.monitor_type !== 'ping'">Cari ONU (SN / Nama)
                <input x-model="powerOnuSearch" @input.debounce.250ms="loadPowerOnus(powerForm.olt_id, powerOnuSearch)" placeholder="Ketik SN ZTEG... atau nama ONU">
            </label>
            <label x-show="powerForm.monitor_type !== 'ping'" :class="{'full-width': powerForm.monitor_type === 'onu'}">ONU Terkait
                <select x-model="powerForm.onu_id" :required="powerForm.monitor_type !== 'ping'">
                    <option value="">— Pilih ONU Target —</option>
                    <template x-for="onu in powerOnuOptions" :key="onu.id">
                        <option :value="String(onu.id)" x-text="oltName(onu.olt_id) + ' · ' + (onu.name && onu.name!==onu.sn ? onu.name + ' · ' : '') + onu.sn + ' (' + onu.interface + ')'"></option>
                    </template>
                </select>
                <small class="red-text" x-show="fieldError('onu_id')" x-text="fieldError('onu_id')"></small>
            </label>
            <label :class="{'full-width': powerForm.monitor_type !== 'ping'}">Override Grup WA Khusus Target Ini (opsional)
                <input x-model="powerForm.whatsapp_recipients_override" maxlength="500" placeholder="Kosongkan untuk mengikuti Grup WA kategori yang dipilih">
            </label>
        </div>
        <div class="power-editor-footer">
            <label class="checkbox-row">
                <input type="checkbox" x-model="powerForm.is_enabled">
                <span>Aktifkan pengecekan otomatis &amp; alert gangguan/pulih</span>
            </label>
            <div class="modal-actions" style="margin-top:0">
                <button type="button" class="btn secondary" @click="closeModal()" :disabled="busy">Batal</button>
                <button type="submit" class="btn primary" :disabled="busy" x-text="busy ? 'Menyimpan...' : 'Simpan & Cek Status'"></button>
            </div>
        </div>
    </form>
</template>
