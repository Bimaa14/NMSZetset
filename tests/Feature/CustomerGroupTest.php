<?php

namespace Tests\Feature;

use App\Models\{CustomerGroup, Onu, User};
use App\Services\Olt\ZteOutputParser;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function owner(): User
    {
        return User::where('email', 'isp_owner@bima.test')->firstOrFail();
    }

    public function test_zte_running_config_parser_extracts_service_port_and_mng_vlans(): void
    {
        $config = implode("\n", [
            'interface gpon-onu_1/1/1:1',
            '  name STARBIZ-01',
            '  tcont 1 profile UP-100M',
            '  service-port 1 vport 1 user-vlan 97 vlan 97',
            '!',
            'interface gpon-onu_1/1/1:2',
            '  name RETAIL-01',
            '  service-port 1 vport 1 user-vlan 95 vlan 95',
            '  service-port 2 vport 2 user-vlan 350 svlan 350',
            '!',
            'pon-onu-mng gpon-onu_1/1/1:3',
            '  service internet gemport 1 vlan 99',
            '!',
        ]);

        $parsed = app(ZteOutputParser::class)->onuLabels($config);

        $this->assertSame('STARBIZ-01', $parsed['1/1/1:1']['device_name']);
        $this->assertSame(97, $parsed['1/1/1:1']['vlan_id']);
        $this->assertSame([97], $parsed['1/1/1:1']['vlan_ids']);

        $this->assertSame(95, $parsed['1/1/1:2']['vlan_id']);
        $this->assertSame([95, 350], $parsed['1/1/1:2']['vlan_ids']);

        $this->assertSame(99, $parsed['1/1/1:3']['vlan_id']);
        $this->assertSame([99], $parsed['1/1/1:3']['vlan_ids']);
    }

    public function test_customer_group_crud_and_vlan_based_onu_filtering(): void
    {
        $owner = $this->owner();
        $onus = Onu::current()->where('tenant_id', $owner->tenant_id)->orderBy('id')->limit(4)->get();
        $onus[0]->update(['vlan_id' => 97, 'vlan_ids' => [97], 'phase_state' => 'working']);
        $onus[1]->update(['vlan_id' => 95, 'vlan_ids' => [95], 'phase_state' => 'working']);
        $onus[2]->update(['vlan_id' => 350, 'vlan_ids' => [99, 350], 'phase_state' => 'los']);
        $onus[3]->update(['vlan_id' => 200, 'vlan_ids' => [200], 'phase_state' => 'working']);

        $starbizRes = $this->actingAs($owner)->postJson('/customer-groups', [
            'name' => 'Simaya Starbiz',
            'vlans' => '97',
            'color_hex' => '#38bdf8',
        ])->assertCreated();
        $starbizId = $starbizRes->json('group.id');

        $retailRes = $this->actingAs($owner)->postJson('/customer-groups', [
            'name' => 'Simaya Retail',
            'vlans' => '95, 99',
            'color_hex' => '#10b981',
        ])->assertCreated();
        $retailId = $retailRes->json('group.id');

        $index = $this->getJson('/customer-groups')->assertOk()->json();
        $starbiz = collect($index['groups'])->firstWhere('id', $starbizId);
        $retail = collect($index['groups'])->firstWhere('id', $retailId);

        $this->assertSame([97], $starbiz['vlan_ids']);
        $this->assertSame(1, $starbiz['total_onus']);
        $this->assertSame([95, 99], $retail['vlan_ids']);
        $this->assertSame(2, $retail['total_onus']);
        $this->assertSame(1, $retail['los_onus']);

        // Filter /onus by Simaya Retail (matches scalar 95 AND multi-VLAN json 99)
        $retailOnus = $this->getJson('/onus?customer_group_id='.$retailId)->assertOk()->json('data');
        $this->assertCount(2, $retailOnus);
        $this->assertEqualsCanonicalizing([$onus[1]->id, $onus[2]->id], array_column($retailOnus, 'id'));
        $this->assertSame('Simaya Retail', $retailOnus[0]['customer_groups'][0]['name']);

        // Filter /onus by Simaya Starbiz
        $starbizOnus = $this->getJson('/onus?customer_group_id='.$starbizId)->assertOk()->json('data');
        $this->assertCount(1, $starbizOnus);
        $this->assertSame($onus[0]->id, $starbizOnus[0]['id']);

        // Filter ungrouped excludes all 3 grouped ONUs
        $ungrouped = $this->getJson('/onus?customer_group_id=ungrouped&per_page=200')->assertOk()->json('data');
        $ungroupedIds = array_column($ungrouped, 'id');
        $this->assertNotContains($onus[0]->id, $ungroupedIds);
        $this->assertNotContains($onus[1]->id, $ungroupedIds);
        $this->assertNotContains($onus[2]->id, $ungroupedIds);
        $this->assertContains($onus[3]->id, $ungroupedIds);
    }
}
