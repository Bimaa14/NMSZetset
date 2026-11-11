<?php
return [
    'verified_firmware'=>[],
    'trap_bind'=>env('NMS_TRAP_BIND','127.0.0.1:1162'),
    'retention_days'=>['onu_traffic_logs'=>45,'port_traffic_logs'=>45,'onu_optical_logs'=>90,'onu_status_logs'=>180,'olt_operations'=>90,'snmp_trap_events'=>30,'olt_backups'=>90],
];
