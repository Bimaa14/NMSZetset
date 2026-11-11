import {test,expect} from '@playwright/test';

test('global search opens the matching ONU inspector by name, row and keyboard',async({page})=>{
    const errors=[],reads=[];page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/onus/*/detail',route=>{reads.push(route.request().url());return route.fulfill({json:{sections:{},source:'simulator',position_history:[]}});});
    await page.route('**/onus/*/detail/refresh',route=>route.fulfill({json:{data:{phase_state:'working',reported_type:'F609',rx_power:-10.9},warnings:[],source:'simulator',observed_at:new Date().toISOString()}}));
    await page.goto('/');
    const onu=await page.evaluate(async()=> (await (await fetch('/onus?per_page=1',{headers:{Accept:'application/json'}})).json()).data[0]);
    await page.getByRole('searchbox').fill(onu.sn);
    const results=page.locator('.search-results'),name=results.getByRole('button',{name:onu.name,exact:true}),dialog=page.locator('.onu-inspector');
    await expect(results.locator('tbody tr')).toHaveCount(1);
    await expect.poll(()=>page.evaluate(()=>Alpine.$data(document.querySelector('[x-data=dashboard]')).error)).toBe('');
    await name.click();await expect(dialog).toBeVisible();await expect(dialog.locator('h2')).toHaveText(onu.name.toUpperCase());await expect(dialog).toContainText(onu.sn);expect(reads).toHaveLength(1);expect(reads[0]).toContain('/onus/'+onu.id+'/detail');
    await page.getByRole('button',{name:'Tutup detail ONU',exact:true}).click();await expect(dialog).toBeHidden();
    await results.locator('tbody tr small.mono').first().click();await expect(dialog).toBeVisible();expect(reads).toHaveLength(2);
    await page.keyboard.press('Escape');await expect(dialog).toBeHidden();await expect(page.getByRole('searchbox')).toHaveValue(onu.sn);
    await name.focus();await page.keyboard.press('Enter');await expect(dialog).toBeVisible();expect(reads).toHaveLength(3);expect(errors).toEqual([]);
});

test('ONU directory loads server pages and caches rows without showing an error',async({page})=>{
    await page.goto('/');
    await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();
    await expect(page.locator('.onu-identity').first()).toBeVisible();
    const state=()=>page.evaluate(()=>{const d=Alpine.$data(document.querySelector('[x-data=dashboard]'));return {error:d.error,busy:d.directoryBusy,page:d.directory.current_page,ids:d.directory.data.map(o=>o.id),cached:d.db.onus.map(o=>o.id)};});
    await expect.poll(async()=> (await state()).busy).toBe(false);
    const first=await state();expect(first.error).toBe('');expect(first.ids.every(id=>first.cached.includes(id))).toBe(true);
    await page.evaluate(()=>{Alpine.$data(document.querySelector('[x-data=dashboard]')).onuPageSize=1;});
    await expect.poll(async()=> (await state()).ids.length).toBe(1);
    await page.evaluate(()=>{Alpine.$data(document.querySelector('[x-data=dashboard]')).onuPage=2;});
    await expect.poll(async()=> (await state()).page).toBe(2);
    const next=await state();expect(next.error).toBe('');expect(next.ids).toHaveLength(1);expect(next.cached).toContain(next.ids[0]);
});
