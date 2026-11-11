<?php
namespace App\Services\Olt;
use App\Models\Onu;
class ZteOnuConfiguration {
    public function __construct(private ZteTelnetDriver $driver,private ZteOutputParser $parser){}
    public function apply(Onu $onu,string $action,array $data=[]):void{
        app(OltCapabilities::class)->requireFeature($onu->olt,'onu_configuration');
        $client=$this->driver->client($onu->olt);$port=$onu->port->interface_name;$index=str_replace('gpon-olt_','',$port).':'.$onu->onu_index;
        try{
            $client->connect($onu->olt);$registered=$this->parser->configuredOnus($client->command('show running-config interface '.$port),$port);
            if(($registered[$index]['sn']??null)!==$onu->sn)throw new OltConnectionException('Identitas ONU pada perangkat berubah. Jalankan Sync sebelum mengubah konfigurasi.','configuration');
            $commands=$action==='delete'?['configure terminal','interface '.$port,'no onu '.$onu->onu_index,'end']:['configure terminal','interface gpon-onu_'.$index,$action==='rename'?'name '.$data['name']:($data['enabled']?'no shutdown':'shutdown'),'end'];
            try{
                foreach($commands as $command){$output=$client->command($command,$action==='delete');if(preg_match(ZteTelnetClient::CONFIRM,$output))$client->command('yes');}
                if($action==='delete'){
                    $after=$this->parser->configuredOnus($client->command('show running-config interface '.$port),$port);
                    if(isset($after[$index]))throw new OltConnectionException('ONU masih tercatat setelah perintah hapus.','configuration_uncertain');
                }elseif($action==='rename'){
                    $labels=$this->parser->onuLabels($client->command('show running-config interface gpon-onu_'.$index));
                    if(($labels[$index]['device_name']??null)!==$data['name'])throw new OltConnectionException('Nama baru belum terverifikasi dari OLT.','configuration_uncertain');
                }else{
                    $states=$this->parser->states($client->command('show gpon onu state '.$port),$port);$expected=$data['enabled']?'enable':'disable';
                    if(strtolower($states[$index]['admin_state']??'')!==$expected)throw new OltConnectionException('Admin state belum terverifikasi dari OLT.','configuration_uncertain');
                }
            }catch(OltConnectionException $e){throw new OltConnectionException('Hasil konfigurasi perlu diperiksa sebelum diulang. '.$e->getMessage(),'configuration_uncertain');}
        }finally{$client->close();}
    }
}
