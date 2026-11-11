<template x-if="modal==='profile'"><form class="form-stack" @submit.prevent="saveProfile()">
    <p class="muted"><span x-text="roleLabel"></span> &middot; <span x-text="db.tenant.name"></span></p>
    <label>Nama profil<input x-model="profileForm.name" required maxlength="100"></label><label>Email profil<input type="email" x-model="profileForm.email" required></label>
    <label>Password saat ini<input type="password" x-model="profileForm.current_password" autocomplete="current-password" required></label>
    <label>Password baru<input type="password" x-model="profileForm.password" autocomplete="new-password" minlength="12"><small class="muted">Kosongkan untuk mempertahankan password saat ini.</small></label>
    <label>Konfirmasi password baru<input type="password" x-model="profileForm.password_confirmation" autocomplete="new-password"></label>
    <div class="modal-actions"><button class="btn primary" :disabled="busy">Simpan profil</button></div>
</form></template>
