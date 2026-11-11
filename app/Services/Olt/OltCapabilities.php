<?php
namespace App\Services\Olt;
use App\Models\Olt;
class OltCapabilities {
    public function describe(Olt $olt):array {
        $zte=in_array($olt->chassis_type,['C300','C320'],true);
        $yotc=$olt->chassis_type==='C1600';
        $supported=$zte||$yotc;
        $portTraffic=$zte||$this->hasYotcPortTraffic($olt);
        $onuTraffic=$zte||$this->hasYotcOnuTraffic($olt);
        $traffic=$portTraffic||$onuTraffic;
        $firmware=$olt->boards['metrics']['firmware_version']??$olt->firmware_version;
        $verified=in_array($olt->chassis_type.':'.$firmware,config('operations.verified_firmware',[]),true);
        return ['family_supported'=>$supported,'protocol'=>$yotc?'EPON':'GPON','firmware'=>$firmware,'firmware_verified'=>$verified,'validation'=>$yotc?'monitoring_only':($verified?'verified_firmware':'preview_and_readback_required'),'unsupported'=>$yotc?array_values(array_filter(['provision','onu_configuration','onu_restore','cli','reboot','backup', $onuTraffic?null:'onu_traffic', $traffic?null:'traffic'])):['EPON','C6xx','full_olt_restore'],'features'=>[
            'monitor'=>$supported,'system'=>$supported,'inventory'=>$supported,'names'=>$supported,'optics'=>$supported,
            'provision'=>$zte,'onu_configuration'=>$zte,'onu_restore'=>$zte,'cli'=>$zte,'reboot'=>$zte,'backup'=>$zte,'traffic'=>$traffic,'port_traffic'=>$portTraffic,'onu_traffic'=>$onuTraffic,
            'trap_receive'=>true,'trap_status_mapping'=>false,
        ]];
    }
    public function hasYotcTraffic(Olt $olt):bool {
        return $this->hasYotcPortTraffic($olt) || $this->hasYotcOnuTraffic($olt);
    }
    public function hasYotcPortTraffic(Olt $olt):bool {
        return $olt->chassis_type==='C1600' && $olt->connection_mode==='real' && $olt->traffic_monitoring_enabled
            && count($olt->traffic_pon_ports??[])>0 && (string)$olt->getRawOriginal('traffic_telnet_pass_enc')!=='';
    }
    public function hasYotcOnuTraffic(Olt $olt):bool {
        return $olt->chassis_type==='C1600' && $olt->connection_mode==='real' && $olt->traffic_monitoring_enabled
            && app(MikrotikPppoeTrafficService::class)->isConfiguredFor($olt);
    }
    /** Guard ZTE command paths even when another model supports monitoring. */
    public function requireSupported(Olt $olt):void {abort_unless(in_array($olt->chassis_type,['C300','C320'],true),422,'Model OLT belum mempunyai profil command ZTE yang didukung.');}
    public function requireFeature(Olt $olt,string $feature):void {
        abort_unless($this->describe($olt)['features'][$feature]??false,422,'Fitur '.$feature.' belum didukung untuk model '.$olt->chassis_type.'.'.($olt->chassis_type==='C1600'?' Integrasi C1600 saat ini hanya mendukung pemantauan.':''));
    }
    public function requireOperation(Olt $olt,string $kind):void {
        abort_unless(in_array($kind,['sync','inventory','names','optics','system','traffic','backup'],true),422,'Jenis operasi OLT tidak dikenal.');
        $this->requireFeature($olt,$kind==='sync'?'monitor':$kind);
    }
}
