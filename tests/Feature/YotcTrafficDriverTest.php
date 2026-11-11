<?php

namespace Tests\Feature;

use App\Models\{Olt, Tenant};
use App\Services\Olt\{OltConnectionException, YotcTelnetClient, YotcTrafficCollectorService, YotcTrafficParser};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB};
use Symfony\Component\Process\Process;
use Tests\TestCase;

class YotcTrafficDriverTest extends TestCase
{
    use RefreshDatabase;
    private ?Process $server = null;

    protected function tearDown(): void { $this->server?->stop(0); parent::tearDown(); }

    private function olt(string $scenario = 'normal'): Olt
    {
        $this->server = new Process([PHP_BINARY, base_path('tests/Fixtures/yotc-telnet-server.php'), $scenario]);
        $this->server->setTimeout(20); $this->server->start();
        $this->server->waitUntil(fn () => str_contains($this->server->getOutput(), '"port":'));
        $port = json_decode(explode("\n", $this->server->getOutput())[0], true)['port'];
        return Tenant::create(['name'=>'YOTC traffic fixture'])->olts()->create([
            'name'=>'YOTC fixture','chassis_type'=>'C1600','connection_mode'=>'real','ip_address'=>'127.0.0.1',
            'cli_transport'=>'http','telnet_port'=>80,'telnet_user'=>'web-fixture','telnet_pass_enc'=>'fixture-web-secret',
            'snmp_community_enc'=>'fixture-snmp-secret','traffic_monitoring_enabled'=>true,'traffic_telnet_port'=>$port,
            'traffic_telnet_user'=>'fixture-user','traffic_telnet_pass_enc'=>'fixture-traffic-secret',
            'traffic_enable_pass_enc'=>'fixture-enable-secret','traffic_pon_ports'=>['1/1'],'connection_timeout'=>1,
        ])->fresh();
    }

    private function port(Olt $olt): \App\Models\OltPort
    {
        return $olt->ports()->create(['slot_no'=>1,'port_no'=>1,'interface_name'=>'epon-olt_1/1',
            'is_present'=>true,'observation_source'=>'real'])->setRelation('olt', $olt);
    }

    private function assertReason(string $reason, callable $action): OltConnectionException
    {
        try { $action(); $this->fail('Expected '.$reason); }
        catch (OltConnectionException $e) { $this->assertSame($reason, $e->reason); return $e; }
    }

    public function test_split_negotiation_password_only_privilege_and_pager_use_read_allowlist(): void
    {
        $olt = $this->olt(); $client = app(YotcTelnetClient::class);
        try {
            $client->connect($olt);
            $rates = app(YotcTrafficParser::class)->parse($client->readPort('1/1'), '1/1');
            $this->assertSame(['rx_bps'=>15872377.0,'tx_bps'=>1476037.0,'rx_bytes'=>null,'tx_bytes'=>null], $rates);
            $this->assertReason('configuration', fn () => $client->readPort("1/1\r\nperf 1/1 disable"));
            $this->assertReason('configuration', fn () => $client->readPort('1/2'));
        } finally { $client->close(); }
        $events = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", $this->server->getOutput())));
        $commands = array_values(array_filter(array_column($events, 'command')));
        $this->assertSame(['enable','configure terminal','show perf current 1/1','end','exit'], $commands);
        $this->assertStringNotContainsString('fixture-traffic-secret', $this->server->getOutput());
        $this->assertStringContainsString('"iac":253', $this->server->getOutput());
    }

    public function test_optional_username_prompt_and_echoed_secrets_are_redacted(): void
    {
        $olt = $this->olt('username'); $client = app(YotcTelnetClient::class);
        try { $client->connect($olt); $this->assertStringContainsString('DS Rate(bps)', $client->readPort('1/1')); }
        finally { $client->close(); }
        $this->assertStringContainsString('"event":"username"', $this->server->getOutput());
        $this->server->stop(0); $olt = $this->olt('secrets');
        try {
            $client->connect($olt); $output = $client->readPort('1/1');
            foreach (['traffic','enable','web','snmp'] as $secret) $this->assertStringNotContainsString('fixture-'.$secret.'-secret', $output);
            $this->assertStringContainsString('[REDACTED]', $output);
        } finally { $client->close(); }
    }

    public function test_delayed_login_and_enable_password_redraws_and_historical_failure_notices_do_not_reject_valid_login(): void
    {
        foreach (['login-redraw','enable-redraw','login-history'] as $scenario) {
            $this->server?->stop(0); $olt = $this->olt($scenario); $client = app(YotcTelnetClient::class);
            try {
                $client->connect($olt);
                $rates = app(YotcTrafficParser::class)->parse($client->readPort('1/1'), '1/1');
                $this->assertSame(15872377.0, $rates['rx_bps']);
            } finally { $client->close(); }
            $output = $this->server->getOutput();
            $this->assertSame(1, substr_count($output, '"event":"login_password"'));
            $this->assertSame(1, substr_count($output, '"event":"enable_password"'));
            $this->assertStringNotContainsString('fixture-traffic-secret', $output);
            $this->assertStringNotContainsString('fixture-enable-secret', $output);
        }
    }

    public function test_reprompt_wait_is_bounded_by_quiet_grace_and_original_login_deadline_and_explicit_rejection_is_fatal(): void
    {
        foreach ([['auth-quiet',2,1.7],['auth-quiet',0.4,1.0],['auth-explicit',2,0.8]] as [$scenario,$timeout,$maximum]) {
            $this->server?->stop(0); $olt = $this->olt($scenario); $client = app(YotcTelnetClient::class);
            config(['olt.command_timeout'=>$timeout]);
            $start = microtime(true);
            $this->assertReason('authentication_failed', fn () => $client->connect($olt));
            $this->assertLessThan($maximum, microtime(true)-$start);
            $this->assertSame(1, substr_count($this->server->getOutput(), '"event":"login_password"'));
            $this->assertStringNotContainsString('"command":"exit"', $this->server->getOutput());
            // A failed attempt is cooled down; the second call must not open a new socket.
            $this->assertReason('authentication_failed', fn () => $client->connect($olt));
            $this->assertSame(1, substr_count($this->server->getOutput(), '"event":"login_password"'));
        }
    }

    public function test_credentials_wait_for_the_initial_and_enable_challenge_redraws_to_settle(): void
    {
        $olt = $this->olt('challenge-settle'); $client = app(YotcTelnetClient::class);
        try {
            $client->connect($olt);
            $this->assertSame(15872377.0, app(YotcTrafficParser::class)->parse($client->readPort('1/1'), '1/1')['rx_bps']);
        } finally { $client->close(); }
        $output = $this->server->getOutput();
        $this->assertStringNotContainsString('early_login_password', $output);
        $this->assertStringNotContainsString('early_enable_password', $output);
        $this->assertSame(1, substr_count($output, '"event":"login_password"'));
        $this->assertSame(1, substr_count($output, '"event":"enable_password"'));
    }

    public function test_unsettled_challenge_deadline_expires_without_sending_a_password(): void
    {
        $olt = $this->olt(); $client = app(YotcTelnetClient::class); config(['olt.command_timeout'=>0.1]);
        $start = microtime(true); $this->assertReason('timeout', fn () => $client->connect($olt));
        $this->assertLessThan(0.4, microtime(true)-$start);
        $this->assertStringNotContainsString('"event":"login_password"', $this->server->getOutput());
        $this->assertStringNotContainsString('"event":"enable_password"', $this->server->getOutput());
    }

    public function test_fragmented_debug_noise_does_not_block_challenges_prompts_pagers_or_cross_command_tails(): void
    {
        $olt = $this->olt('debug-stream'); $client = app(YotcTelnetClient::class);
        try {
            $client->connect($olt); $output = $client->readPort('1/1');
            $this->assertStringNotContainsString('[DEBUG]', $output);
            $this->assertStringNotContainsString('ponHalGet', $output);
            $this->assertStringNotContainsString('--More--', $output);
            $this->assertSame(15872377.0, app(YotcTrafficParser::class)->parse($output, '1/1')['rx_bps']);
            $this->assertStringContainsString('17%', $client->readCpuStatistics());
        } finally { $client->close(); }
        $output = $this->server->getOutput();
        $this->assertSame(2, substr_count($output, '"event":"credential_during_debug"'));
        $this->assertSame(1, substr_count($output, '"event":"login_password"'));
        $this->assertSame(1, substr_count($output, '"event":"enable_password"'));
        $this->assertStringNotContainsString('"forbidden"', $output);
    }

    public function test_debug_filter_only_hides_the_observed_timestamped_module_format(): void
    {
        $client = app(YotcTelnetClient::class); $visible = new \ReflectionMethod($client, 'protocolVisible');
        $kept = "Performance: enable\n2026/10/20 23:58:36 [pon] [WARN] important\n[pon] [DEBUG] no timestamp\n2026/10/20 23:58:36 [pon] other text\nLocation: 1/1\nDS Rate(bps): 0 US Rate(bps): 0";
        $this->assertSame($kept, $visible->invoke($client, $kept));
        $raw = "C1600-OLT_TEST>\n2026/10/20 23:58:36 [po";
        $this->assertSame('C1600-OLT_TEST>', $visible->invoke($client, $raw));
        $raw .= "n] [DEBUG] partial";
        $this->assertSame('C1600-OLT_TEST>', $visible->invoke($client, $raw));
        $raw .= " message\nshow perf current 1/1\n".$kept;
        $this->assertSame("C1600-OLT_TEST>\nshow perf current 1/1\n".$kept, $visible->invoke($client, $raw));
    }

    public function test_rejected_authentication_has_one_password_attempt_and_releases_shared_host_lock(): void
    {
        foreach (['auth','enable-auth'] as $scenario) {
            $this->server?->stop(0); $olt = $this->olt($scenario); $client = app(YotcTelnetClient::class);
            $this->assertReason('authentication_failed', fn () => $client->connect($olt));
            $this->assertSame(1, substr_count($this->server->getOutput(), '"event":"login_password"'));
            $this->assertSame($scenario === 'enable-auth' ? 1 : 0, substr_count($this->server->getOutput(), '"event":"enable_password"'));
            $lock = Cache::lock('nms:yotc-host:'.hash('sha256', inet_pton('127.0.0.1')), 5);
            $this->assertTrue($lock->get()); $lock->release();
            $this->assertStringNotContainsString('fixture-traffic-secret', $this->server->getOutput());
            $this->assertStringNotContainsString('"command":"end"', $this->server->getOutput());
            $this->assertStringNotContainsString('"command":"exit"', $this->server->getOutput());
        }
    }

    public function test_graceful_logout_has_a_bounded_wait_and_releases_the_physical_host_lock(): void
    {
        $olt = $this->olt('slow-logout'); $client = app(YotcTelnetClient::class); $client->connect($olt);
        $start = microtime(true); $client->close(); $elapsed = microtime(true)-$start;
        $this->assertLessThan(0.5, $elapsed);
        $this->assertStringContainsString('"command":"end"', $this->server->getOutput());
        $this->assertStringContainsString('"command":"exit"', $this->server->getOutput());
        $lock = Cache::lock('nms:yotc-host:'.hash('sha256', inet_pton('127.0.0.1')), 5);
        $this->assertTrue($lock->get()); $lock->release();
        $client->close(); // Repeated close is silent and sends no second logout.
        $this->assertSame(1, substr_count($this->server->getOutput(), '"command":"exit"'));
    }

    public function test_authentication_cooldown_prevents_more_socket_logins_and_changed_credentials_get_one_fresh_attempt(): void
    {
        $olt = $this->olt('auth'); $client = app(YotcTelnetClient::class);
        $this->assertReason('authentication_failed', fn () => $client->connect($olt));
        $this->assertSame(1, substr_count($this->server->getOutput(), '"event":"login_password"'));
        $this->assertReason('authentication_failed', fn () => $client->connect($olt));
        $this->assertSame(1, substr_count($this->server->getOutput(), '"event":"login_password"'));
        $this->assertStringNotContainsString('"command":"exit"', $this->server->getOutput());
        $olt->update(['traffic_telnet_pass_enc'=>'changed-fixture-traffic-secret']);
        $this->assertReason('authentication_failed', fn () => $client->connect($olt));
        $this->assertSame(2, substr_count($this->server->getOutput(), '"event":"login_password"'));
        $this->assertReason('authentication_failed', fn () => $client->connect($olt));
        $this->assertSame(2, substr_count($this->server->getOutput(), '"event":"login_password"'));
    }

    public function test_seeded_cooldown_expires_after_ten_minutes_and_denials_do_not_extend_it(): void
    {
        $olt = $this->olt(); $client = app(YotcTelnetClient::class); $client->pauseAuthentication($olt);
        $this->travel(590)->seconds();
        $this->assertReason('authentication_failed', fn () => $client->connect($olt));
        $this->assertStringNotContainsString('"event":"login_password"', $this->server->getOutput());
        $this->travel(11)->seconds();
        try { $client->connect($olt); $client->readPort('1/1'); } finally { $client->close(); }
        $this->assertSame(1, substr_count($this->server->getOutput(), '"event":"login_password"'));
    }

    public function test_non_authentication_timeout_does_not_pause_the_credential_profile(): void
    {
        $olt = $this->olt('timeout'); $client = app(YotcTelnetClient::class);
        config(['olt.command_timeout'=>0.35]);
        $client->connect($olt); $this->assertReason('timeout', fn () => $client->readPort('1/1'));
        config(['olt.command_timeout'=>2]);
        try { $client->connect($olt); $this->assertStringContainsString('17%', $client->readCpuStatistics()); }
        finally { $client->close(); }
        $this->assertSame(2, substr_count($this->server->getOutput(), '"event":"login_password"'));
    }

    public function test_private_address_policy_and_shared_web_lock_reject_before_socket_io(): void
    {
        $olt = $this->olt(); $client = app(YotcTelnetClient::class);
        foreach (['8.8.8.8','169.254.169.254','100.100.100.200','fe80::1','::ffff:127.0.0.1','fd00:ec2::254'] as $address) {
            $probe = clone $olt; $probe->ip_address = $address;
            $this->assertReason('configuration', fn () => $client->connect($probe));
        }
        $lock = Cache::lock('nms:yotc-host:'.hash('sha256', inet_pton('127.0.0.1')), 5); $lock->get();
        try { $this->assertReason('read_busy', fn () => $client->connect($olt)); }
        finally { $lock->release(); }
    }

    public function test_timeout_and_output_limits_close_session_and_release_host_lock(): void
    {
        foreach (['timeout'=>'timeout','output-limit'=>'output_limit'] as $scenario=>$reason) {
            $this->server?->stop(0); $olt = $this->olt($scenario); $client = app(YotcTelnetClient::class);
            config(['olt.command_timeout'=>0.3,'olt.max_output_bytes'=>1024]);
            $client->connect($olt); $this->assertReason($reason, fn () => $client->readPort('1/1'));
            $lock = Cache::lock('nms:yotc-host:'.hash('sha256', inet_pton('127.0.0.1')), 5);
            $this->assertTrue($lock->get()); $lock->release();
        }
    }

    public function test_parser_rejects_disabled_missing_duplicate_foreign_or_malformed_rates_and_accepts_real_zero(): void
    {
        $parser = app(YotcTrafficParser::class);
        $valid = "Performance : enable\nLocation : 1/1\nDS Rate(bps): 15,872,377 US Rate(bps): 1,476,037\n";
        foreach (["Performance : disable", str_replace('1/1','1/2',$valid), str_replace(' US Rate(bps): 1,476,037','',$valid),
            $valid."DS Rate(bps): 1 US Rate(bps): 1\n", str_replace('15,872,377','15,87,377',$valid),
            str_replace('15,872,377','-1',$valid),str_replace('15,872,377','NaN',$valid),str_replace('15,872,377','10000000001',$valid)] as $invalid) {
            $this->assertReason('traffic_unavailable', fn () => $parser->parse($invalid, '1/1'));
        }
        $this->assertSame(0.0, $parser->parse(str_replace(['15,872,377','1,476,037'], ['0','0'], $valid), '1/1')['rx_bps']);
    }

    public function test_enabled_rates_persist_port_history_only_and_recent_live_read_does_not_repoll(): void
    {
        $olt = $this->olt(); $port = $this->port($olt); $collector = app(YotcTrafficCollectorService::class);
        $row = $collector->collect($port); $collector->collect($port);
        $this->assertSame(15872377.0, $row['rx_bps']); $this->assertSame('cli', $row['source']);
        $this->assertNull($row['rx_bytes']); $this->assertNull($row['tx_bytes']);
        $this->assertDatabaseCount('port_traffic_logs', 1); $this->assertDatabaseCount('onu_traffic_logs', 0);
        $this->assertSame(15872377.0, $port->fresh()->download_bps); $this->assertNotNull($olt->fresh()->traffic_observed_at);
        $this->assertSame(1, substr_count($this->server->getOutput(), 'show perf current 1\\/1'));
        $coverage = $collector->coverage($olt); $this->assertSame(1, $coverage['interfaces']); $this->assertTrue($coverage['complete']);
    }

    public function test_disabled_or_incomplete_read_preserves_last_rates_history_and_freshness(): void
    {
        foreach (['disabled','wrong-location','missing'] as $scenario) {
            $this->server?->stop(0); $olt = $this->olt($scenario); $port = $this->port($olt);
            $before = now()->subMinutes(3)->startOfSecond();
            $port->update(['download_bps'=>42,'upload_bps'=>24,'traffic_observed_at'=>$before]);
            $olt->update(['traffic_observed_at'=>$before]);
            $this->assertReason('traffic_unavailable', fn () => app(YotcTrafficCollectorService::class)->collect($port));
            $this->assertSame(42.0, $port->fresh()->download_bps); $this->assertTrue($before->equalTo($port->fresh()->traffic_observed_at));
            $this->assertTrue($before->equalTo($olt->fresh()->traffic_observed_at));
        }
        $this->assertDatabaseCount('port_traffic_logs', 0);
    }

    public function test_configuration_change_during_read_refuses_persistence_and_zero_enabled_sample_is_valid(): void
    {
        $olt = $this->olt(); $port = $this->port($olt);
        $client = $this->mock(YotcTelnetClient::class);
        $client->shouldReceive('connect')->once(); $client->shouldReceive('close')->once();
        $client->shouldReceive('readPort')->once()->with('1/1')->andReturnUsing(function () use ($olt) {
            Olt::whereKey($olt->id)->update(['traffic_monitoring_enabled'=>false]);
            return "Performance: enable\nLocation: 1/1\nDS Rate(bps): 0 US Rate(bps): 0\n";
        });
        $this->assertReason('configuration_changed', fn () => app(YotcTrafficCollectorService::class)->collect($port));
        $this->assertDatabaseCount('port_traffic_logs', 0); $this->assertNull($port->fresh()->traffic_observed_at);
        $this->app->forgetInstance(YotcTelnetClient::class); $this->server->stop(0); $olt = $this->olt('zero'); $port = $this->port($olt);
        app(YotcTrafficCollectorService::class)->collect($port);
        $this->assertSame(0.0, $port->fresh()->download_bps); $this->assertNotNull($port->fresh()->traffic_observed_at);
    }

    public function test_background_poll_uses_only_selected_pons_in_one_session_and_reuses_recent_samples(): void
    {
        config(['olt.yotc_traffic_request_delay_ms'=>0]);
        $olt = $this->olt(); $this->port($olt);
        $olt->ports()->create(['slot_no'=>1,'port_no'=>2,'interface_name'=>'epon-olt_1/2','is_present'=>true,'observation_source'=>'real']);
        $olt->ports()->create(['slot_no'=>1,'port_no'=>3,'interface_name'=>'epon-olt_1/3','is_present'=>true,'observation_source'=>'real']);
        $olt->update(['traffic_pon_ports'=>['1/1','1/2']]);
        $collector = app(YotcTrafficCollectorService::class);
        $this->assertSame([], $collector->poll($olt)); $this->assertSame([], $collector->poll($olt));
        $this->assertDatabaseCount('port_traffic_logs', 2); $this->assertDatabaseCount('onu_traffic_logs', 0);
        $output = $this->server->getOutput();
        $this->assertSame(1, substr_count($output, '"event":"login_password"'));
        $this->assertSame(1, substr_count($output, 'show perf current 1\\/1'));
        $this->assertSame(1, substr_count($output, 'show perf current 1\\/2'));
        $this->assertStringNotContainsString('show perf current 1\\/3', $output);
        $this->assertSame(2, $collector->coverage($olt)['interfaces']);
    }

    public function test_background_disabled_sampling_warns_and_does_not_advance_global_freshness(): void
    {
        $olt = $this->olt('disabled'); $this->port($olt);
        $warnings = app(YotcTrafficCollectorService::class)->poll($olt);
        $this->assertSame(['Pengukuran traffic PON 1/1 belum aktif pada OLT.'], $warnings);
        $this->assertNull($olt->fresh()->traffic_observed_at); $this->assertDatabaseCount('port_traffic_logs', 0);
        $this->assertFalse(app(YotcTrafficCollectorService::class)->coverage($olt)['complete']);
        $this->assertStringNotContainsString('perf 1\\/1 enable', $this->server->getOutput());
    }
}
