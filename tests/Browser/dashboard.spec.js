import { test, expect } from '@playwright/test';

test('dashboard desktop, search fields, details, themes, and safe CRUD', async ({ page }) => {
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.setViewportSize({ width: 1440, height: 1080 });
    await page.goto('/');
    await expect(page.locator('.olt-card')).toHaveCount(22);
    await expect(page.locator('.alert-count')).toHaveText('64');
    await expect(page.locator('.profile-copy small')).toHaveText('ISP Owner • Premium');
    await page.screenshot({ path: 'docs/dashboard-desktop.png', fullPage: false });
    const search = page.getByRole('searchbox');
    for (const query of ['Budi_Santoso', 'ZTEGC1122334', 'Jl. Monex', 'Prioritas']) {
        await search.fill(query);
        await expect(page.locator('.search-results tbody tr').first()).toBeVisible();
    }
    await search.fill('tidak-ada-pelanggan-ini');
    await expect(page.getByText('ONU tidak ditemukan', { exact: true })).toBeVisible();
    await search.fill('');
    await page.getByRole('button', { name: /Redaman Memburuk/ }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.locator('dialog tbody tr')).toHaveCount(64);
    await page.keyboard.press('Escape');
    await page.locator('.olt-card').first().getByRole('button', { name: 'View', exact: true }).click();
    await expect(page.locator('dialog[x-ref="dialog"]')).toContainText('CPU');
    await page.keyboard.press('Escape');
    await page.locator('.olt-card').first().getByRole('button', { name: 'Port', exact: true }).click();
    await expect(page.locator('dialog tbody tr')).toHaveCount(4);
    await page.keyboard.press('Escape');
    await page.locator('.olt-card').first().getByRole('button', { name: 'ONU', exact: true }).click();
    await expect(page.locator('dialog tbody tr')).toHaveCount(16);
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Tambah OLT' }).click();
    await expect(page.locator('dialog[x-ref="dialog"]')).toContainText('Kuota OLT penuh');
    await expect(page.getByLabel('Username Telnet', { exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Tema Light' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.getByRole('button', { name: 'Tema System' }).click();
    await page.getByRole('button', { name: 'Tema Dark' }).click();
    await page.locator('.olt-card').first().getByRole('button', { name: 'Sync', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Sinkronisasi demo selesai');
    await page.locator('.olt-card').first().getByRole('button', { name: /Web CLI/ }).click();
    await page.getByLabel('Perintah CLI').fill('show version');
    await page.getByRole('button', { name: 'Jalankan perintah' }).click();
    await expect(page.locator('.terminal-output')).toContainText('ZTE ZXAN C320');
    await page.keyboard.press('Escape');
    const card = page.locator('.olt-card').last();
    await card.getByRole('button', { name: /Edit / }).click();
    await page.getByLabel('Lokasi', { exact: true }).fill('Area Bandung Raya · uji');
    await page.getByRole('button', { name: 'Simpan OLT' }).click();
    await expect(card).toContainText('Area Bandung Raya · uji');
    await card.getByRole('button', { name: /Edit / }).click();
    await page.getByLabel('Lokasi', { exact: true }).fill('Area Bandung Raya');
    await page.getByRole('button', { name: 'Simpan OLT' }).click();
    await expect(card).toContainText('Area Bandung Raya');
    await card.getByRole('button', { name: 'Hapus', exact: true }).click();
    await page.getByRole('button', { name: 'Batal', exact: true }).click();
    await expect(page.locator('.olt-card')).toHaveCount(22);
    expect(errors).toEqual([]);
});

test('responsive layout and accessible dialogs at mobile and tablet widths', async ({ page }) => {
    await page.goto('/');
    for (const width of [375, 768, 1024]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(page.locator('.olt-card').first()).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        expect(overflow).toBe(false);
    }
    await page.setViewportSize({ width: 375, height: 900 });
    await page.screenshot({ path: 'docs/dashboard-mobile.png', fullPage: false });
    await page.getByRole('button', { name: /Redaman Memburuk/ }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Tutup dialog' })).toBeInViewport();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
});

test('compact ONU table separates names and serials, sorts positions, and scrolls inside the dialog on mobile', async ({page}) => {
    const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.setViewportSize({width:1440,height:1000});
    await page.goto('/');
    await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();
    const table=page.locator('.onu-reference-table');
    await expect(table.getByRole('columnheader',{name:/Nama/})).toBeVisible();
    await expect(table.getByRole('columnheader',{name:/Posisi/})).toBeVisible();
    await expect(table.getByRole('columnheader',{name:/Redaman ONT/})).toBeVisible();
    await expect(table.getByRole('columnheader',{name:/Serial/})).toBeVisible();
    await expect(table.locator('tbody tr').first().locator('td').first()).toHaveText('Agus_Setiawan');
    await table.getByRole('button',{name:/^Nama/}).click();
    await expect(table.getByRole('columnheader',{name:/Nama/})).toHaveAttribute('aria-sort','descending');
    await table.getByRole('button',{name:/^Posisi/}).click();
    await expect(table.locator('tbody tr').first().locator('.onu-position')).toHaveText('1/1:1');
    await table.getByRole('button',{name:/^Nama/}).click();
    await page.screenshot({path:'docs/onu-reference-desktop.png'});
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.setViewportSize({width:375,height:900});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
    expect(await table.evaluate(el=>el.scrollWidth>el.clientWidth)).toBe(true);
    await expect(page.getByRole('button',{name:'Tutup dialog',exact:true})).toBeInViewport();
    await page.screenshot({path:'docs/onu-reference-mobile.png'});
    expect(errors).toEqual([]);
});

test('CS role is read only and logout persists', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('button', { name: 'CS', exact: true }).click();
    await expect(page.locator('.profile-copy small')).toHaveText('CS');
    await expect(page.getByRole('button', { name: 'Tambah OLT' })).not.toBeVisible();
    await expect(page.locator('.olt-card').first().getByRole('button', { name: 'Hapus', exact: true })).not.toBeVisible();
    await expect(page.locator('.olt-card').first().getByRole('button', { name: 'Sync', exact: true })).not.toBeVisible();
    await page.getByRole('searchbox').fill('Budi');
    await expect(page.locator('.search-results tbody tr').first()).toBeVisible();
    await page.getByRole('button', { name: 'Keluar' }).click();
    await expect(page).toHaveURL(/login/);
    await page.goto('/');
    await expect(page).toHaveURL(/login/);
});
