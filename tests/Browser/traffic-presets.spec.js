import {test,expect} from '@playwright/test';

test('full preset endpoints, WIB inputs and zero baseline stay aligned without changing measured statistics',async({page})=>{
    test.setTimeout(60000);await page.setViewportSize({width:1440,height:1200});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.addInitScript(()=>{window.trafficLabels=[];const original=CanvasRenderingContext2D.prototype.fillText;CanvasRenderingContext2D.prototype.fillText=function(text,...args){window.trafficLabels.push(String(text));return original.call(this,text,...args);};});
    const end=Date.parse('2026-10-17T13:23:00Z'),first=end-6.5*3600000;
    await page.route('**/traffic/**',route=>{
        if(route.request().method()==='POST')return route.fulfill({status:202,json:{skipped:true,reason:'read_busy'}});
        const url=new URL(route.request().url()),minutes=Number(url.searchParams.get('minutes')||43200),from=new Date(end-minutes*60000).toISOString();
        const points=[0,1,2].map((i)=>({recorded_at:new Date(first+i*3600000).toISOString(),rx_bps:[10000000,20000000,91740000][i],tx_bps:1000000,source:'cli'}));
        return route.fulfill({json:{from,to:new Date(end).toISOString(),chart_baseline:{from,to:points[0].recorded_at,buckets:120},points,statistics:{rx:{current:91740000,peak:91740000,average:35430000,minimum:10000000},tx:{current:1000000,peak:1000000,average:1000000,minimum:1000000}},sample_count:3,source:'cli',last_recorded_at:points.at(-1).recorded_at}});
    });
    await page.goto('/');await page.evaluate(async()=>{const s=Alpine.$data(document.querySelector('[x-data="dashboard"]'));await s.openTraffic('onus',s.db.onus[0]);});
    const dialog=page.locator('.traffic-dialog');
    for(const [label,from,firstLabel,lastLabel]of [['24 Jam','2026-10-16','16 Okt','17 Okt'],['7 Hari','2026-10-10','10 Okt','17 Okt'],['30 Hari','2026-09-17','17 Sep','17 Okt']]){
        await page.evaluate(()=>window.trafficLabels=[]);
        await dialog.getByRole('button',{name:label,exact:true}).click();
        await expect(dialog.getByLabel('Dari (WIB)')).toHaveValue(from);await expect(dialog.getByLabel('Sampai (WIB)')).toHaveValue('2026-10-17');
        await expect.poll(()=>page.evaluate(([a,b])=>window.trafficLabels.some(t=>t.includes(a))&&window.trafficLabels.some(t=>t.includes(b)),[firstLabel,lastLabel])).toBe(true);
        await expect(dialog.locator('.traffic-extremes').first()).toContainText('35.43 Mbps');await expect(dialog.locator('.traffic-extremes').first()).toContainText('10 Mbps');
        await expect(dialog.getByRole('slider')).toHaveAttribute('max','2');
        const baseline=await dialog.locator('canvas').evaluate(canvas=>{const w=canvas.width,h=canvas.height,p=canvas.getContext('2d').getImageData(0,0,w,h).data;let columns=0;for(let x=Math.floor(w*.15);x<w*.75;x++){let blue=false;for(let y=Math.floor(h*.8);y<h-15;y++){const n=(y*w+x)*4;if(p[n+2]>130&&p[n+2]>p[n]+45&&p[n+1]>p[n]+20&&p[n+3]>80){blue=true;break;}}if(blue)columns++;}return columns/w;});
        expect(baseline).toBeGreaterThan(.5);
    }
    await dialog.screenshot({path:'docs/traffic-full-30-days.png'});
    await dialog.getByRole('button',{name:'Zoom in',exact:true}).click();await page.evaluate(()=>window.trafficLabels=[]);await dialog.getByRole('button',{name:'Reset zoom',exact:true}).click();
    expect(await page.evaluate(()=>window.trafficLabels.some(t=>t==='17 Sep')&&window.trafficLabels.some(t=>t==='17 Okt'))).toBe(true);
    expect(errors).toEqual([]);
});
