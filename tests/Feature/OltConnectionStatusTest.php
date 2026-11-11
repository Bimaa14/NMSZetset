<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Olt\{OltConnectionException, RealOltOperations, ZteTelnetDriver};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OltConnectionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_failure_preserves_inventory_and_success_restores_online(): void
    {
        $this->seed(DatabaseSeeder::class);
        $olt = User::where('role', 'isp_owner')->first()->tenant->olts()->first();
        $olt->update(['connection_mode'=>'real', 'status'=>'Online']);
        $count = $olt->onus()->count();
        $driver = $this->mock(ZteTelnetDriver::class);
        $driver->shouldReceive('view')->once()->andThrow(new OltConnectionException('Connection lost', 'connect_failed'));
        $service = app(RealOltOperations::class);
        try { $service->view($olt); $this->fail('Expected connection failure'); }
        catch (OltConnectionException $e) { $this->assertSame('connect_failed', $e->reason); }
        $this->assertSame('Offline', $olt->fresh()->status);
        $this->assertSame($count, $olt->onus()->count());
        $olt->update(['snmp_enabled'=>false]);
        $olt->refresh();
        $driver->shouldReceive('view')->once()->andReturn(['system'=>['uptime'=>'100 seconds'], 'boards'=>[], 'warnings'=>[]]);
        $service->view($olt);
        $this->assertSame('Online', $olt->fresh()->status);
        $this->assertNull($olt->fresh()->last_connection_error);
    }

    public function test_parser_failure_does_not_mark_reachable_device_offline(): void
    {
        $this->seed(DatabaseSeeder::class);
        $olt = User::where('role', 'isp_owner')->first()->tenant->olts()->first();
        $olt->update(['connection_mode'=>'real', 'status'=>'Online']);
        $this->mock(ZteTelnetDriver::class)->shouldReceive('view')->once()->andThrow(new OltConnectionException('Unknown output', 'parse_failed'));
        try { app(RealOltOperations::class)->view($olt); $this->fail('Expected parser failure'); }
        catch (OltConnectionException $e) { $this->assertSame('parse_failed', $e->reason); }
        $this->assertSame('Online', $olt->fresh()->status);
        $this->assertSame('Unknown output', $olt->fresh()->last_connection_error);
    }
}
