<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class SnmpWireTest extends TestCase
{
    public function test_native_snmp_reads_system_oids_from_udp_agent(): void
    {
        $windowsDll=dirname(PHP_BINARY).'/ext/php_snmp.dll';
        if(!extension_loaded('snmp') && !is_file($windowsDll))$this->markTestSkipped('ext-snmp not available on this PHP installation.');
        $agent=new Process([PHP_BINARY,base_path('tests/Fixtures/zte-snmp-agent.php')]);
        try {
            $agent->start();$agent->waitUntil(fn()=>str_contains($agent->getOutput(),'"port":'));
            $port=json_decode(explode("\n",$agent->getOutput())[0],true)['port'];
            $args=[PHP_BINARY];if(!extension_loaded('snmp'))$args=array_merge($args,['-d','extension=snmp']);
            $probe=new Process(array_merge($args,[base_path('tests/Fixtures/snmp-probe.php'),(string)$port]),base_path(),['MIBS'=>PHP_OS_FAMILY==='Windows'?';':':']);
            $probe->mustRun();$data=json_decode($probe->getOutput(),true);
            $this->assertSame('ZTE ZXAN C320 fixture',$data['description']);
            $this->assertSame(3600,$data['uptime_seconds']);
            $this->assertSame('ZXAN-TEST',$data['hostname']);
        } finally { $agent->stop(0); }
    }
}
