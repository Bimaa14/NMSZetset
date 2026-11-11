// Windows-compatible local PHP pool. Every request still gets a fresh Laravel lifecycle.
import http from 'node:http';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.argv[2],port=Number(process.argv[3]||8000),count=6;
if(!php)throw new Error('Provide the PHP executable path.');
const workers=[];let stopping=false;
const server=http.createServer((req,res)=>{
    const worker=workers.filter(w=>w.ready&&!w.child.killed).sort((a,b)=>a.busy-b.busy)[0];
    if(!worker){res.writeHead(503);res.end('Local PHP workers unavailable.');return;}
    worker.busy++;
    const upstream=http.request({hostname:'127.0.0.1',port:worker.port,path:req.url,method:req.method,headers:req.headers,agent:false},reply=>{
        if(res.destroyed)reply.resume();else {res.writeHead(reply.statusCode,reply.headers);reply.pipe(res);}
        reply.on('error',()=>res.destroy());
    });
    let released=false;
    const release=()=>{if(!released){released=true;worker.busy--;}};
    upstream.on('error',()=>{release();if(!res.headersSent)res.writeHead(502);res.end('Local PHP request failed.');});
    upstream.on('close',release);
    req.on('aborted',()=>upstream.destroy());
    // A cancelled browser read cannot interrupt PHP's Telnet IO. Keep accounting
    // for that worker until PHP finishes, so new clicks use another worker.
    upstream.setTimeout(360000,()=>upstream.destroy());
    req.pipe(upstream);
});
async function freePort(){const probe=net.createServer();await new Promise((resolve,reject)=>{probe.once('error',reject);probe.listen(0,'127.0.0.1',resolve);});const p=probe.address().port;await new Promise(resolve=>probe.close(resolve));return p;}
async function startWorker(){
    const p=await freePort();const env={...process.env};delete env.PHP_CLI_SERVER_WORKERS;
    const args=['-S',`127.0.0.1:${p}`,'-t','public'];
    if(process.env.NMS_LOCAL_ROUTER)args.push(process.env.NMS_LOCAL_ROUTER);
    const child=spawn(php,args,{cwd:root,env,windowsHide:true,stdio:['ignore','inherit','inherit']});
    const worker={child,port:p,busy:0,ready:false};workers.push(worker);
    child.on('error',error=>{console.error(error.message);shutdown(1);});
    child.on('exit',()=>{worker.ready=false;if(!stopping){console.error('PHP worker exited; stopping the local pool.');shutdown(1);}});
    for(let attempt=0;attempt<100;attempt++){
        if(stopping)return;
        const ready=await new Promise(resolve=>{const socket=net.connect(p,'127.0.0.1');socket.on('connect',()=>{socket.destroy();resolve(true);});socket.on('error',()=>resolve(false));});
        if(ready){worker.ready=true;return;}
        await new Promise(resolve=>setTimeout(resolve,50));
    }
    throw new Error('PHP worker startup timed out.');
}
function shutdown(code=0){if(stopping)return;stopping=true;server.close();server.closeAllConnections();for(const w of workers)w.child.kill();setTimeout(()=>process.exit(code),100).unref();}
process.on('SIGINT',()=>shutdown());process.on('SIGTERM',()=>shutdown());
server.on('error',error=>{console.error(error.message);shutdown(1);});
try{await Promise.all(Array.from({length:count},startWorker));if(!stopping)server.listen(port,'127.0.0.1',()=>console.log(`NMS: http://127.0.0.1:${port} (${count} PHP workers)`));}catch(error){console.error(error.message);shutdown(1);}
