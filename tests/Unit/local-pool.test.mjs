import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import path from 'node:path';
import net from 'node:net';
test('six PHP workers keep fast requests responsive during slow and aborted reads',async()=>{
    const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
    const child=spawn(process.execPath,['scripts/local-web.mjs',path.resolve('.tools/php/php.exe'),String(port)],{windowsHide:true,env:{...process.env,MIBS:';',NMS_LOCAL_ROUTER:path.resolve('tests/Fixtures/local-pool-router.php')},stdio:['ignore','pipe','pipe']});
    let logs='';child.stderr.on('data',d=>logs+=d);
    try{
        await new Promise((resolve,reject)=>{const timeout=setTimeout(()=>reject(new Error(logs)),10000);child.stdout.on('data',()=>{clearTimeout(timeout);resolve();});child.once('exit',()=>{clearTimeout(timeout);reject(new Error(logs));});});
        const base=`http://127.0.0.1:${port}`;const slow=Array.from({length:5},()=>fetch(base+'/slow').then(r=>r.json()));
        await new Promise(resolve=>setTimeout(resolve,200));
        const start=Date.now();const fast=await fetch(base+'/fast').then(r=>r.json());assert.ok(Date.now()-start<1000,'Fast request blocked behind slow IO');
        const replies=await Promise.all(slow);assert.equal(new Set([...replies,fast].map(r=>r.pid)).size,6);
        const abort=new AbortController();const cancelled=fetch(base+'/slow',{signal:abort.signal}).catch(()=>null);await new Promise(resolve=>setTimeout(resolve,100));abort.abort();await cancelled;
        const next=Date.now();await fetch(base+'/fast');assert.ok(Date.now()-next<1000,'Cancelled read reused its still-busy worker');
    }finally{child.kill('SIGTERM');await new Promise(resolve=>child.once('exit',resolve));}
});
