<template x-if="pendingAction">
    <section class="confirmation-panel" aria-label="Konfirmasi tindakan">
        <div><i data-lucide="alert-triangle"></i><strong x-text="confirmationTitle"></strong></div>
        <p><span x-text="pendingAction.name"></span></p>
        <p class="muted small" x-text="pendingAction.kind==='reboot'?(selected?.connection_mode==='real'?'Reboot dikirim ke OLT fisik dan memutus koneksi pelanggan sementara. Setelah diterima OLT, jalankan Sync untuk memastikan status.':'Koneksi ONU akan terputus sementara dalam simulasi. Kondisi LOS atau mati listrik tidak diperbaiki oleh reboot.'):pendingAction.kind==='onu-delete'?'Pendaftaran ONU pada OLT akan dihapus dan layanan pelanggan akan terputus. Riwayat lokal tetap disimpan.':pendingAction.kind==='tag'?'Tag akan dilepas dari seluruh ONU terkait. Data ONU tidak dihapus.':'Akun ini akan kehilangan akses ke workspace. Riwayat audit tetap disimpan.'"></p>
        <p class="notice" role="status" x-show="rebootState?.waiting">Menunggu sesi OLT tersedia. Reboot belum dikirim (maksimal 90 detik).</p>
        <div class="modal-actions"><button class="btn secondary" @click="cancelRebootWait();pendingAction=null" :disabled="busy&&!rebootState?.waiting">Batalkan tindakan</button><button id="confirm-action" class="btn danger-solid" @click="executeAction()" :disabled="busy" x-text="busy?'Memproses...':'Ya, '+confirmationTitle.toLowerCase()"></button></div>
    </section>
</template>
