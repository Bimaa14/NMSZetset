export function onuDetailModule(){return {
    detailAbort:null,detailPrefetchAbort:null,detailInitialLoading:false,detailTab:'info',detailSections:{},detailMeta:{},detailBusy:false,detailPrefetching:false,detailError:'',detailAuto:true,detailTimer:null,detailClock:null,detailEpoch:0,detailNow:Date.now(),detailPanel:'',detailTag:'',detailSecrets:{},detailEdit:null,detailNodes:[],detailOdp:{node_id:'',port_number:1},detailConfirm:null,
    detailTabs:[{key:'info',name:'Info perangkat'},{key:'wan',name:'WAN'},{key:'ethernet',name:'Port Ethernet'},{key:'iphost',name:'IP host'},{key:'wifi',name:'WiFi'}],
    detailAvailableTabs(){return this.isYotc(this.oltForOnu(this.inspectedOnu))?this.detailTabs.filter(tab=>tab.key==='info'):this.detailTabs;},
    detailPonTrafficPort(){const olt=this.oltForOnu(this.inspectedOnu);if(!this.isYotc(olt))return null;const port=(olt.ports||[]).find(item=>Number(item.id)===Number(this.inspectedOnu?.olt_port_id));return port&&this.portTrafficSupported(port)?port:null;},
    openDetailPonTraffic(){const port=this.detailPonTrafficPort();if(port)return this.openTraffic('ports',port);},
    async openOnuInspector(onu){
        this.suspendOnuDetail();const epoch=this.detailEpoch;this.startDetailClock();this.detailAbort=new AbortController();this.inspectedOnu=onu;this.onuNameDraft=onu.name;this.onuLocationDraft={latitude:onu.latitude,longitude:onu.longitude};this.onuHistory=null;
        this.detailTab='info';this.detailSections={};this.detailMeta={};this.detailError='';this.error='';this.detailPanel='';this.detailSecrets={};this.detailEdit=null;this.detailBusy=false;this.detailPrefetching=false;this.detailAuto=!this.isYotc(this.oltForOnu(onu));
        this.detailInitialLoading=true;this.$refs.onuDialog.showModal();
        try{
            const data=await this.request('/onus/'+onu.id+'/detail','GET',undefined,{signal:this.detailAbort.signal});
            if(epoch!==this.detailEpoch)return;
            this.detailMeta=data;this.detailSections=data.sections||{};this.detailInitialLoading=false;
            await this.refreshOnuSection('info');
            if(epoch===this.detailEpoch&&this.$refs.onuDialog.open&&!this.isYotc(this.oltForOnu(onu))){
                this.prefetchOnuTabs(this.detailTab!=='info'?this.detailTab:null);
            }
        }catch(e){if(e.name!=='AbortError'&&epoch===this.detailEpoch)this.detailError=e.message;}
        finally{if(epoch===this.detailEpoch)this.detailInitialLoading=false;}
        if(epoch===this.detailEpoch)this.scheduleOnuDetail();
    },
    startDetailClock(){clearInterval(this.detailClock);if(!document.hidden)this.detailClock=setInterval(()=>{this.detailNow=Date.now();},5000);},
    suspendOnuDetail(){this.detailEpoch++;clearTimeout(this.detailTimer);clearTimeout(this.detailRetryTimer);clearInterval(this.detailClock);this.detailAbort?.abort();this.detailAbort=null;this.detailPrefetchAbort?.abort();this.detailPrefetchAbort=null;this.detailBusy=false;this.detailPrefetching=false;},
    closeOnuInspector(){this.cancelRebootWait();this.suspendOnuDetail();this.$refs.onuDialog.close();this.inspectedOnu=null;this.detailSecrets={};this.detailEdit=null;this.detailConfirm=null;},
    detailData(section=this.detailTab){return this.detailSections[section]?.data||{};},
    detailItems(){return this.detailData().items||[];},
    detailValue(key){const value=this.detailData('info')[key];if(Object.hasOwn(this.detailData('info'),key))return value;return this.inspectedOnu?.[key]??null;},
    detailSubtitle(){const o=this.inspectedOnu;if(!o)return '';const m=(o.interface||'').match(/(?:(\d+)\/)?(\d+)\/(\d+):(\d+)/);return this.oltName(o.olt_id)+(m?' · Slot '+m[2]+' / PON '+m[3]+' / ID '+m[4]:'')+' · SN '+o.sn;},
    detailAge(){
        const stamp=this.detailSections[this.detailTab]?.observed_at||this.inspectedOnu?.state_observed_at;
        if(!stamp)return 'Belum ada pembacaan live';
        const seconds=Math.max(0,Math.floor((this.detailNow-new Date(stamp).getTime())/1000));
        if(seconds<60)return 'Diperbarui '+seconds+' detik lalu';
        const minutes=Math.floor(seconds/60);
        if(minutes<60)return 'Diperbarui '+minutes+' menit lalu';
        const hours=Math.floor(minutes/60);
        if(hours<24)return 'Diperbarui '+hours+' jam lalu';
        return 'Diperbarui '+Math.floor(hours/24)+' hari lalu';
    },
    detailSectionFresh(sec,maxAgeMs=180000){
        const entry=this.detailSections[sec];
        if(!entry)return false;
        if(entry.unavailable)return true;
        if(!entry.observed_at)return false;
        return (Date.now()-new Date(entry.observed_at).getTime())<maxAgeMs;
    },
    async selectOnuTab(tab){
        if(!this.detailAvailableTabs().some(item=>item.key===tab))return;
        this.detailTab=tab;this.detailPanel='';this.detailSecrets={};this.detailError='';
        if(!this.detailSections[tab]){
            if(this.detailPrefetching||this.detailBusy)return;
            if(['wan','ethernet','iphost','wifi'].includes(tab))await this.prefetchOnuTabs(tab);
            else await this.refreshOnuSection(tab);
        }
    },
    onuTabKey(event,index){const tabs=this.detailAvailableTabs(),count=tabs.length;let next=index;if(event.key==='ArrowRight')next=(index+1)%count;else if(event.key==='ArrowLeft')next=(index+count-1)%count;else if(event.key==='Home')next=0;else if(event.key==='End')next=count-1;else return;event.preventDefault();this.selectOnuTab(tabs[next].key);document.getElementById('onu-tab-'+tabs[next].key)?.focus();},
    async prefetchOnuTabs(priorityTab=null){
        if(!this.inspectedOnu||this.isYotc(this.oltForOnu(this.inspectedOnu))||this.detailBusy||this.detailPrefetching||document.hidden)return;
        const candidates=['wan','ethernet','iphost','wifi'].filter(sec=>!this.detailSectionFresh(sec));
        if(priorityTab&&['wan','ethernet','iphost','wifi'].includes(priorityTab)&&!this.detailSections[priorityTab]){
            const idx=candidates.indexOf(priorityTab);
            if(idx>=0)candidates.splice(idx,1);
            candidates.unshift(priorityTab);
        }
        if(!candidates.length)return;
        const id=this.inspectedOnu.id,epoch=this.detailEpoch;
        this.detailPrefetching=true;this.detailPrefetchAbort=new AbortController();
        try{
            const payload=candidates.length>1?{section:candidates[0],sections:candidates}:{section:candidates[0]};
            const data=await this.request('/onus/'+id+'/detail/refresh','POST',payload,{signal:this.detailPrefetchAbort.signal});
            if(epoch!==this.detailEpoch||data.skipped)return;
            if(data.sections&&typeof data.sections==='object'){
                this.detailSections={...this.detailSections,...data.sections};
            }else{
                this.detailSections={...this.detailSections,[candidates[0]]:data};
            }
            this.detailNow=Date.now();
        }catch(e){
            if(e.name!=='AbortError'&&epoch===this.detailEpoch&&priorityTab&&!this.detailSections[priorityTab])this.detailError=e.message;
        }finally{
            if(epoch===this.detailEpoch){
                this.detailPrefetching=false;
                this.detailPrefetchAbort=null;
                if(this.detailTab!=='info'&&!this.detailSections[this.detailTab]&&!this.detailBusy&&!this.detailError){
                    await this.refreshOnuSection(this.detailTab);
                }
            }
        }
    },
    detailRetryTimer:null,
    async refreshOnuSection(section=this.detailTab,retryAttempt=0){
        if(!this.inspectedOnu||this.isYotc(this.oltForOnu(this.inspectedOnu))||this.detailBusy||document.hidden)return;
        if(this.detailPrefetching){this.detailPrefetchAbort?.abort();this.detailPrefetchAbort=null;this.detailPrefetching=false;}
        clearTimeout(this.detailRetryTimer);
        const id=this.inspectedOnu.id,epoch=this.detailEpoch;this.detailBusy=true;this.detailError='';this.detailAbort=new AbortController();
        try{
            const data=await this.request('/onus/'+id+'/detail/refresh','POST',{section},{signal:this.detailAbort.signal});
            if(epoch!==this.detailEpoch)return;
            if(data.skipped){
                if(retryAttempt<3&&this.$refs.onuDialog?.open){
                    this.detailRetryTimer=setTimeout(()=>{if(epoch===this.detailEpoch&&this.$refs.onuDialog?.open&&!this.detailBusy)this.refreshOnuSection(section,retryAttempt+1);},3000);
                }
                return;
            }
            this.detailSections={...this.detailSections,[section]:data};this.detailSecrets={};this.detailNow=Date.now();
        }
        catch(e){if(e.name!=='AbortError'&&epoch===this.detailEpoch)this.detailError=e.message;}
        finally{
            if(epoch===this.detailEpoch){
                this.detailBusy=false;
                if(section!==this.detailTab&&section!=='config'&&!this.detailSections[this.detailTab]){
                    if(['wan','ethernet','iphost','wifi'].includes(this.detailTab))await this.prefetchOnuTabs(this.detailTab);
                    else await this.refreshOnuSection(this.detailTab);
                }
            }
        }
    },
    scheduleOnuDetail(){clearTimeout(this.detailTimer);if(document.hidden||!this.inspectedOnu||!this.detailAuto)return;this.detailTimer=setTimeout(async()=>{if(!this.$refs.onuDialog.open)return;this.detailNow=Date.now();if(this.detailAuto&&!this.trafficTarget&&!document.hidden&&!this.detailBusy&&!this.detailPrefetching&&!this.detailEdit){if(this.detailPanel==='traffic')await this.loadOnuHistory();else await this.refreshOnuSection();}this.scheduleOnuDetail();},30000);},
    toggleOnuAuto(){this.detailAuto=!this.detailAuto;if(!this.detailAuto)this.suspendOnuDetail();else {this.startDetailClock();this.scheduleOnuDetail();}},
    async showOnuPanel(panel){if(['config','edit','service'].includes(panel)&&!this.onuSupports(this.inspectedOnu,'onu_configuration'))return;if(panel==='traffic'&&!this.onuSupports(this.inspectedOnu,'onu_traffic'))return;this.detailPanel=this.detailPanel===panel?'':panel;this.detailEdit=null;this.focusOnuPanel();try{if(['optical','status','traffic'].includes(panel))await this.loadOnuHistory();if(panel==='config')await this.refreshOnuSection('config');if(panel==='edit'){this.detailDescription=this.detailData('info').description||this.detailMeta.description||'';if(this.db.permissions.maps){const data=await this.request('/ftth');this.detailNodes=data.nodes.filter(n=>n.type==='ODP'&&n.olt_id===this.inspectedOnu?.olt_id);}}}catch(e){this.detailError=e.message;}},
    focusOnuPanel(){this.$nextTick(()=>{const panel=[...this.$refs.onuDialog.querySelectorAll('.onu-extra-panel')].find(e=>e.getClientRects().length);panel?.scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});});},
    detailDescription:'',
    async detailAssignOdp(){try{await this.request('/ftth/nodes/'+this.detailOdp.node_id+'/assign','POST',{onu_id:this.inspectedOnu.id,port_number:Number(this.detailOdp.port_number)});this.detailMeta=await this.request('/onus/'+this.inspectedOnu.id+'/detail');this.notify('Hubungan ODP disimpan.');}catch(e){this.detailError=e.message;}},
    async detailSetTag(tag,remove=false){if(!this.inspectedOnu)return;const onuId=this.inspectedOnu.id,epoch=this.detailEpoch,originalIds=this.inspectedOnu.tags.map(t=>t.id);try{let id=tag?.id;if(!id){const name=this.detailTag.trim();if(!name)return;id=this.db.tags.find(t=>t.tag_name.toLowerCase()===name.toLowerCase())?.id;if(!id){await this.request('/tags','POST',{tag_name:name,color_hex:'#60a5fa'});await this.reload();id=this.db.tags.find(t=>t.tag_name===name)?.id;}}if(!id)return;const ids=new Set(originalIds);remove?ids.delete(id):ids.add(id);await this.request('/onus/'+onuId+'/tags','PUT',{tag_ids:[...ids]});await this.reload();if(epoch===this.detailEpoch)this.detailTag='';}catch(e){if(e.name!=='AbortError'&&epoch===this.detailEpoch)this.detailError=e.message;}},
    async revealOnuSecret(item){if(!this.onuSupports(this.inspectedOnu,'onu_configuration'))return;const key=this.detailTab+':'+item.id,epoch=this.detailEpoch,section=this.detailTab;if(this.detailSecrets[key]){delete this.detailSecrets[key];return;}try{const result=await this.request('/onus/'+this.inspectedOnu.id+'/detail/secret','POST',{section,item_id:item.id});if(epoch===this.detailEpoch&&section===this.detailTab)this.detailSecrets[key]=result.password||'Belum tersedia';}catch(e){if(e.name!=='AbortError'&&epoch===this.detailEpoch)this.detailError=e.message;}},
    detailStatus(value){return ['up','active','enable','enabled','unlock','connected','connected.','working','online'].includes(String(value||'').toLowerCase());},
    detailUpCount(){return this.detailItems().filter(i=>this.detailStatus(i.operational||i.status||i.admin)).length;},
    chartPoints(kind){const rows=[...(this.onuHistory?.[kind==='traffic'?'traffic':'optical']||[])].reverse().filter(r=>Number.isFinite(Number(r[kind==='traffic'?'download_bps':'rx_power']))&&r[kind==='traffic'?'download_bps':'rx_power']!=null);if(!rows.length)return '';const values=rows.map(r=>Number(r[kind==='traffic'?'download_bps':'rx_power']));const min=Math.min(...values),range=Math.max(...values)-min||1;return values.map((v,i)=>(20+i*560/Math.max(1,values.length-1))+','+(150-(v-min)*120)).join(' ');},
    editOnuService(kind,item={}){if(!this.onuSupports(this.inspectedOnu,'onu_configuration'))return;this.detailEdit={kind,id:item.id||1,username:item.username||'',password:'',vlan_profile:item.vlan_profile||'',host:item.host||1,enabled:this.detailStatus(item.admin),name:item.name||'',description:this.detailDescription};this.detailPanel='service';this.focusOnuPanel();},
    async saveOnuService(){
        const data={...this.detailEdit,confirmed:true};
        const ok=await this.askConfirm({title:'Terapkan Perubahan '+data.kind.toUpperCase()+'?',message:'Perubahan konfigurasi akan langsung dikirim dan diverifikasi pada OLT.',detail:this.onuDisplayName(this.inspectedOnu)+' · '+this.inspectedOnu.sn,confirmText:'Ya, Terapkan',tone:'primary'});
        if(!ok)return;
        await this.executeOnuConfiguration(data);
    },
    async deleteOnuWan(id){
        if(!this.inspectedOnu||!this.onuSupports(this.inspectedOnu,'onu_configuration'))return;
        const ok=await this.askConfirm({title:'Hapus Konfigurasi WAN '+id+'?',message:'Konfigurasi WAN ini akan dihapus dari ONU di OLT dan koneksi dapat terputus.',detail:this.onuDisplayName(this.inspectedOnu)+' · WAN '+id,confirmText:'Ya, Hapus WAN',tone:'danger'});
        if(!ok)return;
        await this.executeOnuConfiguration({kind:'wan',id,delete:true,confirmed:true});
    },
    async saveOnuDescription(){
        if(!this.inspectedOnu||!this.onuSupports(this.inspectedOnu,'onu_configuration'))return;
        const ok=await this.askConfirm({title:'Simpan Deskripsi ke OLT?',message:'Deskripsi ONU akan diperbarui langsung pada konfigurasi OLT.',detail:this.onuDisplayName(this.inspectedOnu)+' → '+this.detailDescription,confirmText:'Ya, Simpan',tone:'primary'});
        if(!ok)return;
        await this.executeOnuConfiguration({kind:'description',description:this.detailDescription,confirmed:true});
    },
    async executeOnuConfiguration(data){if(this.detailBusy||!this.inspectedOnu||!this.onuSupports(this.inspectedOnu,'onu_configuration'))return;const epoch=this.detailEpoch;this.detailBusy=true;this.detailError='';try{const result=await this.request('/onus/'+this.inspectedOnu.id+'/detail/configure','POST',data);this.notify(result.message);if(epoch===this.detailEpoch){this.detailEdit=null;this.detailPanel='';this.detailSections={};}}catch(e){if(e.name!=='AbortError'&&epoch===this.detailEpoch)this.detailError=e.message;}finally{if(epoch===this.detailEpoch)this.detailBusy=false;}},
    async detailAction(kind){
        const onu=this.inspectedOnu;if(!onu||this.rebootState||!this.onuSupports(onu,kind==='reboot'?'reboot':'onu_configuration'))return;
        this.$refs.onuDialog?.querySelectorAll('.onu-actions-menu[open]').forEach(el=>el.removeAttribute('open'));
        const enable=onu.admin_state==='disable';
        const prompts={
            reboot:{title:'Reboot ONU?',message:'Perintah restart akan dikirim ke OLT. Koneksi pelanggan akan terputus sementara selama ONU menyala ulang.',confirmText:'Ya, Reboot ONU',tone:'warning'},
            admin:{title:(enable?'Aktifkan':'Nonaktifkan')+' ONU?',message:enable?'Layanan ONU akan diaktifkan kembali di OLT.':'Layanan pelanggan pada ONU ini akan dinonaktifkan sementara.',confirmText:enable?'Ya, Aktifkan':'Ya, Nonaktifkan',tone:enable?'primary':'warning'},
            factory:{title:'Reset Pabrik ONU?',message:'Reset pabrik menghapus seluruh pengaturan pada ONU dan dapat memutus layanan pelanggan.',confirmText:'Ya, Reset Pabrik',tone:'danger'},
            delete:{title:'Hapus ONU dari OLT?',message:'Pendaftaran ONU ini akan dihapus permanen dari OLT dan layanan akan terputus.',confirmText:'Ya, Hapus ONU',tone:'danger'},
        };
        const cfg=prompts[kind]||{title:'Konfirmasi Tindakan',message:'Jalankan '+kind+' untuk ONU ini?',confirmText:'Lanjutkan',tone:'warning'};
        const ok=await this.askConfirm({...cfg,detail:this.onuDisplayName(onu)+' · '+onu.sn});
        if(!ok)return;
        if(kind==='factory')return this.executeOnuConfiguration({kind:'factory',confirmed:true});
        if(kind==='reboot'){
            const epoch=this.detailEpoch;this.detailError='';this.error='';
            try{
                const result=await this.requestOnuReboot(onu.id);
                if(result){
                    this.notify(result.message);
                    await this.reload();
                    if(epoch===this.detailEpoch&&this.$refs.onuDialog.open){
                        await this.refreshOnuSection('info');
                        setTimeout(()=>{if(epoch===this.detailEpoch&&this.$refs.onuDialog.open&&!this.detailBusy)this.refreshOnuSection('info');},8000);
                    }
                }
            }
            catch(e){if(epoch===this.detailEpoch)this.detailError=e.message;else this.notify(e.message,true);}
            return;
        }
        try{await this.request('/onus/'+onu.id+(kind==='delete'?'':kind==='reboot'?'/reboot':'/admin'),kind==='delete'?'DELETE':kind==='reboot'?'POST':'PUT',kind==='admin'?{enabled:enable}:undefined);if(kind==='delete')this.closeOnuInspector();await this.reload();this.notify('Permintaan '+kind+' diterima.');}catch(e){this.detailError=e.message;}
    },
};}
