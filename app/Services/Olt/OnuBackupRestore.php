<?php
namespace App\Services\Olt;
use App\Models\{Onu,OltBackup};
use App\Http\Requests\OnuToolRequest;
class OnuBackupRestore {
    public function prepare(Onu $onu,OltBackup $backup,CliClient $client,string $interface):array {
        app(OltCapabilities::class)->requireFeature($onu->olt,'onu_restore');
        abort_unless($backup->olt_id===$onu->olt_id&&$backup->tenant_id===$onu->tenant_id,404);
        $text=$backup->configuration;abort_unless(hash_equals($backup->checksum,hash('sha256',$text)),409,'Checksum backup tidak cocok.');
        $port=$onu->port->interface_name;$key=str_replace('gpon-olt_','',$port).':'.$onu->onu_index;
        preg_match('/^\h*interface '.preg_quote($port,'/').'\h*\r?\n(.*?)(?=^\h*!|^interface |^pon-onu-mng |\z)/ms',$text,$portBlock);
        abort_unless(isset($portBlock[0]),422,'Blok PON tidak tersedia di backup.');
        $registered=app(ZteOutputParser::class)->configuredOnus($portBlock[0],$port);
        abort_unless(strcasecmp($registered[$key]['sn']??'',$onu->sn)===0,409,'Backup tidak memuat serial yang sama pada posisi ONU ini.');
        $block=function(string $context)use($text,$interface):string{
            preg_match('/^\h*'.preg_quote($context.' '.$interface,'/').'\h*\r?\n(.*?)(?=^\h*!|^interface |^pon-onu-mng |\z)/ms',$text,$m);
            abort_unless(isset($m[0]),422,'Blok layanan ONU tidak lengkap di backup.');return $m[0];
        };
        $service=app(OnuToolService::class);$parser=app(OnuToolParser::class);$desired=$parser->parse($block('interface'),$block('pon-onu-mng'));
        $read=$service->read($client,$interface);$state=$read['state'];$profiles=$service->profiles($onu,$client);$plans=[];$commands=[];$secrets=[];
        foreach(['tcont','gem','service','onu_service','ports','wan','tr069','remote']as$kind)foreach($desired[$kind]as$row){
            $keyField=match($kind){'ports'=>'port','onu_service'=>'name',default=>'id'};
            $input=$row+['kind'=>$kind,'action'=>collect($state[$kind])->contains(fn($r)=>(string)$r[$keyField]===(string)$row[$keyField])?'update':'create'];
            if($kind==='ports'){if(!preg_match('/^(eth|wifi)_0\/([1-4])$/',$row['port'],$m))abort(422,'Jenis port backup belum didukung restore.');$input['port_type']=$m[1]==='eth'?'ethernet':'wlan';$input['port_id']=(int)$m[2];}
            if($kind==='wan')$input['ping']=($row['ping']??'disable')==='enable';
            if($kind==='tr069')$input['auth']=($row['auth']??'disable')==='basic'?'basic':'disable';
            foreach(['id','tcont_id','gem_id','vlan','priority','host','port_id']as$field)if(isset($input[$field])&&is_numeric($input[$field]))$input[$field]=(int)$input[$field];
            $request=new OnuToolRequest();$request->merge($input);$input=validator($input,$request->rules())->validate();
            $plan=$service->prepare($input,$state,$profiles,$interface);$plans[]=$plan;array_push($commands,...$plan['commands']);if(!empty($plan['input']['password']))$secrets[]=$plan['input']['password'];
            $state[$kind]=array_values(array_filter($state[$kind],fn($r)=>(string)$r[$keyField]!==(string)$row[$keyField]));$state[$kind][]=$row;
        }
        abort_unless($plans,422,'Backup tidak memuat entri layanan yang dapat dipulihkan.');
        return ['input'=>['kind'=>'restore','action'=>'update'],'commands'=>$commands,'restore_plans'=>$plans,'secrets'=>$secrets,'fingerprint'=>$read['fingerprint'],'backup'=>$read['backup'],'connection'=>$service->connection($onu),'source_backup_id'=>$backup->id];
    }
}
