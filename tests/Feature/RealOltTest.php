<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Olt, Onu, User};
use App\Services\Olt\{OltConnectionException, ZteTelnetClient, ZteTelnetDriver, ZteOutputParser};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RealOltTest extends TestCase
{
    use RefreshDatabase;
    private ?Process $server=null;

    protected function setUp(): void { parent::setUp(); $this->seed(DatabaseSeeder::class); }
    protected function tearDown(): void { $this->server?->stop(0); parent::tearDown(); }
    private function owner(): User { return User::where('email','isp_owner@bima.test')->firstOrFail(); }
    private function real(string $scenario='normal'): Olt
    {
        $this->server=new Process([PHP_BINARY,base_path('tests/Fixtures/zte-telnet-server.php'),$scenario]);
        $this->server->setTimeout(30); $this->server->start();
        $this->server->waitUntil(fn($type,$output)=>str_contains($this->server->getOutput(),'"port":'));
        $port=json_decode(explode("\n",$this->server->getOutput())[0],true)['port'];
        $olt=$this->owner()->tenant->olts()->firstOrFail();
        $olt->update(['connection_mode'=>'real','cli_transport'=>'telnet','ip_address'=>'127.0.0.1','telnet_port'=>$port,'telnet_user'=>'admin','telnet_pass_enc'=>'test-secret','snmp_community_enc'=>'test-community']);
        return $olt->fresh();
    }

    public function test_real_sync_uses_socket_parses_and_atomically_persists_inventory(): void
    {
        $olt=$this->real(); $old=$olt->onus()->first();
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk()->assertJsonPath('source','real');
        $this->assertSame(8,$olt->ports()->current()->count());
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertFalse((bool)$old->fresh()->is_present);
        $this->assertDatabaseHas('onus',['sn'=>'ZTEGC2222222','phase_state'=>'working','rx_power'=>-27,'olt_rx_power'=>null,'admin_state'=>'enable']);
        $offline=Onu::where('sn','ZTEGC3333333')->firstOrFail(); $this->assertNull($offline->rx_power);
        $this->assertSame(2,\App\Models\OnuOpticalLog::whereIn('onu_id',$olt->onus()->current()->pluck('id'))->count());
        $this->assertSame('V2.1.0',$olt->fresh()->firmware_version);
        $this->assertSame(12,$olt->fresh()->cpu);
        $this->assertDatabaseHas('audit_logs',['action'=>'olt.sync.real','olt_id'=>$olt->id]);
        $this->getJson('/dashboard/data')->assertOk();
        $this->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $commands=$this->server->getOutput();
        $this->assertStringContainsString('terminal length 0',$commands);
        $this->assertStringNotContainsString('test-secret',$commands);
    }

    public function test_model_resembling_serial_does_not_create_false_duplicate_during_sync(): void
    {
        $olt=$this->real('model-serial');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'sn'=>'ZTEGC2222222','onu_type'=>'ZXHNF660V10']);
        $this->assertDatabaseMissing('onus',['olt_id'=>$olt->id,'sn'=>'ZXHNF660V10']);
    }

    public function test_duplicate_serials_are_separate_idempotent_onus_with_independent_metadata_and_logs(): void
    {
        $olt=$this->real('duplicate-sn');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $onus=$olt->onus()->current()->where('sn','ZTEGC2222222')->orderBy('onu_index')->get();
        $this->assertCount(2,$onus);
        $this->assertSame([1,2],$onus->pluck('onu_index')->all());
        $first=$onus[0]; $second=$onus[1];
        $first->update(['name'=>'Pelanggan Satu','pppoe_user'=>'satu@isp']);
        $tag=\App\Models\Tag::where('tenant_id',$olt->tenant_id)->firstOrFail();
        $first->tags()->attach($tag);
        $this->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame($onus->pluck('id')->all(),$olt->onus()->current()->orderBy('onu_index')->pluck('id')->all());
        $this->assertSame('Pelanggan Satu',$first->fresh()->name);
        $this->assertSame('satu@isp',$first->fresh()->pppoe_user);
        $this->assertSame(1,$first->tags()->count());
        $this->assertSame(0,$second->tags()->count());
        $this->assertSame(2,$first->opticalLogs()->count());
        $this->assertSame(2,$second->opticalLogs()->count());
        $this->postJson('/onus/'.$second->id.'/reboot')->assertOk();
        $this->assertStringContainsString('gpon-onu_1\\/1\\/1:2',$this->server->getOutput());
    }

    public function test_ambiguous_baseinfo_serials_are_verified_against_running_config(): void
    {
        $olt=$this->real('config-ambiguous');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'sn'=>'ZTEGC3333333']);
        $this->assertStringContainsString('show running-config interface gpon-olt_1\\/1\\/1',$this->server->getOutput());
    }

    public function test_sync_imports_device_names_descriptions_and_refreshes_sn_placeholders_without_overwriting_local_edits(): void
    {
        $olt=$this->real('device-labels');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $first=$olt->onus()->current()->where('onu_index',1)->firstOrFail();
        $second=$olt->onus()->current()->where('onu_index',2)->firstOrFail();
        $this->assertSame('Budi Rumah',$first->name);
        $this->assertSame('Jl Mawar 10',$first->description);
        $this->assertSame('Siti Toko',$second->name);
        $this->assertSame('dying-gasp',$second->phase_state);
        $this->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame('Budi Baru',$first->fresh()->name);
        $this->assertSame('Jl Melati 20',$first->fresh()->description);
        $first->update(['name'=>$first->sn,'device_name'=>null]);
        $second->update(['name'=>'Nama Lokal','description'=>'Catatan Lokal','pppoe_user'=>'tetap@isp']);
        $this->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame('Budi Baru',$first->fresh()->name);
        $this->assertSame('Nama Lokal',$second->fresh()->name);
        $this->assertSame('Catatan Lokal',$second->fresh()->description);
        $this->assertSame('tetap@isp',$second->fresh()->pppoe_user);
        $this->assertSame(3,substr_count($this->server->getOutput(),'"command":"show running-config"'));
        $this->assertSame(2,$olt->onus()->current()->count());
    }

    public function test_names_query_timeout_does_not_discard_committed_inventory(): void
    {
        $olt=$this->real('labels-timeout'); config(['olt.command_timeout'=>0.15]);
        $response=$this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertNotNull($olt->fresh()->last_sync_at);
        $this->assertStringContainsString("Timeout pada perintah 'show running-config'",implode(' ',$response->json('warnings')));
    }

    public function test_device_description_is_used_as_label_when_no_onu_name_is_configured(): void
    {
        $olt=$this->real('device-description');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $first=$olt->onus()->current()->where('onu_index',1)->firstOrFail();
        $this->assertSame('Pelanggan Deskripsi',$first->name);
        $this->assertSame('Pelanggan Deskripsi',$first->description);
        $this->assertSame('ZTEGC3333333',$olt->onus()->current()->where('onu_index',2)->firstOrFail()->name);
    }

    public function test_real_firmware_interface_config_and_detail_info_import_names_when_global_config_omits_onus(): void
    {
        $olt=$this->real('real-interface-labels');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'onu_index'=>31,'name'=>'SIDSMP-PAS1-BDG','sn'=>'ZTEGCCA26B50','phase_state'=>'offline']);
        $this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'onu_index'=>33,'name'=>'CIFO-BANJIR-ADIPURA','sn'=>'ZTEGC8A6C5A3','phase_state'=>'los']);
        $this->assertSame(0,$olt->onus()->current()->whereNotNull('last_online_at')->count());
        $this->getJson('/dashboard/data')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertStringContainsString('show gpon onu detail-info gpon-onu_1\\/1\\/10:33',$this->server->getOutput());
    }

    public function test_duplicate_serials_on_different_ports_remain_separate_after_repeated_sync(): void
    {
        $olt=$this->real('duplicate-ports');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $duplicates=$olt->onus()->current()->where('sn','ZTEGC2222222')->get();
        $this->assertCount(2,$duplicates);
        $this->assertCount(2,$duplicates->pluck('olt_port_id')->unique());
        $ids=$duplicates->pluck('id')->sort()->values()->all();
        $this->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame($ids,$olt->onus()->current()->where('sn','ZTEGC2222222')->orderBy('id')->pluck('id')->all());
        $this->assertSame(3,$olt->onus()->current()->count());
    }

    public function test_onu_limit_counts_duplicate_serials_as_separate_entries(): void
    {
        $olt=$this->real('duplicate-sn'); config(['olt.max_onus'=>1]);
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertStatus(502)->assertJsonPath('reason','output_limit');
        $this->assertSame(16,$olt->onus()->count());
    }

    public function test_login_failure_keeps_previous_inventory_and_never_runs_simulator(): void
    {
        $olt=$this->real('auth'); $before=$olt->last_sync_at;
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertStatus(502)->assertJsonPath('reason','authentication_failed');
        $this->assertEquals($before,$olt->fresh()->last_sync_at);
        $this->assertSame(16,$olt->onus()->count());
        $this->assertSame(0,$olt->onus()->current()->count());
        $this->assertDatabaseMissing('audit_logs',['action'=>'olt.sync.demo','olt_id'=>$olt->id]);
    }

    public function test_c300_gtgh_discovers_sixteen_ports_with_the_same_real_driver(): void
    {
        $olt=$this->real('c300');$olt->update(['chassis_type'=>'C300']);
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(16,$olt->ports()->current()->count());
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertSame('GTGH',$olt->fresh()->boards['items'][0]['type']);
        $this->assertDatabaseHas('olt_ports',['olt_id'=>$olt->id,'interface_name'=>'gpon-olt_1/1/16','is_present'=>true]);
    }

    public function test_timeout_is_bounded_and_does_not_overwrite_snapshot(): void
    {
        $olt=$this->real('timeout'); config(['olt.command_timeout'=>0.15]); $start=microtime(true);
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertStatus(504)->assertJsonPath('reason','timeout');
        $this->assertLessThan(2,microtime(true)-$start);
        $this->assertSame(16,$olt->onus()->count());
    }

    public function test_malformed_output_fails_without_partial_database_updates(): void
    {
        $olt=$this->real('malformed');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertStatus(502)->assertJsonPath('reason','parse_failed');
        $this->assertSame(16,$olt->onus()->where('is_present',true)->count());
        $this->assertSame(4,$olt->ports()->count());
    }

    public function test_view_and_ports_pull_real_data_with_read_only_permissions(): void
    {
        $olt=$this->real(); $cs=User::where('role','cs')->firstOrFail();
        $this->actingAs($cs)->postJson('/olts/'.$olt->id.'/refresh',['operation'=>'view'])->assertOk();
        $this->assertSame('real',$olt->fresh()->telemetry_source);
        $this->assertSame(0,$olt->onus()->current()->count());
        $this->postJson('/olts/'.$olt->id.'/refresh',['operation'=>'ports'])->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->postJson('/olts/'.$olt->id.'/cli',['command'=>'show card'])->assertForbidden();
    }

    public function test_real_reboot_requires_acknowledgement_and_preserves_last_observed_phase(): void
    {
        $olt=$this->real(); $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $onu=$olt->onus()->current()->where('phase_state','working')->firstOrFail();
        $this->postJson('/onus/'.$onu->id.'/reboot')->assertOk()->assertJsonPath('source','real');
        $this->assertSame('working',$onu->fresh()->phase_state);
        $this->assertNull($onu->fresh()->reboot_complete_at);
        $commands=$this->server->getOutput();
        $sent=[]; foreach(explode("\n",trim($commands)) as $line) { $entry=json_decode($line,true); if(isset($entry['command']))$sent[]=$entry['command']; }
        $this->assertSame(['configure terminal','pon-onu-mng gpon-onu_1/1/1:1','reboot','yes','exit'],array_slice($sent,-5));
        $this->assertDatabaseHas('audit_logs',['action'=>'onu.reboot.real']);
    }

    public function test_real_cli_supports_requested_commands_and_rejects_multiline_control_input(): void
    {
        $olt=$this->real();
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/cli',['command'=>'show gpon onu uncfg'])->assertOk()->assertJsonPath('source','real');
        $this->postJson('/olts/'.$olt->id.'/cli',['command'=>"show card\nreboot"])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs',['action'=>'olt.cli.real']);
    }

    public function test_connection_probe_accepts_unsaved_credentials_and_existing_blanks_without_saving(): void
    {
        $olt=$this->real(); $input=['olt_id'=>$olt->id,'protocol'=>'cli','ip_address'=>$olt->ip_address,'telnet_port'=>$olt->telnet_port,'telnet_username'=>'admin','telnet_password'=>'','snmp_port'=>161,'snmp_community'=>'','cli_transport'=>'telnet','connection_timeout'=>2];
        $this->actingAs($this->owner())->postJson('/olts/test-connection',$input)->assertOk()->assertJsonPath('source','real');
        unset($input['olt_id']); $input['telnet_password']='test-secret';
        $this->postJson('/olts/test-connection',$input)->assertOk();
        $this->assertSame(22,$this->owner()->tenant->olts()->count());
        $this->actingAs(User::where('role','noc')->first())->postJson('/olts/test-connection',$input)->assertForbidden();
    }

    public function test_unknown_metrics_are_not_shown_as_old_simulator_values(): void
    {
        $olt=$this->real();
        $response=$this->actingAs($this->owner())->getJson('/dashboard/data')->assertOk();
        $first=collect($response->json('olts'))->firstWhere('id',$olt->id);
        $this->assertNull($first['cpu']); $this->assertNull($first['firmware_version']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('connectionScenarios')]
    public function test_authenticated_probe_tolerates_firmware_and_privilege_variations(string $scenario, ?string $command): void
    {
        $olt=$this->real($scenario);
        $input=['olt_id'=>$olt->id,'protocol'=>'cli','ip_address'=>$olt->ip_address,'telnet_port'=>$olt->telnet_port,'telnet_username'=>'admin','telnet_password'=>'','snmp_port'=>161,'snmp_community'=>'','cli_transport'=>'telnet','connection_timeout'=>2];
        $this->actingAs($this->owner())->postJson('/olts/test-connection',$input)->assertOk()
            ->assertJsonPath('results.cli.authenticated',true)->assertJsonPath('results.cli.probe_command',$command);
        $commands=$this->server->getOutput();
        $this->assertStringNotContainsString('test-secret',$commands);
        if(str_starts_with($scenario,'user-'))$this->assertStringContainsString('"command":"enable"',$commands);
        if($scenario==='paging')$this->assertStringContainsString('show version',$commands);
    }

    public static function connectionScenarios(): array
    {
        return [
            ['banner','show version-running'], ['paging','show version-running'],
            ['user-direct','show version-running'], ['user-password','show version-running'], ['user-denied','show version-running'],
            ['user-unsupported','show version-running'], ['fallback-card','show card'],
            ['fallback-group','show system-group'], ['fallback-legacy','show version'], ['fallback-all',null],
        ];
    }

    public function test_rejected_cli_includes_command_and_original_error_without_secrets(): void
    {
        $olt=$this->real();
        $response=$this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/cli',['command'=>'show rejected test-secret'])
            ->assertStatus(502)->assertJsonPath('reason','command_rejected');
        $message=$response->json('message');
        $this->assertStringContainsString("Perintah 'show rejected [REDACTED]' ditolak OLT: %Error: Invalid input",$message);
        $this->assertStringNotContainsString('test-secret',$message);
        $this->assertStringNotContainsString('test-community',$message);
    }

    public function test_sync_still_rejects_mandatory_commands_with_diagnostic_and_preserves_snapshot(): void
    {
        $olt=$this->real('sync-rejected'); $before=$olt->last_sync_at;
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertStatus(502)
            ->assertJsonPath('reason','command_rejected')
            ->assertJsonPath('message',"Perintah 'show card' ditolak OLT: %Error: Permission denied [REDACTED]");
        $this->assertEquals($before,$olt->fresh()->last_sync_at);
        $this->assertSame(16,$olt->onus()->count());
    }

    public function test_sync_works_when_terminal_length_is_rejected_and_version_is_paged(): void
    {
        $olt=$this->real('paging');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk()->assertJsonPath('source','real');
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertSame('V2.1.0',$olt->fresh()->firmware_version);
    }

    public function test_optional_setup_does_not_hide_a_socket_timeout(): void
    {
        $olt=$this->real('setup-timeout'); config(['olt.command_timeout'=>0.15]);
        try {
            app(ZteTelnetDriver::class)->test($olt);
            $this->fail('A timed out session must not report connection success');
        } catch (OltConnectionException $e) {
            $this->assertSame('timeout',$e->reason);
        }
    }

    public function test_view_and_sync_prefer_running_version_and_never_send_ambiguous_legacy_command(): void
    {
        $olt=$this->real('firmware-modern');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/refresh',['operation'=>'view'])->assertOk();
        $this->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame('V2.1.0',$olt->fresh()->firmware_version);
        $commands=$this->server->getOutput();
        $this->assertStringContainsString('"command":"show version-running"',$commands);
        $this->assertStringNotContainsString('"command":"show version"',$commands);
    }

    public function test_view_can_use_system_group_when_version_and_card_are_rejected_but_sync_preserves_inventory(): void
    {
        $olt=$this->real('system-only'); $before=$olt->last_sync_at;
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/refresh',['operation'=>'view'])->assertOk();
        $this->assertSame('V2.2.0P1',$olt->fresh()->firmware_version);
        $this->assertSame('41 days',$olt->fresh()->uptime);
        $this->postJson('/olts/'.$olt->id.'/sync')->assertStatus(502)->assertJsonPath('reason','command_rejected');
        $this->assertEquals($before,$olt->fresh()->last_sync_at);
        $this->assertSame(16,$olt->onus()->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyPortScenarios')]
    public function test_empty_gpon_ports_are_saved_and_polling_continues(string $scenario): void
    {
        $olt=$this->real($scenario);
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(8,$olt->ports()->current()->count());
        $this->assertSame(0,$olt->onus()->current()->count());
        $this->assertSame(0,(int)$olt->ports()->current()->sum('total_onu'));
        $this->assertStringContainsString('gpon-olt_1\\/1\\/8',$this->server->getOutput());
    }

    public static function emptyPortScenarios(): array { return [['empty-code'],['empty-noonu'],['empty-text']]; }

    public function test_missing_optical_information_keeps_registered_onus_with_null_power(): void
    {
        $olt=$this->real('optical-empty');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertSame(0,$olt->onus()->current()->whereNotNull('rx_power')->count());
        $this->assertSame(0,$olt->onus()->current()->whereNotNull('olt_rx_power')->count());
    }

    public function test_baseinfo_fallback_reads_running_config_and_normalizes_hex_serial(): void
    {
        $olt=$this->real('config-fallback');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertDatabaseHas('onus',['sn'=>'ZTEGC2222222','onu_type'=>'ZTE-F660','phase_state'=>'working']);
        $this->assertDatabaseHas('onus',['sn'=>'ZTEGC3333333','phase_state'=>'dying-gasp']);
        $this->assertStringContainsString('show running-config interface gpon-olt_1\\/1\\/1',$this->server->getOutput());
    }

    public function test_malformed_running_config_fallback_does_not_replace_inventory(): void
    {
        $olt=$this->real('config-malformed'); $before=$olt->last_sync_at;
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertStatus(502)->assertJsonPath('reason','parse_failed');
        $this->assertEquals($before,$olt->fresh()->last_sync_at);
        $this->assertSame(16,$olt->onus()->count());
    }

    public function test_parser_normalizes_offline_null_and_rejects_incomplete_rows(): void
    {
        $parser=new ZteOutputParser;
        $state=$parser->states("OnuIndex Admin State OMCC State Phase State\n1/1/1:1 enable disable OffLine\nTotal: 1",'gpon-olt_1/1/1');
        $this->assertSame('offline',$state['1/1/1:1']['phase_state']);
        $power=$parser->power("OnuIndex Rx power\n1/1/1:1 N/A",'gpon-olt_1/1/1');
        $this->assertNull($power['1/1/1:1']);
        $this->expectException(OltConnectionException::class);
        $parser->states("OnuIndex Admin State OMCC State Phase State\n1/1/1:1 invalid\nTotal: 1",'gpon-olt_1/1/1');
    }

    public function test_sequential_state_on_two_gtgh_boards_polls_only_populated_working_ports(): void
    {
        $olt=$this->real('gtgh32');
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(32,$olt->ports()->current()->count());
        $this->assertSame(3,$olt->onus()->current()->count());
        $commands=$this->server->getOutput();
        $this->assertStringNotContainsString('"command":"show gpon onu state"',$commands);
        $this->assertSame(32,substr_count($commands,'show gpon onu state gpon-olt_'));
        $this->assertSame(2,substr_count($commands,'show gpon onu baseinfo '));
        $this->assertSame(1,substr_count($commands,'show pon power onu-rx '));
        $this->assertStringNotContainsString('show pon power olt-rx',$commands);
        $this->assertDatabaseHas('onus',['sn'=>'ZTEGC4444444','phase_state'=>'los','rx_power'=>null]);
    }

    public function test_physical_state_format_and_recap_sync_under_default_zxan_prompt_keeps_database_name(): void
    {
        $olt=$this->real('physical-state'); $olt->update(['name'=>'CONTOH']);
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame('CONTOH',$olt->fresh()->name);
        $this->assertSame(8,$olt->ports()->current()->count());
        $this->assertSame(13,$olt->onus()->current()->count());
        $this->assertSame(0,$olt->onus()->current()->where('phase_state','working')->count());
        $port=$olt->ports()->where('interface_name','gpon-olt_1/1/1')->firstOrFail();
        $this->assertSame(13,$port->total_onu);
        $this->assertSame(0,$port->online_onu);
        $this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'olt_port_id'=>$port->id,'onu_index'=>7,'sn'=>'ZTEG00000007','phase_state'=>'dying-gasp']);
        $this->assertDatabaseHas('onus',['olt_id'=>$olt->id,'onu_index'=>1,'phase_state'=>'offline','admin_state'=>'disable']);
        $commands=$this->server->getOutput();
        $this->assertSame(8,substr_count($commands,'show gpon onu state gpon-olt_'));
        $this->assertStringNotContainsString('show pon power onu-rx',$commands);
        $this->assertStringContainsString('show running-config interface gpon-olt_1\\/1\\/1',$commands);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('physicalTruncationScenarios')]
    public function test_physical_recap_mismatch_keeps_snapshot_atomic_even_on_last_port(string $scenario): void
    {
        $olt=$this->real($scenario); $before=$olt->last_sync_at;
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertStatus(502)->assertJsonPath('reason','parse_failed');
        $this->assertEquals($before,$olt->fresh()->last_sync_at);
        $this->assertSame(16,$olt->onus()->count());
        $this->assertDatabaseMissing('onus',['olt_id'=>$olt->id,'sn'=>'ZTEG00000007']);
    }

    public static function physicalTruncationScenarios(): array { return [['physical-truncated'],['physical-late-truncated']]; }

    public function test_optical_timeout_keeps_all_32_ports_and_all_onus_with_command_diagnostic(): void
    {
        $olt=$this->real('optical-timeout'); config(['olt.optical_idle_timeout'=>0.15]);
        $response=$this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertStringContainsString("Timeout pada perintah 'show pon power onu-rx gpon-olt_1/1/1'",implode(' ',$response->json('warnings')));
        $this->assertSame(32,$olt->ports()->current()->count());
        $this->assertSame(3,$olt->onus()->current()->count());
        $this->assertSame(0,$olt->onus()->current()->whereNotNull('rx_power')->count());
        $this->assertNotNull($olt->fresh()->last_sync_at);
        $this->assertNull($olt->fresh()->last_connection_error);
        $this->assertDatabaseHas('audit_logs',['action'=>'olt.sync.real','olt_id'=>$olt->id]);
    }

    public function test_inventory_timeout_saves_completed_ports_and_does_not_archive_unobserved_ports(): void
    {
        $olt=$this->real('inventory-timeout'); config(['olt.command_timeout'=>0.15]);
        $untouched=$olt->onus()->whereHas('port',fn($q)=>$q->where('port_no',2))->firstOrFail();
        $untouched->update(['observation_source'=>'real','is_present'=>true]);
        $response=$this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertStringContainsString("Timeout pada perintah 'show gpon onu state gpon-olt_1/1/2'",implode(' ',$response->json('warnings')));
        $this->assertTrue((bool)$untouched->fresh()->is_present);
        $this->assertDatabaseHas('onus',['sn'=>'ZTEGC2222222','is_present'=>true,'rx_power'=>null]);
    }

    public function test_checkpoint_commits_inventory_before_optical_queries_and_budget_can_skip_optics(): void
    {
        $olt=$this->real('gtgh32');
        // Allow the per-command quiet window for 32 ports, then exhaust only the save budget.
        config(['olt.operation_timeout'=>5,'olt.save_reserve'=>0]);
        $called=false;$started=microtime(true);
        $snapshot=app(ZteTelnetDriver::class)->snapshot($olt,function(array $inventory)use(&$called,$started){
            $called=true;
            $this->assertCount(32,$inventory['ports']);
            $this->assertStringNotContainsString('show pon power onu-rx',$this->server->getOutput());
            // Simulate persistence using the remaining polling budget.
            usleep((int)(max(0,5.1-(microtime(true)-$started))*1000000));
        });
        $this->assertTrue($called);
        $this->assertCount(32,$snapshot['ports']);
        $this->assertStringContainsString('Batas waktu Sync',implode(' ',$snapshot['warnings']));
        $this->assertStringNotContainsString('show pon power onu-rx',$this->server->getOutput());
    }

    public function test_idle_timeout_resets_for_streaming_bytes_and_reports_command_on_silence(): void
    {
        $olt=$this->real(); config(['olt.command_timeout'=>0.15]);
        $output=app(ZteTelnetDriver::class)->cli($olt,'show streaming');
        $this->assertSame('ZTE stream AC is alive',$output);
    }

    public function test_view_temperature_fallback_is_silent_when_not_supported(): void
    {
        $olt=$this->real('temp-none');
        $snapshot=app(ZteTelnetDriver::class)->view($olt);
        $this->assertNull($snapshot['system']['temperature']);
        $this->assertSame([],$snapshot['warnings']);
    }

    public function test_view_temperature_fallback_uses_show_temp_table(): void
    {
        $olt=$this->real('temp-fallback');
        $snapshot=app(ZteTelnetDriver::class)->view($olt);
        $this->assertSame(47.0,$snapshot['system']['temperature']);
        $this->assertSame([],$snapshot['warnings']);
        $this->assertStringNotContainsString('"command":"show environment"',$this->server->getOutput());
    }

    public function test_same_serial_on_another_olt_does_not_block_sync_or_move_existing_customer(): void
    {
        $olt=$this->real(); $other=$this->owner()->tenant->olts()->where('id','!=',$olt->id)->firstOrFail();
        $customer=$other->onus()->firstOrFail(); $customer->update(['sn'=>'ZTEGC2222222','name'=>'Pelanggan OLT Lain']);
        $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $this->assertSame(2,$olt->onus()->current()->count());
        $this->assertSame($other->id,$customer->fresh()->olt_id);
        $this->assertSame('Pelanggan OLT Lain',$customer->fresh()->name);
        $this->assertSame(2,Onu::where('tenant_id',$olt->tenant_id)->where('sn','ZTEGC2222222')->count());
        $this->assertDatabaseHas('audit_logs',['action'=>'olt.sync.real','olt_id'=>$olt->id]);
    }

    public function test_silent_reboot_is_uncertain_and_is_never_retried_or_marked_complete(): void
    {
        $olt=$this->real('uncertain');$this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertOk();
        $onu=$olt->onus()->current()->where('phase_state','working')->firstOrFail();
        $this->postJson('/onus/'.$onu->id.'/reboot')->assertStatus(502)->assertJsonPath('reason','reboot_uncertain');
        $this->assertSame('working',$onu->fresh()->phase_state);
        $this->assertDatabaseHas('audit_logs',['action'=>'onu.reboot.reboot_uncertain']);
        $this->assertSame(1,substr_count($this->server->getOutput(),'"command":"reboot"'));
    }

    public function test_delayed_state_tail_is_drained_before_the_next_command(): void
    {
        $olt=$this->real('drain-tail');$client=app(ZteTelnetClient::class);
        try {
            $client->connect($olt);
            $state=$client->command('show gpon onu state gpon-olt_1/1/1');
            $this->assertCount(2,app(ZteOutputParser::class)->states($state,'gpon-olt_1/1/1'));
            $version=$client->command('show version-running');
            $this->assertStringContainsString('V2.1.0',$version);
            $this->assertStringNotContainsString('SyncMib',$version);
        } finally { $client->close(); }
    }

    public function test_lock_prevents_edit_delete_and_real_operations_and_probe_is_tenant_scoped(): void
    {
        $olt=$this->real();$lock=\Illuminate\Support\Facades\Cache::lock('nms:olt:'.$olt->tenant_id.':'.$olt->id,10);$lock->get();
        try {
            $this->actingAs($this->owner())->postJson('/olts/'.$olt->id.'/sync')->assertConflict();
            $this->deleteJson('/olts/'.$olt->id)->assertConflict();
        } finally { $lock->release(); }
        $other=User::where('email','owner@nusantara.test')->first();
        $this->actingAs($other)->postJson('/olts/test-connection',['olt_id'=>$olt->id,'protocol'=>'cli'])->assertNotFound();
    }
}
