import {test,expect} from '@playwright/test';

test('ONU close icon works during reads and after returning from advanced configuration',async({page})=>{
    test.setTimeout(60000);
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let pendingRead;
    await page.route('**/onus/*/detail',r=>r.fulfill({json:{sections:{},source:'real',position_history:[]}}));
    await page.route('**/onus/*/detail/refresh',r=>{pendingRead=r;});
    await page.route('**/onus/*/configuration-tool',r=>r.fulfill({json:{snapshot:null,source:'simulator'}}));
    await page.goto('/');
    await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();
    const dialog=page.locator('.onu-inspector'),list=page.locator('.onu-reference-table');
    const open=async()=>{
        const read=page.waitForRequest(r=>r.url().endsWith('/detail/refresh'));
        await page.locator('.onu-name-link').first().click();await read;
        await expect(dialog.getByRole('button',{name:'Refresh detail ONU',exact:true})).toBeDisabled();
    };
    const close=async()=>{
        // Click the actual SVG, where a user clicks the X, including after scrolling.
        const icon=dialog.getByRole('button',{name:'Tutup detail ONU',exact:true}).locator('svg');
        const original=await icon.elementHandle();
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))));
        expect(await original.evaluate(e=>e.isConnected)).toBe(true);
        await icon.click();
        await expect(dialog).toBeHidden();await expect(list).toBeVisible();
    };
    await open();await close();
    await pendingRead.fulfill({json:{data:{reported_type:'OLD-READ'},observed_at:new Date().toISOString()}}).catch(()=>{});
    await open();
    await dialog.getByRole('button',{name:'Konfigurasi Lanjutan',exact:true}).click();
    const tool=page.locator('.onu-tool-dialog');await expect(tool).toBeVisible();
    await tool.getByRole('button',{name:'Tutup ONU Configuration Tool',exact:true}).click();
    await expect(tool).toBeHidden();await expect(dialog).toBeVisible();
    await dialog.locator('.onu-inspector-body').evaluate(e=>e.scrollTop=e.scrollHeight);
    await close();
    await open();await page.keyboard.press('Escape');await expect(dialog).toBeHidden();
    expect(errors).toEqual([]);
});
