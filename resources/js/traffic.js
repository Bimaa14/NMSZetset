import {wibDate} from './traffic-domain';
import {renderTrafficChart,destroyTrafficChart,trafficChartAction,focusTrafficPoint,downloadTraffic} from './traffic-chart';
export function trafficModule(){return {
    trafficAbort:null,trafficTarget:null,trafficRange:'live',trafficData:null,trafficError:'',trafficBusy:false,trafficTimer:null,trafficEpoch:0,trafficPaused:false,trafficPollSeconds:2,trafficHover:0,trafficDateMode:'date',trafficFrom:'',trafficTo:'',trafficCustom:null,trafficChartMode:'select',trafficDownloadMenu:false,
    trafficPresets:[['live','Live Traffic'],['30','30 Menit'],['60','1 Jam'],['360','6 Jam'],['1440','24 Jam'],['10080','7 Hari'],['43200','30 Hari']],
    async openTraffic(kind,entity){const olt=kind==='onus'?this.oltForOnu(entity):this.db.olts.find(item=>item.id===entity.olt_id)||this.selected;if(kind==='onus'?!this.supportsFeature(olt,'onu_traffic'):!this.portTrafficSupported(entity))return;this.closeTraffic();this.trafficTarget={kind,id:entity.id,name:entity.name||entity.interface_name,oltId:olt?.id,chassis_type:olt?.chassis_type};this.trafficPollSeconds=this.isYotc(olt)?15:2;this.trafficRange='live';this.syncTrafficPresetDates();this.trafficChartMode='select';this.trafficPaused=false;this.trafficData=null;this.trafficHover=0;this.trafficCustom=null;this.$refs.trafficDialog.showModal();const epoch=this.trafficEpoch;await this.loadTraffic(false);if(epoch===this.trafficEpoch&&!this.trafficPaused)await this.loadTraffic(true);},
    suspendTraffic(){this.trafficEpoch++;clearTimeout(this.trafficTimer);this.trafficTimer=null;this.trafficAbort?.abort();this.trafficAbort=null;this.trafficBusy=false;},
    closeTraffic(){this.suspendTraffic();destroyTrafficChart(this.$refs.trafficCanvas);this.trafficDownloadMenu=false;this.trafficEpoch++;clearTimeout(this.trafficTimer);this.$refs.trafficDialog?.close();this.trafficTarget=null;this.trafficBusy=false;},
    async setTrafficRange(range){this.suspendTraffic();destroyTrafficChart(this.$refs.trafficCanvas);this.trafficChartMode='select';this.trafficEpoch++;clearTimeout(this.trafficTimer);this.trafficBusy=false;this.trafficRange=range;if(range!=='custom')this.syncTrafficPresetDates();this.trafficPaused=false;await this.loadTraffic(range==='live');},
    syncTrafficPresetDates(data=null){const end=data?.to||Date.now(),start=data?.from||(Number(end)-(this.trafficRange==='live'?5:Number(this.trafficRange))*60000);this.trafficDateMode='date';this.trafficFrom=wibDate(start);this.trafficTo=wibDate(end);},
    async applyTrafficDates(){
        if(!this.trafficFrom||!this.trafficTo){this.trafficError='Isi tanggal atau bulan awal dan akhir.';return;}
        const from=new Date(this.trafficFrom+(this.trafficDateMode==='month'?'-01':'')+'T00:00:00+07:00');
        let end=this.trafficTo;if(this.trafficDateMode==='month'){const [y,m]=end.split('-').map(Number);end=new Date(Date.UTC(y,m,0)).toISOString().slice(0,10);}
        const to=new Date(end+'T23:59:59+07:00');
        if(!Number.isFinite(+from)||!Number.isFinite(+to)||from>=to||to-from>366*86400000){this.trafficError='Rentang maksimal 366 hari, dengan akhir setelah awal.';return;}
        this.trafficCustom={from:from.toISOString().replace('.000Z','+00:00'),to:to.toISOString().replace('.000Z','+00:00')};await this.setTrafficRange('custom');
    },
    trafficQuery(){return new URLSearchParams(this.trafficRange==='custom'?this.trafficCustom:{minutes:this.trafficRange==='live'?5:this.trafficRange}).toString();},
    async loadTraffic(live=false){
        if(!this.trafficTarget||this.trafficBusy||document.hidden)return;const epoch=this.trafficEpoch,target=this.trafficTarget,base='/traffic/'+target.kind+'/'+target.id;this.trafficBusy=true;this.trafficError='';const controller=new AbortController();this.trafficAbort=controller;
        try{
            if(live&&!document.hidden&&!this.trafficPaused){
                let skipped=false;
                try{const result=await this.request(base+'/live','POST',undefined,{signal:controller.signal});skipped=result.skipped===true;const interval=Number(result.poll_after_seconds);if(Number.isFinite(interval)&&interval>0)this.trafficPollSeconds=Math.max(this.trafficTarget?.chassis_type==='C1600'?15:1,interval);}
                catch(e){
                    // Only lock contention is silent; permission/identity failures stay visible.
                    skipped=e.reason==='read_busy'||(e.status===409&&e.message==='OLT sedang menjalankan operasi lain. Tunggu hingga selesai.');
                    if(e.name==='AbortError')return;if(!skipped&&epoch===this.trafficEpoch){this.trafficError=e.message;if([403,422].includes(e.status)||e.reason==='authentication_failed')this.trafficPaused=true;}
                }
                if(epoch!==this.trafficEpoch)return;
                // The first busy cycle still loads persisted history; later skips keep the exact plot.
                if(skipped&&this.trafficData)return;
            }
            if(epoch!==this.trafficEpoch)return;
            const data=await this.request(base+'?'+this.trafficQuery(),'GET',undefined,{signal:controller.signal});if(epoch!==this.trafficEpoch)return;if(this.trafficRange!=='custom')this.syncTrafficPresetDates(data);data.longRange=this.trafficRange==='custom'||Number(this.trafficRange)>=1440;this.trafficData=data;this.trafficHover=Math.max(0,data.points.length-1);this.$nextTick(()=>{if(epoch===this.trafficEpoch)renderTrafficChart(this.$refs.trafficCanvas,data);});
        }catch(e){if(e.name!=='AbortError'&&epoch===this.trafficEpoch){this.trafficError=e.message;if([403,422].includes(e.status)||e.reason==='authentication_failed')this.trafficPaused=true;}}
        finally{if(epoch===this.trafficEpoch){this.trafficBusy=false;clearTimeout(this.trafficTimer);if(this.trafficRange==='live'&&!this.trafficPaused&&!document.hidden)this.trafficTimer=setTimeout(()=>this.loadTraffic(true),this.trafficPollSeconds*1000);}}
    },
    toggleTrafficPause(){this.trafficPaused=!this.trafficPaused;this.suspendTraffic();if(!this.trafficPaused)this.loadTraffic(true);},
    trafficUnit(value,bytes=false){if(value==null||!Number.isFinite(Number(value)))return '—';const units=bytes?['B','KB','MB','GB','TB']:['bps','Kbps','Mbps','Gbps','Tbps'];let n=Number(value),i=0;while(n>=1000&&i<4){n/=1000;i++;}return n.toLocaleString('en-US',{maximumFractionDigits:2})+' '+units[i];},
    trafficChartAction(action){trafficChartAction(this.$refs.trafficCanvas,action);if(['pan','select'].includes(action))this.trafficChartMode=action;},
    trafficFocusPoint(){focusTrafficPoint(this.$refs.trafficCanvas,this.trafficPoint());},
    downloadTraffic(type){if(this.trafficData)downloadTraffic(this.$refs.trafficCanvas,this.trafficData,type);this.trafficDownloadMenu=false;},
    trafficByteRate(bps){if(bps==null||!Number.isFinite(Number(bps)))return '\u2014';let n=Number(bps)/8,i=0;const units=['B/s','KB/s','MB/s','GB/s','TB/s'];while(n>=1024&&i<4){n/=1024;i++;}return n.toLocaleString('en-US',{maximumFractionDigits:2})+' '+units[i];},
    trafficCaption(){const olt=this.db.olts.find(item=>item.id===this.trafficTarget?.oltId)||this.selected;const scope=this.trafficTarget?.chassis_type==='C1600'?(this.trafficTarget?.kind==='onus'?'Traffic PPPoE ONU dari router MikroTik. ':this.supportsFeature(olt,'onu_traffic')?'Traffic total port PON. ':'Traffic total port PON; traffic per ONU belum tersedia. '):'';if(this.trafficRange==='live')return scope+'Membaca perangkat setiap '+this.trafficPollSeconds+' detik setelah respons selesai. Siklus sibuk dilewati; sampel terakhir dipertahankan.';const label=this.trafficRange==='custom'?'rentang pilihan':(this.trafficPresets.find(p=>p[0]===this.trafficRange)?.[1]||'').toLowerCase()+' terakhir';return scope+'Riwayat '+label+' dari database, dirangkum otomatis sesuai panjang rentang. Pilih Live Traffic untuk membaca perangkat sekarang.';},
    trafficPoint(){return this.trafficData?.points?.[this.trafficHover]||{};},
};}
