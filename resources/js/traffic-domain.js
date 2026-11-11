export function bounds(data){
    const start=Date.parse(data.from),end=Date.parse(data.to);
    if(Number.isFinite(start)&&Number.isFinite(end)&&end>start)return {min:start,max:end};
    const times=data.points.filter(p=>p.rx_bps!=null||p.tx_bps!=null).map(p=>Date.parse(p.recorded_at)).filter(Number.isFinite);
    if(!times.length)return {min:Date.parse(data.from),max:Date.parse(data.to)};
    const min=Math.min(...times),max=Math.max(...times);return min===max?{min:min-30000,max:max+30000}:{min,max};
}
// Baseline is presentation only. Statistics, slider and CSV use measured points.
export function chartPoints(data){
    const points=data.points.map(p=>({...p}));
    const {min,max}=bounds(data),until=Date.parse(data.chart_baseline?.to);
    if(Number.isFinite(until)&&until>min){
        const step=(max-min)/119,baseline=[];
        for(let i=0;i<120;i++){const time=min+i*step;if(time>=until)break;baseline.push({recorded_at:new Date(time).toISOString(),rx_bps:0,tx_bps:0,synthetic:true});}
        const edge=until-1;
        if(edge>min&&edge>Date.parse(baseline.at(-1)?.recorded_at))baseline.push({recorded_at:new Date(edge).toISOString(),rx_bps:0,tx_bps:0,synthetic:true});
        points.unshift(...baseline);
    }
    const measured=data.points.filter(p=>p.rx_bps!=null||p.tx_bps!=null);
    if(measured.length===1&&Date.parse(measured[0].recorded_at)<max)points.push({...measured[0],recorded_at:new Date(max).toISOString(),synthetic:true});
    return points;
}
export function wibDate(value,mode='date'){
    const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date(value));
    const part=key=>parts.find(p=>p.type===key).value;
    return part('year')+'-'+part('month')+(mode==='month'?'':'-'+part('day'));
}
export function ceiling(data){return Math.max(0.001,...['rx','tx'].map(k=>Number(data.statistics?.[k]?.peak||0)/1000000),...data.points.flatMap(p=>[Number(p.rx_bps||0)/1000000,Number(p.tx_bps||0)/1000000]))*1.15;}
