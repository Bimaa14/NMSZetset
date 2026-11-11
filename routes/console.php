<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('nms:tick', function () {
    $this->info(app(\App\Services\Olt\OltOperationService::class)->tick().' operasi dijadwalkan.');
})->purpose('Schedule due OLT inventory, labels, optics and system reads');
Schedule::command('nms:tick')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('nms:monitor {--once}', function () {
    do {
        $lock=\Illuminate\Support\Facades\Cache::lock('nms:monitor:tick',20);
        if($lock->get()) { try { app(\App\Services\Olt\OltOperationService::class)->tick(); } finally { $lock->release(); } }
        if(!\Illuminate\Support\Facades\Cache::has('nms:retention:done:'.now()->toDateString())) {
            $retentionLock=\Illuminate\Support\Facades\Cache::lock('nms:retention',3600);
            if($retentionLock->get()){try{app(\App\Services\HistoryRetention::class)->run(true);\Illuminate\Support\Facades\Cache::put('nms:retention:done:'.now()->toDateString(),true,172800);}finally{$retentionLock->release();}}
        }
        if($this->option('once'))break;
        usleep(15000000);
    } while(true);
})->purpose('Run the OLT monitor loop; stop with Ctrl+C');

Artisan::command('nms:prune {--apply}', function () {
    $counts=app(\App\Services\HistoryRetention::class)->run((bool)$this->option('apply'));
    $this->line(json_encode(['applied'=>(bool)$this->option('apply'),'rows'=>$counts]));
})->purpose('Preview retention cleanup; --apply deletes expired history');

Artisan::command('nms:trap-listen {--bind=} {--once}', function () {
    $bind=$this->option('bind')?:config('operations.trap_bind');
    $socket=stream_socket_server('udp://'.$bind,$errno,$error,STREAM_SERVER_BIND);
    if(!$socket){$this->error('Tidak dapat membuka listener trap.');return 1;}
    $this->info('Trap listener '.$bind.'; event disimpan tanpa mengubah status ONU.');
    try {do {$peer='';$packet=stream_socket_recvfrom($socket,65507,0,$peer);if($packet===false)continue;$source=preg_replace('/:\d+$/','',$peer);$source=trim($source,'[]');app(\App\Services\SnmpTrapReceiver::class)->accept($packet,$source);}while(!$this->option('once'));}finally{fclose($socket);}
})->purpose('Receive authenticated SNMP v1/v2c traps from configured OLT addresses');
