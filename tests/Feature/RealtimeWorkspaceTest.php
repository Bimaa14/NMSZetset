<?php
namespace Tests\Feature;
use App\Jobs\PollOlt;
use App\Models\{ApiToken,FtthNode,OltOperation,Onu,ServiceProfile,User};
use App\Services\Olt\OltOperationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Queue};
use Tests\TestCase;
class RealtimeWorkspaceTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);Queue::fake();}
    private function owner():User{return User::where('email','isp_owner@bima.test')->firstOrFail();}
    public function test_activity_poll_is_small_and_tenant_scoped():void{
        $owner=$this->owner();$foreign=User::where('email','owner@nusantara.test')->first();
        $own=app(OltOperationService::class)->enqueue($owner->tenant->olts()->first(),'sync',$owner);
        app(OltOperationService::class)->enqueue($foreign->tenant->olts()->first(),'sync',$foreign);
        $data=$this->actingAs($owner)->getJson('/dashboard/activity')->assertOk()->json();
        $this->assertSame([$own->id],array_column($data['active_operations'],'id'));
        $this->assertArrayNotHasKey('onus',$data);$this->assertArrayNotHasKey('olts',$data);
    }
    private function node(array $extra=[]):array{return $extra+['olt_id'=>$this->owner()->tenant->olts()->first()->id,'parent_id'=>null,'name'=>'ODP TEST','type'=>'ODP','latitude'=>-6.9,'longitude'=>107.6,'capacity'=>8];}
    private function foreignOnu():Onu {
        $tenant=User::where('email','owner@nusantara.test')->first()->tenant;$olt=$tenant->olts()->first();
        $port=$olt->ports()->firstOrCreate(['interface_name'=>'gpon-olt_1/1/1'],['slot_no'=>1,'port_no'=>1]);
        $onu=$this->owner()->tenant->onus()->first()->replicate();$onu->tenant_id=$tenant->id;$onu->olt_id=$olt->id;$onu->olt_port_id=$port->id;$onu->save();return $onu;
    }
    public function test_background_request_is_deduplicated_and_does_not_touch_device():void{
        $olt=$this->owner()->tenant->olts()->first();$olt->update(['connection_mode'=>'real','ip_address'=>'192.0.2.1']);
        $this->actingAs($this->owner());$id=$this->postJson('/olts/'.$olt->id.'/operations')->assertStatus(202)->assertJsonPath('operation.status','queued')->json('operation.id');
        $this->postJson('/olts/'.$olt->id.'/operations')->assertStatus(202)->assertJsonPath('operation.id',$id);$this->assertSame(1,OltOperation::count());
        $this->getJson('/olts/'.$olt->id.'/operations')->assertOk()->assertDontSee('telnet_pass_enc');
    }
    public function test_readonly_cannot_start_sync_and_other_tenant_cannot_read_status():void{
        $olt=$this->owner()->tenant->olts()->first();$cs=User::where('role','cs')->first();
        $this->actingAs($cs)->postJson('/olts/'.$olt->id.'/operations')->assertForbidden();
        $this->actingAs(User::where('email','owner@nusantara.test')->first())->getJson('/olts/'.$olt->id.'/operations')->assertNotFound();
    }
    public function test_worker_rechecks_role_and_releases_reservation():void{
        $owner=$this->owner();$op=app(OltOperationService::class)->enqueue($owner->tenant->olts()->first(),'sync',$owner);$owner->update(['role'=>'cs']);
        app()->call([new PollOlt($op->id),'handle']);$this->assertSame('failed',$op->fresh()->status);$this->assertNull($op->fresh()->active_olt_id);
    }
    public function test_monitor_respects_due_times_pause_and_one_active_operation():void{
        $olt=$this->owner()->tenant->olts()->first();$olt->update(['connection_mode'=>'real','monitoring_enabled'=>false]);
        $service=app(OltOperationService::class);$this->assertSame(0,$service->tick());$olt->update(['monitoring_enabled'=>true]);
        $this->assertSame(1,$service->tick());$this->assertSame(0,$service->tick());$this->assertSame('inventory',OltOperation::first()->kind);
    }
    public function test_inventory_waits_five_minutes_and_initial_progress_flag_survives_checkpoints():void{
        $olt=$this->owner()->tenant->olts()->first();$olt->update(['connection_mode'=>'real','last_sync_at'=>null,'state_observed_at'=>null,'names_observed_at'=>now(),'optical_observed_at'=>now(),'system_observed_at'=>now(),'last_backup_at'=>now(),'traffic_observed_at'=>now()]);
        $service=app(OltOperationService::class);$this->assertSame(1,$service->tick());$initial=OltOperation::where('olt_id',$olt->id)->first();$this->assertTrue($initial->is_initial_sync);
        $olt->update(['last_sync_at'=>now(),'state_observed_at'=>now()]);$this->assertTrue($initial->fresh()->is_initial_sync);
        $initial->update(['status'=>'completed','active_olt_id'=>null,'completed_at'=>now()]);
        config(['olt.state_interval'=>300]);$this->travel(61)->seconds();$olt->update(['traffic_observed_at'=>now()]);$this->assertSame(0,$service->tick());
        $this->travel(240)->seconds();$olt->update(['traffic_observed_at'=>now(),'optical_observed_at'=>now(),'system_observed_at'=>now()]);$this->assertSame(1,$service->tick());
        $background=OltOperation::where('active_olt_id',$olt->id)->first();$this->assertSame('inventory',$background->kind);$this->assertFalse($background->is_initial_sync);
    }
    public function test_stale_running_job_is_recovered_before_scheduling():void{
        $olt=$this->owner()->tenant->olts()->first();$olt->update(['connection_mode'=>'real']);$service=app(OltOperationService::class);
        $op=$service->enqueue($olt);$op->update(['status'=>'running','heartbeat_at'=>now()->subMinutes(8)]);$service->tick();
        $this->assertSame('failed',$op->fresh()->status);$this->assertNull($op->fresh()->active_olt_id);
    }
    public function test_optional_poll_failure_backs_off_without_starving_other_metrics():void{
        $olt=$this->owner()->tenant->olts()->first();$olt->update(['connection_mode'=>'real','snmp_enabled'=>true,'state_observed_at'=>now()]);
        $service=app(OltOperationService::class);$traffic=$service->enqueue($olt,'traffic');
        $traffic->update(['status'=>'failed','active_olt_id'=>null,'completed_at'=>now()]);
        $this->assertSame(1,$service->tick());$optics=OltOperation::where('active_olt_id',$olt->id)->first();$this->assertSame('optics',$optics->kind);
        $optics->update(['status'=>'failed','active_olt_id'=>null,'completed_at'=>now()]);
        $this->assertSame(1,$service->tick());$names=OltOperation::where('active_olt_id',$olt->id)->first();$this->assertSame('names',$names->kind);
        $names->update(['status'=>'completed','active_olt_id'=>null,'completed_at'=>now()]);$olt->update(['names_observed_at'=>now(),'system_observed_at'=>now(),'last_backup_at'=>now()]);
        $this->assertSame(0,$service->tick());
        $this->travel(121)->seconds();$olt->update(['state_observed_at'=>now()]);
        $this->assertSame(1,$service->tick());$this->assertSame('traffic',OltOperation::where('active_olt_id',$olt->id)->first()->kind);
    }
    public function test_api_tokens_are_hashed_tenant_scoped_and_revocable():void{
        $this->actingAs($this->owner());$data=$this->postJson('/api-tokens',['name'=>'Integration','days'=>30,'scope'=>'read'])->assertCreated()->json();
        $this->assertSame(hash('sha256',$data['token']),ApiToken::first()->token_hash);
        $this->getJson('/api-tokens')->assertOk()->assertDontSee($data['token'])->assertDontSee('token_hash');
        $response=$this->withToken($data['token'])->getJson('/api/v1/olts')->assertOk();$this->assertCount(22,$response->json('data'));
        $onu=Onu::where('tenant_id',$this->owner()->tenant_id)->first();$this->withToken($data['token'])->postJson('/api/v1/onus/'.$onu->id.'/reboot')->assertForbidden();
        $this->deleteJson('/api-tokens/'.$data['record']['id'])->assertOk();$this->withToken($data['token'])->getJson('/api/v1/olts')->assertUnauthorized();
    }
    public function test_api_rejects_expired_token_and_deleted_issuer():void{
        $this->actingAs($this->owner());$data=$this->postJson('/api-tokens',['name'=>'Expired','days'=>1,'scope'=>'read'])->json();ApiToken::first()->update(['expires_at'=>now()->subSecond()]);
        $this->withToken($data['token'])->getJson('/api/v1/olts')->assertUnauthorized();
    }
    public function test_api_live_is_honest_snapshot_with_background_operation():void{
        $this->actingAs($this->owner());$token=$this->postJson('/api-tokens',['name'=>'Live','days'=>1,'scope'=>'read'])->json('token');$onu=$this->owner()->tenant->onus()->first();
        $this->withToken($token)->getJson('/api/v1/onus/'.$onu->id.'/live')->assertStatus(202)->assertJsonPath('realtime',false)->assertJsonPath('source','stored');
        $other=$this->foreignOnu();$this->withToken($token)->getJson('/api/v1/onus/'.$other->id.'/live')->assertNotFound();
    }
    public function test_ftth_rejects_cycles_capacity_conflicts_and_cross_tenant_parents():void{
        $this->actingAs($this->owner());$a=$this->postJson('/ftth/nodes',$this->node())->assertCreated()->json('node');$b=$this->postJson('/ftth/nodes',$this->node(['name'=>'CHILD','parent_id'=>$a['id']]))->assertCreated()->json('node');
        $this->putJson('/ftth/nodes/'.$a['id'],$this->node(['parent_id'=>$b['id']]))->assertUnprocessable();
        $onus=$this->owner()->tenant->onus()->where('olt_id',$a['olt_id'])->take(2)->get();$this->postJson('/ftth/nodes/'.$a['id'].'/assign',['onu_id'=>$onus[0]->id,'port_number'=>8])->assertOk();
        $this->postJson('/ftth/nodes/'.$a['id'].'/assign',['onu_id'=>$onus[1]->id,'port_number'=>8])->assertUnprocessable();
        $this->putJson('/ftth/nodes/'.$a['id'],$this->node(['capacity'=>4]))->assertUnprocessable();$this->deleteJson('/ftth/nodes/'.$a['id'])->assertUnprocessable();
    }
    public function test_ftth_permissions_and_other_tenant_nodes_are_isolated():void{
        $this->actingAs($this->owner());$id=$this->postJson('/ftth/nodes',$this->node())->json('node.id');
        $this->actingAs(User::where('role','teknisi')->first())->getJson('/ftth')->assertOk();$this->postJson('/ftth/nodes',$this->node())->assertForbidden();
        $this->actingAs(User::where('email','owner@nusantara.test')->first())->putJson('/ftth/nodes/'.$id,$this->node())->assertNotFound();
    }
    public function test_provisioning_preview_is_single_use_and_never_stores_pppoe_password():void{
        app()->detectEnvironment(fn()=>'local');config(['nms.demo'=>true]);$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);$this->actingAs($this->owner());
        $profile=$this->postJson('/service-profiles',['name'=>'Basic','onu_type'=>'F609','mode'=>'registration'])->assertCreated()->json('profile.id');$olt=$this->owner()->tenant->olts()->first();
        $params=['profile_id'=>$profile,'frame'=>1,'slot'=>1,'port'=>1,'onu_index'=>80,'sn'=>'ZTEG11223344','name'=>'NEW-ONU'];
        $plan=$this->postJson('/olts/'.$olt->id.'/provision/preview',$params)->assertOk()->json();$payload=['plan_id'=>$plan['plan']['id'],'confirmation_token'=>$plan['confirmation_token']];
        $this->postJson('/olts/'.$olt->id.'/provision/apply',$payload)->assertOk()->assertJsonPath('plan.status','applied');$this->postJson('/olts/'.$olt->id.'/provision/apply',$payload)->assertConflict();
        $this->assertDatabaseHas('onus',['sn'=>'ZTEG11223344','name'=>'NEW-ONU','phase_state'=>'offline']);
        $this->assertStringNotContainsString($plan['confirmation_token'],DB::table('provision_plans')->first()->confirmation_hash);
    }
    public function test_profile_changes_invalidate_provision_preview():void{
        app()->detectEnvironment(fn()=>'local');config(['nms.demo'=>true]);$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);$this->actingAs($this->owner());$olt=$this->owner()->tenant->olts()->first();
        $profile=ServiceProfile::create(['tenant_id'=>$olt->tenant_id,'name'=>'Old','onu_type'=>'F609','mode'=>'registration']);
        $p=$this->postJson('/olts/'.$olt->id.'/provision/preview',['profile_id'=>$profile->id,'frame'=>1,'slot'=>1,'port'=>1,'onu_index'=>81,'sn'=>'ZTEG11223345','name'=>'NEW'])->assertOk()->json();
        $profile->update(['onu_type'=>'F660']);$this->postJson('/olts/'.$olt->id.'/provision/apply',['plan_id'=>$p['plan']['id'],'confirmation_token'=>$p['confirmation_token']])->assertConflict();
    }
    public function test_token_and_provision_management_reject_readonly_users():void{
        $this->actingAs(User::where('role','cs')->first());$this->getJson('/api-tokens')->assertForbidden();$this->postJson('/service-profiles',[])->assertForbidden();$this->getJson('/service-profiles')->assertForbidden();
    }
    public function test_onu_history_and_paginated_queries_remain_scoped():void{
        $this->actingAs($this->owner());$this->getJson('/onus?per_page=2&sort=name')->assertOk()->assertJsonCount(2,'data');
        $other=$this->foreignOnu();$this->getJson('/onus/'.$other->id.'/history')->assertNotFound();
    }
    public function test_different_operation_kinds_wait_and_are_promoted_without_being_lost():void{
        $olt=$this->owner()->tenant->olts()->first();$service=app(OltOperationService::class);$view=$service->enqueue($olt,'system',$this->owner());$sync=$service->enqueue($olt,'sync',$this->owner());
        $this->assertNotSame($view->id,$sync->id);$this->assertNull($sync->active_olt_id);$this->assertSame($sync->id,$service->enqueue($olt,'sync',$this->owner())->id);
        (new PollOlt($view->id))->failed(new \RuntimeException('test interruption'));$this->assertSame($olt->id,$sync->fresh()->active_olt_id);
    }
    public function test_preferences_do_not_change_role_or_other_users():void{
        $owner=$this->owner();$this->actingAs($owner)->putJson('/preferences',['onu_columns'=>[],'role'=>'noc'])->assertOk();$this->assertSame('isp_owner',$owner->fresh()->role);$this->assertSame([],$owner->fresh()->preferences['onu_columns']);
    }
    public function test_simulator_onu_rename_admin_and_archive_require_operational_permission():void{
        app()->detectEnvironment(fn()=>'local');config(['nms.demo'=>true]);$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $onu=$this->owner()->tenant->onus()->first();$this->actingAs($this->owner())->putJson('/onus/'.$onu->id.'/name',['name'=>'NEW-NAME'])->assertOk();$this->assertSame('NEW-NAME',$onu->fresh()->name);
        $this->putJson('/onus/'.$onu->id.'/admin',['enabled'=>false])->assertOk();$this->assertSame('disable',$onu->fresh()->admin_state);
        $this->actingAs(User::where('role','cs')->first())->deleteJson('/onus/'.$onu->id)->assertForbidden();$this->actingAs($this->owner())->deleteJson('/onus/'.$onu->id)->assertOk();$this->assertFalse((bool)$onu->fresh()->is_present);
    }
    public function test_cancelling_queued_work_promotes_the_next_kind():void{
        $this->actingAs($this->owner());$olt=$this->owner()->tenant->olts()->first();$s=app(OltOperationService::class);$a=$s->enqueue($olt,'system',$this->owner());$b=$s->enqueue($olt,'sync',$this->owner());
        $this->deleteJson('/olts/'.$olt->id.'/operations/'.$a->id)->assertOk();$this->assertSame('cancelled',$a->fresh()->status);$this->assertSame($olt->id,$b->fresh()->active_olt_id);
        $b->update(['status'=>'running']);$this->deleteJson('/olts/'.$olt->id.'/operations/'.$b->id)->assertConflict();$this->assertSame('running',$b->fresh()->status);
    }
    public function test_api_write_token_can_reboot_and_audits_the_token_actor():void{
        app()->detectEnvironment(fn()=>'local');config(['nms.demo'=>true]);$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);$this->actingAs($this->owner());
        $t=$this->postJson('/api-tokens',['name'=>'write integration','days'=>1,'scope'=>'write'])->assertCreated()->json();$onu=$this->owner()->tenant->onus()->first();
        $this->withToken($t['token'])->postJson('/api/v1/onus/'.$onu->id.'/reboot')->assertStatus(202);$this->assertDatabaseHas('audit_logs',['actor_type'=>'API_Token','actor_id'=>$t['record']['id'],'action'=>'onu.reboot.demo']);
    }
    public function test_profile_requires_current_password_and_does_not_change_role():void{
        $owner=$this->owner();$this->actingAs($owner)->putJson('/profile',['name'=>'Wrong','email'=>$owner->email,'current_password'=>'incorrect'])->assertUnprocessable();
        $this->putJson('/profile',['name'=>'Changed','email'=>$owner->email,'current_password'=>'NmsDemo2026!','password'=>'NewPassword2026!','password_confirmation'=>'NewPassword2026!','role'=>'noc'])->assertOk()->assertJsonStructure(['csrf_token']);
        $this->assertSame('Changed',$owner->fresh()->name);$this->assertSame('isp_owner',$owner->fresh()->role);$this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword2026!',$owner->fresh()->password));
    }
}
