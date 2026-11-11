<?php
// Isolated concurrency peer: holds leases only; never connects to a device.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default'=>'file','cache.stores.file.path'=>$argv[1],'cache.stores.file.lock_path'=>$argv[1],'olt.control_sessions'=>2]);
$olt=new App\Models\Olt(['id'=>1,'tenant_id'=>1]);
$port=new App\Models\OltPort(['id'=>1,'olt_id'=>1,'interface_name'=>'gpon-olt_1/1/1']);
$onu=(new App\Models\Onu(['id'=>1,'olt_id'=>1,'tenant_id'=>1,'onu_index'=>1]))->setRelation('olt',$olt)->setRelation('port',$port);
$s=app(App\Services\Olt\OltSessionCoordinator::class);
$s->monitor($olt,fn()=>$s->read($olt,fn()=>$s->control($onu,function(){
    echo "READY\n";flush();$until=microtime(true)+20;
    while(!file_exists($GLOBALS['argv'][1].'/release')&&microtime(true)<$until)usleep(20000);
})));
