import {test,expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';

function history(){
    const end=Date.parse('2026-10-17T14:00:00+07:00');
    return {points:Array.from({length:31},(_,i)=>({recorded_at:new Date(end-3600000+i*120000).toISOString(),rx_bps:(24+5*Math.sin(i/3))*1000000,tx_bps:(1.7+.8*Math.sin(i/4))*1000000,source:'cli'})),statistics:{rx:{current:18960000,peak:29120000,average:25430000,minimum:18960000,volume_bytes:250000000,covered_seconds:3600},tx:{current:1690000,peak:2600000,average:2100000,minimum:1690000,volume_bytes:25000000,covered_seconds:3600}},sample_count:31,source:'cli',from:new Date(end-3600000).toISOString(),to:new Date(end).toISOString(),last_recorded_at:new Date(end).toISOString()};
}
async function openTraffic(page){
    await page.goto('/');
    await page.locator('.olt-card').first().getByRole('button',{name:'ONU',exact:true}).click();
    await page.locator('.onu-name-link').first().click();
    await page.locator('.onu-inspector').getByRole('button',{name:'Traffic',exact:true}).click();
    await expect(page.locator('.traffic-headline').first()).toContainText('18.96 Mbps');
}
async function cycle(page){
    await page.evaluate(async()=>{const state=Alpine.$data(document.querySelector('[x-data="dashboard"]'));clearTimeout(state.trafficTimer);await state.loadTraffic(true);clearTimeout(state.trafficTimer);});
}

test('busy live cycles retain history and chart, while genuine conflicts remain visible',async({page})=>{
    let mode='busy',historyReads=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/traffic/**',route=>{
        if(route.request().method()==='GET'){historyReads++;return route.fulfill({json:history()});}
        if(mode==='busy')return route.fulfill({status:202,json:{skipped:true,reason:'read_busy'}});
        if(mode==='legacy')return route.fulfill({status:409,json:{message:'OLT sedang menjalankan operasi lain. Tunggu hingga selesai.'}});
        if(mode==='conflict')return route.fulfill({status:409,json:{message:'Identitas ONU berubah.'}});
        return route.fulfill({json:{sample:{source:'cli'}}});
    });
    await openTraffic(page);expect(historyReads).toBe(1);
    const snapshot=()=>page.evaluate(()=>JSON.stringify(Alpine.$data(document.querySelector('[x-data="dashboard"]')).trafficData));
    const before=await snapshot();
    await cycle(page);expect(historyReads).toBe(1);expect(await snapshot()).toBe(before);
    await expect(page.locator('.traffic-dialog [role="alert"]')).toBeHidden();
    mode='legacy';await cycle(page);expect(historyReads).toBe(1);expect(await snapshot()).toBe(before);
    mode='ok';await cycle(page);expect(historyReads).toBe(2);
    mode='conflict';await cycle(page);await expect(page.locator('.traffic-dialog [role="alert"]')).toHaveText('Identitas ONU berubah.');
    await page.getByRole('button',{name:'Tutup traffic',exact:true}).click();expect(errors).toEqual([]);
});

test('traffic summary, toolbar, hover, zoom, pan and exports work on desktop and mobile',async({page})=>{
    test.setTimeout(60000);await page.setViewportSize({width:1440,height:1100});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let sample=history();
    await page.route('**/traffic/**',route=>route.fulfill({status:route.request().method()==='POST'?202:200,json:route.request().method()==='POST'?{skipped:true,reason:'read_busy'}:sample}));
    await openTraffic(page);await page.getByRole('button',{name:'Jeda live'}).click();
    const dialog=page.locator('.traffic-dialog'),canvas=dialog.locator('canvas');
    await expect(dialog.locator('.traffic-headline').first()).toContainText('2.26 MB/s');
    await expect(dialog.locator('.traffic-extremes').first()).toContainText('29.12 Mbps');
    await expect(dialog.locator('.traffic-extremes').first()).toContainText('25.43 Mbps');
    await expect(dialog.locator('.traffic-extremes').first()).toContainText('18.96 Mbps');
    await expect(dialog.locator('.traffic-toolbar svg')).toHaveCount(6);
    const picture=()=>canvas.evaluate(c=>c.toDataURL());const original=await picture();
    await dialog.getByRole('button',{name:'Zoom in',exact:true}).click();await expect.poll(picture).not.toBe(original);
    await dialog.getByRole('button',{name:'Zoom out',exact:true}).click();
    await dialog.getByRole('button',{name:'Reset zoom',exact:true}).click();await expect.poll(picture).toBe(original);
    await dialog.getByRole('button',{name:'Zoom selection',exact:true}).click();
    const box=await canvas.boundingBox();
    await page.mouse.move(box.x+box.width*.3,box.y+box.height*.45);await page.mouse.down();await page.mouse.move(box.x+box.width*.7,box.y+box.height*.45,{steps:10});await page.mouse.up();
    await page.mouse.move(1,1);await expect.poll(picture).not.toBe(original);
    await dialog.getByRole('button',{name:'Pan',exact:true}).click();await expect(dialog.getByRole('button',{name:'Pan',exact:true})).toHaveAttribute('aria-pressed','true');
    const selected=await picture();await page.mouse.move(box.x+box.width*.6,box.y+box.height*.5);await page.mouse.down();await page.mouse.move(box.x+box.width*.4,box.y+box.height*.5,{steps:10});await page.mouse.up();await page.mouse.move(1,1);await expect.poll(picture).not.toBe(selected);
    // While zoomed, live history advances. Reset must use that new window, not the opening range.
    const later=value=>new Date(Date.parse(value)+300000).toISOString();
    sample={...sample,from:later(sample.from),to:later(sample.to),last_recorded_at:later(sample.last_recorded_at),points:sample.points.map(p=>({...p,recorded_at:later(p.recorded_at)}))};
    await page.evaluate(async()=>{await Alpine.$data(document.querySelector('[x-data="dashboard"]')).loadTraffic(false);});
    await dialog.getByRole('button',{name:'Reset zoom',exact:true}).click();await page.mouse.move(1,1);const latestReset=await picture();expect(latestReset).not.toBe(original);
    await dialog.getByRole('button',{name:'Tutup traffic',exact:true}).click();await page.locator('.onu-inspector').getByRole('button',{name:'Traffic',exact:true}).click();await expect(dialog.locator('.traffic-headline').first()).toContainText('18.96 Mbps');await dialog.getByRole('button',{name:'Jeda live'}).click();await page.mouse.move(1,1);await expect.poll(picture).toBe(latestReset);
    await expect(dialog.getByRole('slider',{name:'Pilih sampel traffic'})).toHaveValue('30');
    const hoverBox=await canvas.boundingBox();await page.mouse.move(hoverBox.x+hoverBox.width*.6,hoverBox.y+hoverBox.height*.45);await dialog.screenshot({path:'docs/traffic-redesign-hover.png'});
    for(const type of ['PNG','CSV']){
        await dialog.getByRole('button',{name:'Download grafik'}).click();
        const pending=page.waitForEvent('download');await dialog.getByRole('button',{name:'Download '+type,exact:true}).click();const download=await pending;
        expect(download.suggestedFilename()).toBe('traffic.'+type.toLowerCase());const bytes=await readFile(await download.path());
        if(type==='CSV')expect(bytes.toString()).toContain('recorded_at,download_bps,upload_bps,source');else expect(bytes.subarray(1,4).toString()).toBe('PNG');
    }
    await dialog.getByRole('button',{name:'1 Jam',exact:true}).click();await expect(dialog.locator('.traffic-caption')).toContainText('Riwayat 1 jam terakhir dari database');
    await page.setViewportSize({width:375,height:812});expect(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth)).toBe(true);await dialog.screenshot({path:'docs/traffic-redesign-mobile.png'});
    await page.keyboard.press('Escape');expect(errors).toEqual([]);
});

test('background progress stays silent while manual queued Sync and initial Sync remain visible',async({page})=>{
    await page.goto('/');await expect(page.locator('.olt-card')).toHaveCount(22);
    const setOperations=async(ops)=>page.evaluate(ops=>{const s=Alpine.$data(document.querySelector('[x-data="dashboard"]'));clearTimeout(s.liveTimer);s.db.active_operations=ops.map(op=>({...op,olt_id:s.db.olts[0].id}));s.db.olts[0].last_sync_at=new Date().toISOString();},ops);
    const background={id:101,actor_type:'System',kind:'inventory',status:'running',current_step:'Background state',progress:63,is_initial_sync:false};
    await setOperations([background]);await expect(page.locator('.operation-progress')).toHaveCount(0);
    await setOperations([background,{id:102,actor_type:'User',kind:'sync',status:'queued',current_step:'Sync manual menunggu',progress:0}]);await expect(page.locator('.operation-progress')).toContainText('Sync manual menunggu');
    let cancelled='';await page.route('**/olts/*/operations/*',route=>{cancelled=route.request().url();return route.fulfill({json:{message:'Dibatalkan'}});});
    await page.getByRole('button',{name:'Batalkan antrean'}).click();expect(cancelled).toMatch(/\/operations\/102$/);
    await setOperations([{...background,is_initial_sync:true}]);await expect(page.locator('.operation-progress')).toContainText('63%');
    await setOperations([{...background,is_initial_sync:true,status:'completed'}]);await expect(page.locator('.operation-progress')).toHaveCount(0);
});
