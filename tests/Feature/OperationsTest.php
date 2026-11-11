<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Olt, Onu, Tag, User};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(DatabaseSeeder::class);
    }

    private function user(string $role = 'isp_owner'): User { return User::where('email', $role.'@bima.test')->firstOrFail(); }
    private function olt(): Olt { return $this->user()->tenant->olts()->firstOrFail(); }
    private function onu(): Onu { return $this->olt()->onus()->firstOrFail(); }
    private function input(): array { return ['name'=>'OLT-OPERATIONS','chassis_type'=>'C300','ip_address'=>'103.110.8.1','telnet_port'=>2161,'telnet_user'=>'zxan-admin','telnet_password'=>'  secret-password  ','snmp_port'=>161,'snmp_community'=>' secret-community ']; }
    private function demo(): void { $this->app->detectEnvironment(fn () => 'local'); config(['nms.demo'=>true]); $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class); }

    public function test_credentials_are_encrypted_and_edit_blanks_preserve_them(): void
    {
        config(['nms.subscription'=>false]);
        $this->actingAs($this->user())->postJson('/olts',$this->input())->assertCreated();
        $olt=Olt::where('name','OLT-OPERATIONS')->firstOrFail();
        $this->assertSame('  secret-password  ', $olt->telnet_pass_enc);
        $this->assertSame(' secret-community ', $olt->snmp_community_enc);
        $this->assertNotSame($olt->telnet_pass_enc, $olt->getRawOriginal('telnet_pass_enc'));
        $this->assertNotSame($olt->snmp_community_enc, $olt->getRawOriginal('snmp_community_enc'));
        $settings=$this->getJson('/olts/'.$olt->id.'/settings')->assertOk()->assertJsonPath('telnet_user','zxan-admin')->assertJsonPath('has_telnet_password',true);
        $this->assertStringNotContainsString('secret-password',$settings->getContent());
        $this->putJson('/olts/'.$olt->id, array_merge($this->input(),['telnet_password'=>'','snmp_community'=>'']))->assertOk();
        $this->assertSame('  secret-password  ', $olt->fresh()->telnet_pass_enc);
        $this->putJson('/olts/'.$olt->id, array_merge($this->input(),['telnet_password'=>'replacement-secret']))->assertOk();
        $this->assertSame('replacement-secret', $olt->fresh()->telnet_pass_enc);
        $this->assertStringNotContainsString('replacement-secret',$this->getJson('/dashboard/data')->getContent());
        $this->assertStringNotContainsString('secret',AuditLog::all()->toJson());
        $this->actingAs($this->user('noc'))->getJson('/olts/'.$olt->id.'/settings')->assertForbidden();
    }

    public function test_invalid_credentials_and_chassis_do_not_create_a_device(): void
    {
        config(['nms.subscription'=>false]);
        $input=$this->input(); unset($input['telnet_password'],$input['snmp_community']);
        $this->actingAs($this->user())->postJson('/olts',$input)->assertUnprocessable()->assertJsonValidationErrors(['telnet_password','snmp_community']);
        $this->postJson('/olts',array_merge($this->input(),['ip_address'=>'not-an-ip','chassis_type'=>'C650']))->assertUnprocessable()->assertJsonValidationErrors(['ip_address','chassis_type']);
        $this->assertDatabaseMissing('olts',['name'=>'OLT-OPERATIONS']);
    }

    public function test_sync_refreshes_counts_timestamp_and_audits_without_changing_optics(): void
    {
        $this->demo(); $olt=$this->olt(); $original=$this->onu()->rx_power;
        $olt->ports()->update(['online_onu'=>999,'total_onu'=>999]);
        $this->actingAs($this->user('noc'))->postJson('/olts/'.$olt->id.'/sync')->assertOk()->assertJsonStructure(['last_sync_at']);
        $this->assertTrue($olt->fresh()->last_sync_at->isToday());
        $this->assertSame(16,(int)$olt->ports()->sum('total_onu'));
        $this->assertSame(15,(int)$olt->ports()->sum('online_onu'));
        $this->assertSame($original,$this->onu()->rx_power);
        $this->assertNotNull($olt->fresh()->firmware_version);
        $this->assertDatabaseHas('audit_logs',['action'=>'olt.sync.demo','actor_id'=>$this->user('noc')->id]);
    }

    public function test_reboot_is_tenant_scoped_rejects_duplicates_and_restores_state(): void
    {
        $this->demo(); $onu=$this->onu(); $rx=$onu->rx_power;
        $this->actingAs($this->user('noc'))->postJson('/onus/'.$onu->id.'/reboot')->assertStatus(202)->assertJsonStructure(['audit_log_id']);
        $this->assertSame('rebooting',$onu->fresh()->phase_state);
        $this->assertSame(3,(int)$onu->port->fresh()->online_onu);
        $this->postJson('/onus/'.$onu->id.'/reboot')->assertConflict();
        $this->travel(9)->seconds();
        $this->getJson('/dashboard/data')->assertOk();
        $this->assertSame('working',$onu->fresh()->phase_state);
        $this->assertSame($rx,$onu->fresh()->rx_power);
        $this->assertSame(15,(int)$this->olt()->ports()->sum('online_onu'));
        $this->assertSame(1,AuditLog::where('action','onu.reboot.demo')->count());
        $los=$this->olt()->onus()->where('phase_state','los')->firstOrFail();
        $this->postJson('/onus/'.$los->id.'/reboot')->assertStatus(202);
        $this->travel(9)->seconds(); $this->getJson('/dashboard/data');
        $this->assertSame('los',$los->fresh()->phase_state);
    }

    public function test_read_only_roles_cannot_reboot_and_non_demo_does_not_simulate(): void
    {
        $onu=$this->onu();
        foreach (['cs','teknisi'] as $role) $this->actingAs($this->user($role))->postJson('/onus/'.$onu->id.'/reboot')->assertForbidden();
        $this->actingAs($this->user('noc'))->postJson('/onus/'.$onu->id.'/reboot')->assertStatus(503);
        $other=User::where('email','owner@nusantara.test')->firstOrFail();
        $this->actingAs($other)->postJson('/onus/'.$onu->id.'/reboot')->assertNotFound();
    }

    public function test_tags_crud_and_onu_assignment_are_isolated_and_audited(): void
    {
        $this->actingAs($this->user('noc'))->postJson('/tags',['tag_name'=>'Gangguan','color_hex'=>'#fb7185'])->assertCreated();
        $tag=Tag::where('tag_name','Gangguan')->firstOrFail(); $onu=$this->onu();
        $this->putJson('/onus/'.$onu->id.'/tags',['tag_ids'=>[$tag->id]])->assertOk();
        $this->assertSame([$tag->id],$onu->tags()->pluck('tags.id')->all());
        $this->putJson('/tags/'.$tag->id,['tag_name'=>'Perbaikan','color_hex'=>'#60a5fa'])->assertOk();
        $otherTag=Tag::create(['tenant_id'=>User::where('email','owner@nusantara.test')->first()->tenant_id,'tag_name'=>'Private','color_hex'=>'#000000']);
        $this->putJson('/onus/'.$onu->id.'/tags',['tag_ids'=>[$otherTag->id]])->assertUnprocessable();
        $this->putJson('/tags/'.$otherTag->id,['tag_name'=>'Leaked','color_hex'=>'#000000'])->assertNotFound();
        $this->deleteJson('/tags/'.$tag->id)->assertOk();
        $this->assertDatabaseHas('onus',['id'=>$onu->id]);
        $this->assertDatabaseMissing('onu_tag',['tag_id'=>$tag->id]);
        $this->assertDatabaseHas('audit_logs',['action'=>'tag.delete','target'=>'Perbaikan']);
        $this->actingAs($this->user('cs'))->postJson('/tags',['tag_name'=>'Forbidden','color_hex'=>'#000000'])->assertForbidden();
        $this->putJson('/onus/'.$onu->id.'/tags',['tag_ids'=>[]])->assertForbidden();
    }

    public function test_staff_management_cannot_promote_owner_or_cross_tenant_and_password_is_hashed(): void
    {
        $this->actingAs($this->user())->postJson('/staff',['name'=>'Staff Test','email'=>'staff-test@bima.test','role'=>'cs','password'=>'SafeStaffPassword12!'])->assertCreated();
        $staff=User::where('email','staff-test@bima.test')->firstOrFail();
        $this->assertTrue(Hash::check('SafeStaffPassword12!',$staff->password));
        $this->putJson('/staff/'.$staff->id,['name'=>'Staff Test','email'=>$staff->email,'role'=>'noc','password'=>''])->assertOk();
        $this->assertTrue($staff->fresh()->allows('operate'));
        $this->putJson('/staff/'.$staff->id,['name'=>'Staff Test','email'=>$staff->email,'role'=>'isp_owner'])->assertUnprocessable();
        $this->deleteJson('/staff/'.$this->user()->id)->assertForbidden();
        $other=User::where('email','owner@nusantara.test')->firstOrFail();
        $this->deleteJson('/staff/'.$other->id)->assertNotFound();
        $this->actingAs($this->user('noc'))->deleteJson('/staff/'.$staff->id)->assertForbidden();
        $this->actingAs($this->user())->deleteJson('/staff/'.$staff->id)->assertOk();
        $this->assertDatabaseHas('audit_logs',['action'=>'staff.delete']);
        $this->assertDatabaseMissing('users',['id'=>$staff->id]);
    }

    public function test_role_change_affects_existing_session_on_next_request(): void
    {
        $staff=$this->user('noc');
        $this->post('/login',['email'=>$staff->email,'password'=>'NmsDemo2026!'])->assertRedirect('/');
        $this->getJson('/dashboard/data')->assertJsonPath('permissions.operate',true);
        // Simulate Owner changing this account from a different session.
        User::whereKey($staff->id)->update(['role'=>'cs']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/dashboard/data')->assertOk()->assertJsonPath('permissions.operate',false);
        $this->postJson('/onus/'.$this->onu()->id.'/reboot')->assertForbidden();
    }

    public function test_audit_filters_and_pagination_never_return_another_tenants_history(): void
    {
        $owner=$this->user();
        for($i=0;$i<25;$i++) AuditLog::create(['tenant_id'=>$owner->tenant_id,'actor_type'=>'User','actor_id'=>$owner->id,'action'=>'tag.create','target'=>'Tag-'.$i,'created_at'=>now(),'ip_address'=>'127.0.0.1']);
        $other=User::where('email','owner@nusantara.test')->firstOrFail();
        AuditLog::create(['tenant_id'=>$other->tenant_id,'actor_type'=>'User','actor_id'=>$other->id,'action'=>'tag.create','target'=>'Private event','created_at'=>now()]);
        $response=$this->actingAs($owner)->getJson('/audit?action=tag.create')->assertOk()->assertJsonPath('total',25)->assertJsonCount(20,'data')->assertJsonPath('data.0.actor_name',$owner->name);
        $this->assertStringNotContainsString('Private event',$response->getContent());
        $this->getJson('/audit?action=tag.create&page=2')->assertJsonCount(5,'data');
        $this->getJson('/audit?q=Tag-24')->assertJsonPath('total',1);
        $this->getJson('/audit?from=2026-10-20&to=2026-10-01')->assertUnprocessable();
        $this->actingAs($this->user('noc'))->getJson('/audit')->assertForbidden();
    }

    public function test_cli_remains_a_simulator_and_does_not_execute_shell_commands(): void
    {
        $this->demo(); $olt=$this->olt();
        $this->actingAs($this->user('noc'))->postJson('/olts/'.$olt->id.'/cli',['command'=>'show processor'])->assertOk()->assertJsonPath('output','CPU load: '.$olt->cpu.'% (snapshot demo)');
        $this->postJson('/olts/'.$olt->id.'/cli',['command'=>'show gpon onu optical-power'])->assertOk();
        $this->postJson('/olts/'.$olt->id.'/cli',['command'=>'rm -rf /'])->assertOk()->assertJsonPath('output','Perintah tidak tersedia. Ketik help untuk daftar perintah simulator.');
    }
}
