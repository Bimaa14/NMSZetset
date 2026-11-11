import {bounds,ceiling,chartPoints} from './traffic-domain';
import Chart from 'chart.js/auto';
import zoomPlugin from 'chartjs-plugin-zoom';

const charts=new WeakMap();
const ranges=new WeakMap();
const clock=value=>new Intl.DateTimeFormat('id-ID',{timeZone:'Asia/Jakarta',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date(value));
Chart.register(zoomPlugin,{
    id:'trafficCrosshair',afterDatasetsDraw(chart){
        if(!chart.options.plugins.trafficCrosshair)return;
        const active=chart.tooltip?.getActiveElements();if(!active?.length)return;
        const {ctx,chartArea}=chart,x=active[0].element.x;
        ctx.save();ctx.beginPath();ctx.setLineDash([4,4]);ctx.strokeStyle='#94a3b8';ctx.moveTo(x,chartArea.top);ctx.lineTo(x,chartArea.bottom);ctx.stroke();ctx.restore();
    },
});

function datasets(data){
    const series=chartPoints(data);
    return ['rx','tx'].map((key,i)=>{
        const points=series.map(p=>({x:Date.parse(p.recorded_at),y:p[key+'_bps']==null?null:p[key+'_bps']/1000000})).filter(p=>Number.isFinite(p.x));
        const valid=points.filter(p=>p.y!==null&&Number.isFinite(p.y));
        return {label:i?'Upload':'Download',data:points,borderColor:i?'#cbd5e1':'#60a5fa',borderWidth:2.5,borderDash:i?[6,5]:[],pointRadius:context=>data.points.length===1&&!series[context.dataIndex]?.synthetic?3:0,pointHoverRadius:5,pointHoverBorderWidth:2,pointHoverBackgroundColor:i?'#cbd5e1':'#60a5fa',pointHoverBorderColor:'#0f172a',cubicInterpolationMode:'monotone',tension:.35,spanGaps:true,fill:!i,backgroundColor:context=>{
            if(i)return 'transparent';const {chart}=context;if(!chart.chartArea)return '#3b82f622';const gradient=chart.ctx.createLinearGradient(0,chart.chartArea.top,0,chart.chartArea.bottom);gradient.addColorStop(0,'#3b82f65c');gradient.addColorStop(1,'#3b82f603');return gradient;
        }};
    });
}
const dateClock=value=>new Intl.DateTimeFormat('id-ID',{timeZone:'Asia/Jakarta',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit',hour12:false}).format(new Date(value));
const dateOnly=value=>new Intl.DateTimeFormat('id-ID',{timeZone:'Asia/Jakarta',day:'2-digit',month:'short'}).format(new Date(value));
const domainTicks=scale=>{scale.ticks=Array.from({length:6},(_,i)=>({value:scale.min+(scale.max-scale.min)*i/5}));};
export function renderTrafficChart(canvas,data){
    if(!canvas||!data)return;let chart=charts.get(canvas);
    const {min,max}=bounds(data),yMax=ceiling(data),label=data.longRange?(max-min>=7*86400000?dateOnly:dateClock):clock,tooltipLabel=data.longRange?dateClock:clock;
    const previous=ranges.get(canvas);ranges.set(canvas,{min,max});
    if(chart){const zoomed=previous&&(Math.abs(chart.scales.x.min-previous.min)>1||Math.abs(chart.scales.x.max-previous.max)>1);chart.data.datasets=datasets(data);chart.options.plugins.zoom.limits.x={min,max,minRange:1000};if(!zoomed){chart.options.scales.x.min=min;chart.options.scales.x.max=max;}chart.options.scales.y.max=yMax;chart.options.scales.x.ticks.callback=label;chart.options.plugins.tooltip.callbacks.title=items=>items.length?tooltipLabel(items[0].parsed.x):'';chart.update('none');return;}
    const light=document.documentElement.dataset.theme==='light',text=light?'#475569':'#94a3b8';
    chart=new Chart(canvas,{type:'line',data:{datasets:datasets(data)},options:{responsive:true,maintainAspectRatio:false,animation:false,parsing:false,normalized:true,interaction:{mode:'index',intersect:false},layout:{padding:8},scales:{x:{type:'linear',min,max,afterBuildTicks:domainTicks,ticks:{autoSkip:false,maxTicksLimit:7,color:text,callback:label,font:{size:11}},grid:{display:false},border:{color:light?'#cbd5e1':'#334155'}},y:{max:yMax,beginAtZero:true,title:{display:true,text:'Mbps',color:text},ticks:{color:text,maxTicksLimit:5},grid:{color:light?'#cbd5e166':'#33415588'},border:{display:false,dash:[4,4]}}},plugins:{legend:{display:false},trafficCrosshair:true,tooltip:{enabled:true,backgroundColor:'#0f172a',borderColor:'#334155',borderWidth:1,titleColor:'#e2e8f0',bodyColor:'#e2e8f0',padding:12,cornerRadius:7,boxPadding:6,bodySpacing:6,titleMarginBottom:8,bodyFont:{size:13},usePointStyle:true,callbacks:{title:items=>items.length?tooltipLabel(items[0].parsed.x):'',label:context=>context.dataset.label+': '+context.parsed.y.toFixed(2)+' Mbps',labelColor:context=>({borderColor:context.dataset.borderColor,backgroundColor:context.dataset.borderColor})}},zoom:{limits:{x:{min,max,minRange:1000}},pan:{enabled:false,mode:'x'},zoom:{mode:'x',wheel:{enabled:false},pinch:{enabled:true},drag:{enabled:true,backgroundColor:'#60a5fa33',borderColor:'#60a5fa',borderWidth:1}}}}}});
    charts.set(canvas,chart);
}
export function destroyTrafficChart(canvas){charts.get(canvas)?.destroy();charts.delete(canvas);ranges.delete(canvas);}
export function trafficChartAction(canvas,action){const chart=charts.get(canvas);if(!chart)return;
    if(action==='in'||action==='out')chart.zoom({x:action==='in'?1.5:1/1.5});
    if(action==='reset'){chart.resetZoom('none');Object.assign(chart.options.scales.x,ranges.get(canvas));chart.update('none');}
    if(action==='pan'||action==='select'){chart.options.plugins.zoom.pan.enabled=action==='pan';chart.options.plugins.zoom.zoom.drag.enabled=action==='select';chart.update('none');}
}
export function focusTrafficPoint(canvas,point){const chart=charts.get(canvas);if(!chart||!point)return;const index=chart.data.datasets[0].data.findIndex(p=>p.x===Date.parse(point.recorded_at));if(index<0)return;const active=chart.data.datasets.map((d,datasetIndex)=>({datasetIndex,index})).filter(a=>chart.data.datasets[a.datasetIndex].data[index]?.y!=null);chart.setActiveElements(active);chart.tooltip.setActiveElements(active,{x:chart.scales.x.getPixelForValue(Date.parse(point.recorded_at)),y:chart.chartArea.top});chart.update('none');}
export function downloadTraffic(canvas,data,type){
    const link=document.createElement('a');let url;
    if(type==='png'){if(!charts.get(canvas))return;link.href=charts.get(canvas).toBase64Image();link.download='traffic.png';}
    else{const rows=['recorded_at,download_bps,upload_bps,source',...data.points.map(p=>[p.recorded_at,p.rx_bps??'',p.tx_bps??'',p.source].join(','))];url=URL.createObjectURL(new Blob([rows.join('\r\n')],{type:'text/csv;charset=utf-8'}));link.href=url;link.download='traffic.csv';}
    link.click();if(url)setTimeout(()=>URL.revokeObjectURL(url),1000);
}
