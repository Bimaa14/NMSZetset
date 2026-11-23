import {wibDate} from './traffic-domain';
let chartMod=null;
let chartModPromise=null;
const ensureChartMod=()=>chartMod?Promise.resolve(chartMod):(chartModPromise??=import('./traffic-chart').then(m=>(chartMod=m)));
export function trafficModule(){return {
    trafficAbort:null,trafficTarget:null,trafficRange:'live',trafficData:null,trafficError:'',trafficBusy:false,trafficLiveTicking:false,trafficTimer:null,trafficEpoch:0,trafficPaused:false,trafficPollSeconds:2,trafficHover:0,trafficPinned:true,trafficDateMode:'date',trafficFrom:'',trafficTo:'',trafficCustom:null,trafficChartMode:'select',trafficDownloadMenu:false,
    trafficPresets:[['live','Live Traffic'],['30','30 Menit'],['60','1 Jam'],['360','6 Jam'],['1440','24 Jam'],['10080','7 Hari'],['43200','30 Hari']],
    async openTraffic(kind,entity){
        const olt=kind==='onus'?this.oltForOnu(entity):this.db.olts.find(item=>item.id===entity.olt_id)||this.selected;
        if(kind==='onus'?!this.supportsFeature(olt,'onu_traffic'):!this.portTrafficSupported(entity))return;
        this.closeTraffic();
        ensureChartMod();
        this.trafficTarget={kind,id:entity.id,name:entity.name||entity.interface_name,oltId:olt?.id,chassis_type:olt?.chassis_type};
        this.trafficPollSeconds=this.isYotc(olt)?15:2;
        this.trafficRange='live';
        this.syncTrafficPresetDates();
        this.trafficChartMode='select';
        this.trafficPaused=false;
        this.trafficData=null;
        this.trafficHover=0;
        this.trafficPinned=true;
        this.trafficCustom=null;
        this.$refs.trafficDialog.showModal();
        const epoch=this.trafficEpoch;
        await this.loadTraffic(false);
        if(epoch===this.trafficEpoch&&!this.trafficPaused)await this.loadTraffic(true);
    },
    suspendTraffic(){this.trafficEpoch++;clearTimeout(this.trafficTimer);this.trafficTimer=null;this.trafficAbort?.abort();this.trafficAbort=null;this.trafficBusy=false;this.trafficLiveTicking=false;},
    closeTraffic(){this.suspendTraffic();chartMod?.destroyTrafficChart(this.$refs.trafficCanvas);this.trafficDownloadMenu=false;this.trafficEpoch++;clearTimeout(this.trafficTimer);this.$refs.trafficDialog?.close();this.trafficTarget=null;this.trafficBusy=false;this.trafficLiveTicking=false;},
    async setTrafficRange(range){this.suspendTraffic();chartMod?.destroyTrafficChart(this.$refs.trafficCanvas);this.trafficChartMode='select';this.trafficEpoch++;clearTimeout(this.trafficTimer);this.trafficBusy=false;this.trafficLiveTicking=false;this.trafficRange=range;this.trafficPinned=true;if(range!=='custom')this.syncTrafficPresetDates();this.trafficPaused=false;await this.loadTraffic(range==='live');},
    syncTrafficPresetDates(data=null){const end=data?.to||Date.now(),start=data?.from||(Number(end)-(this.trafficRange==='live'?2:Number(this.trafficRange))*60000);this.trafficDateMode='date';this.trafficFrom=wibDate(start);this.trafficTo=wibDate(end);},
    async applyTrafficDates(){
        if(!this.trafficFrom||!this.trafficTo){this.trafficError='Isi tanggal atau bulan awal dan akhir.';return;}
        const from=new Date(this.trafficFrom+(this.trafficDateMode==='month'?'-01':'')+'T00:00:00+07:00');
        let end=this.trafficTo;if(this.trafficDateMode==='month'){const [y,m]=end.split('-').map(Number);end=new Date(Date.UTC(y,m,0)).toISOString().slice(0,10);}
        const to=new Date(end+'T23:59:59+07:00');
        if(!Number.isFinite(+from)||!Number.isFinite(+to)||from>=to||to-from>366*86400000){this.trafficError='Rentang maksimal 366 hari, dengan akhir setelah awal.';return;}
        this.trafficCustom={from:from.toISOString().replace('.000Z','+00:00'),to:to.toISOString().replace('.000Z','+00:00')};await this.setTrafficRange('custom');
    },
    trafficQuery(){return new URLSearchParams(this.trafficRange==='custom'?this.trafficCustom:{minutes:this.trafficRange==='live'?2:this.trafficRange}).toString();},
    applyLiveSampleInPlace(sample){
        const data=this.trafficData;
        if(!data||!sample?.recorded_at)return false;
        const point={
            recorded_at:sample.recorded_at,
            rx_bps:sample.rx_bps==null?null:Number(sample.rx_bps),
            tx_bps:sample.tx_bps==null?null:Number(sample.tx_bps),
            source:sample.source||data.source||'cli',
        };
        const nowMs=Date.parse(point.recorded_at)||Date.now();
        const windowMs=this.trafficTarget?.chassis_type==='C1600'?180000:90000;
        const cutoffMs=nowMs-windowMs;
        const existing=(data.points||[]).filter(p=>{
            const t=Date.parse(p.recorded_at);
            return Number.isFinite(t)&&t>=cutoffMs&&t<nowMs;
        });
        existing.push(point);

        // Smooth rolling live window: grow from first point up to 90s so chart fills cleanly without cramping right
        const firstMs=Date.parse(existing[0]?.recorded_at)||cutoffMs;
        const spanStart=existing.length>=2?Math.max(cutoffMs,Math.min(firstMs,nowMs-Math.max(20000,nowMs-firstMs))):nowMs-30000;

        const stats={rx:{...data.statistics?.rx},tx:{...data.statistics?.tx}};
        for(const key of ['rx','tx']){
            const vals=existing.map(p=>p[key+'_bps']).filter(v=>v!=null&&Number.isFinite(v));
            stats[key]={
                ...stats[key],
                current:point[key+'_bps']??stats[key]?.current??null,
                peak:vals.length?Math.max(...vals):null,
                minimum:vals.length?Math.min(...vals):null,
                average:vals.length?(vals.reduce((a,b)=>a+b,0)/vals.length):null,
            };
        }

        const nextData={
            ...data,
            from:new Date(spanStart).toISOString(),
            to:new Date(nowMs).toISOString(),
            points:existing,
            chart_baseline:null,
            statistics:stats,
            sample_count:existing.length,
            last_recorded_at:point.recorded_at,
            source:point.source,
            longRange:false,
        };
        this.trafficData=nextData;
        if(this.trafficPinned)this.trafficHover=Math.max(0,existing.length-1);
        else this.trafficHover=Math.min(this.trafficHover,Math.max(0,existing.length-1));
        this.$nextTick(async()=>{const m=await ensureChartMod();if(this.trafficTarget&&this.trafficRange==='live')m.renderTrafficChart(this.$refs.trafficCanvas,nextData);});
        return true;
    },
    async loadTraffic(live=false){
        if(!this.trafficTarget||this.trafficBusy||this.trafficLiveTicking||document.hidden)return;
        const epoch=this.trafficEpoch,target=this.trafficTarget,base='/traffic/'+target.kind+'/'+target.id;
        const isIncrementalLive=live&&this.trafficRange==='live'&&!!this.trafficData;
        if(isIncrementalLive)this.trafficLiveTicking=true;
        else this.trafficBusy=true;
        this.trafficError='';
        const controller=new AbortController();
        this.trafficAbort=controller;
        const startedAt=performance.now();
        try{
            let liveResult=null;
            if(live&&!document.hidden&&!this.trafficPaused){
                let skipped=false;
                try{
                    liveResult=await this.request(base+'/live','POST',undefined,{signal:controller.signal});
                    skipped=liveResult.skipped===true;
                    const interval=Number(liveResult.poll_after_seconds);
                    if(Number.isFinite(interval)&&interval>0)this.trafficPollSeconds=Math.max(this.trafficTarget?.chassis_type==='C1600'?15:1,interval);
                }catch(e){
                    // Only lock contention is silent; permission/identity failures stay visible.
                    skipped=e.reason==='read_busy'||(e.status===409&&e.message==='OLT sedang menjalankan operasi lain. Tunggu hingga selesai.');
                    if(e.name==='AbortError')return;
                    if(!skipped&&epoch===this.trafficEpoch){this.trafficError=e.message;if([403,422].includes(e.status)||e.reason==='authentication_failed')this.trafficPaused=true;}
                }
                if(epoch!==this.trafficEpoch)return;
                // The first busy cycle still loads persisted history; later skips keep the exact plot.
                if(skipped&&this.trafficData)return;
                // Fast live path: append returned sample directly without a 2nd HTTP + SQL round-trip!
                if(isIncrementalLive&&liveResult?.sample?.recorded_at){
                    this.applyLiveSampleInPlace(liveResult.sample);
                    return;
                }
            }
            if(epoch!==this.trafficEpoch)return;
            const data=await this.request(base+'?'+this.trafficQuery(),'GET',undefined,{signal:controller.signal});
            if(epoch!==this.trafficEpoch)return;
            if(this.trafficRange!=='custom')this.syncTrafficPresetDates(data);
            data.longRange=this.trafficRange==='custom'||Number(this.trafficRange)>=1440;
            // On live view, trim empty leading dead-space if we already have recent points
            if(this.trafficRange==='live'&&data.points?.length){
                const pts=data.points;
                const lastMs=Date.parse(pts[pts.length-1].recorded_at);
                const firstMs=Date.parse(pts[0].recorded_at);
                const windowMs=this.trafficTarget?.chassis_type==='C1600'?180000:90000;
                if(Number.isFinite(lastMs)&&Number.isFinite(firstMs)){
                    const startMs=Math.max(lastMs-windowMs,Math.min(firstMs,lastMs-30000));
                    data.from=new Date(startMs).toISOString();
                    data.to=new Date(lastMs).toISOString();
                    data.chart_baseline=null;
                }
            }
            this.trafficData=data;
            this.trafficHover=Math.max(0,data.points.length-1);
            this.$nextTick(async()=>{const m=await ensureChartMod();if(epoch===this.trafficEpoch)m.renderTrafficChart(this.$refs.trafficCanvas,data);});
        }catch(e){
            if(e.name!=='AbortError'&&epoch===this.trafficEpoch){this.trafficError=e.message;if([403,422].includes(e.status)||e.reason==='authentication_failed')this.trafficPaused=true;}
        }finally{
            if(epoch===this.trafficEpoch){
                this.trafficBusy=false;
                this.trafficLiveTicking=false;
                clearTimeout(this.trafficTimer);
                if(this.trafficRange==='live'&&!this.trafficPaused&&!document.hidden){
                    const elapsed=performance.now()-startedAt;
                    const delay=Math.max(350,(this.trafficPollSeconds*1000)-elapsed);
                    this.trafficTimer=setTimeout(()=>this.loadTraffic(true),delay);
                }
            }
        }
    },
    toggleTrafficPause(){this.trafficPaused=!this.trafficPaused;this.suspendTraffic();if(!this.trafficPaused)this.loadTraffic(true);},
    trafficUnit(value,bytes=false){if(value==null||!Number.isFinite(Number(value)))return '—';const units=bytes?['B','KB','MB','GB','TB']:['bps','Kbps','Mbps','Gbps','Tbps'];let n=Number(value),i=0;while(n>=1000&&i<4){n/=1000;i++;}return n.toLocaleString('en-US',{maximumFractionDigits:2})+' '+units[i];},
    trafficChartAction(action){chartMod?.trafficChartAction(this.$refs.trafficCanvas,action);if(['pan','select'].includes(action))this.trafficChartMode=action;},
    trafficFocusPoint(){this.trafficPinned=this.trafficHover>=Math.max(0,(this.trafficData?.points?.length||1)-1);chartMod?.focusTrafficPoint(this.$refs.trafficCanvas,this.trafficPoint());},
    async downloadTraffic(type){if(this.trafficData){const m=await ensureChartMod();m.downloadTraffic(this.$refs.trafficCanvas,this.trafficData,type);}this.trafficDownloadMenu=false;},
    trafficByteRate(bps){if(bps==null||!Number.isFinite(Number(bps)))return '\u2014';let n=Number(bps)/8,i=0;const units=['B/s','KB/s','MB/s','GB/s','TB/s'];while(n>=1024&&i<4){n/=1024;i++;}return n.toLocaleString('en-US',{maximumFractionDigits:2})+' '+units[i];},
    trafficCaption(){const olt=this.db.olts.find(item=>item.id===this.trafficTarget?.oltId)||this.selected;const scope=this.trafficTarget?.chassis_type==='C1600'?(this.trafficTarget?.kind==='onus'?'Traffic PPPoE ONU dari router MikroTik. ':'Traffic agregasi port PON. '):'';if(this.trafficRange==='live')return scope+'Live streaming langsung ke grafik tiap '+this.trafficPollSeconds+' detik (jendela geser 90 detik).';const label=this.trafficRange==='custom'?'rentang pilihan':(this.trafficPresets.find(p=>p[0]===this.trafficRange)?.[1]||'').toLowerCase()+' terakhir';return scope+'Riwayat '+label+' dari database, dirangkum otomatis sesuai rentang waktu. Pilih Live Traffic untuk pemantauan langsung.';},
    trafficPoint(){return this.trafficData?.points?.[this.trafficHover]||{};},
};}
