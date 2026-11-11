import {test,expect} from '@playwright/test';

async function inspector(page){
    page.on('dialog',dialog=>dialog.accept());
    await page.route('**/onus/*/detail',r=>r.fulfill({json:{sections:{},source:'real',position_history:[]}}));
    await page.route('**/onus/*/detail/refresh',r=>r.fulfill({json:{data:{phase_state:'working'},observed_at:new Date().toISOString()}}));
    await page.goto('/');await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();
    await page.locator('.onu-name-link').first().click();const dialog=page.locator('.onu-inspector');
    await dialog.locator('.onu-actions-menu summary').click();
    return dialog;
}

test('confirmed reboot waits for a rejected lock and sends one accepted reboot',async({page})=>{
    let attempts=0,accepted=0;
    await page.route('**/onus/*/reboot',r=>{
        attempts++;
        if(attempts===1)return r.fulfill({status:409,json:{reason:'olt_busy',message:'Sesi OLT sedang dipakai.'}});
        accepted++;return r.fulfill({json:{message:'OLT menerima perintah reboot.'}});
    });
    const dialog=await inspector(page);await dialog.getByRole('button',{name:'Reboot ONU',exact:true}).click();
    await expect(dialog).toContainText('Reboot belum dikirim');
    // A second click during the wait must not create another confirmed operation.
    await dialog.getByRole('button',{name:'Reboot ONU',exact:true}).click();
    await expect(page.locator('.toast')).toContainText('OLT menerima perintah reboot.');
    expect(attempts).toBe(2);expect(accepted).toBe(1);
    await expect(dialog.getByRole('button',{name:'Batalkan reboot'})).toBeHidden();
});

for(const cancel of ['button','close'])test(`reboot waiting can be cancelled by ${cancel} without a later write`,async({page})=>{
    let attempts=0;await page.route('**/onus/*/reboot',r=>{attempts++;return r.fulfill({status:409,json:{reason:'olt_busy',message:'Sesi OLT sedang dipakai.'}});});
    const dialog=await inspector(page);await dialog.getByRole('button',{name:'Reboot ONU',exact:true}).click();
    await expect(dialog).toContainText('Reboot belum dikirim');
    await dialog.getByRole('button',{name:cancel==='button'?'Batalkan reboot':'Tutup detail ONU',exact:true}).click();
    await page.waitForTimeout(2200);expect(attempts).toBe(1);
});

test('an uncertain reboot result is shown once and never retried',async({page})=>{
    let attempts=0;await page.route('**/onus/*/reboot',r=>{attempts++;return r.fulfill({status:502,json:{reason:'reboot_uncertain',message:'Hasil reboot belum dapat dipastikan.'}});});
    const dialog=await inspector(page);await dialog.getByRole('button',{name:'Reboot ONU',exact:true}).click();
    await expect(dialog.getByRole('alert')).toContainText('Hasil reboot belum dapat dipastikan.');
    await page.waitForTimeout(2200);expect(attempts).toBe(1);
});
