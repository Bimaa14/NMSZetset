// Only an explicit lock rejection proves that no write was attempted.
// Transport errors and uncertain device acknowledgements must never be replayed.
export async function rebootWhenAvailable(request,id,{signal,onWaiting=()=>{}}={}) {
    const deadline=Date.now()+90000;
    while(!signal?.aborted){
        onWaiting(false);
        try{return await request('/onus/'+id+'/reboot','POST');}
        catch(error){
            if(error.status!==409||error.reason!=='olt_busy')throw error;
            if(Date.now()>=deadline)throw new Error('OLT masih sibuk. Reboot belum dikirim. Coba lagi setelah operasi OLT selesai.');
            if(signal?.aborted)break;
            onWaiting(true);
            await new Promise(resolve=>{
                const done=()=>{clearTimeout(timer);signal?.removeEventListener('abort',done);resolve();};
                const timer=setTimeout(done,2000);
                signal?.addEventListener('abort',done,{once:true});
            });
        }
    }
    return null;
}

export function onuRebootModule(){return {
    rebootState:null,
    cancelRebootWait(){this.rebootState?.controller.abort();},
    async requestOnuReboot(id){
        const onu=this.db.onus.find(item=>item.id===id)||this.inspectedOnu;if(onu&&!this.onuSupports(onu,'reboot'))throw new Error('Reboot belum tersedia untuk perangkat ini.');
        if(this.rebootState)throw new Error('Permintaan reboot lain masih diproses.');
        const controller=new AbortController();this.rebootState={id,controller,waiting:false};
        try{return await rebootWhenAvailable((...args)=>this.request(...args),id,{
            signal:controller.signal,onWaiting:waiting=>{if(this.rebootState?.controller===controller)this.rebootState.waiting=waiting;}
        });}
        finally{if(this.rebootState?.controller===controller)this.rebootState=null;}
    },
};}
