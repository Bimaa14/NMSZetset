import {onuConfigToolModule} from './onu-config-tool';
import {onuRebootModule} from './onu-reboot';
import {trafficModule} from './traffic';
import {onuDetailModule} from './onu-detail';
import Alpine from 'alpinejs';
import {provisioningModule} from './provisioning';
import {workspaceModules} from './workspace-modules';
import { AlertTriangle, RotateCw, Minus, ScanSearch, Move, RotateCcw, Menu, ArrowLeft, Settings2, MessageCircle, Waypoints } from 'lucide';
import { createIcons, Server, Sun, Moon, Monitor, ChevronDown, LogOut, LayoutDashboard, CircleUserRound, Send, CodeXml, Tags, ShieldCheck, Wallet, UsersRound, ContactRound, ScrollText, ChevronRight, Network, Search, SearchX, Layers2, Zap, ArrowUpRight, ServerCog, Cpu, RadioTower, Unplug, Activity, ArrowRight, Map as MapIcon, Plus, MapPin, Info, Pencil, Terminal, EthernetPort, GitFork, Eye, RefreshCw, Router, Trash2, LockKeyhole, ServerOff, X, Thermometer, Clock3, CornerDownLeft, Copy, PlugZap, CircleCheck, MoreHorizontal } from 'lucide';
import '@fontsource-variable/inter';
import '@fontsource/jetbrains-mono/400.css';

const icons = { Server, Sun, Moon, Monitor, ChevronDown, LogOut, LayoutDashboard, CircleUserRound, Send, CodeXml, Tags, ShieldCheck, Wallet, UsersRound, ContactRound, ScrollText, ChevronRight, Network, Search, SearchX, Layers2, Zap, ArrowUpRight, ServerCog, Cpu, RadioTower, Unplug, Activity, ArrowRight, Map: MapIcon, Plus, MapPin, Info, Pencil, Terminal, EthernetPort, GitFork, Eye, RefreshCw, Router, Trash2, LockKeyhole, ServerOff, X, Thermometer, Clock3, CornerDownLeft, Copy, PlugZap, CircleCheck, MoreHorizontal };
let iconFrame;
const pendingIconSelector = '[data-lucide]:not(svg)';
const refreshIcons = () => {
    cancelAnimationFrame(iconFrame);
    iconFrame = requestAnimationFrame(() => {
        if (!document.querySelector(pendingIconSelector)) return;
        createIcons({ icons: {...icons, AlertTriangle, RotateCw, Minus, ScanSearch, Move, RotateCcw, Menu, ArrowLeft, Settings2, MessageCircle, Waypoints}, attrs: { 'aria-hidden': 'true', 'stroke-width': 1.7 } });
        // Lucide copies this marker onto its output. Consume it so later renders
        // leave existing SVGs attached, including between pointer down and up.
        document.querySelectorAll('svg[data-lucide]').forEach(icon => icon.removeAttribute('data-lucide'));
    });
};
new MutationObserver(records => { if (records.some(r => [...r.addedNodes].some(n => n.nodeType === 1 && (n.matches?.(pendingIconSelector) || n.querySelector?.(pendingIconSelector))))) refreshIcons(); }).observe(document.body, { childList: true, subtree: true });

Alpine.data('dashboard', () => Object.defineProperties({
    ...workspaceModules(),
    ...onuDetailModule(),
    ...onuRebootModule(),
    ...trafficModule(),
    ...provisioningModule(),
    db: JSON.parse(document.getElementById('initial-data').textContent),
    directory:{data:[],total:0,last_page:1,summary:{total:0,online:0,other:0,weak:0,percent:0}},directoryBusy:false,directoryEpoch:0,searchEpoch:0,searchRows:[],searchTotal:0,searchOltIds:[],
    query: '', modalQuery: '', statusFilter: 'all', sort: 'default', equipmentOpen: true,
    modal: '', selected: null, mapOnu: null, error: '', busy: false, syncing: [], theme: 'light', uiReady:false,
    toast: { message: '', error: false }, toastTimer: null,
    connectionTest: '', testProtocol: 'cli', detailLoading: false, detailSerial: 0,
    form: {}, command: '', terminalOutput: '', commandHistory: [], historyIndex: 0, commandDraft: '',
    onuPage:1,onuPageSize:50,onuSort: 'name', onuSortDirection: 1, inspectedOnu: null, onuStatus: 'all', pendingAction: null, tagOnu: null, onuTagIds: [],
    tagForm: { id: null, tag_name: '', color_hex: '#60a5fa' }, staffForm: null,
    audit: { data: [], current_page: 1, last_page: 1, total: 0 }, auditQuery: '', auditAction: '', auditFrom: '', auditTo: '', auditLoading: false, auditRequest: 0,
    validationErrors: {}, rebootTimer: null, pollBusy: false, liveTimer:null,liveAbort:null,lastDashboardRefresh:Date.now(), liveError:'', manualOperations:{}, detailOperation:null, onuPort:'all',onuOlt:'all', onuHistory:null, historyLoading:false,onuNameDraft:'',onuLocationDraft:{latitude:null,longitude:null},
    roleNames: { isp_owner: 'ISP Owner', noc: 'NOC', cs: 'CS', teknisi: 'Teknisi' },
    init() {
        try { this.theme = localStorage.getItem('nms-theme') || 'light'; } catch {}
        this.setTheme(this.theme);
        this.$nextTick(()=>{this.uiReady=true;});
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (this.theme === 'system') this.setTheme('system'); });
        this.scheduleRebootRefresh();
        this.scheduleLiveRefresh();
        for(const field of ['modalQuery','onuStatus','onuPort','onuOlt','onuPageSize'])this.$watch(field,()=>{this.onuPage=1;});
        for(const field of ['modalQuery','onuStatus','onuPort','onuOlt','onuPageSize','onuPage','onuSort','onuSortDirection'])this.$watch(field,()=>this.scheduleDirectory());
        this.$watch('query',()=>this.searchDirectory());
        document.addEventListener('visibilitychange', () => {
            if(document.hidden){clearTimeout(this.liveTimer);clearTimeout(this.rebootTimer);this.liveAbort?.abort();this.suspendTraffic();this.suspendOnuDetail();if(this.toolTarget&&!this.toolApplying)this.toolStopRead();}
            else {this.scheduleRebootRefresh();this.scheduleLiveRefresh(100);if(this.trafficTarget)this.loadTraffic(this.trafficRange==='live');if(this.inspectedOnu&&!this.toolTarget){this.startDetailClock();this.scheduleOnuDetail();}}
        });
    },
    get roleLabel() { return this.roleNames[this.db.user.role] || this.db.user.role; },
    get expiry() { return this.db.tenant.expired_at ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(this.db.tenant.expired_at)) : 'Tanpa batas'; },
    get onlineOlts() { return this.db.olts.filter(o => o.status === 'Online'); },
    get onlineOnus() { return this.db.onus.filter(o => o.phase_state === 'working'); },
    get badOnus() { return this.db.onus.filter(o => o.rx_power != null && o.rx_power <= this.db.threshold); },
    get portCount() { return this.db.olts.reduce((n, o) => n + o.ports_count, 0); },
    get slotCount() { return this.db.olts.reduce((n, o) => n + new Set(o.ports.map(p => p.slot_no)).size, 0); },
    matches(onu, q) { return [onu.name, onu.sn, onu.description, ...onu.tags.map(t => t.tag_name)].some(v => (v || '').toLocaleLowerCase('id').includes(q.trim().toLocaleLowerCase('id'))); },
    get filteredOnus() { return this.db.compact?this.searchRows:this.db.onus.filter(o => this.matches(o, this.query)); },
    get onuScopeSummary() {
        if(this.db.compact)return this.directory.summary;
        const rows=(this.modal==='degradation'?this.badOnus:this.db.onus).filter(o=>(this.selected?o.olt_id===this.selected.id:this.onuOlt==='all'||o.olt_id===Number(this.onuOlt))&&(this.onuPort==='all'||o.olt_port_id===Number(this.onuPort)));
        const online=rows.filter(o=>o.phase_state==='working').length;
        return {total:rows.length,online,other:rows.length-online,weak:rows.filter(o=>o.rx_power!=null&&o.rx_power<=this.db.threshold).length,percent:rows.length?Math.round(online/rows.length*100):0};
    },
    get modalOnus() {
        if(this.db.compact)return this.directory.data;
        const matchesStatus = onu => this.onuStatus === 'all' || (this.onuStatus === 'nonworking' ? onu.phase_state !== 'working' : onu.phase_state === this.onuStatus);
        const rows=(this.modal === 'degradation' ? this.badOnus : this.db.onus.filter(o => this.selected ? o.olt_id===this.selected.id : this.onuOlt==='all'||o.olt_id===Number(this.onuOlt))).filter(o => this.matches(o, this.modalQuery) && matchesStatus(o) && (this.onuPort==='all' || o.olt_port_id===Number(this.onuPort)));
        const value=onu=>this.onuSort==='position'?this.onuPosition(onu):this.onuSort==='status'?this.onuState(onu.phase_state):onu[this.onuSort];
        return rows.sort((a,b)=>{ const av=value(a), bv=value(b); if(av==null)return bv==null?a.id-b.id:1; if(bv==null)return -1; const order=typeof av==='number'?av-bv:String(av).localeCompare(String(bv),'id',{numeric:true,sensitivity:'base'}); return order*this.onuSortDirection || a.id-b.id; });
    },
    get pagedOnus() {if(this.db.compact)return this.directory.data;return this.modalOnus.slice((this.onuPage-1)*this.onuPageSize,this.onuPage*this.onuPageSize);},
    get onuPageCount() {if(this.db.compact)return this.directory.last_page;return Math.max(1,Math.ceil(this.modalOnus.length/this.onuPageSize));},
    directoryParams(page=this.onuPage){const p=new URLSearchParams({page,per_page:this.onuPageSize,sort:({status:'phase_state',position:'position'}[this.onuSort]||this.onuSort),direction:this.onuSortDirection===1?'asc':'desc'});if(this.selected)p.set('olt_id',this.selected.id);else if(this.onuOlt!=='all')p.set('olt_id',this.onuOlt);if(this.onuPort!=='all')p.set('port_id',this.onuPort);if(this.onuStatus!=='all')p.set('status',this.onuStatus);if(this.modalQuery)p.set('q',this.modalQuery);if(this.modal==='degradation')p.set('degradation','1');return p;},
    cacheOnus(rows){const map=new Map(this.db.onus.map(o=>[o.id,o]));for(const row of rows)map.set(row.id,row);this.db.onus=[...map.values()].slice(-1000);},
    scheduleDirectory(){clearTimeout(this.directoryTimer);if(['onus','degradation'].includes(this.modal))this.directoryTimer=setTimeout(()=>this.loadDirectory(),80);},
    async loadDirectory(){if(!['onus','degradation'].includes(this.modal))return;const epoch=++this.directoryEpoch;this.directoryBusy=true;try{const result=await this.request('/onus?'+this.directoryParams());if(epoch!==this.directoryEpoch)return;this.directory=result;this.cacheOnus(result.data);if(this.onuPage>result.last_page)this.onuPage=result.last_page;}catch(e){if(epoch===this.directoryEpoch)this.showError(e);}finally{if(epoch===this.directoryEpoch)this.directoryBusy=false;}},
    async searchDirectory(){const epoch=++this.searchEpoch,q=this.query.trim();if(!q){this.searchRows=[];this.searchTotal=0;this.searchOltIds=[];return;}try{const data=await this.request('/onus?'+new URLSearchParams({q,per_page:100}));if(epoch!==this.searchEpoch)return;this.searchRows=data.data;this.searchTotal=data.total;this.searchOltIds=data.matched_olt_ids;this.cacheOnus(data.data);}catch(e){if(epoch===this.searchEpoch)this.showError(e);}},
    async allDirectoryRows(params){const rows=[];for(let page=1;;page++){params.set('page',page);params.set('per_page',200);const result=await this.request('/onus?'+params);rows.push(...result.data);if(page>=result.last_page)break;}return rows;},
    sortOnus(key) {this.onuPage=1; if(this.onuSort===key)this.onuSortDirection*=-1; else {this.onuSort=key;this.onuSortDirection=1;} },
    onuSortState(key) { return this.onuSort===key?(this.onuSortDirection===1?'ascending':'descending'):'none'; },
    onuSortIcon(key) { return this.onuSort===key?(this.onuSortDirection===1?'\u2191':'\u2193'):'\u2195'; },
    onuPosition(onu) { const position=(onu.interface||'').replace(/^(?:gpon|epon)-onu_/,'');return (this.isYotc(this.oltForOnu(onu))?position:position.split('/').slice(1).join('/')) || '\u2014'; },
    isYotc(olt) { return olt?.chassis_type==='C1600'; },
    oltForOnu(onu) { return this.db.olts.find(olt=>olt.id===onu?.olt_id)||onu?.olt||null; },
    supportsFeature(olt, feature) { const features=olt?.capabilities?.features||{},supported=features[feature];if(supported!==undefined)return supported===true;if(['port_traffic','onu_traffic'].includes(feature)&&!this.isYotc(olt))return features.traffic!==false;return !this.isYotc(olt); },
    onuSupports(onu, feature) { return this.supportsFeature(this.oltForOnu(onu),feature); },
    onuTrafficAvailable() { const olts=this.selected?[this.selected]:this.db.olts.filter(olt=>this.onuOlt==='all'||olt.id===Number(this.onuOlt));return olts.some(olt=>this.supportsFeature(olt,'onu_traffic')); },
    trafficPonKey(port) { return (port?.interface_name||'').match(/^epon-olt_(\d+\/\d+)$/)?.[1]||''; },
    portTrafficSupported(port) { const olt=this.db.olts.find(item=>item.id===port?.olt_id)||this.selected;if(!this.supportsFeature(olt,'port_traffic'))return false;return !this.isYotc(olt)||(olt.traffic_monitoring_enabled===true&&(olt.traffic_pon_ports||[]).includes(this.trafficPonKey(port))); },
    trafficFormPorts() { return (this.selected?.ports||[]).map(port=>({key:this.trafficPonKey(port),name:port.interface_name})).filter(port=>port.key); },
    trafficCoverageLabel(olt) { const coverage=olt.traffic_coverage||{},scope=this.isYotc(olt)?'Traffic PON':'Traffic';return scope+': '+(coverage.fresh??0)+' / '+(coverage.interfaces??0)+(this.isYotc(olt)?' PON':' interface')+' segar; target '+(coverage.target_seconds??60)+' dtk'; },
    ponLabel(olt=this.selected) { return this.isYotc(olt)?'EPON':'GPON'; },
    get connectionLabel() { return this.form.chassis_type==='C1600'?'web':this.form.cli_transport==='ssh'?'SSH':'Telnet'; },
    changeOltModel() { this.form.cli_transport=this.form.chassis_type==='C1600'?'https':'telnet';this.form.telnet_port=this.form.chassis_type==='C1600'?443:23;this.testProtocol=this.form.chassis_type==='C1600'?'web':'cli';if(this.form.chassis_type==='C1600'){this.form.connection_mode='real';this.form.snmp_enabled=false;this.testProtocol='web';} },
    changeOltTransport() { this.form.telnet_port=({http:80,https:443,ssh:22,telnet:23})[this.form.cli_transport]||this.form.telnet_port; },
    onuDisplayName(onu) { return onu.name && onu.name!==onu.sn ? onu.name : 'Nama belum terbaca'; },
    onuState(phase) { return ({working:'Online',los:'LOS','dying-gasp':'Dying gasp',offline:'Offline',poweroff:'Power off',restorefail:'Restore fail',rebooting:'Rebooting...','sync-mib':'Sync MIB',logging:'Logging',auth:'Auth'})[phase] || phase; },
    inspectOnu(onu) { this.openOnuInspector(onu); },
    get quotaFull() { return this.db.subscription && this.db.olts.length >= this.db.tenant.olt_quota; },
    get visibleOlts() {
        let result = this.db.olts.filter(o => this.statusFilter === 'all' || o.status === this.statusFilter);
        if (this.query.trim()) { const ids = new Set(this.db.compact?this.searchOltIds:this.filteredOnus.map(o => o.olt_id)); result = result.filter(o => ids.has(o.id)); }
        if (this.sort === 'name') result.sort((a, b) => a.name.localeCompare(b.name));
        if (this.sort === 'onu') result.sort((a, b) => b.onus_count - a.onus_count);
        return result;
    },
    get modalTitle() { if(this.modal==='equipment')return 'Card / Slot semua OLT';if(this.modal==='onus'&&!this.selected)return 'ONU semua OLT';if(this.modal==='ports')return 'Port '+this.ponLabel(); return ({ profiles: 'Profil layanan', provision: 'Pasang ONU', profile: 'Profil Anda', telegram: 'Notifikasi Telegram & WhatsApp', whatsapp: 'Notifikasi WhatsApp & Telegram', api: 'Open API', tags: 'Tags pelanggan', vpn: 'VPN', balance: 'Saldo', affiliate: 'Affiliate', staff: 'Staff workspace', audit: 'Audit aktivitas', degradation: 'Redaman Memburuk', maps: 'FTTH Maps', backups:'Backup konfigurasi', upgrade: 'Upgrade paket', add: 'Tambah OLT', edit: 'Edit OLT', delete: 'Hapus perangkat OLT', info: 'Informasi OLT', view: 'Detail sistem OLT', onus: 'Daftar ONU', cli: 'Web CLI' })[this.modal] || ''; },
    get moduleDescription() { return ({ telegram: 'Notifikasi gangguan dan redaman optik untuk NOC serta teknisi lapangan.', vpn: 'Koneksi privat untuk menghubungkan jaringan tenant ke platform NMS.', affiliate: 'Program referensi ISP dan pencatatan komisi langganan.' })[this.modal]; },
    oltName(id) { return this.db.olts.find(o => o.id === id)?.name || '—'; },
    traffic(onu) { if(onu.download_bps==null || onu.upload_bps==null || !onu.traffic_observed_at || Date.now()-new Date(onu.traffic_observed_at).getTime()>180000)return '\u2014 / \u2014';return '\u2193 '+(onu.download_bps/1000000).toFixed(2)+' / \u2191 '+(onu.upload_bps/1000000).toFixed(2)+' Mbps'; },
    rx(value) { return value == null ? '—' : Number(value).toFixed(2) + ' dBm'; },
    money(value) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value); },
    time(value) { return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' }).format(new Date(value)) + ' WIB'; },
    dateTime(value) { return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value.includes('T') ? value : value.replace(' ', 'T') + '+07:00')) + ' WIB'; },
    setTheme(theme) { this.theme = theme; try { localStorage.setItem('nms-theme', theme); } catch {} document.documentElement.dataset.theme = theme === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme; },
    signalClass(onu) { return onu.rx_power == null ? 'muted' : onu.rx_power <= this.db.threshold ? 'red-text' : onu.rx_power < -25 ? 'amber-text' : 'green-text'; },
    signalLabel(onu) { return onu.rx_power == null ? 'Belum tersedia' : onu.rx_power <= this.db.threshold ? 'Buruk' : onu.rx_power < -25 ? 'Waspada' : 'Normal'; },
    phaseLabel(phase) { return ({working:'working',los:'los', 'dying-gasp':'dying-gasp', rebooting:'rebooting'})[phase] || phase; },
    phaseClass(phase) { return phase === 'working' ? 'online' : phase === 'los' ? 'phase-los' : ['rebooting','sync-mib','logging','auth'].includes(phase) ? 'phase-reboot' : 'offline'; },
    open(type, olt = null) { const feature={cli:'cli',provision:'provision',backups:'backup'}[type];if(feature&&olt&&!this.supportsFeature(olt,feature)){this.notify('Fitur ini belum tersedia untuk perangkat ini.',true);return;}this.detailSerial++;this.detailOperation=null;this.onuPage=1; this.selected = olt; this.error = ''; this.validationErrors = {}; this.pendingAction = null; this.tagOnu = null; this.inspectedOnu=null; this.onuSort='name'; this.onuSortDirection=1; this.onuStatus = 'all'; this.onuPort='all';this.onuOlt='all'; this.onuHistory=null; this.modalQuery = ''; this.modal = type; this.connectionTest=''; this.detailLoading=false; if(type==='staff') this.staffForm=null; if(type==='tags') this.resetTag(); if(type==='audit') { this.auditQuery=''; this.auditAction=''; this.auditFrom=''; this.auditTo=''; this.loadAudit(); } if (!this.$refs.dialog.open) this.$refs.dialog.showModal(); this.onModuleOpen(type);if(['onus','degradation'].includes(type)){this.directory.data=[];this.loadDirectory();} if(olt?.connection_mode==='real' && ['view','info','ports'].includes(type))this.refreshRealDetails(type,olt); },
    closeModal() { if (this.busy) return; this.detailSerial++;this.detailOperation=null; this.detailLoading=false; this.onModuleClose(); this.$refs.dialog.close(); this.modal = ''; this.error = ''; this.pendingAction = null; this.tagOnu = null; this.form.telnet_password = ''; this.form.snmp_community = '';this.form.traffic_telnet_password='';this.form.traffic_enable_password=''; if(this.staffForm) this.staffForm.password=''; },
    notify(message, error = false) { this.toast = { message, error }; clearTimeout(this.toastTimer); this.toastTimer = setTimeout(() => { this.toast.message = ''; }, 6500); },
    async request(url, method = 'GET', body = undefined, options = {}) {
        const response = await fetch(url, { ...options, method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, credentials: 'same-origin', body: body === undefined ? undefined : JSON.stringify(body) });
        if (response.status === 401 || response.status === 419) { throw new Error('Sesi berakhir. Muat ulang halaman dan masuk kembali.'); }
        let data; try { data = await response.json(); } catch { throw new Error('Server tidak dapat memproses permintaan. Silakan coba lagi.'); }
        if (!response.ok) { const error = new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Permintaan gagal.'); error.fields = data.errors || {}; error.status=response.status; error.reason=data.reason; throw error; }
        return data;
    },
    async reload(options={}) { const data = await this.request('/dashboard/data?compact=1','GET',undefined,options);if(options.signal?.aborted)return;const cache=this.db.onus;this.db = data;this.db.onus=cache;this.scheduleDirectory();if(this.query.trim())this.searchDirectory();this.lastDashboardRefresh=Date.now(); if (this.selected) this.selected = this.db.olts.find(o => o.id === this.selected.id) || this.selected; if(this.inspectedOnu)this.inspectedOnu=this.db.onus.find(o=>o.id===this.inspectedOnu.id)||this.inspectedOnu; this.scheduleRebootRefresh(); this.checkOperations(); if(this.modal==='maps')await this.loadFtth();if(this.modal==='backups')await this.loadBackups();if(this.onuPage>this.onuPageCount)this.onuPage=this.onuPageCount; },
    scheduleRebootRefresh() { clearTimeout(this.rebootTimer);if(!document.hidden&&this.db.onus.some(o=>o.phase_state==='rebooting'))this.scheduleLiveRefresh(2000); },
    addOlt() { this.testProtocol='cli';this.form = { name: '', chassis_type: 'C320', ip_address: '', telnet_port: 23, telnet_user: '', telnet_password: '', snmp_port: 161, snmp_community: '', location: '', connection_mode: 'simulator', cli_transport: 'telnet', connection_timeout: 5, snmp_enabled: false, ssh_host_key_sha256: '',traffic_monitoring_enabled:false,traffic_pon_ports:[],traffic_telnet_port:23,traffic_telnet_user:'',traffic_telnet_password:'',traffic_enable_password:'',has_traffic_telnet_password:false,has_traffic_enable_password:false }; this.open('add'); },
    async editOlt(olt) { if(this.busy) return; this.form = { name: olt.name, chassis_type: olt.chassis_type, ip_address: olt.ip_address, telnet_port: olt.telnet_port, telnet_user: '', telnet_password: '', snmp_port: olt.snmp_port, snmp_community: '', location: olt.location || '', connection_mode: olt.connection_mode, cli_transport: olt.cli_transport, connection_timeout: olt.connection_timeout, snmp_enabled: olt.snmp_enabled, ssh_host_key_sha256: olt.ssh_host_key_sha256 || '',traffic_monitoring_enabled:olt.traffic_monitoring_enabled===true,traffic_pon_ports:[...(olt.traffic_pon_ports||[])],traffic_telnet_port:23,traffic_telnet_user:'',traffic_telnet_password:'',traffic_enable_password:'',has_traffic_telnet_password:false,has_traffic_enable_password:false }; this.open('edit', olt);this.testProtocol=this.isYotc(olt)?'web':'cli'; this.busy=true; try { const settings = await this.request('/olts/'+olt.id+'/settings'); this.form = {...this.form, ...settings}; } catch(e) { this.showError(e); } finally { this.busy=false; } },
    showError(e) { this.error=e.message; this.validationErrors=e.fields || {}; this.$nextTick(() => this.$refs.errorSummary?.focus()); },
    fieldError(name) { return this.validationErrors[name]?.[0] || ''; },
    async saveOlt() { if (this.busy) return; this.busy = true; this.error = ''; this.validationErrors={}; try { const data = await this.request(this.modal === 'edit' ? '/olts/' + this.selected.id : '/olts', this.modal === 'edit' ? 'PUT' : 'POST', this.form); await this.reload(); this.busy = false; this.closeModal(); this.notify(data.message); } catch (e) { this.showError(e); } finally { this.busy = false; } },
    async deleteOlt() { if (this.busy) return; this.busy = true; try { const data = await this.request('/olts/' + this.selected.id, 'DELETE'); await this.reload(); this.busy = false; this.closeModal(); this.notify(data.message); } catch (e) { this.error = e.message; } finally { this.busy = false; } },
    operation(olt) { return (this.db.active_operations||[]).find(op=>op.olt_id===olt?.id)||this.db.operations?.[olt?.id]||null; },
    visibleOperation(olt){return (this.db.active_operations||[]).find(op=>op.olt_id===olt?.id&&this.isActive(op)&&((op.actor_type==='User'&&op.kind==='sync')||op.is_initial_sync))||null;},
    get runtimeHealthy(){if(!this.db.olts.some(o=>o.connection_mode==='real'))return true;return !!this.db.monitor?.scheduler&&!!this.db.monitor?.worker&&Date.now()-new Date(this.db.monitor.scheduler).getTime()<120000&&Date.now()-new Date(this.db.monitor.worker).getTime()<600000;},
    async cancelOperation(olt){const op=this.visibleOperation(olt);if(op)await this.mutate('/olts/'+olt.id+'/operations/'+op.id,'DELETE');},
    isActive(op) { return op && ['queued','running'].includes(op.status); },
    async sync(olt) {
        if (this.syncing.includes(olt.id)) return;
        this.syncing.push(olt.id);
        try {
            const data=await this.request('/olts/'+olt.id+'/operations','POST',{kind:'sync'});
            this.manualOperations[data.operation.id]=olt.id;
            this.notify(data.message); await this.reload(); this.scheduleLiveRefresh(300);
        } catch(e) { this.notify(e.message,true); this.syncing=this.syncing.filter(id=>id!==olt.id); }
    },
    checkOperations() {
        const recent=[...(this.db.active_operations||[]),...(this.db.recent_operations||Object.values(this.db.operations||{}))];
        for(const [id,oltId] of Object.entries(this.manualOperations)) {
            const op=recent.find(row=>row.id===Number(id));
            if(op && !this.isActive(op)) {
                this.notify(op.status==='failed'?op.error:op.status==='cancelled'?'Antrean dibatalkan.':'Sinkronisasi '+(this.db.olts.find(o=>o.id===oltId)?.connection_mode==='real'?'OLT nyata berhasil':'demo selesai')+(op.warnings?.length?'. '+op.warnings.join(' '):''),op.status==='failed');
                delete this.manualOperations[id];
            }
        }
        this.syncing=(this.db.active_operations||Object.values(this.db.operations||{})).filter(op=>this.isActive(op)&&op.actor_type==='User'&&op.kind==='sync').map(op=>op.olt_id);
        if(this.detailOperation) {
            const op=recent.find(row=>row.id===this.detailOperation);
            if(op && !this.isActive(op)) { this.detailLoading=false; this.detailOperation=null; if(op.status==='failed')this.error=op.error; }
        }
    },
    scheduleLiveRefresh(delay=null) {
        clearTimeout(this.liveTimer);if(document.hidden)return;
        this.liveTimer=setTimeout(async()=>{
            if(document.hidden || this.pollBusy || this.busy) return this.scheduleLiveRefresh();
            this.pollBusy=true;this.liveAbort=new AbortController();
            try {
                const options={signal:this.liveAbort.signal};
                if(Date.now()-this.lastDashboardRefresh>=30000||this.db.onus.some(o=>o.phase_state==='rebooting'))await this.reload(options);
                else {
                    const active=(this.db.active_operations||[]).map(op=>op.id),activity=await this.request('/dashboard/activity','GET',undefined,options);
                    if(options.signal.aborted)return;
                    Object.assign(this.db,activity);this.checkOperations();
                    if(active.some(id=>!activity.active_operations.some(op=>op.id===id)))await this.reload(options);
                }
                this.liveError='';
            } catch(e) { if(e.name!=='AbortError')this.liveError=e.message; }
            finally {this.pollBusy=false;this.scheduleLiveRefresh();}
        },delay??(this.db.onus.some(o=>o.phase_state==='rebooting')?2000:(this.db.active_operations||Object.values(this.db.operations||{})).some(op=>this.isActive(op)&&(['sync','inventory'].includes(op.kind)||op.id===this.detailOperation))?2000:30000));
    },
    async toggleMonitoring() { await this.mutate('/olts/'+this.selected.id+'/monitoring','PUT',{monitoring_enabled:!this.selected.monitoring_enabled}); },
    async renameOnu(){if(this.inspectedOnu&&this.onuSupports(this.inspectedOnu,'onu_configuration'))await this.mutate('/onus/'+this.inspectedOnu.id+'/name','PUT',{name:this.onuNameDraft});},
    async toggleOnuAdmin(){const onu=this.inspectedOnu;if(!onu||!this.onuSupports(onu,'onu_configuration')||!confirm((onu.admin_state==='disable'?'Aktifkan':'Nonaktifkan')+' ONU '+onu.name+'?'))return;await this.mutate('/onus/'+onu.id+'/admin','PUT',{enabled:onu.admin_state==='disable'});},
    async saveOnuLocation(){if(this.inspectedOnu)await this.mutate('/ftth/onus/'+this.inspectedOnu.id+'/location','PUT',this.onuLocationDraft);},
    async loadOnuHistory() {
        if(!this.inspectedOnu)return; const id=this.inspectedOnu.id; this.historyLoading=true;
        try { const data=await this.request('/onus/'+id+'/history'); if(this.inspectedOnu?.id===id)this.onuHistory=data; } catch(e) {this.showError(e);} finally {this.historyLoading=false;}
    },
    openCli(olt) { if (!olt || !this.db.permissions.cli || !this.supportsFeature(olt,'cli')) return; this.command = ''; this.commandHistory=[]; this.historyIndex=0; this.commandDraft=''; this.terminalOutput = 'ZTE ZXAN · ' + olt.name + (olt.connection_mode==='real'?'\nMode REAL. Perintah dikirim langsung. Sesi baru untuk setiap perintah.\n':'\nSimulator sesi lokal. Ketik help untuk memulai.\n'); this.open('cli', olt); this.$nextTick(()=>document.getElementById('cli-command')?.focus()); },
    history(direction) { if(!this.commandHistory.length)return; if(this.historyIndex===this.commandHistory.length)this.commandDraft=this.command; this.historyIndex=Math.max(0,Math.min(this.commandHistory.length,this.historyIndex+direction)); this.command=this.commandHistory[this.historyIndex]??this.commandDraft; },
    async runCommand() { if (!this.command.trim() || this.busy || !this.supportsFeature(this.selected,'cli')) return; const cmd = this.command.trim(); this.commandHistory.push(cmd); this.historyIndex=this.commandHistory.length; this.commandDraft=''; this.command = ''; if (cmd.toLowerCase() === 'clear') { this.terminalOutput = ''; return; } this.busy = true; this.terminalOutput += '\nZXAN# ' + cmd + '\n'; try { const data = await this.request('/olts/' + this.selected.id + '/cli', 'POST', { command: cmd }); this.terminalOutput += data.output + '\n'; } catch (e) { this.terminalOutput += e.message + '\n'; } finally { this.busy = false; this.$nextTick(() => { document.getElementById('cli-command')?.focus(); const output=document.querySelector('.terminal-output'); if(output)output.scrollTop=output.scrollHeight; }); } },
    confirmAction(kind, item) { if(['reboot','onu-delete'].includes(kind)&&!this.onuSupports(item,kind==='reboot'?'reboot':'onu_configuration'))return;this.error=''; this.pendingAction={kind,id:item.id,name:item.name || item.tag_name || item.email}; this.$nextTick(()=>document.getElementById('confirm-action')?.focus()); },
    get confirmationTitle() { return ({reboot:'Reboot ONU','onu-delete':'Hapus ONU',tag:'Hapus tag',staff:'Hapus staff'})[this.pendingAction?.kind] || ''; },
    async executeAction() {
        if(!this.pendingAction || this.busy)return;const {kind,id}=this.pendingAction;
        if(kind==='reboot'){
            this.busy=true;this.error='';
            try{const result=await this.requestOnuReboot(id);if(result){this.pendingAction=null;this.notify(result.message);await this.reload();}}
            catch(e){this.showError(e);}finally{this.busy=false;}
            return;
        }
        await this.mutate(kind==='tag'?'/tags/'+id:kind==='onu-delete'?'/onus/'+id:'/staff/'+id,'DELETE',undefined,()=>{this.pendingAction=null;if(kind==='tag')this.resetTag();if(kind==='staff')this.staffForm=null;});
    },
    async mutate(url, method, body, onSuccess=()=>{}) { if(this.busy)return; this.busy=true; this.error=''; this.validationErrors={}; try { const result=await this.request(url,method,body); await this.reload(); onSuccess(); this.notify(result.message); } catch(e) { this.showError(e); } finally { this.busy=false; } },
    resetTag() { this.tagForm={id:null,tag_name:'',color_hex:'#60a5fa'}; this.validationErrors={}; },
    editTag(tag) { this.error=''; this.validationErrors={}; this.tagForm={id:tag.id,tag_name:tag.tag_name,color_hex:tag.color_hex}; this.$nextTick(()=>document.getElementById('tag-name')?.focus()); },
    async saveTag() { await this.mutate(this.tagForm.id?'/tags/'+this.tagForm.id:'/tags',this.tagForm.id?'PUT':'POST',{tag_name:this.tagForm.tag_name,color_hex:this.tagForm.color_hex},()=>this.resetTag()); },
    editOnuTags(onu) { this.error=''; this.validationErrors={}; this.tagOnu=onu; this.onuTagIds=onu.tags.map(t=>String(t.id)); this.$nextTick(()=>document.getElementById('onu-tag-editor')?.focus()); },
    async saveOnuTags() { await this.mutate('/onus/'+this.tagOnu.id+'/tags','PUT',{tag_ids:this.onuTagIds.map(Number)},()=>{this.tagOnu=null;}); },
    editStaff(staff=null) { this.error=''; this.validationErrors={}; this.staffForm={id:staff?.id??null,name:staff?.name??'',email:staff?.email??'',role:staff?.role??'cs',password:''}; this.$nextTick(()=>document.getElementById('staff-name')?.focus()); },
    async saveStaff() { await this.mutate(this.staffForm.id?'/staff/'+this.staffForm.id:'/staff',this.staffForm.id?'PUT':'POST',{name:this.staffForm.name,email:this.staffForm.email,role:this.staffForm.role,password:this.staffForm.password},()=>{this.staffForm=null;}); },
    async loadAudit(page=1) { const serial=++this.auditRequest; this.auditLoading=true; this.error=''; try { const params=new URLSearchParams({page}); for(const [key,value] of Object.entries({q:this.auditQuery,action:this.auditAction,from:this.auditFrom,to:this.auditTo}))if(value)params.set(key,value); const data=await this.request('/audit?'+params); if(serial===this.auditRequest)this.audit=data; } catch(e) { if(serial===this.auditRequest)this.showError(e); } finally { if(serial===this.auditRequest)this.auditLoading=false; } },
    async testConnection() { if(this.busy)return; this.busy=true; this.error=''; this.connectionTest=''; try { const result=await this.request('/olts/test-connection','POST',{...this.form,olt_id:this.modal==='edit'?this.selected.id:null,protocol:this.form.chassis_type==='C1600'?'web':this.testProtocol}); this.connectionTest=result.message; } catch(e){this.showError(e);} finally{this.busy=false;} },
    async refreshRealDetails(type,olt) {
        const serial=++this.detailSerial;
        try {
            const data=await this.request('/olts/'+olt.id+'/operations','POST',{kind:type==='ports'?'inventory':'system'});
            if(serial!==this.detailSerial)return;
            this.detailOperation=data.operation.id; this.detailLoading=this.isActive(data.operation);
            await this.reload(); this.scheduleLiveRefresh(300);
        } catch(e) { if(serial===this.detailSerial)this.showError(e); }
    },
    async copy(text) { try { await navigator.clipboard.writeText(text); this.notify('Alamat berhasil disalin.'); } catch { this.notify('Tidak dapat mengakses clipboard. Salin alamat secara manual.', true); } },
}, Object.getOwnPropertyDescriptors(onuConfigToolModule())));
window.Alpine = Alpine;
Alpine.start();
refreshIcons();
