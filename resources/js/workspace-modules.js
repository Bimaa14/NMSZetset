import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

let networkMap;let networkLayers;
function clearMap() { if(networkMap){networkMap.remove();networkMap=null;} }
function drawMap(container,nodes,onus,assignments,olts,selectNode,coordinates) {
    if(!container)return;const first=!networkMap||networkMap.getContainer()!==container;
    if(first){clearMap();networkMap=L.map(container).setView([-6.9175,107.6191],12);networkLayers=L.layerGroup().addTo(networkMap);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(networkMap);}
    networkLayers.clearLayers();
    const points=[]; const indexed=new Map(nodes.map(node=>[node.id,node]));
    for(const olt of olts.filter(o=>o.latitude!=null&&o.longitude!=null)){const point=[olt.latitude,olt.longitude];points.push(point);const text=document.createElement('strong');text.textContent=olt.name;L.circleMarker(point,{radius:12,color:'#60a5fa',fillOpacity:1}).addTo(networkLayers).bindPopup(text);}
    for(const node of nodes){
        const point=[Number(node.latitude),Number(node.longitude)]; points.push(point);
        const popup=document.createElement('strong'); popup.textContent=node.type+' '+node.name;
        L.circleMarker(point,{radius:9,color:node.type==='ODP'?'#22d3ee':'#a78bfa',fillOpacity:0.9}).addTo(networkLayers).bindPopup(popup).on('click',()=>selectNode(node));
        const parent=indexed.get(node.parent_id)||olts.find(o=>o.id===node.olt_id&&o.latitude!=null&&o.longitude!=null); if(parent)L.polyline([[parent.latitude,parent.longitude],point],{color:'#60a5fa',weight:3}).addTo(networkLayers);
    }
    for(const onu of onus.filter(o=>o.latitude!=null&&o.longitude!=null)){
        const point=[Number(onu.latitude),Number(onu.longitude)]; if(!point.every(Number.isFinite))continue; points.push(point);
        const popup=document.createElement('span'); popup.textContent=onu.name+' / '+onu.sn;
        const assigned=assignments.find(a=>a.onu_id===onu.id);const odp=indexed.get(assigned?.node_id);if(odp)L.polyline([[odp.latitude,odp.longitude],point],{color:'#34d399',weight:1,dashArray:'4,4'}).addTo(networkLayers);
        L.circleMarker(point,{radius:4,color:onu.phase_state==='working'?'#34d399':'#fb7185',fillOpacity:0.8}).addTo(networkLayers).bindPopup(popup);
    }
    if(first&&points.length)networkMap.fitBounds(points,{padding:[25,25],maxZoom:16});
    networkMap.off('click').on('click',event=>coordinates(event.latlng)); networkMap.invalidateSize();
}

export function workspaceModules(){return {
    backups:[],restoreBackupId:null,restoreQuery:'',restoreRows:[],restoreOnuId:'',restorePlan:null,restoreBusy:false,columnsOpen:false,
    apiInfo:{tokens:[],base_url:'',documentation_url:''},tokenForm:{name:'',days:30,scope:'read'},newToken:'',
    profileForm:{name:'',username:'',current_password:'',password:'',password_confirmation:''},
    notificationTab:'whatsapp',notificationView:'settings',
    notificationMeta:{has_telegram_bot_token:false,has_whatsapp_api_key:false},
    notificationLogs:[],
    notificationForm:{telegram_enabled:false,telegram_bot_token:'',telegram_chat_id:'',telegram_thread_id:'',whatsapp_enabled:false,whatsapp_provider:'baileys',whatsapp_endpoint_url:'http://127.0.0.1:3001/send-message',whatsapp_api_key:'',whatsapp_session_id:'default',whatsapp_recipients:'',notify_on_los:true,notify_on_dying_gasp:true,notify_on_recovery:true,notify_on_bad_rx:true,bad_rx_threshold:-27,cooldown_minutes:15},
    powerData:{monitors:[],monitor_groups:[],ungrouped_count:0,groups:[],summary:{total:0,up:0,warning:0,down:0,unknown:0,avg_latency_ms:null},settings:null,logs:[]},
    customerGroupData:{groups:[],summary:{total_groups:0,total_onus:0,grouped_onus:0,ungrouped_onus:0,with_vlan_onus:0,without_vlan_onus:0},available_vlans:[]},
    customerGroupForm:null,groupKeywordPreview:{loading:false,total:0,term:'',samples:[]},groupKeywordPreviewSeq:0,selectedGroupFilter:'all',groupOnus:{data:[],total:0,current_page:1,last_page:1},groupOnuQuery:'',groupOnuOlt:'all',groupOnuStatus:'all',groupOnuLoading:false,groupOnuRequest:0,groupSyncing:false,vlanChipsOpen:false,vlanChipSearch:'',
    activePage:({'#monitoring-onu':'power','#listrik':'power','#grup-pelanggan':'groups','#whatsapp':'whatsapp','#telegram':'telegram','#profil':'profile','#api':'api','#profil-layanan':'profiles','#tags':'tags','#staff':'staff','#audit':'audit'})[window.location.hash]||'dashboard',
    powerTab:'monitors',selectedPowerGroup:'',powerGroupForm:null,powerForm:null,powerOnuOptions:[],powerOnuSearch:'',powerFilter:{q:'',group:'all',status:'all'},powerChecking:false,powerCheckingId:null,
    powerSettingsForm:{power_notify_enabled:true,power_whatsapp_recipients:'',power_telegram_chat_id:'',power_telegram_thread_id:'',power_cooldown_minutes:5},
    ftth:{nodes:[],assignments:[]},nodeForm:null,mapOlt:'all',assignmentForm:{node_id:'',onu_id:'',port_number:1},
    pageFromHash(hash){return ({'#monitoring-onu':'power','#listrik':'power','#grup-pelanggan':'groups','#whatsapp':'whatsapp','#telegram':'telegram','#profil':'profile','#api':'api','#profil-layanan':'profiles','#tags':'tags','#staff':'staff','#audit':'audit'})[hash]||'dashboard';},
    pageLabel(){return ({dashboard:'Overview',power:'Monitoring ONU',groups:'Grup Pelanggan',whatsapp:'WhatsApp',telegram:'Telegram',profile:'Profil Anda',api:'Open API',profiles:'Profil Layanan',tags:'Tags Pelanggan',staff:'Staff Workspace',audit:'Audit Aktivitas'})[this.activePage]||'Overview';},
    showPage(page){
        const hashes={dashboard:'',power:'#monitoring-onu',groups:'#grup-pelanggan',whatsapp:'#whatsapp',telegram:'#telegram',profile:'#profil',api:'#api',profiles:'#profil-layanan',tags:'#tags',staff:'#staff',audit:'#audit'};
        const target=Object.hasOwn(hashes,page)?page:'dashboard';
        if(target==='api'&&!this.db?.permissions?.api)return this.showPage('dashboard');
        if(target==='profiles'&&!this.db?.permissions?.operate)return this.showPage('dashboard');
        if(target==='tags'&&!this.db?.permissions?.tags)return this.showPage('dashboard');
        if(target==='staff'&&!this.db?.permissions?.staff)return this.showPage('dashboard');
        if(target==='audit'&&!this.db?.permissions?.audit)return this.showPage('dashboard');
        this.activePage=target;
        if(this.modal&&this.$refs?.dialog?.open)this.closeModal();
        this.error='';this.validationErrors={};this.pendingAction=null;
        try{history.replaceState(null,'',hashes[target]||(window.location.pathname+window.location.search));}catch{}
        if(target==='power'){this.powerForm=null;this.powerGroupForm=null;this.loadPowerMonitors();}
        else if(target==='groups'){this.customerGroupForm=null;this.loadCustomerGroups();this.loadGroupOnus(1);}
        else if(['whatsapp','telegram'].includes(target)){this.notificationTab=target;this.loadNotifications();}
        else if(target==='profile'){this.profileForm={name:this.db.user.name,username:this.db.user.username,current_password:'',password:'',password_confirmation:''};}
        else if(target==='api'){this.newToken='';this.loadApiTokens();}
        else if(target==='profiles'){this.serviceProfileForm=null;this.loadServiceProfiles();}
        else if(target==='tags'){this.resetTag();}
        else if(target==='staff'){this.staffForm=null;}
        else if(target==='audit'){this.auditQuery='';this.auditAction='';this.auditFrom='';this.auditTo='';this.loadAudit();}
        window.scrollTo({top:0,behavior:'instant'});
    },
    onModuleOpen(type){
        if(type==='provision'){this.loadServiceProfiles();this.provisionPlan=null;this.discovery={onus:[],ports:[],registered:[]};this.provisionForm={profile_id:'',frame:1,slot:1,port:1,onu_index:1,sn:'',name:'',pppoe_user:'',pppoe_password:''};}
        if(type==='backups'){this.restoreBackupId=null;this.restorePlan=null;this.restoreRows=[];this.restoreOnuId='';this.loadBackups();}
        if(type==='maps'){this.nodeForm=null;this.loadFtth();}
    },
    onModuleClose(){this.provisionForm.pppoe_password='';this.provisionPlan=null;this.newToken='';this.profileForm.current_password='';this.profileForm.password='';this.profileForm.password_confirmation='';this.notificationForm.telegram_bot_token='';this.notificationForm.whatsapp_api_key='';this.powerForm=null;this.powerGroupForm=null;this.customerGroupForm=null;clearMap();},
    applyCustomerGroupPayload(data){
        this.customerGroupData={groups:data.groups||[],summary:data.summary||{total_groups:0,total_onus:0,grouped_onus:0,ungrouped_onus:0,with_vlan_onus:0,without_vlan_onus:0},available_vlans:data.available_vlans||[]};
        if(this.db)this.db.customer_groups=(data.groups||[]).map(g=>({id:g.id,name:g.name,color_hex:g.color_hex,vlan_ids:g.vlan_ids,name_keywords:g.name_keywords||[],description:g.description}));
    },
    customerGroupRuleLabel(g){
        const parts=[];
        if((g.vlan_ids||[]).length)parts.push('VLAN '+(g.vlan_ids||[]).join(', '));
        if((g.name_keywords||[]).length)parts.push('Nama: '+(g.name_keywords||[]).join(', '));
        return parts.length ? g.name+' ('+parts.join(' · ')+')' : g.name;
    },
    async loadCustomerGroups(){try{const data=await this.request('/customer-groups');this.applyCustomerGroupPayload(data);}catch(e){this.powerNotifyError(e);}},
    selectedGroupTitle(){
        if(this.selectedGroupFilter==='all')return '';
        if(this.selectedGroupFilter==='ungrouped')return 'Belum Tergrup';
        if(this.selectedGroupFilter.startsWith('vlan:'))return 'VLAN '+this.selectedGroupFilter.slice(5);
        const g=(this.customerGroupData.groups||[]).find(item=>String(item.id)===this.selectedGroupFilter);
        return g?g.name:'';
    },
    selectCustomerGroup(key){this.selectedGroupFilter=key;this.loadGroupOnus(1);},
    filteredAvailableVlans(){const q=(this.vlanChipSearch||'').trim().toLowerCase();const list=this.customerGroupData.available_vlans||[];return q?list.filter(i=>String(i.vlan_id).includes(q)):list;},
    async loadGroupOnus(page=1){
        const serial=++this.groupOnuRequest;this.groupOnuLoading=true;
        try{
            const p=new URLSearchParams({page,per_page:50,sort:'name',direction:'asc'});
            if(this.groupOnuOlt!=='all')p.set('olt_id',this.groupOnuOlt);
            if(this.groupOnuStatus!=='all')p.set('status',this.groupOnuStatus);
            if((this.groupOnuQuery||'').trim())p.set('q',this.groupOnuQuery.trim());
            if(this.selectedGroupFilter==='ungrouped')p.set('customer_group_id','ungrouped');
            else if(this.selectedGroupFilter.startsWith('vlan:'))p.set('vlan_id',this.selectedGroupFilter.slice(5));
            else if(this.selectedGroupFilter!=='all')p.set('customer_group_id',this.selectedGroupFilter);
            const data=await this.request('/onus?'+p);
            if(serial!==this.groupOnuRequest)return;
            this.groupOnus=data;
            this.cacheOnus(data.data||[]);
        }catch(e){if(serial===this.groupOnuRequest)this.powerNotifyError(e);}
        finally{if(serial===this.groupOnuRequest)this.groupOnuLoading=false;}
    },
    newCustomerGroup(prefillVlan=''){this.error='';this.validationErrors={};const palette=['#38bdf8','#10b981','#a855f7','#f59e0b','#ec4899'];const nextColor=palette[(this.customerGroupData.groups||[]).length%palette.length];this.groupKeywordPreview={loading:false,total:0,term:'',samples:[]};this.customerGroupForm={id:null,name:'',color_hex:nextColor,vlans_text:prefillVlan?String(prefillVlan):'',keywords_text:'',description:''};this.open('customer-group-form');},
    editCustomerGroup(g){this.error='';this.validationErrors={};this.groupKeywordPreview={loading:false,total:0,term:'',samples:[]};this.customerGroupForm={id:g.id,name:g.name,color_hex:g.color_hex||'#38bdf8',vlans_text:(g.vlan_ids||[]).join(', '),keywords_text:(g.name_keywords||[]).join(', '),description:g.description||''};this.open('customer-group-form');if((g.name_keywords||[]).length)this.previewGroupKeywords();},
    async previewGroupKeywords(){
        const raw=(this.customerGroupForm?.keywords_text||'').split(/[,;\r\n]+/).map(s=>s.trim()).filter(Boolean);
        const term=raw[0]||'';
        if(term.length<2){this.groupKeywordPreview={loading:false,total:0,term:'',samples:[]};return;}
        const seq=++this.groupKeywordPreviewSeq;
        this.groupKeywordPreview={...this.groupKeywordPreview,loading:true,term};
        try{
            const res=await this.request('/onus?'+new URLSearchParams({q:term,per_page:5,sort:'name',direction:'asc'}));
            if(seq!==this.groupKeywordPreviewSeq)return;
            this.groupKeywordPreview={loading:false,total:res.total||0,term,samples:res.data||[]};
        }catch{if(seq===this.groupKeywordPreviewSeq)this.groupKeywordPreview={loading:false,total:0,term,samples:[]};}
    },
    async saveCustomerGroup(){
        if(this.busy||!this.customerGroupForm)return;
        this.busy=true;this.error='';this.validationErrors={};
        try{
            const p=this.customerGroupForm;
            const payload={name:p.name,color_hex:p.color_hex,vlans:p.vlans_text,keywords:p.keywords_text,description:p.description||null};
            const data=await this.request(p.id?'/customer-groups/'+p.id:'/customer-groups',p.id?'PUT':'POST',payload);
            this.applyCustomerGroupPayload(data);
            this.busy=false;this.closeModal();
            await this.loadGroupOnus(1);
            this.notify(data.message);
        }catch(e){this.showError(e);}finally{this.busy=false;}
    },
    async deleteCustomerGroup(g){
        const ok=await this.askConfirm({title:'Hapus Grup Pelanggan?',message:'Grup ini akan dihapus. Data ONU di OLT tidak ikut terhapus.',detail:this.customerGroupRuleLabel(g),confirmText:'Ya, Hapus Grup',tone:'danger'});
        if(!ok||this.busy)return;
        this.busy=true;this.error='';
        try{
            const data=await this.request('/customer-groups/'+g.id,'DELETE');
            if(this.selectedGroupFilter===String(g.id))this.selectedGroupFilter='all';
            this.applyCustomerGroupPayload(data);
            await this.loadGroupOnus(1);
            this.notify(data.message);
        }catch(e){this.powerNotifyError(e);}finally{this.busy=false;}
    },
    async syncGroupVlans(){
        if(this.groupSyncing)return;
        this.groupSyncing=true;this.error='';
        try{
            const data=await this.request('/customer-groups/sync-vlans','POST');
            this.applyCustomerGroupPayload(data);
            await this.loadGroupOnus(1);
            await this.reload();
            this.notify(data.message);
        }catch(e){this.powerNotifyError(e);}finally{this.groupSyncing=false;}
    },
    applyPowerPayload(data){
        this.powerData={monitors:data.monitors||[],monitor_groups:data.monitor_groups||[],ungrouped_count:data.ungrouped_count||0,groups:data.groups||[],summary:data.summary||{total:0,up:0,warning:0,down:0,unknown:0,avg_latency_ms:null},settings:data.settings||null,logs:data.logs||[]};
        if(this.selectedPowerGroup&&!['all','ungrouped'].includes(this.selectedPowerGroup)&&!(this.powerData.monitor_groups||[]).some(g=>String(g.id)===this.selectedPowerGroup)){this.selectedPowerGroup='';}
        if(data.settings)this.powerSettingsForm={power_notify_enabled:!!data.settings.power_notify_enabled,power_whatsapp_recipients:data.settings.power_whatsapp_recipients||'',power_telegram_chat_id:data.settings.power_telegram_chat_id||'',power_telegram_thread_id:data.settings.power_telegram_thread_id||'',power_cooldown_minutes:Number(data.settings.power_cooldown_minutes??5)};
    },
    openPowerGroup(key){this.selectedPowerGroup=String(key);this.powerFilter={q:'',group:'all',status:'all'};},
    closePowerGroup(){this.selectedPowerGroup='';this.powerFilter={q:'',group:'all',status:'all'};},
    activePowerGroupObj(){if(!this.selectedPowerGroup||['all','ungrouped'].includes(this.selectedPowerGroup))return null;return (this.powerData.monitor_groups||[]).find(g=>String(g.id)===this.selectedPowerGroup)||null;},
    activePowerGroupLabel(){if(this.selectedPowerGroup==='all')return 'Semua Target';if(this.selectedPowerGroup==='ungrouped')return 'Tanpa Kategori';return this.activePowerGroupObj()?.name||'Monitoring ONU';},
    newPowerGroup(){this.error='';this.validationErrors={};const palette=['#f59e0b','#10b981','#38bdf8','#a855f7','#ec4899'];const nextColor=palette[(this.powerData.monitor_groups||[]).length%palette.length];this.powerGroupForm={id:null,name:'',color_hex:nextColor,description:'',whatsapp_recipients:'',telegram_chat_id:'',telegram_thread_id:''};this.open('power-group-form');},
    editPowerGroup(g){if(!g)return;this.error='';this.validationErrors={};this.powerGroupForm={id:g.id,name:g.name,color_hex:g.color_hex||'#f59e0b',description:g.description||'',whatsapp_recipients:g.whatsapp_recipients||'',telegram_chat_id:g.telegram_chat_id||'',telegram_thread_id:g.telegram_thread_id||''};this.open('power-group-form');},
    async savePowerGroup(){
        if(this.busy||!this.powerGroupForm)return;
        this.busy=true;this.error='';this.validationErrors={};
        try{
            const p=this.powerGroupForm;
            const data=await this.request(p.id?'/power-monitors/groups/'+p.id:'/power-monitors/groups',p.id?'PUT':'POST',{name:p.name,color_hex:p.color_hex,description:p.description||null,whatsapp_recipients:p.whatsapp_recipients||null,telegram_chat_id:p.telegram_chat_id||null,telegram_thread_id:p.telegram_thread_id||null});
            this.applyPowerPayload(data);
            this.busy=false;this.closeModal();
            this.notify(data.message);
        }catch(e){this.showError(e);}finally{this.busy=false;}
    },
    async deletePowerGroup(g){
        const ok=await this.askConfirm({title:'Hapus Kategori Monitoring?',message:'Kategori ini akan dihapus. Target IP & ONU di dalamnya tidak terhapus dan akan dipindahkan ke Tanpa Kategori.',detail:g.name+' ('+(g.total||0)+' target)',confirmText:'Ya, Hapus Kategori',tone:'danger'});
        if(!ok||this.busy)return;
        this.busy=true;this.error='';
        try{
            const data=await this.request('/power-monitors/groups/'+g.id,'DELETE');
            if(this.selectedPowerGroup===String(g.id))this.selectedPowerGroup='';
            this.applyPowerPayload(data);
            this.notify(data.message);
        }catch(e){this.powerNotifyError(e);}finally{this.busy=false;}
    },
    powerNotifyError(e){if(this.modal){this.showError(e);}else{this.notify(e.message||'Permintaan gagal.',true);}},
    async loadPowerMonitors(){try{const data=await this.request('/power-monitors');this.applyPowerPayload(data);}catch(e){this.powerNotifyError(e);}},
    newPowerMonitor(){
        this.error='';this.validationErrors={};
        const activeGroup=this.activePowerGroupObj();
        const fallbackGroup=(this.powerData.monitor_groups||[])[0]||null;
        const chosenGroup=activeGroup||fallbackGroup;
        this.powerOnuSearch='';
        this.powerForm={id:null,name:'',power_monitor_group_id:chosenGroup?String(chosenGroup.id):'',group_name:chosenGroup?chosenGroup.name:'Listrik',location:'',monitor_type:'both',ip_address:'',olt_id:'',onu_id:'',whatsapp_recipients_override:'',is_enabled:true};
        this.powerOnuOptions=[];
        this.open('power-form');
        this.loadPowerOnus('','');
    },
    editPowerMonitor(m){this.error='';this.validationErrors={};const oltId=m.onu?.olt_id?String(m.onu.olt_id):'';this.powerOnuSearch=m.onu?.sn||'';this.powerForm={id:m.id,name:m.name,power_monitor_group_id:m.power_monitor_group_id?String(m.power_monitor_group_id):'',group_name:m.group_name||m.monitor_group_name||'Listrik',location:m.location||'',monitor_type:m.monitor_type||'ping',ip_address:m.ip_address||'',olt_id:oltId,onu_id:m.onu_id?String(m.onu_id):'',whatsapp_recipients_override:m.whatsapp_recipients_override||'',is_enabled:!!m.is_enabled};this.open('power-form');this.loadPowerOnus(oltId,this.powerOnuSearch);},
    onPowerTypeChange(){if(this.powerForm&&this.powerForm.monitor_type!=='ping'&&!this.powerOnuOptions.length)this.loadPowerOnus(this.powerForm.olt_id,this.powerOnuSearch);},
    async loadPowerOnus(oltId='',q=''){try{const params=new URLSearchParams({per_page:100,sort:'name',direction:'asc'});if(oltId)params.set('olt_id',oltId);if((q||'').trim())params.set('q',q.trim());const res=await this.request('/onus?'+params);this.powerOnuOptions=res.data||[];}catch{this.powerOnuOptions=[];}},
    async savePowerMonitor(){if(this.busy||!this.powerForm)return;this.busy=true;this.error='';this.validationErrors={};try{const p=this.powerForm;const grp=(this.powerData.monitor_groups||[]).find(g=>String(g.id)===String(p.power_monitor_group_id));const payload={name:p.name,power_monitor_group_id:p.power_monitor_group_id?Number(p.power_monitor_group_id):null,group_name:grp?grp.name:(p.group_name||'Listrik'),location:p.location,monitor_type:p.monitor_type,ip_address:p.ip_address||null,onu_id:p.onu_id?Number(p.onu_id):null,whatsapp_recipients_override:p.whatsapp_recipients_override||null,is_enabled:!!p.is_enabled};const data=await this.request(p.id?'/power-monitors/'+p.id:'/power-monitors',p.id?'PUT':'POST',payload);this.applyPowerPayload(data);this.busy=false;this.closeModal();await this.reload();this.notify(data.message);}catch(e){this.showError(e);}finally{this.busy=false;}},
    async deletePowerMonitor(m){const ok=await this.askConfirm({title:'Hapus Target Monitoring?',message:'Target IP & ONU ini akan dihapus dari daftar Monitoring ONU.',detail:m.name+(m.ip_address?' · '+m.ip_address:''),confirmText:'Ya, Hapus Target',tone:'danger'});if(!ok)return;if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/power-monitors/'+m.id,'DELETE');this.applyPowerPayload(data);await this.reload();this.notify(data.message);}catch(e){this.powerNotifyError(e);}finally{this.busy=false;}},
    async checkAllPower(){if(this.powerChecking)return;this.powerChecking=true;this.error='';try{const data=await this.request('/power-monitors/check','POST');this.applyPowerPayload(data);await this.reload();this.notify(data.message);}catch(e){this.powerNotifyError(e);}finally{this.powerChecking=false;}},
    async checkOnePower(m){if(this.powerCheckingId)return;this.powerCheckingId=m.id;this.error='';try{const data=await this.request('/power-monitors/'+m.id+'/check','POST');this.applyPowerPayload(data);await this.reload();this.notify(data.message);}catch(e){this.powerNotifyError(e);}finally{this.powerCheckingId=null;}},
    async savePowerSettings(){if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/power-monitors/settings','PUT',this.powerSettingsForm);this.applyPowerPayload(data);this.notify(data.message);}catch(e){this.powerNotifyError(e);}finally{this.busy=false;}},
    async testPowerAlert(){if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/power-monitors/test','POST',this.powerSettingsForm);this.applyPowerPayload(data);this.notify(data.message);}catch(e){this.powerNotifyError(e);try{await this.loadPowerMonitors();}catch{}}finally{this.busy=false;}},
    applyNotificationPayload(data){
        if(data.settings){
            this.notificationMeta={has_telegram_bot_token:!!data.settings.has_telegram_bot_token,has_whatsapp_api_key:!!data.settings.has_whatsapp_api_key};
            this.notificationForm={...this.notificationForm,...data.settings,telegram_bot_token:'',whatsapp_api_key:''};
        }
        if(data.logs)this.notificationLogs=data.logs;
    },
    async loadNotifications(){try{const data=await this.request('/notifications/settings');this.applyNotificationPayload(data);}catch(e){this.showError(e);}},
    async saveNotifications(){if(this.busy)return;this.busy=true;this.error='';this.validationErrors={};try{const data=await this.request('/notifications/settings','PUT',this.notificationForm);this.applyNotificationPayload(data);this.notify(data.message);}catch(e){this.showError(e);}finally{this.busy=false;}},
    async testNotification(channel){if(this.busy)return;this.busy=true;this.error='';this.validationErrors={};try{const data=await this.request('/notifications/test','POST',{...this.notificationForm,channel});if(data.logs)this.notificationLogs=data.logs;this.notify(data.message);}catch(e){this.showError(e);try{await this.loadNotifications();}catch{}}finally{this.busy=false;}},
    columnVisible(key){return (key!=='traffic'||this.onuTrafficAvailable())&&(this.db.user.preferences?.onu_columns||['status','rx','last_online','serial','traffic']).includes(key);},
    async toggleColumn(key){const old=this.db.user.preferences||{};const columns=old.onu_columns||['status','rx','last_online','serial','traffic'];const next=columns.includes(key)?columns.filter(k=>k!==key):[...columns,key];this.db.user.preferences={...old,onu_columns:next};try{await this.request('/preferences','PUT',{onu_columns:next});}catch(e){this.db.user.preferences=old;this.showError(e);}},
    async exportOnus(){let allRows;try{allRows=await this.allDirectoryRows(this.directoryParams());}catch(e){this.showError(e);return;}const safe=value=>{let text=String(value??'');if(/^[=+@\-\t\r]/.test(text))text="'"+text;return '"'+text.replaceAll('"','""')+'"';};const rows=[['Nama','OLT','Interface','Status','SN','RX dBm','PPPoE','VLAN'],...allRows.map(o=>[o.name,this.oltName(o.olt_id),o.interface,o.phase_state,o.sn,o.rx_power,o.pppoe_user,o.vlan_id])];const url=URL.createObjectURL(new Blob(['\ufeff'+rows.map(row=>row.map(safe).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8;'}));const link=document.createElement('a');link.href=url;link.download='onu-nms.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);},
    async chooseRestore(backup){this.restoreBackupId=backup.id;this.restoreOnuId='';this.restorePlan=null;this.restoreQuery='';await this.searchRestoreOnus();},
    async searchRestoreOnus(){const id=this.selected?.id,q=this.restoreQuery;try{const result=await this.request('/onus?'+new URLSearchParams({olt_id:id,q,per_page:50}));if(id===this.selected?.id&&q===this.restoreQuery)this.restoreRows=result.data;}catch(e){this.showError(e);}},
    async previewRestore(){if(this.restoreBusy||!this.restoreOnuId)return;this.restoreBusy=true;this.error='';try{this.restorePlan=await this.request('/backups/'+this.restoreBackupId+'/restore-preview','POST',{onu_id:Number(this.restoreOnuId)});}catch(e){this.showError(e);}finally{this.restoreBusy=false;}},
    async applyRestore(){if(this.restoreBusy||!this.restorePlan)return;this.restoreBusy=true;this.error='';try{const p=this.restorePlan,result=await this.request('/onus/'+p.onu_id+'/configuration-tool/apply','POST',{change_id:p.change_id,confirmation_token:p.confirmation_token});this.restorePlan=null;this.notify(result.message);}catch(e){if(e.reason!=='olt_busy')this.restorePlan=null;this.showError(e);}finally{this.restoreBusy=false;}},
    async loadBackups(){if(!this.selected)return;try{this.backups=(await this.request('/olts/'+this.selected.id+'/backups')).backups;}catch(e){this.showError(e);}},
    async createBackup(){if(!this.selected)return;try{const data=await this.request('/olts/'+this.selected.id+'/operations','POST',{kind:'backup'});this.notify(data.message);await this.reload();this.scheduleLiveRefresh(300);}catch(e){this.showError(e);}},
    async loadApiTokens(){try{this.apiInfo=await this.request('/api-tokens');}catch(e){this.showError(e);}},
    async createApiToken(){if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/api-tokens','POST',this.tokenForm);this.newToken=data.token;await this.loadApiTokens();this.notify(data.message);}catch(e){this.showError(e);}finally{this.busy=false;}},
    async revokeApiToken(token){const ok=await this.askConfirm({title:'Cabut Token API?',message:'Integrasi yang memakai token ini akan langsung kehilangan akses.',detail:token.name,confirmText:'Ya, Cabut Token',tone:'danger'});if(!ok)return;await this.mutate('/api-tokens/'+token.id,'DELETE',undefined,()=>this.loadApiTokens());},
    async saveProfile(){if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/profile','PUT',this.profileForm);if(data.csrf_token)document.querySelector('meta[name="csrf-token"]').content=data.csrf_token;await this.reload();this.profileForm={name:this.db.user.name,username:this.db.user.username,current_password:'',password:'',password_confirmation:''};this.notify(data.message);}catch(e){this.showError(e);}finally{this.busy=false;}},
    filteredFtthNodes(){return this.ftth.nodes.filter(node=>this.mapOlt==='all'||node.olt_id===Number(this.mapOlt));},
    async loadFtth(){try{this.ftth=await this.request('/ftth');this.db.onus=await this.allDirectoryRows(new URLSearchParams());this.renderFtth();}catch(e){this.showError(e);}},
    renderFtth(){this.$nextTick(()=>{if(this.modal!=='maps')return;drawMap(document.getElementById('ftth-map'),this.filteredFtthNodes(),this.db.onus.filter(o=>this.mapOlt==='all'||o.olt_id===Number(this.mapOlt)),this.ftth.assignments,this.db.olts.filter(o=>this.mapOlt==='all'||o.id===Number(this.mapOlt)),node=>this.editNode(node),point=>{if(this.nodeForm){this.nodeForm.latitude=Number(point.lat.toFixed(7));this.nodeForm.longitude=Number(point.lng.toFixed(7));}});});},
    editNode(node=null){this.error='';this.nodeForm=node?{...node}:{olt_id:Number(this.mapOlt==='all'?this.db.olts[0]?.id:this.mapOlt),parent_id:'',type:'ODP',name:'',description:'',latitude:-6.9175,longitude:107.6191,capacity:8};},
    async saveNode(){const data={...this.nodeForm,olt_id:Number(this.nodeForm.olt_id),parent_id:this.nodeForm.parent_id?Number(this.nodeForm.parent_id):null};await this.mutate('/ftth/nodes'+(data.id?'/'+data.id:''),data.id?'PUT':'POST',data,()=>{this.nodeForm=null;this.loadFtth();});},
    async deleteNode(node){const ok=await this.askConfirm({title:'Hapus Perangkat Peta?',message:'Node FTTH ini akan dihapus dari peta jaringan.',detail:node.name,confirmText:'Ya, Hapus',tone:'danger'});if(!ok)return;await this.mutate('/ftth/nodes/'+node.id,'DELETE',undefined,()=>{this.nodeForm=null;this.loadFtth();});},
    async assignOnu(){await this.mutate('/ftth/nodes/'+this.assignmentForm.node_id+'/assign','POST',{onu_id:Number(this.assignmentForm.onu_id),port_number:Number(this.assignmentForm.port_number)},()=>this.loadFtth());},
    async detachOnu(id){const ok=await this.askConfirm({title:'Lepaskan ONU dari ODP?',message:'Relasi port ODP untuk ONU ini akan dilepaskan.',confirmText:'Ya, Lepaskan',tone:'warning'});if(!ok)return;await this.mutate('/ftth/onus/'+id+'/assignment','DELETE',undefined,()=>this.loadFtth());},
};}
