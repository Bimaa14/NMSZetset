<?php
namespace Tests\Feature;
use App\Jobs\PollOlt;
use App\Models\{Olt,OnuOpticalLog,User};
use App\Services\Olt\{OltOperationService,RealOltOperations};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;
class BackgroundDriverTest extends TestCase {
    use RefreshDatabase;
    private ?Process $server=null;
    protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);Queue::fake();}
    protected function tearDown():void{$this->server?->stop(0);parent::tearDown();}
    private function olt(string $scenario='device-labels'):Olt{
        $this->server=new Process([PHP_BINARY,base_path('tests/Fixtures/zte-telnet-server.php'),$scenario]);$this->server->start();$this->server->waitUntil(fn()=>str_contains($this->server->getOutput(),'"port":'));
        $port=json_decode(explode("\n",$this->server->getOutput())[0],true)['port'];$olt=User::where('role','isp_owner')->first()->tenant->olts()->first();
        $olt->update(['connection_mode'=>'real','ip_address'=>'127.0.0.1','telnet_port'=>$port,'telnet_user'=>'admin','telnet_pass_enc'=>'test-secret']);return $olt->fresh();
    }
    public function test_background_sync_advances_three_stages_and_saves_names_and_optics():void{
        $olt=$this->olt();$actor=User::where('tenant_id',$olt->tenant_id)->where('role','isp_owner')->first();$op=app(OltOperationService::class)->enqueue($olt,'sync',$actor);
        app()->call([new PollOlt($op->id),'handle']);$this->assertSame('queued',$op->fresh()->status);$this->assertSame('names',$op->fresh()->stage);$this->assertSame(2,$olt->onus()->current()->count());
        app()->call([new PollOlt($op->id),'handle']);$this->assertSame('queued',$op->fresh()->status);$this->assertSame('optics',$op->fresh()->stage);$this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'name'=>'Budi Rumah']);
        app()->call([new PollOlt($op->id),'handle']);$this->assertSame('completed',$op->fresh()->status);$this->assertSame(100,$op->fresh()->progress);$this->assertNull($op->fresh()->active_olt_id);
        $this->assertSame(2,OnuOpticalLog::whereIn('onu_id',$olt->onus()->current()->pluck('id'))->count());$this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'rx_power'=>-27]);
        $this->assertNotNull($olt->fresh()->names_observed_at);$this->assertNotNull($olt->fresh()->optical_observed_at);
    }
    public function test_status_poll_preserves_optics_until_optical_job_runs():void{
        $olt=$this->olt();$real=app(RealOltOperations::class);$real->sync($olt);$onu=$olt->onus()->current()->where('phase_state','working')->first();$onu->update(['rx_power'=>-22.5]);$count=OnuOpticalLog::count();
        $real->poll($olt->fresh(),'inventory');$this->assertSame(-22.5,$onu->fresh()->rx_power);$this->assertSame($count,OnuOpticalLog::count());
        $real->poll($olt->fresh(),'optics');$this->assertSame(-27.0,$onu->fresh()->rx_power);$this->assertNotNull($onu->fresh()->optical_observed_at);
    }
    public function test_failed_worker_retains_database_and_records_original_command():void{
        $olt=$this->olt('sync-rejected');$before=$olt->onus()->count();$op=app(OltOperationService::class)->enqueue($olt,'inventory',User::where('role','isp_owner')->first());
        app()->call([new PollOlt($op->id),'handle']);$op->refresh();$this->assertSame('failed',$op->status);$this->assertStringContainsString('show card',$op->error);$this->assertSame($before,$olt->onus()->count());$this->assertNull($op->active_olt_id);
    }
    public function test_optical_poll_prioritizes_ports_that_have_not_been_sampled():void{
        $olt=$this->olt();$real=app(RealOltOperations::class);$real->poll($olt,'inventory');
        $first=$olt->ports()->where('port_no',1)->first();$second=$olt->ports()->where('port_no',2)->first();$first->update(['optical_polled_at'=>now()]);
        $onu=$olt->onus()->current()->where('phase_state','!=','working')->first();$onu->update(['olt_port_id'=>$second->id,'phase_state'=>'working']);
        $real->poll($olt->fresh(),'optics');$commands=array_column(array_filter(array_map(fn($line)=>json_decode($line,true),explode("\n",$this->server->getOutput()))),'command');$a=array_search('show pon power onu-rx gpon-olt_1/1/2',$commands,true);$b=array_search('show pon power onu-rx gpon-olt_1/1/1',$commands,true);
        $this->assertNotFalse($a);$this->assertNotFalse($b);$this->assertLessThan($b,$a);$this->assertNotNull($second->fresh()->optical_polled_at);
    }
}
