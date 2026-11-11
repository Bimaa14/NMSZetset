import {test,expect} from '@playwright/test';

const state=fn=>`(${fn})(Alpine.$data(document.querySelector('[x-data="dashboard"]')))`;
test('sparse long history and single sample paint visible curves; pagination stays bounded',async({page})=>{
    await page.setViewportSize({width:1440,height:1100});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));let single=false;
    await page.route('**/traffic/**',route=>{
        if(route.request().method()==='POST')return route.fulfill({status:202,json:{skipped:true,reason:'read_busy'}});
        const end=Date.parse('2026-10-17T12:48:00Z');
        return route.fulfill({json:{from:new Date(end-30*86400000).toISOString(),to:new Date(end).toISOString(),chart_baseline:{to:new Date(end-3600000).toISOString()},points:(single?[0]:[0,1,2]).map((i)=>({recorded_at:new Date(end-3600000+i*1800000).toISOString(),rx_bps:single?18850000:[18850000,null,25870000][i],tx_bps:1000000,source:'cli'})),sample_count:single?1:3,last_recorded_at:new Date(end).toISOString(),source:'cli',statistics:{rx:{current:18850000,peak:80820000},tx:{current:1000000,peak:2000000}}}});
    });
    await page.goto('/');
    await page.evaluate(state(s=>{const source=s.db.onus[0];s.db.onus=Array.from({length:386},(_,i)=>({...source,id:10000+i,name:'ONU '+i}));s.open('onus');}));
    await expect(page.locator('.onu-name-link')).toHaveCount(50);
    await page.locator('.onu-name-link').first().click();
    await page.locator('.onu-inspector').getByRole('button',{name:'Traffic',exact:true}).click();
    const dialog=page.locator('.traffic-dialog');
    // Count blue series pixels across the full canvas, excluding toolbar/labels.
    const blueColumns=()=>dialog.locator('canvas').evaluate(canvas=>{const {width:w,height:h}=canvas;const p=canvas.getContext('2d').getImageData(0,0,w,h).data;let columns=0;for(let x=Math.floor(w*.15);x<w*.9;x++){let found=false;for(let y=10;y<h;y++){const n=(y*w+x)*4;if(p[n+2]>130&&p[n+2]>p[n]+45&&p[n+1]>p[n]+20&&p[n+3]>80){found=true;break;}}if(found)columns++;}return columns/w;});
    for(const label of ['7 Hari','30 Hari']){await dialog.getByRole('button',{name:label,exact:true}).click();await expect.poll(blueColumns).toBeGreaterThan(.5);}
    await dialog.screenshot({path:'docs/traffic-sparse-30-days.png'});
    single=true;await dialog.getByRole('button',{name:'Refresh histori',exact:true}).click();await expect.poll(blueColumns).toBeGreaterThan(.5);
    await dialog.screenshot({path:'docs/traffic-single-sample.png'});
    expect(errors).toEqual([]);
});

test('traffic/detail requests abort on close, range switch and hidden tab without stale updates',async({page})=>{
    await page.goto('/');
    const result=await page.evaluate(async()=>{
        const s=Alpine.$data(document.querySelector('[x-data="dashboard"]'));
        const real=s.request;let aborted=0;let signal;
        s.request=async(url,method,body,options={})=>new Promise((resolve,reject)=>{signal=options.signal;signal?.addEventListener('abort',()=>{aborted++;reject(new DOMException('Aborted','AbortError'));});});
        const opening=s.openTraffic('onus',s.db.onus[0]);await Promise.resolve();s.closeTraffic();await opening;
        s.trafficTarget={kind:'onus',id:s.db.onus[0].id};s.trafficRange='live';const live=s.loadTraffic(true);await Promise.resolve();const switching=s.setTrafficRange('10080');await Promise.resolve();s.closeTraffic();await Promise.all([live,switching]);
        const detail=s.openOnuInspector(s.db.onus[0]);await Promise.resolve();s.closeOnuInspector();await detail;
        s.trafficTarget={kind:'onus',id:s.db.onus[0].id};const hidden=s.loadTraffic(true);await Promise.resolve();Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));await hidden;
        const result={aborted,trafficBusy:s.trafficBusy,detailBusy:s.detailBusy,trafficTimer:s.trafficTimer,trafficError:s.trafficError,detailError:s.detailError};
        s.closeTraffic();s.request=real;delete document.hidden;return result;
    });
    expect(result.aborted).toBe(5);expect(result.trafficBusy).toBe(false);expect(result.detailBusy).toBe(false);expect(result.trafficTimer).toBeNull();expect(result.trafficError).toBe('');expect(result.detailError).toBe('');
});
