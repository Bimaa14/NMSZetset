import {test,expect} from '@playwright/test';

test('API token creation, authenticated search and revocation work',async({page})=>{
    await page.goto('/');await page.getByRole('button',{name:'API',exact:true}).click();
    await page.getByLabel('Nama integrasi',{exact:true}).fill('Browser integration');
    await page.getByRole('button',{name:'Buat token API',exact:true}).click();
    const value=page.locator('.token-value');await expect(value).toContainText('nms_');const token=await value.textContent();
    const result=await page.request.get('/api/v1/onus/search?q=Budi',{headers:{Authorization:'Bearer '+token,Accept:'application/json'}});expect(result.status()).toBe(200);expect((await result.json()).total).toBeGreaterThan(0);
    await page.screenshot({path:'docs/api-token-management.png',mask:[value]});
    page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Cabut token',exact:true}).click();
    await expect(page.locator('dialog tbody tr')).toHaveCount(0);
    expect((await page.request.get('/api/v1/olts',{headers:{Authorization:'Bearer '+token}})).status()).toBe(401);
});

test('FTTH nodes, capacity and ONU assignments persist on a real map',async({page})=>{
    const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('/');await page.getByRole('button',{name:'FTTH Maps',exact:true}).click();
    await expect(page.locator('#ftth-map.leaflet-container')).toBeVisible();
    await page.getByRole('button',{name:'Tambah perangkat FTTH',exact:true}).click();await page.getByLabel('Nama perangkat',{exact:true}).fill('ODP-BROWSER');
    await page.getByRole('button',{name:'Simpan perangkat FTTH',exact:true}).click();
    await expect(page.locator('dialog tr').filter({hasText:'ODP-BROWSER'}).first()).toContainText('0 / 8');
    const odp=await page.getByLabel('ODP tujuan',{exact:true}).locator('option').filter({hasText:'ODP-BROWSER'}).getAttribute('value');
    await page.getByLabel('ODP tujuan',{exact:true}).selectOption(odp);await page.getByLabel('ONU pelanggan',{exact:true}).selectOption({index:1});
    await page.getByRole('button',{name:'Hubungkan ONU',exact:true}).click();await expect(page.locator('dialog tr').filter({hasText:'ODP-BROWSER'}).first()).toContainText('1 / 8');
    await page.screenshot({path:'docs/ftth-live-desktop.png'});
    await page.setViewportSize({width:390,height:844});await expect(page.locator('#ftth-map')).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.screenshot({path:'docs/ftth-live-mobile.png'});
    page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Lepaskan',exact:true}).click();await expect(page.locator('dialog tr').filter({hasText:'ODP-BROWSER'}).first()).toContainText('0 / 8');
    expect(errors).toEqual([]);
});

test('service profile and simulator provisioning require preview and confirmation',async({page})=>{
    await page.goto('/');await page.getByRole('button',{name:'Profil layanan',exact:true}).click();await page.getByRole('button',{name:'Tambah profil layanan',exact:true}).click();
    await page.getByLabel('Nama profil layanan',{exact:true}).fill('Browser-Service');await page.getByRole('button',{name:'Simpan profil layanan',exact:true}).click();
    await expect(page.locator('dialog tbody tr').filter({hasText:'Browser-Service'})).toBeVisible();await page.keyboard.press('Escape');
    await page.locator('.olt-card').first().getByRole('button',{name:'Pasang ONU',exact:true}).click();await page.getByLabel('Profil pemasangan',{exact:true}).selectOption({label:'Browser-Service / registration'});
    await page.getByLabel('Nama pelanggan di OLT',{exact:true}).fill('BROWSER-NEW');await page.getByLabel('Serial ONU',{exact:true}).fill('ZTEG98765432');await page.getByLabel('ID ONU tujuan',{exact:true}).fill('80');
    await page.getByRole('button',{name:'Periksa dan pratinjau',exact:true}).click();await expect(page.locator('dialog .terminal-output')).toContainText('onu 80 type F609 sn ZTEG98765432');
    await page.screenshot({path:'docs/provisioning-preview.png'});await page.getByRole('button',{name:'Konfirmasi pasang ONU',exact:true}).click();await expect(page.getByRole('status')).toContainText('ONU simulator didaftarkan');
    await page.keyboard.press('Escape');await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();await expect(page.locator('dialog tbody tr').filter({hasText:'ZTEG98765432'})).toContainText('BROWSER-NEW');
});

test('dashboard receives changes from another request without a page reload',async({page})=>{
    await page.goto('/');const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');const headers={'X-CSRF-TOKEN':csrf,Accept:'application/json'};
    const db=await (await page.request.get('/dashboard/data')).json();const onu=db.onus[0];
    await page.getByRole('searchbox').fill('Realtime-Tag');await expect(page.getByText('ONU tidak ditemukan',{exact:true})).toBeVisible();
    const tag=await (await page.request.post('/tags',{headers,data:{tag_name:'Realtime-Tag',color_hex:'#22d3ee'}})).json();
    const current=await (await page.request.get('/dashboard/data')).json();const created=current.tags.find(t=>t.tag_name==='Realtime-Tag');expect(created).toBeTruthy();
    const update=await page.request.put('/onus/'+onu.id+'/tags',{headers,data:{tag_ids:[...onu.tags.map(t=>t.id),created.id]}});expect(update.ok()).toBe(true);
    await expect(page.locator('.search-results tbody tr').first()).toBeVisible({timeout:15000});
    await page.request.delete('/tags/'+created.id,{headers});
});

test('ONU actions, location and column preferences work without script errors',async({page})=>{
    test.setTimeout(60000);
    const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('/');
    await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();const row=page.locator('dialog tbody tr').first();await row.getByRole('button',{name:/Detail ONU/}).click();
    await page.locator('.onu-inspector').getByRole('button',{name:'Edit',exact:true}).click();await page.getByLabel('Nama baru ONU',{exact:true}).fill('CHANGED-ALIAS');await page.getByRole('button',{name:'Simpan nama ke OLT',exact:true}).click();await expect(page.locator('#onu-inspector-title')).toHaveText('CHANGED-ALIAS');
    await page.locator('.onu-actions-menu summary').click();page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Nonaktifkan ONU',exact:true}).click();await expect(page.getByRole('button',{name:'Aktifkan ONU',exact:true})).toBeVisible();
    await page.getByLabel('Latitude pelanggan',{exact:true}).fill('-6.92');await page.getByLabel('Longitude pelanggan',{exact:true}).fill('107.61');await page.getByRole('button',{name:'Simpan lokasi pelanggan',exact:true}).click();await expect(page.getByRole('status')).toContainText('Lokasi ONU disimpan');
    await page.getByRole('button',{name:'Tutup detail ONU',exact:true}).click();await page.getByRole('button',{name:'Kolom',exact:true}).click();const preferenceSaved=page.waitForResponse(r=>r.url().endsWith('/preferences')&&r.request().method()==='PUT'&&r.status()===200);await page.getByRole('checkbox',{name:'Serial',exact:true}).uncheck();await preferenceSaved;await expect(page.getByRole('columnheader',{name:/Serial/})).toBeHidden();await page.reload();
    await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();await expect(page.getByRole('columnheader',{name:/Serial/})).toBeHidden();await page.getByRole('button',{name:'Kolom',exact:true}).click();await page.getByRole('checkbox',{name:'Serial',exact:true}).check();
    await page.locator('dialog tbody tr').filter({hasText:'CHANGED-ALIAS'}).getByRole('button',{name:/Detail ONU/}).click();await page.locator('.onu-actions-menu summary').click();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Hapus ONU',exact:true}).click();await expect(page.locator('dialog tbody tr').filter({hasText:'CHANGED-ALIAS'})).toHaveCount(0);expect(errors).toEqual([]);
});

test('profile updates keep CSRF valid for subsequent requests',async({page})=>{
    await page.goto('/');await page.getByRole('button',{name:'Profil',exact:true}).click();const original=await page.getByLabel('Nama profil',{exact:true}).inputValue();
    await page.getByLabel('Nama profil',{exact:true}).fill('Browser Owner');await page.getByLabel('Password saat ini',{exact:true}).fill('NmsDemo2026!');await page.getByRole('button',{name:'Simpan profil',exact:true}).click();await expect(page.locator('.profile-copy strong')).toHaveText('Browser Owner');
    await page.getByLabel('Nama profil',{exact:true}).fill(original);await page.getByLabel('Password saat ini',{exact:true}).fill('NmsDemo2026!');await page.getByRole('button',{name:'Simpan profil',exact:true}).click();await expect(page.locator('.profile-copy strong')).toHaveText(original);
});
