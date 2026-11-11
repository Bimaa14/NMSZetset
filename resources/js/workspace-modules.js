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
    profileForm:{name:'',email:'',current_password:'',password:'',password_confirmation:''},
    notificationTab:'whatsapp',
    notificationMeta:{has_telegram_bot_token:false,has_whatsapp_api_key:false},
    notificationLogs:[],
    notificationForm:{telegram_enabled:false,telegram_bot_token:'',telegram_chat_id:'',telegram_thread_id:'',whatsapp_enabled:false,whatsapp_provider:'baileys',whatsapp_endpoint_url:'http://127.0.0.1:3001/send-message',whatsapp_api_key:'',whatsapp_session_id:'default',whatsapp_recipients:'',notify_on_los:true,notify_on_dying_gasp:true,notify_on_recovery:true,notify_on_bad_rx:true,bad_rx_threshold:-27,cooldown_minutes:15},
    ftth:{nodes:[],assignments:[]},nodeForm:null,mapOlt:'all',assignmentForm:{node_id:'',onu_id:'',port_number:1},
    onModuleOpen(type){
        if(['profiles','provision'].includes(type))this.loadServiceProfiles();
        if(type==='provision'){this.provisionPlan=null;this.discovery={onus:[],ports:[],registered:[]};this.provisionForm={profile_id:'',frame:1,slot:1,port:1,onu_index:1,sn:'',name:'',pppoe_user:'',pppoe_password:''};}
        if(type==='backups'){this.restoreBackupId=null;this.restorePlan=null;this.restoreRows=[];this.restoreOnuId='';this.loadBackups();}
        if(type==='api')this.loadApiTokens();
        if(['telegram','whatsapp'].includes(type)){this.notificationTab=type;this.loadNotifications();}
        if(type==='profile')this.profileForm={name:this.db.user.name,email:this.db.user.email,current_password:'',password:'',password_confirmation:''};
        if(type==='maps'){this.nodeForm=null;this.loadFtth();}
    },
    onModuleClose(){this.provisionForm.pppoe_password='';this.provisionPlan=null;this.newToken='';this.profileForm.current_password='';this.profileForm.password='';this.profileForm.password_confirmation='';this.notificationForm.telegram_bot_token='';this.notificationForm.whatsapp_api_key='';clearMap();},
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
    async revokeApiToken(token){if(!confirm('Cabut token '+token.name+'? Integrasi yang memakainya akan kehilangan akses.'))return;await this.mutate('/api-tokens/'+token.id,'DELETE',undefined,()=>this.loadApiTokens());},
    async saveProfile(){if(this.busy)return;this.busy=true;this.error='';try{const data=await this.request('/profile','PUT',this.profileForm);if(data.csrf_token)document.querySelector('meta[name="csrf-token"]').content=data.csrf_token;await this.reload();this.onModuleOpen('profile');this.notify(data.message);}catch(e){this.showError(e);}finally{this.busy=false;}},
    filteredFtthNodes(){return this.ftth.nodes.filter(node=>this.mapOlt==='all'||node.olt_id===Number(this.mapOlt));},
    async loadFtth(){try{this.ftth=await this.request('/ftth');this.db.onus=await this.allDirectoryRows(new URLSearchParams());this.renderFtth();}catch(e){this.showError(e);}},
    renderFtth(){this.$nextTick(()=>{if(this.modal!=='maps')return;drawMap(document.getElementById('ftth-map'),this.filteredFtthNodes(),this.db.onus.filter(o=>this.mapOlt==='all'||o.olt_id===Number(this.mapOlt)),this.ftth.assignments,this.db.olts.filter(o=>this.mapOlt==='all'||o.id===Number(this.mapOlt)),node=>this.editNode(node),point=>{if(this.nodeForm){this.nodeForm.latitude=Number(point.lat.toFixed(7));this.nodeForm.longitude=Number(point.lng.toFixed(7));}});});},
    editNode(node=null){this.error='';this.nodeForm=node?{...node}:{olt_id:Number(this.mapOlt==='all'?this.db.olts[0]?.id:this.mapOlt),parent_id:'',type:'ODP',name:'',description:'',latitude:-6.9175,longitude:107.6191,capacity:8};},
    async saveNode(){const data={...this.nodeForm,olt_id:Number(this.nodeForm.olt_id),parent_id:this.nodeForm.parent_id?Number(this.nodeForm.parent_id):null};await this.mutate('/ftth/nodes'+(data.id?'/'+data.id:''),data.id?'PUT':'POST',data,()=>{this.nodeForm=null;this.loadFtth();});},
    async deleteNode(node){if(!confirm('Hapus '+node.name+' dari peta?'))return;await this.mutate('/ftth/nodes/'+node.id,'DELETE',undefined,()=>{this.nodeForm=null;this.loadFtth();});},
    async assignOnu(){await this.mutate('/ftth/nodes/'+this.assignmentForm.node_id+'/assign','POST',{onu_id:Number(this.assignmentForm.onu_id),port_number:Number(this.assignmentForm.port_number)},()=>this.loadFtth());},
    async detachOnu(id){if(!confirm('Lepaskan ONU dari ODP?'))return;await this.mutate('/ftth/onus/'+id+'/assignment','DELETE',undefined,()=>this.loadFtth());},
};}
