<?php

namespace Tests\Feature;

use App\Models\{Olt, Onu, OltPort};
use App\Services\Olt\{OltSessionCoordinator, OltBusyException, OltConnectionException};
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OltConcurrencyTest extends TestCase
{
    private OltSessionCoordinator $sessions;

    protected function setUp(): void
    {
        parent::setUp();Cache::flush();config(['olt.control_sessions'=>2]);
        $this->sessions=app(OltSessionCoordinator::class);
    }

    private function onu(int $index=1,int $oltId=1): Onu
    {
        $olt=new Olt(['id'=>$oltId,'tenant_id'=>1]);
        $port=new OltPort(['id'=>1,'olt_id'=>$oltId,'interface_name'=>'gpon-olt_1/1/1']);
        $onu=new Onu(['id'=>$index,'olt_id'=>$oltId,'tenant_id'=>1,'onu_index'=>$index]);
        return $onu->setRelation('olt',$olt)->setRelation('port',$port);
    }

    private function busy(callable $action): void
    {
        try{$action();$this->fail('Conflicting operation was admitted.');}
        catch(OltBusyException $e){$this->assertSame(409,$e->getStatusCode());}
    }

    public function test_monitor_read_and_two_distinct_onu_controls_overlap_but_a_third_waits(): void
    {
        $a=$this->onu();$b=$this->onu(2);$c=$this->onu(3);$s=$this->sessions;
        $s->monitor($a->olt,fn()=>$s->read($a->olt,fn()=>$s->control($a,fn()=>$s->control($b,function()use($s,$c){
            $this->busy(fn()=>$s->control($c,fn()=>$this->fail('Capacity exceeded')));
            // Another OLT has independent capacity.
            $this->assertSame('other',$s->control($this->onu(1,2),fn()=>'other'));
        }))));
        $this->assertSame('free',$s->control($c,fn()=>'free'));
    }

    public function test_same_physical_onu_cannot_receive_overlapping_writes_even_with_another_record_id(): void
    {
        $a=$this->onu();$alias=clone $a;$alias->id=999;
        $this->sessions->control($a,function()use($alias){
            $this->busy(fn()=>$this->sessions->control($alias,fn()=>$this->fail('Duplicate write')));
        });
    }

    public function test_exclusive_olt_changes_cannot_overlap_any_session_in_either_direction(): void
    {
        $a=$this->onu();$s=$this->sessions;
        foreach(['monitor','read'] as $lane)$s->$lane($a->olt,fn()=>$this->busy(fn()=>$s->exclusive($a->olt,fn()=>null)));
        $s->control($a,fn()=>$this->busy(fn()=>$s->exclusive($a->olt,fn()=>null)));
        $s->exclusive($a->olt,function()use($s,$a){
            $this->busy(fn()=>$s->control($a,fn()=>null));
            $this->busy(fn()=>$s->monitor($a->olt,fn()=>null));
            try{$s->read($a->olt,fn()=>$this->fail('Read admitted'));}
            catch(OltConnectionException $e){$this->assertSame('read_busy',$e->reason);}
        });
    }

    public function test_failure_releases_slots_and_does_not_release_another_owner(): void
    {
        $a=$this->onu();$s=$this->sessions;
        try{$s->control($a,fn()=>throw new \RuntimeException('CLI failed'));}
        catch(\RuntimeException $e){$this->assertSame('CLI failed',$e->getMessage());}
        $this->assertSame('recovered',$s->exclusive($a->olt,fn()=>'recovered'));
        $s->control($a,function()use($s,$a){
            $this->busy(fn()=>$s->exclusive($a->olt,fn()=>null));
            // Failed exclusive admission must not drop the existing ONU lock.
            $this->busy(fn()=>$s->control($a,fn()=>null));
        });
    }

    public function test_file_leases_coordinate_independent_php_processes(): void
    {
        $path=storage_path('framework/testing/olt-lock-'.bin2hex(random_bytes(8)));
        \Illuminate\Support\Facades\File::ensureDirectoryExists($path);
        config(['cache.default'=>'file','cache.stores.file.path'=>$path,'cache.stores.file.lock_path'=>$path]);
        $peer=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('tests/Fixtures/hold-olt-sessions.php'),$path],base_path());
        $peer->setTimeout(30);
        try{
            $peer->start();$this->assertTrue($peer->waitUntil(fn($type,$text)=>str_contains($peer->getOutput(),'READY')));
            $a=$this->onu();$b=$this->onu(2);$c=$this->onu(3);$s=$this->sessions;
            $this->busy(fn()=>$s->control($a,fn()=>null));
            $s->control($b,function()use($s,$c,$a){
                $this->assertTrue(true); // Second control is admitted beside the peer's three sessions.
                $this->busy(fn()=>$s->control($c,fn()=>null));
                $this->busy(fn()=>$s->exclusive($a->olt,fn()=>null));
            });
            file_put_contents($path.'/release','1');$this->assertSame(0,$peer->wait());
            $this->assertSame('released',$s->exclusive($a->olt,fn()=>'released'));
        }finally{$peer->stop(0);\Illuminate\Support\Facades\File::deleteDirectory($path);}
    }
}
