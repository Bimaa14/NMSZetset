<?php

return [
    // Reserved independently of one monitoring and one interactive read session.
    'control_sessions' => max(1, min(4, (int) env('OLT_CONTROL_SESSIONS', 2))),
    'state_interval' => max(300, (int) env('OLT_STATE_INTERVAL', 300)),
    'optical_interval' => max(60, (int) env('OLT_OPTICAL_INTERVAL', 300)),
    'names_interval' => max(60, (int) env('OLT_NAMES_INTERVAL', 900)),
    'system_interval' => max(60, (int) env('OLT_SYSTEM_INTERVAL', 300)),
    'traffic_interval' => max(15, (int) env('OLT_TRAFFIC_INTERVAL',60)),
    'backup_interval' => max(3600, (int) env('OLT_BACKUP_INTERVAL',86400)),
    'poll_retry_seconds' => 120,
    'command_timeout' => (float) env('OLT_COMMAND_TIMEOUT', 10),
    'operation_timeout' => (int) env('OLT_OPERATION_TIMEOUT', 300),
    'optical_idle_timeout' => (float) env('OLT_OPTICAL_IDLE_TIMEOUT', 30),
    'save_reserve' => 10,
    'name_poll_budget' => 90,
    'name_idle_timeout' => 5,
    'max_output_bytes' => 4 * 1024 * 1024,
    'max_ports' => 256,
    'max_onus' => 32768,
    // C1600 optical queries are per ONU; avoid bursts against the management CPU.
    'yotc_optical_batch' => 12,
    'yotc_request_delay_ms' => 250,
    'yotc_traffic_interval' => 60,
    'yotc_traffic_live_interval' => 15,
    'yotc_traffic_request_delay_ms' => 250,
    'mikrotik_snapshot_ttl' => (int) env('MIKROTIK_SNAPSHOT_TTL', 12),
    'mikrotik_routers' => [
        'cikuray' => [
            'name' => 'SMY-CORE-BDG-CIKURAY',
            'host' => (string) env('MIKROTIK_CIKURAY_HOST', ''),
            'port' => (int) env('MIKROTIK_CIKURAY_PORT', 60193),
            'user' => (string) env('MIKROTIK_CIKURAY_USER', ''),
            'password' => (string) env('MIKROTIK_CIKURAY_PASS', ''),
            'timeout' => (float) env('MIKROTIK_CIKURAY_TIMEOUT', 12),
            'service_filter' => env('MIKROTIK_CIKURAY_SERVICE', null),
        ],
        'tamsar' => [
            'name' => 'RO-SIMAYA-TAMANSARI',
            'host' => (string) env('MIKROTIK_TAMSAR_HOST', ''),
            'port' => (int) env('MIKROTIK_TAMSAR_PORT', 60193),
            'user' => (string) env('MIKROTIK_TAMSAR_USER', ''),
            'password' => (string) env('MIKROTIK_TAMSAR_PASS', ''),
            'timeout' => (float) env('MIKROTIK_TAMSAR_TIMEOUT', 12),
            'service_filter' => env('MIKROTIK_TAMSAR_SERVICE', null),
        ],
    ],
    'board_ports' => ['GTGO' => 8, 'GTGH' => 16],
    'snmp_retries' => 1,
    // Optional numeric vendor OIDs verified against the installed ZTE firmware/MIB.
    // No vendor-specific index or scaling is guessed.
    'snmp_metrics' => [],
];
