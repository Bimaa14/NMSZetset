<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Olt, Tenant, User};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(DatabaseSeeder::class);
    }

    private function owner(): User { return User::where('email', 'isp_owner@bima.test')->firstOrFail(); }
    private function input(): array { return ['name' => 'OLT-TEST', 'chassis_type' => 'C320', 'ip_address' => '10.10.1.99', 'telnet_port' => 23, 'telnet_user' => 'admin', 'telnet_password' => 'device-demo-password', 'snmp_port' => 161, 'snmp_community' => 'private-demo', 'location' => 'Kantor']; }

    public function test_guests_must_authenticate(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/dashboard/data')->assertUnauthorized();
    }

    public function test_dashboard_has_consistent_seed_and_hides_secrets_and_other_tenants(): void
    {
        $response = $this->actingAs($this->owner())->getJson('/dashboard/data')->assertOk()->assertJsonCount(22, 'olts')->assertJsonCount(352, 'onus');
        $data = $response->json();
        $this->assertCount(64, array_filter($data['onus'], fn ($onu) => $onu['rx_power'] < -27));
        $this->assertNotContains('OLT-TENANT-LAIN', array_column($data['olts'], 'name'));
        $this->assertArrayNotHasKey('telnet_pass_enc', $data['olts'][0]);
        $this->assertArrayNotHasKey('snmp_community_enc', $data['olts'][0]);
        $this->assertArrayNotHasKey('password', $data['staff'][0]);
        $this->get('/')->assertOk()->assertSee('NMS OLT')->assertSee('OLT Anda');
    }

    public function test_cs_cannot_mutate_olts_even_with_direct_http_requests(): void
    {
        $cs = User::where('role', 'cs')->firstOrFail(); $olt = $cs->tenant->olts()->first();
        $this->actingAs($cs)->postJson('/olts', $this->input())->assertForbidden();
        $this->putJson('/olts/'.$olt->id, $this->input())->assertForbidden();
        $this->deleteJson('/olts/'.$olt->id)->assertForbidden();
        $this->postJson('/olts/'.$olt->id.'/sync')->assertForbidden();
        $this->postJson('/olts/'.$olt->id.'/cli', ['command' => 'show version'])->assertForbidden();
        $this->getJson('/dashboard/data')->assertOk()->assertJsonPath('permissions.manage', false)->assertJsonCount(0, 'staff')->assertJsonCount(0, 'audit');
    }

    public function test_noc_cannot_create_edit_or_delete_olt(): void
    {
        $user = User::where('role', 'noc')->firstOrFail(); $olt = $user->tenant->olts()->first();
        $this->actingAs($user)->getJson('/dashboard/data')->assertJsonPath('permissions.operate', true)->assertJsonPath('permissions.cli', true)->assertJsonPath('permissions.manage', false);
        $this->putJson('/olts/'.$olt->id, $this->input())->assertForbidden();
        $this->deleteJson('/olts/'.$olt->id)->assertForbidden();
    }

    public function test_technician_can_read_maps_but_cannot_sync_or_use_cli(): void
    {
        $user = User::where('role', 'teknisi')->firstOrFail(); $olt = $user->tenant->olts()->first();
        $this->actingAs($user)->getJson('/dashboard/data')->assertOk()->assertJsonPath('permissions.maps', true)->assertJsonPath('permissions.operate', false);
        $this->postJson('/olts/'.$olt->id.'/sync')->assertForbidden();
        $this->postJson('/olts/'.$olt->id.'/cli', ['command' => 'help'])->assertForbidden();
    }

    public function test_owner_cannot_address_another_tenants_olt(): void
    {
        $olt = Olt::where('name', 'OLT-TENANT-LAIN')->firstOrFail();
        $this->actingAs($this->owner())->putJson('/olts/'.$olt->id, $this->input())->assertNotFound();
        $this->deleteJson('/olts/'.$olt->id)->assertNotFound();
        $this->postJson('/olts/'.$olt->id.'/sync')->assertNotFound();
        $this->postJson('/olts/'.$olt->id.'/cli', ['command' => 'help'])->assertNotFound();
    }

    public function test_quota_is_enforced_server_side_and_delete_releases_capacity(): void
    {
        $owner = $this->owner(); $olt = $owner->tenant->olts()->first();
        $this->actingAs($owner)->postJson('/olts', $this->input())->assertUnprocessable();
        $this->deleteJson('/olts/'.$olt->id)->assertOk();
        $this->assertDatabaseMissing('onus', ['olt_id' => $olt->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'olt.delete', 'target' => $olt->name]);
        $this->postJson('/olts', $this->input())->assertCreated();
        $this->assertDatabaseHas('olts', ['name' => 'OLT-TEST', 'status' => 'Offline', 'tenant_id' => $owner->tenant_id]);
        $this->assertSame(22, $owner->tenant->olts()->count());
    }

    public function test_owner_can_edit_private_ip_and_action_is_audited(): void
    {
        $owner = $this->owner(); $olt = $owner->tenant->olts()->first();
        $this->actingAs($owner)->putJson('/olts/'.$olt->id, $this->input())->assertOk();
        $this->assertDatabaseHas('olts', ['id' => $olt->id, 'ip_address' => '10.10.1.99']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'olt.update', 'actor_id' => $owner->id]);
        $this->putJson('/olts/'.$olt->id, array_merge($this->input(), ['telnet_port' => 70000]))->assertUnprocessable();
    }

    public function test_local_simulation_and_demo_role_switch_are_disabled_in_testing(): void
    {
        config(['nms.demo' => true]); $owner = $this->owner(); $olt = $owner->tenant->olts()->first();
        $this->actingAs($owner)->postJson('/olts/'.$olt->id.'/sync')->assertStatus(503);
        $this->postJson('/demo/login', ['role' => 'noc'])->assertNotFound();
    }

    public function test_logout_ends_authenticated_session(): void
    {
        $this->actingAs($this->owner())->post('/logout')->assertRedirect('/login');
        $this->getJson('/dashboard/data')->assertUnauthorized();
    }
}
