import {test,expect} from '@playwright/test';

async function monitoringFixture(page,options={}){
    const errors=[],requests=[];let initial;
    page.on('pageerror',error=>errors.push(error.message));
    const onuPon=options.onuPon??2;
    const onu={id:991,olt_id:990,olt_port_id:onuPon===1?994:992,onu_index:7,name:'EPON-CUSTOMER',sn:'00:11:22:33:44:55',interface:'epon-onu_1/'+onuPon+':7',phase_state:'working',rx_power:-19.25,olt_rx_power:-22.5,tags:[],state_observed_at:new Date().toISOString()};
    await page.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url());
        if(request.isNavigationRequest()&&url.pathname==='/'){
            const response=await route.fetch(),html=await response.text();
            const match=html.match(/(<script id="initial-data" type="application\/json">)([\s\S]*?)(<\/script>)/);
            if(!match)throw new Error('The local dashboard must be available to run its UI fixture.');
            initial=JSON.parse(match[2]);
            const base=initial.olts[0]||{};
            const trafficEnabled=options.trafficEnabled===true,ports=[{id:992,olt_id:990,slot_no:1,port_no:2,interface_name:'epon-olt_1/2',online_onu:1,total_onu:1}];
            if(trafficEnabled)ports.unshift({id:994,olt_id:990,slot_no:1,port_no:1,interface_name:'epon-olt_1/1',online_onu:0,total_onu:0});
            if(options.morePorts)for(let port=3;port<=6;port++)ports.push({id:1000+port,olt_id:990,slot_no:1,port_no:port,interface_name:'epon-olt_1/'+port,online_onu:0,total_onu:0});
            initial.olts=[{...base,id:990,name:'YOTC-FIXTURE',chassis_type:'C1600',connection_mode:'real',cli_transport:'http',ip_address:'192.0.2.10',telnet_port:80,snmp_enabled:false,status:'Online',ports_count:ports.length,onus_count:1,online_onu:1,ports,traffic_monitoring_enabled:trafficEnabled,traffic_pon_ports:trafficEnabled?['1/1']:[],traffic_coverage:{fresh:1,interfaces:1,target_seconds:60},capabilities:{firmware_verified:false,features:{monitor:true,system:true,inventory:true,names:true,optics:true,cli:false,provision:false,onu_configuration:false,onu_restore:false,reboot:false,backup:false,traffic:trafficEnabled,port_traffic:trafficEnabled,onu_traffic:false}}}];
            initial.onus=[];initial.compact=true;initial.active_operations=[];initial.recent_operations=[];initial.operations={};
            initial.permissions={...initial.permissions,manage:true,operate:true,cli:true};
            await route.fulfill({response,body:html.replace(match[0],match[1]+JSON.stringify(initial)+match[3])});return;
        }
        if(request.resourceType()==='fetch'||request.resourceType()==='xhr'){
            requests.push({path:url.pathname,method:request.method(),body:request.postDataJSON()});
            if(url.pathname==='/traffic/ports/994/live'){
                await route.fulfill(options.liveStatus?{status:options.liveStatus,json:{message:options.liveMessage||'Statistik PON belum aktif.',...(options.liveReason?{reason:options.liveReason}:{})}}:{json:{sample:{source:'cli'},poll_after_seconds:options.pollAfterSeconds??15}});return;
            }
            if(url.pathname==='/traffic/ports/994'){
                const now=Date.now(),stats={current:2000000,average:2000000,peak:2000000,minimum:2000000,volume_bytes:null,covered_seconds:0};
                await route.fulfill({json:{points:[{recorded_at:new Date(now).toISOString(),rx_bps:2000000,tx_bps:100000,source:'cli'}],statistics:{rx:stats,tx:{...stats,current:100000,average:100000,peak:100000,minimum:100000}},sample_count:1,source:'cli',from:new Date(now-300000).toISOString(),to:new Date(now).toISOString(),last_recorded_at:new Date(now).toISOString(),volume_supported:false,volume_note:'Volume belum tersedia untuk perangkat ini.'}});return;
            }
            const status=url.searchParams.get('status'),directoryOnu=['poweroff','restorefail'].includes(status)?{...onu,phase_state:status}:onu;
            const json=url.pathname==='/onus'?{data:[directoryOnu],total:1,last_page:1,summary:{total:1,online:1,other:0,weak:0,percent:100},matched_olt_ids:[990]}
                :url.pathname==='/onus/991/detail'?{sections:{},source:'real',position_history:[],odp:null}
                :url.pathname==='/onus/991/detail/refresh'?{data:{},source:'simulator',observed_at:new Date().toISOString()}
                :url.pathname==='/dashboard/data'?initial
                :url.pathname==='/dashboard/activity'?{active_operations:[],recent_operations:[],operations:{}}
                :url.pathname==='/olts/990/operations'?{operation:{id:993,olt_id:990,status:'succeeded'},message:'Fixture complete'}
                :url.pathname==='/olts/test-connection'?{message:'Fixture connection checked'}
                :url.pathname==='/olts/990/settings'?{telnet_user:'fixture-web',has_telnet_password:true,traffic_monitoring_enabled:options.trafficEnabled===true,traffic_pon_ports:options.trafficEnabled?['1/1']:[],traffic_telnet_port:23,traffic_telnet_user:'fixture-cli',has_traffic_telnet_password:options.trafficEnabled===true,has_traffic_enable_password:options.trafficEnabled===true}
                :url.pathname==='/olts/990'&&request.method()==='PUT'?{message:'Fixture settings saved'}
                :null;
            if(json===null){await route.abort();throw new Error('Unexpected dashboard request in isolated fixture: '+request.method()+' '+url.pathname);}
            await route.fulfill({json});return;
        }
        await route.continue();
    });
    await page.goto('/');await expect(page.locator('.olt-card')).toHaveCount(1);
    return {errors,requests};
}

test('C1600 exposes stored EPON monitoring and hides unsupported device actions',async({page})=>{
    const {errors,requests}=await monitoringFixture(page),card=page.locator('.olt-card');
    await expect(card).toContainText('HTTP: koneksi tidak terenkripsi');
    await expect(card.getByRole('button',{name:/Web CLI/})).not.toBeVisible();
    await expect(card.getByRole('button',{name:'Pasang ONU',exact:true})).not.toBeVisible();
    await card.getByRole('button',{name:'Port',exact:true}).click();
    const modal=page.locator('dialog[x-ref="dialog"]');
    await expect(modal.getByRole('heading',{name:'Port EPON'})).toBeVisible();
    await expect(modal.getByRole('columnheader',{name:'Interface EPON'})).toBeVisible();
    await expect(modal.getByRole('button',{name:/Traffic/})).not.toBeVisible();
    await page.keyboard.press('Escape');await card.getByRole('button',{name:'ONU',exact:true}).click();
    await expect(page.locator('.onu-position')).toHaveText('1/2:7');
    await expect(page.getByRole('group',{name:'Status cepat'}).getByRole('button',{name:'LOS',exact:true})).not.toBeVisible();
    const statusFilter=page.getByLabel('Filter status ONU',{exact:true});
    for(const [status,label] of [['poweroff','Power off'],['restorefail','Restore fail']]){
        await statusFilter.selectOption(status);
        await expect(page.locator('.onu-reference-table .onu-state > span[x-text]')).toHaveText(label);
    }
    await statusFilter.selectOption('all');
    await expect(page.locator('.onu-reference-table .onu-state > span[x-text]')).toHaveText('Online');
    await expect(page.getByRole('button',{name:'Reboot ONU',exact:true})).not.toBeVisible();
    await page.locator('.onu-name-link').click();const inspector=page.locator('.onu-inspector');
    await expect(inspector.getByRole('tab')).toHaveCount(1);
    await expect(inspector).toContainText('-19.25 dBm');
    await expect(inspector).toContainText('Slot 1 / PON 2 / ID 7');
    for(const name of ['Konfigurasi Lanjutan','Konfigurasi','Edit','Traffic','Show Config','Tindakan'])await expect(inspector.getByRole('button',{name,exact:true})).not.toBeVisible();
    expect(requests.some(request=>request.path.endsWith('/detail/refresh'))).toBe(false);
    expect(errors).toEqual([]);
});

test('C1600 connection form defaults to HTTPS and tests the explicit web transport',async({page})=>{
    const {errors,requests}=await monitoringFixture(page);
    await page.getByRole('button',{name:'Tambah OLT',exact:true}).click();
    await page.getByLabel('Tipe chassis',{exact:true}).selectOption('C1600');
    await expect(page.getByLabel('Mode koneksi',{exact:true})).toHaveValue('real');
    await expect(page.getByLabel('Transport CLI',{exact:true})).toHaveValue('https');
    await expect(page.getByLabel('Port web',{exact:true})).toHaveValue('443');
    await expect(page.getByLabel('SNMP Community',{exact:true})).not.toBeVisible();
    await expect(page.getByLabel('Fingerprint host SSH',{exact:true})).not.toBeVisible();
    await expect(page.getByLabel('Aktifkan monitoring traffic PON',{exact:true})).toBeDisabled();
    await expect(page.locator('dialog[x-ref="dialog"]')).toContainText('sinkronkan inventaris terlebih dahulu');
    await page.getByLabel('Transport CLI',{exact:true}).selectOption('http');
    await expect(page.getByLabel('Port web',{exact:true})).toHaveValue('80');
    await expect(page.locator('dialog[x-ref="dialog"]')).toContainText('HTTP tidak mengenkripsi password dan sesi.');
    await page.getByLabel('Username web',{exact:true}).fill('fixture-admin');
    await page.getByLabel('Password web',{exact:true}).fill('fixture-only-secret');
    await page.getByRole('button',{name:'Test Connection',exact:true}).click();
    await expect(page.getByText('Fixture connection checked',{exact:true})).toBeVisible();
    const request=requests.find(request=>request.path==='/olts/test-connection');
    expect(request.body).toMatchObject({chassis_type:'C1600',cli_transport:'http',telnet_port:80,snmp_enabled:false,protocol:'web'});
    await page.getByLabel('Tipe chassis',{exact:true}).selectOption('C320');
    await expect(page.getByLabel('Transport CLI',{exact:true})).toHaveValue('telnet');
    await expect(page.getByLabel('Port Telnet',{exact:true})).toHaveValue('23');
    await expect(page.getByLabel('Protokol pengujian',{exact:true})).toHaveValue('cli');
    expect(errors).toEqual([]);
});

test('C1600 traffic is restricted to configured PONs and polls no faster than 15 seconds',async({page})=>{
    await page.clock.install();
    const {errors,requests}=await monitoringFixture(page,{trafficEnabled:true,pollAfterSeconds:1}),card=page.locator('.olt-card');
    await expect(card).toContainText('Traffic PON: 1 / 1 PON segar');
    await card.getByRole('button',{name:'Port',exact:true}).click();
    const modal=page.locator('dialog[x-ref="dialog"]'),rows=modal.locator('tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0).getByRole('button',{name:/Traffic/})).toBeVisible();
    await expect(rows.nth(1).getByRole('button',{name:/Traffic/})).not.toBeVisible();
    await expect(rows.nth(1)).toContainText('Traffic belum diaktifkan');
    await rows.nth(0).getByRole('button',{name:/Traffic/}).click();
    const dialog=page.locator('.traffic-dialog'),liveRequests=()=>requests.filter(request=>request.path==='/traffic/ports/994/live');
    await expect(dialog).toContainText('2 Mbps');
    await expect(dialog).toContainText('Traffic total port PON; traffic per ONU belum tersedia.');
    await expect(dialog).toContainText('setiap 15 detik');
    await expect(dialog.locator('.traffic-volume')).not.toBeVisible();
    await expect(dialog).toContainText('Volume belum tersedia untuk perangkat ini.');
    await expect.poll(()=>liveRequests().length).toBe(1);
    await page.clock.fastForward(14000);expect(liveRequests()).toHaveLength(1);
    await page.clock.fastForward(2000);await expect.poll(()=>liveRequests().length).toBe(2);
    await page.keyboard.press('Escape');await page.keyboard.press('Escape');
    await card.getByRole('button',{name:'ONU',exact:true}).click();
    await expect(page.getByRole('columnheader',{name:'Traffic ↓ / ↑',exact:true})).not.toBeVisible();
    await page.locator('.onu-name-link').click();
    await expect(page.locator('.onu-inspector').getByRole('button',{name:'Traffic',exact:true})).not.toBeVisible();
    expect(requests.filter(request=>request.path.startsWith('/traffic/onus/'))).toHaveLength(0);
    expect(errors).toEqual([]);
});

test('C1600 traffic settings keep web and Telnet credentials separate and preserve blank passwords',async({page})=>{
    const {errors,requests}=await monitoringFixture(page,{trafficEnabled:true});
    await page.locator('.olt-card').getByRole('button',{name:'Edit YOTC-FIXTURE',exact:true}).click();
    await expect(page.getByLabel('Username web',{exact:true})).toHaveValue('fixture-web');
    await expect(page.getByLabel('Username Telnet traffic',{exact:true})).toHaveValue('fixture-cli');
    for(const name of ['Password web','Password Telnet traffic','Password mode privileged'])await expect(page.getByLabel(name,{exact:true})).toHaveValue('');
    const section=page.getByRole('group',{name:'Traffic PON melalui Telnet',exact:true});
    await expect(section.getByLabel('epon-olt_1/1',{exact:true})).toBeChecked();
    await expect(section.getByLabel('epon-olt_1/2',{exact:true})).not.toBeChecked();
    await expect(section).toContainText('Telnet tidak mengenkripsi password dan sesi.');
    await expect(section).toContainText('Pengumpulan statistik pada OLT perlu diaktifkan terpisah');
    await page.getByRole('button',{name:'Simpan OLT',exact:true}).click();
    await expect(page.getByText('Fixture settings saved',{exact:true})).toBeVisible();
    expect(requests.find(request=>request.path==='/olts/990'&&request.method==='PUT').body).toMatchObject({telnet_user:'fixture-web',telnet_password:'',traffic_monitoring_enabled:true,traffic_pon_ports:['1/1'],traffic_telnet_port:23,traffic_telnet_user:'fixture-cli',traffic_telnet_password:'',traffic_enable_password:''});
    expect(requests.some(request=>request.path.startsWith('/traffic/'))).toBe(false);
    expect(errors).toEqual([]);
});

for(const status of [403,422])test('C1600 live traffic pauses after '+status+' without a retry loop',async({page})=>{
    await page.clock.install();
    const {errors,requests}=await monitoringFixture(page,{trafficEnabled:true,liveStatus:status});
    await page.locator('.olt-card').getByRole('button',{name:'Port',exact:true}).click();
    await page.locator('dialog[x-ref="dialog"] tbody tr').first().getByRole('button',{name:/Traffic/}).click();
    const dialog=page.locator('.traffic-dialog');
    await expect(dialog).toContainText('Statistik PON belum aktif.');
    await expect(dialog.getByRole('button',{name:'Lanjutkan live',exact:true})).toBeVisible();
    await page.clock.fastForward(60000);
    expect(requests.filter(request=>request.path==='/traffic/ports/994/live')).toHaveLength(1);
    expect(errors).toEqual([]);
});

test('C1600 live authentication failure pauses retries and preserves stored history',async({page})=>{
    await page.clock.install();
    const {errors,requests}=await monitoringFixture(page,{trafficEnabled:true,liveStatus:502,liveReason:'authentication_failed',liveMessage:'Login Telnet gagal. Periksa kredensial traffic.'});
    await page.locator('.olt-card').getByRole('button',{name:'Port',exact:true}).click();
    await page.locator('dialog[x-ref="dialog"] tbody tr').first().getByRole('button',{name:/Traffic/}).click();
    const dialog=page.locator('.traffic-dialog');
    await expect(dialog).toContainText('Login Telnet gagal. Periksa kredensial traffic.');
    await expect(dialog.getByRole('button',{name:'Lanjutkan live',exact:true})).toBeVisible();
    await expect(dialog).toContainText('2 Mbps');
    await expect(dialog.getByRole('img',{name:/Grafik traffic/})).toBeVisible();
    await page.clock.fastForward(60000);
    expect(requests.filter(request=>request.path==='/traffic/ports/994/live')).toHaveLength(1);
    await expect(dialog).toContainText('2 Mbps');
    await expect(dialog.getByRole('img',{name:/Grafik traffic/})).toBeVisible();
    expect(errors).toEqual([]);
});

test('C1600 traffic settings allow at most four selected PONs',async({page})=>{
    const {errors,requests}=await monitoringFixture(page,{trafficEnabled:true,morePorts:true});
    await page.locator('.olt-card').getByRole('button',{name:'Edit YOTC-FIXTURE',exact:true}).click();
    const section=page.getByRole('group',{name:'Port PON yang dipantau',exact:true});
    await expect(section).toContainText('Pilih maksimal 4 PON yang sudah diuji.');
    for(const port of [2,3,4])await section.getByLabel('epon-olt_1/'+port,{exact:true}).check();
    await expect(section).toContainText('4 / 4 PON dipilih');
    await expect(section.getByLabel('epon-olt_1/5',{exact:true})).toBeDisabled();
    await section.getByLabel('epon-olt_1/3',{exact:true}).uncheck();
    await expect(section.getByLabel('epon-olt_1/5',{exact:true})).toBeEnabled();
    expect(requests.some(request=>request.path.startsWith('/traffic/'))).toBe(false);
    expect(errors).toEqual([]);
});

test('C1600 ONU inspector opens explicitly labelled aggregate traffic for its configured PON',async({page},testInfo)=>{
    const {errors,requests}=await monitoringFixture(page,{trafficEnabled:true,onuPon:1});
    await page.locator('.olt-card').getByRole('button',{name:'ONU',exact:true}).click();
    await page.locator('.onu-name-link').click();
    const inspector=page.locator('.onu-inspector');
    const section=inspector.locator('.onu-pon-traffic');
    await expect(section).toBeVisible();
    await expect(section).toContainText('Traffic per ONU belum tersedia untuk YOTC C1600. Grafik PON mencakup seluruh ONU pada port yang sama.');
    await expect(inspector.getByRole('button',{name:'Traffic',exact:true})).not.toBeVisible();
    await section.screenshot({path:testInfo.outputPath('c1600-onu-aggregate-traffic.png')});
    await inspector.getByRole('button',{name:'Traffic PON 1/1 (gabungan)',exact:true}).click();
    const traffic=page.locator('.traffic-dialog');
    await expect(traffic).toBeVisible();
    await expect(traffic.getByRole('heading',{name:'Traffic · epon-olt_1/1',exact:true})).toBeVisible();
    await expect(traffic).toContainText('2 Mbps');
    await expect(traffic).toContainText('Traffic total port PON; traffic per ONU belum tersedia.');
    await expect.poll(()=>requests.filter(request=>request.path==='/traffic/ports/994/live').length).toBe(1);
    expect(requests.filter(request=>request.path.startsWith('/traffic/onus/'))).toHaveLength(0);
    await page.keyboard.press('Escape');
    await expect(inspector).toBeVisible();
    expect(errors).toEqual([]);
});

test('C1600 ONU inspector explains unavailable traffic on a PON outside the configured list',async({page})=>{
    const {errors,requests}=await monitoringFixture(page,{trafficEnabled:true});
    await page.locator('.olt-card').getByRole('button',{name:'ONU',exact:true}).click();
    await page.locator('.onu-name-link').click();
    const inspector=page.locator('.onu-inspector');
    const section=inspector.locator('.onu-pon-traffic');
    await expect(section).toBeVisible();
    await expect(section).toContainText('Traffic per ONU belum tersedia untuk YOTC C1600. Grafik PON mencakup seluruh ONU pada port yang sama.');
    await expect(section).toContainText('Traffic PON ini belum diaktifkan.');
    await expect(inspector.getByRole('button',{name:/Traffic PON .*\(gabungan\)/})).not.toBeVisible();
    await expect(inspector.getByRole('button',{name:'Traffic',exact:true})).not.toBeVisible();
    expect(requests.filter(request=>request.path.startsWith('/traffic/'))).toHaveLength(0);
    expect(errors).toEqual([]);
});

test('existing ZTE controls remain available when older data has no feature map',async({page})=>{
    const {errors}=await monitoringFixture(page);
    await page.evaluate(()=>{const dashboard=window.Alpine.$data(document.querySelector('[x-data="dashboard"]'));Object.assign(dashboard.db.olts[0],{chassis_type:'C320',connection_mode:'simulator',cli_transport:'telnet',telnet_port:23,capabilities:{}});});
    const card=page.locator('.olt-card');
    await expect(card.getByRole('button',{name:/Web CLI/})).toBeVisible();
    await expect(card.getByRole('button',{name:'Pasang ONU',exact:true})).toBeVisible();
    await card.getByRole('button',{name:'ONU',exact:true}).click();await page.locator('.onu-name-link').click();
    const inspector=page.locator('.onu-inspector');
    await expect(inspector.getByRole('tab')).toHaveCount(5);
    await expect(inspector.getByRole('button',{name:'Konfigurasi Lanjutan',exact:true})).toBeVisible();
    await expect(inspector.getByRole('button',{name:'Traffic',exact:true})).toBeVisible();
    await expect(inspector.locator('.onu-pon-traffic')).not.toBeVisible();
    await expect(inspector.getByText(/Snapshot monitoring YOTC C1600/)).not.toBeVisible();
    await expect(inspector.getByRole('button',{name:/Traffic PON .*\(gabungan\)/})).not.toBeVisible();
    expect(errors).toEqual([]);
});
