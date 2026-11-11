<?php
namespace Tests\Feature;

use App\Jobs\PollOlt;
use App\Models\{User,OltOperation};
use App\Services\Olt\{OltOperationService,RealOltOperations};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationRecoveryTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void { parent::setUp();$this->seed(\Database\Seeders\DatabaseSeeder::class); }
    private function operation():OltOperation {return app(OltOperationService::class)->enqueue(User::where('role','isp_owner')->first()->tenant->olts()->first(),'sync');}
    public function test_operation_and_queue_job_rollback_together():void {
        DB::beginTransaction();$op=$this->operation();$this->assertDatabaseCount('jobs',1);DB::rollBack();
        $this->assertDatabaseMissing('olt_operations',['id'=>$op->id]);$this->assertDatabaseCount('jobs',0);
    }
    public function test_missing_dispatch_is_recovered_once_without_changing_stage():void {
        $op=$this->operation();$op->update(['stage'=>'names']);DB::table('jobs')->delete();$this->travel(121)->seconds();
        $service=app(OltOperationService::class);$service->recoverQueued();$service->recoverQueued();
        $this->assertDatabaseCount('jobs',1);$this->assertSame('names',$op->fresh()->stage);$this->assertSame('queued',$op->fresh()->status);
        $payload=json_decode(DB::table('jobs')->value('payload'),true);$this->assertStringContainsString('s:13:"expectedStage";s:5:"names";',$payload['data']['command']);
    }
    public function test_reserved_and_delayed_jobs_are_not_duplicated():void {
        $op=$this->operation();$this->travel(121)->seconds();DB::table('jobs')->update(['reserved_at'=>now()->timestamp]);
        app(OltOperationService::class)->recoverQueued();$this->assertDatabaseCount('jobs',1);
        DB::table('jobs')->update(['reserved_at'=>null,'available_at'=>now()->addMinutes(5)->timestamp]);
        app(OltOperationService::class)->recoverQueued();$this->assertDatabaseCount('jobs',1);
    }
    public function test_old_stage_delivery_and_cancelled_work_do_not_run():void {
        $op=$this->operation();$op->update(['stage'=>'names']);$this->mock(RealOltOperations::class)->shouldNotReceive('poll');
        app()->call([new PollOlt($op->id,'inventory'),'handle']);$this->assertSame('queued',$op->fresh()->status);
        $op->update(['status'=>'cancelled','active_olt_id'=>null]);DB::table('jobs')->delete();$this->travel(121)->seconds();
        app(OltOperationService::class)->recoverQueued();$this->assertDatabaseCount('jobs',0);
    }
}
