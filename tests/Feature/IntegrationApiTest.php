<?php

namespace Tests\Feature;

use App\Models\{CustomerGroup, Olt, OltPort, Onu, PowerMonitor, PowerMonitorGroup, Tenant, User};
use App\Services\PowerProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function ownerToken(string $scope = 'read'): string
    {
        $owner = User::where('role', 'isp_owner')->orderBy('id')->firstOrFail();

        return $this->actingAs($owner)
            ->postJson('/api-tokens', ['name' => 'Bot AI WA', 'days' => 30, 'scope' => $scope])
            ->assertCreated()
            ->json('token');
    }

    public function test_summary_and_olts_include_status_breakdown_and_top_non_online_olts(): void
    {
        $token = $this->ownerToken('read');
        $owner = User::where('role', 'isp_owner')->orderBy('id')->firstOrFail();

        // Check /api/v1/olts includes detailed status breakdown per OLT
        $oltsRes = $this->withToken($token)->getJson('/api/v1/olts')->assertOk();
        $firstOlt = $oltsRes->json('data.0');
        $this->assertArrayHasKey('onus_count', $firstOlt);
        $this->assertArrayHasKey('online_onu', $firstOlt);
        $this->assertArrayHasKey('non_online_onu', $firstOlt);
        $this->assertArrayHasKey('offline_onu', $firstOlt);
        $this->assertArrayHasKey('dying_gasp_onu', $firstOlt);
        $this->assertArrayHasKey('los_onu', $firstOlt);
        $this->assertArrayHasKey('power_off_onu', $firstOlt);

        // Make one ONU poweroff & one dying-gasp so counts are exercised
        $olt = Olt::where('tenant_id', $owner->tenant_id)->firstOrFail();
        $onus = Onu::current()->where('olt_id', $olt->id)->take(3)->get();
        $onus[0]->update(['phase_state' => 'poweroff']);
        $onus[1]->update(['phase_state' => 'dying-gasp']);
        $onus[2]->update(['phase_state' => 'los']);

        $summaryRes = $this->withToken($token)
            ->getJson('/api/v1/monitoring/summary?top=5')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $json = $summaryRes->json();
        $this->assertSame(22, $json['total_olts']);
        $this->assertGreaterThan(0, $json['total_onus']);
        $this->assertSame($json['total_onus'] - $json['online_onus'], $json['non_online_onus']);
        $this->assertGreaterThanOrEqual(1, $json['status_breakdown']['power_off']);
        $this->assertGreaterThanOrEqual(1, $json['status_breakdown']['dying_gasp']);
        $this->assertGreaterThanOrEqual(1, $json['status_breakdown']['los']);
        $this->assertCount(5, $json['top_non_online_olts']);

        // Ensure sorted descending by non_online count
        $top = $json['top_non_online_olts'];
        for ($i = 0; $i < count($top) - 1; $i++) {
            $this->assertGreaterThanOrEqual($top[$i + 1]['non_online'], $top[$i]['non_online']);
        }
    }

    public function test_search_matches_pppoe_user_and_show_endpoint_returns_onu_detail_scoped_to_tenant(): void
    {
        $token = $this->ownerToken('read');
        $owner = User::where('role', 'isp_owner')->orderBy('id')->firstOrFail();
        $onu = Onu::current()->where('tenant_id', $owner->tenant_id)->firstOrFail();
        $onu->update(['pppoe_user' => 'erablue_tuntung_01@isp.net', 'onu_type' => 'F609']);

        CustomerGroup::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'ERABLUE',
            'color_hex' => '#10b981',
            'vlan_ids' => [],
            'name_keywords' => ['erablue'],
        ]);

        // Search by PPPoE username
        $this->withToken($token)
            ->getJson('/api/v1/onus/search?q=erablue_tuntung_01')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.onu_id', $onu->id)
            ->assertJsonPath('data.0.pppoe_username', 'erablue_tuntung_01@isp.net');

        // Multi-word & natural-language bot query must still match hyphenated ONU names
        $onu->update(['name' => 'SMY-ERABLUE-SITE-BANJARAN-BARAT']);
        $this->withToken($token)
            ->getJson('/api/v1/onus/search?q='.urlencode('tolong cek status onu client erablue banjaran'))
            ->assertOk()
            ->assertJsonPath('data.0.onu_id', $onu->id)
            ->assertJsonPath('data.0.name', 'SMY-ERABLUE-SITE-BANJARAN-BARAT')
            ->assertJsonPath('data.0.is_online', $onu->phase_state === 'working');

        // Direct detail lookup
        $this->withToken($token)
            ->getJson('/api/v1/onus/'.$onu->id)
            ->assertOk()
            ->assertJsonPath('data.onu_id', $onu->id)
            ->assertJsonPath('data.onu_type', 'F609')
            ->assertJsonPath('data.customer_groups.0', 'ERABLUE');

        // Foreign tenant ONU must return 404
        $otherTenant = Tenant::where('id', '!=', $owner->tenant_id)->firstOrFail();
        $otherOlt = $otherTenant->olts()->firstOrFail();
        $otherPort = $otherOlt->ports()->firstOrCreate(['interface_name' => 'gpon-olt_1/1/1'], ['slot_no' => 1, 'port_no' => 1]);
        $foreignOnu = $onu->replicate();
        $foreignOnu->tenant_id = $otherTenant->id;
        $foreignOnu->olt_id = $otherOlt->id;
        $foreignOnu->olt_port_id = $otherPort->id;
        $foreignOnu->sn = 'ZTEGFOREIGN01';
        $foreignOnu->save();
        $this->withToken($token)->getJson('/api/v1/onus/'.$foreignOnu->id)->assertNotFound();
    }

    public function test_power_monitoring_api_returns_categories_and_filtered_targets(): void
    {
        $probe = Mockery::mock(PowerProbeService::class);
        $probe->shouldReceive('ping')->andReturn(['reachable' => true, 'latency_ms' => 2.1, 'packet_loss' => 0, 'error' => null]);
        $this->app->instance(PowerProbeService::class, $probe);

        $token = $this->ownerToken('read');
        $owner = User::where('role', 'isp_owner')->orderBy('id')->firstOrFail();
        $group = PowerMonitorGroup::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'ERABLUE',
            'color_hex' => '#10b981',
        ]);

        PowerMonitor::create([
            'tenant_id' => $owner->tenant_id,
            'power_monitor_group_id' => $group->id,
            'name' => 'ERABLUE SITE A',
            'group_name' => 'ERABLUE',
            'monitor_type' => 'ping',
            'ip_address' => '192.0.2.88',
            'status' => 'down',
            'last_error' => 'ICMP Ping Timeout',
            'is_enabled' => true,
        ]);

        PowerMonitor::create([
            'tenant_id' => $owner->tenant_id,
            'power_monitor_group_id' => $group->id,
            'name' => 'ERABLUE SITE B',
            'group_name' => 'ERABLUE',
            'monitor_type' => 'ping',
            'ip_address' => '192.0.2.89',
            'status' => 'up',
            'last_latency_ms' => 2.1,
            'is_enabled' => true,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/monitoring/power?category=ERABLUE&status=down')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.down', 1)
            ->assertJsonPath('data.0.name', 'ERABLUE SITE A');
    }
}
