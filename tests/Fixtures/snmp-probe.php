<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$olt=new App\Models\Olt(['ip_address'=>'127.0.0.1','snmp_port'=>(int)$argv[1],'connection_timeout'=>1]);
$olt->snmp_community_enc='test-community';
echo json_encode(app(App\Services\Olt\ZteSnmpDriver::class)->system($olt));
