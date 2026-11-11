import {test} from 'node:test';
import assert from 'node:assert/strict';
import {bounds,ceiling,chartPoints,wibDate} from '../../resources/js/traffic-domain.js';
test('30-day window keeps the full preset domain and includes the unaveraged peak',()=>{
    const data={from:'2026-09-17T12:00:00Z',to:'2026-10-17T12:00:00Z',points:[{recorded_at:'2026-10-17T11:00:00Z',rx_bps:20000000,tx_bps:1000000},{recorded_at:'2026-10-17T11:45:00Z',rx_bps:30000000,tx_bps:2000000}],statistics:{rx:{peak:80820000},tx:{peak:10000000}}};
    assert.deepEqual(bounds(data),{min:Date.parse(data.from),max:Date.parse(data.to)});
    assert.equal(ceiling(data),80.82*1.15);
});
test('pre-recording baseline fills the preset while measured points and statistics stay untouched',()=>{
    const data={from:'2026-09-17T13:23:00Z',to:'2026-10-17T13:23:00Z',points:[{recorded_at:'2026-10-17T06:53:00Z',rx_bps:91740000,tx_bps:1000000}],statistics:{rx:{minimum:91740000,average:91740000}},chart_baseline:{to:'2026-10-17T06:53:00Z'}};
    const before=JSON.stringify(data),series=chartPoints(data),baseline=series.filter(p=>p.synthetic&&p.rx_bps===0);
    assert.equal(Date.parse(baseline[0].recorded_at),Date.parse(data.from));
    assert.ok(baseline.length>100&&baseline.length<=121);
    assert.ok(baseline.every(p=>Date.parse(p.recorded_at)<Date.parse(data.points[0].recorded_at)&&p.tx_bps===0));
    assert.equal(Date.parse(series.at(-1).recorded_at),Date.parse(data.to));assert.equal(series.at(-1).rx_bps,91740000);
    assert.equal(JSON.stringify(data),before);
});
test('WIB date fields follow calendar dates across UTC midnight and preset start',()=>{
    assert.equal(wibDate('2026-10-17T18:00:00Z'),'2026-10-18');
    assert.equal(wibDate('2026-09-17T13:23:00Z'),'2026-09-17');
    assert.equal(wibDate('2026-10-17T13:23:00Z','month'),'2026-10');
});
test('single valid point gets a visible domain; null placeholders never stretch it',()=>{
    const at=Date.parse('2026-10-17T11:00:00Z');
    const data={points:[{recorded_at:'2026-09-17T00:00:00Z',rx_bps:null,tx_bps:null},{recorded_at:new Date(at).toISOString(),rx_bps:0,tx_bps:120000000}]};
    assert.deepEqual(bounds(data),{min:at-30000,max:at+30000});assert.equal(ceiling(data),138);
});
