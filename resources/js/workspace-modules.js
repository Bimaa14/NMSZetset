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
    notificationTab:'whatsapp',
    notificationMeta:{has_telegram_bot_token:false,has_whatsapp_api_key:false},
    notificationLogs:[],
    notificationForm:{telegram_enabled:false,telegram_bot_token:'',telegram_chat_id:'',telegram_thread_id:'',whatsapp_enabled:false,whatsapp_provider:'baileys',whatsapp_endpoint_url:'http://127.0.0.1:3001/send-message',whatsapp_api_key:'',whatsapp_session_id:'default',whatsapp_recipients:'',notify_on_los:true,notify_on_dying_gasp:true,notify_on_recovery:true,notify_on_bad_rx:true,bad_rx_threshold:-27,cooldown_minutes:15},
    powerData:{monitors:[],groups:[],summary:{total:0,up:0,warning:0,down:0,unknown:0,avg_latency_ms:null},settings:null,logs:[]},
    activePage:window.location.hash==='#listrik'?'power':'dashboard',
    powerTab:'monitors',powerForm:null,powerOnuOptions:[],powerOnuSearch:'',powerFilter:{q:'',group:'all',status:'all'},powerChecking:false,powerCheckingId:null,
    powerSettingsForm:{power_notify_enabled:true,power_whatsapp_recipients:'',power_telegram_chat_id:'',power_telegram_thread_id:'',power_cooldown_minutes:5},
    ftth:{nodes:[],assignments:[]},nodeForm:null,mapOlt:'all',assignmentForm:{node_id:'',onu_id:'',port_number:1},
    showPage(page){
        this.activePage=page==='power'?'power':'dashboard';
        if(this.modal&&this.$refs?.dialog?.open)this.closeModal();
        try{history.replaceState(null,'',this.activePage==='power'?'#listrik':window.location.pathname+window.location.search);}catch{}
        if(this.activePage==='power'){this.powerForm=null;this.loadPowerMonitors();}
        window.scrollTo({top:0,behavior:'instant'});
    },
    onModuleOpen(type){
        if(['profiles','provision'].includes(type))this.loadServiceProfiles();
        if(type==='provision'){this.provisionPlan=null;this.discovery={onus:[],ports:[],registered:[]};this.provisionForm={profile_id:'',frame:1,slot:1,port:1,onu_index:1,sn:'',name:'',pppoe_user:'',pppoe_password:''};}
        if(type==='backups'){this.restoreBackupId=null;this.restorePlan=null;this.restoreRows=[];this.restoreOnuId='';this.loadBackups();}
        if(type==='api')this.loadApiTokens();
        if(['telegram','whatsapp'].includes(type)){this.notificationTab=type;this.loadNotifications();}
        if(type==='profile')this.profileForm={name:this.db.user.name,username:this.db.user.username,current_password:'',password:'',password_confirmation:''};
        if(type==='maps'){this.nodeForm=null;this.loadFtth();}
    },
    onModuleClose(){this.provisionForm.pppoe_password='';this.provisionPlan=null;this.newToken='';this.profileForm.current_password='';this.profileForm.password='';this.profileForm.password_confirmation='';this.notificationForm.telegram_bot_token='';this.notificationForm.whatsapp_api_key='';this.powerForm=null;clearMap();},
    applyPowerPayload(data){
        this.powerData={monitors:data.monitors||[],groups:data.groups||[],summary:data.summary||{total:0,up:0,warning:0,down:0,unknown:0,avg_latency_ms:null},settings:data.settings||null,logs:data.logs||[]};
        if(data.settings)this.powerSettingsForm={power_notify_enabled:!!data.settings.power_notify_enabled,power_whatsapp_recipients:data.settings.power_whatsapp_recipients||'',power_telegram_chat_id:data.settings.power_telegram_chat_id||'',power_telegram_thread_id:data.settings.power_telegram_thread_id||'',power_cooldown_minutes:Number(data.settings.power_cooldown_minutes??5)};
    },
    powerNotifyError(e){if(this.modal){this.showError(e);}else{this.notify(e.message||'Permintaan gagal.',true);}},
    async loadPowerMonitors(){try{const data=await this.request('/power-monitors');this.applyPowerPayload(data);}catch(e){this.powerNotifyError(e);}},
    newPowerMonitor(){this.error='';this.validationErrors={};const defaultGroup=this.powerData.groups?.[0]||'MONITORING-LISTRIK-ONU-BANDUNG';this.powerOnuSearch='';this.powerForm={id:null,name:'',group_name:defaultGroup,location:'',monitor_type:'both',ip_address:'',olt_id:'',onu_id:'',whatsapp_recipients_override:'',is_enabled:true};this.powerOnuOptions=[];this.open('power-form');this.loadPowerOnus('','');},
    editPowerMonitor(m){this.error='';this.validationErrors={};const oltId=m.onu?.olt_id?String(m.onu.olt_id):'';this.powerOnuSearch=m.onu?.sn||'';this.powerForm={id:m.id,name:m.name,group_name:m.group_name||'MONITORING-LISTRIK',location:m.location||'',monitor_type:m.monitor_type||'ping',ip_address:m.ip_address||'',olt_id:oltId,onu_id:m.onu_id?String(m.onu_id):'',whatsapp_recipients_override:m.whatsapp_recipients_override||'',is_enabled:!!m.is_enabled};this.open('power-form');this.loadPowerOnus(oltId,this.powerOnuSearch);},
    onPowerTypeChange(){if(this.powerForm&&this.powerForm.monitor_type!=='ping'&&!this.powerOnuOptions.length)this.loadPowerOnus(this.powerForm.olt_id,this.powerOnuSearch);},
    async loadPowerOnus(oltId='',q=''){try{const params=new URLSearchParams({per_page:100,sort:'name',direction:'asc'});if(oltId)params.set('olt_id',oltId);if((q||'').trim())params.set('q',q.trim());const res=await this.request('/onus?'+params);this.powerOnuOptions=res.data||[];}catch{this.powerOnuOptions=[];}},
    async savePowerMonitor(){if(this.busy||!this.powerForm)return;this.busy=true;this.error='';this.validationErrors={};try{const p=this.powerForm;const payload={name:p.name,group_name:p.group_name,location:p.location,monitor_type:p.monitor_type,ip_address:p.ip_address||null,onu_id:p.onu_id?Number(p.onu_id):null,whatsapp_recipients_override:p.whatsapp_recipients_override||null,is_enabled:!!p.is_enabled};const data=await this.request(p.id?'/power-monitors/'+p.id:'/power-monitors',p.id?'PUT':'POST',payload);this.applyPowerPayload(data);this.busy=false;this.closeModal();await this.reload();this.notify(data.message);}catch(e){this.showError(e);}finally{this.busy=false;}},
    async deletePowerMonitor(m){const ok=await this.askConfirm({title:'Hapus Sensor Listrik?',message:'Sensor pemantauan listrik ini akan dihapus dari daftar Monitoring Listrik.',detail:m.name+(m.ip_address?' · '+m.ip_address:''),confirmText:'Ya, Hapus Sensor',tone:'danger'});if(!ok)return;if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/power-monitors/'+m.id,'DELETE');this.applyPowerPayload(data);await this.reload();this.notify(data.message);}catch(e){this.powerNotifyError(e);}finally{this.busy=false;}},
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
    async saveProfile(){if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/profile','PUT',this.profileForm);if(data.csrf_token)document.querySelector('meta[name="csrf-token"]').content=data.csrf_token;await this.reload();this.onModuleOpen('profile');this.notify(data.message);}catch(e){this.showError(e);}finally{this.busy=false;}},
    filteredFtthNodes(){return this.ftth.nodes.filter(node=>this.mapOlt==='all'||node.olt_id===Number(this.mapOlt));},
    async loadFtth(){try{this.ftth=await this.request('/ftth');this.db.onus=await this.allDirectoryRows(new URLSearchParams());this.renderFtth();}catch(e){this.showError(e);}},
    renderFtth(){this.$nextTick(()=>{if(this.modal!=='maps')return;drawMap(document.getElementById('ftth-map'),this.filteredFtthNodes(),this.db.onus.filter(o=>this.mapOlt==='all'||o.olt_id===Number(this.mapOlt)),this.ftth.assignments,this.db.olts.filter(o=>this.mapOlt==='all'||o.id===Number(this.mapOlt)),node=>this.editNode(node),point=>{if(this.nodeForm){this.nodeForm.latitude=Number(point.lat.toFixed(7));this.nodeForm.longitude=Number(point.lng.toFixed(7));}});});},
    editNode(node=null){this.error='';this.nodeForm=node?{...node}:{olt_id:Number(this.mapOlt==='all'?this.db.olts[0]?.id:this.mapOlt),parent_id:'',type:'ODP',name:'',description:'',latitude:-6.9175,longitude:107.6191,capacity:8};},
    async saveNode(){const data={...this.nodeForm,olt_id:Number(this.nodeForm.olt_id),parent_id:this.nodeForm.parent_id?Number(this.nodeForm.parent_id):null};await this.mutate('/ftth/nodes'+(data.id?'/'+data.id:''),data.id?'PUT':'POST',data,()=>{this.nodeForm=null;this.loadFtth();});},
    async deleteNode(node){const ok=await this.askConfirm({title:'Hapus Perangkat Peta?',message:'Node FTTH ini akan dihapus dari peta jaringan.',detail:node.name,confirmText:'Ya, Hapus',tone:'danger'});if(!ok)return;await this.mutate('/ftth/nodes/'+node.id,'DELETE',undefined,()=>{this.nodeForm=null;this.loadFtth();});},
    async assignOnu(){await this.mutate('/ftth/nodes/'+this.assignmentForm.node_id+'/assign','POST',{onu_id:Number(this.assignmentForm.onu_id),port_number:Number(this.assignmentForm.port_number)},()=>this.loadFtth());},
    async detachOnu(id){const ok=await this.askConfirm({title:'Lepaskan ONU dari ODP?',message:'Relasi port ODP untuk ONU ini akan dilepaskan.',confirmText:'Ya, Lepaskan',tone:'warning'});if(!ok)return;await this.mutate('/ftth/onus/'+id+'/assignment','DELETE',undefined,()=>this.loadFtth());},
};}
