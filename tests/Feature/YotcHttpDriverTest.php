<?php

namespace Tests\Feature;

use App\Models\{Olt, Onu, Tenant};
use App\Services\Olt\{OltConnectionException, YotcHttpClient, YotcHttpDriver, YotcOutputParser};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Factory;
use Tests\TestCase;

class YotcHttpDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['olt.yotc_request_delay_ms'=>0, 'olt.yotc_optical_batch'=>2]);
    }

    private function olt(string $transport = 'https', string $ip = '127.0.0.1'): Olt
    {
        $tenant = Tenant::create(['name'=>'YOTC tests']);
        return $tenant->olts()->create(['name'=>'C1600 tests', 'chassis_type'=>'C1600', 'connection_mode'=>'real',
            'cli_transport'=>$transport, 'ip_address'=>$ip, 'telnet_port'=>$transport === 'https' ? 443 : 80,
            'telnet_user'=>'monitor', 'telnet_pass_enc'=>'test-web-secret', 'connection_timeout'=>2]);
    }

    private function fixtures(): array
    {
        return [
            '/action/getSystemInformation'=>['productType'=>'C1600','runningTime'=>319658100,'softwareVersion'=>'109_V300R003B021C24','hardwareVersion'=>'A'],
            '/action/getCpuMemoryTemp'=>['cpuUsage'=>17.4,'memoryUsage'=>59.1796875,'temperature'=>8000],
            '/action/getSlotInfo'=>['data'=>[
                ['slotId'=>1,'installType'=>'epu8a','expectType'=>'epu8a','installStatus'=>'Install','runStatus'=>'Normal'],
                ['slotId'=>3,'installType'=>'scu','expectType'=>'scu','installStatus'=>'Install','runStatus'=>'Normal'],
            ]],
            '/action/getPonPortInfoAll'=>['data'=>array_map(fn ($port) => ['portStr'=>'1/'.$port,'portType'=>'1000BASE-PX20-D','portAdmin'=>'enable','linkStatus'=>'Link Up'], range(1,8))],
            '/action/getAuthOnuAll'=>['data'=>[
                ['onuid'=>'01/01/02','status'=>'online','mac'=>'00-11-22-33-44-55','onutype'=>'EPON','usr_label'=>'Rumah Satu','loid_pwd'=>'subscriber-private-secret'],
                ['onuid'=>'01/02/01','status'=>'poweroff','mac'=>'0011.2233.4466','onutype'=>'EPON','usr_label'=>''],
            ]],
            '/action/getOnuOpticalParam'=>['data'=>[['Pon received power','-21.31','Onu received power','-21.67','Onu transmitted power','2.27'], ['Supply voltage','3.33','Bias current','14484.00','Temperature','56.27']]],
        ];
    }

    private function fake(array $replace = []): void
    {
        $fixtures = array_replace($this->fixtures(), $replace);
        $this->installFake(function ($request) use ($fixtures) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/action/login') return Http::response('', 200, ['Set-Cookie'=>'YOTCSESSION=test-cookie-secret; Path=/; HttpOnly']);
            if ($path === '/action/logout') return Http::response('', 302, ['Location'=>'/pub/login.html']);
            $this->assertSame('GET', $request->method());
            $this->assertArrayHasKey($path, $fixtures);
            return Http::response($fixtures[$path]);
        });
    }

    private function installFake(mixed $callback): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function trackingStream(string $body, bool $failReads = false): \Psr\Http\Message\StreamInterface
    {
        return new class(\GuzzleHttp\Psr7\Utils::streamFor($body), $failReads) implements \Psr\Http\Message\StreamInterface {
            use \GuzzleHttp\Psr7\StreamDecoratorTrait;
            private \Psr\Http\Message\StreamInterface $stream;
            public int $closeCalls = 0;
            public function __construct(\Psr\Http\Message\StreamInterface $stream, private bool $failReads)
            {
                $this->stream = $stream;
            }
            public function read($length): string
            {
                if ($this->failReads) throw new \RuntimeException('Fixture stream read failed');
                return $this->stream->read($length);
            }
            public function close(): void
            {
                $this->closeCalls++;
                $this->stream->close();
            }
        };
    }

    public function test_full_snapshot_reads_verified_paths_normalizes_values_and_drops_subscriber_secrets(): void
    {
        $this->fake(); $olt = $this->olt(); $checkpoint = null;
        $snapshot = app(YotcHttpDriver::class)->snapshot($olt, function ($data) use (&$checkpoint) { $checkpoint = $data; });
        $this->assertTrue($snapshot['complete_inventory']);
        $this->assertCount(8, $snapshot['ports']);
        $this->assertSame('epon-olt_1/1', $snapshot['ports'][0]['interface_name']);
        $this->assertSame('00:11:22:33:44:55', $snapshot['ports'][0]['onus'][0]['sn']);
        $this->assertSame('working', $snapshot['ports'][0]['onus'][0]['phase_state']);
        $this->assertSame('poweroff', $snapshot['ports'][1]['onus'][0]['phase_state']);
        $this->assertSame(-21.67, $snapshot['ports'][0]['onus'][0]['rx_power']);
        $this->assertSame(-21.31, $snapshot['ports'][0]['onus'][0]['olt_rx_power']);
        $this->assertTrue($snapshot['ports'][0]['onus'][0]['optical_attempted']);
        $this->assertArrayNotHasKey('optical_attempted', $checkpoint['ports'][0]['onus'][0]);
        $this->assertSame(17, $snapshot['system']['cpu']); $this->assertSame(80.0, $snapshot['system']['temperature']);
        $this->assertStringStartsWith('36 hari', $snapshot['system']['uptime']);
        $this->assertSame('109_V300R003B021C24', $snapshot['system']['firmware_version']);
        $this->assertStringNotContainsString('subscriber-private-secret', json_encode($snapshot));
        $this->assertStringNotContainsString('test-web-secret', json_encode($snapshot));
        $this->assertStringNotContainsString('test-cookie-secret', json_encode($snapshot));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/action/logout'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/action/login') && $request['username'] === 'monitor' && $request->hasHeader('ClientCallMode', 'ajax'));
        Http::assertSentCount(8);
    }

    public function test_malformed_inventory_never_reaches_persistence_checkpoint(): void
    {
        $this->fake(['/action/getAuthOnuAll'=>['data'=>[['onuid'=>'01/09/01','status'=>'online','mac'=>'001122334455']]]]);
        $called = false;
        try {
            app(YotcHttpDriver::class)->snapshot($this->olt(), function () use (&$called) { $called = true; });
            $this->fail('Unknown ONU ports must fail');
        } catch (OltConnectionException $error) {
            $this->assertSame('parse_failed', $error->reason);
        }
        $this->assertFalse($called);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/action/logout'));
    }

    public function test_empty_registered_onu_list_is_valid_but_missing_data_is_not(): void
    {
        $this->fake(['/action/getAuthOnuAll'=>['data'=>[]]]);
        $snapshot = app(YotcHttpDriver::class)->snapshot($this->olt(), null, 'inventory');
        $this->assertCount(8, $snapshot['ports']); $this->assertSame([], $snapshot['ports'][0]['onus']);
        $this->fake(['/action/getAuthOnuAll'=>['error'=>'session-expired']]);
        $this->expectException(OltConnectionException::class);
        app(YotcHttpDriver::class)->snapshot($this->olt(), null, 'inventory');
    }

    public function test_optics_batch_prioritizes_oldest_onus_and_keeps_complete_port_samples(): void
    {
        $this->fake(); $olt = $this->olt();
        $port = $olt->ports()->create(['slot_no'=>1,'port_no'=>1,'interface_name'=>'epon-olt_1/1','observation_source'=>'real','is_present'=>true,'total_onu'=>3,'online_onu'=>3]);
        for ($i=1; $i<=3; $i++) $olt->onus()->create(['tenant_id'=>$olt->tenant_id,'olt_port_id'=>$port->id,'onu_index'=>$i,'name'=>'ONU '.$i,'sn'=>'00:11:22:33:44:0'.$i,
            'phase_state'=>'working','observation_source'=>'real','is_present'=>true,'rx_power'=>-10 - $i,'olt_rx_power'=>-15,
            'optical_observed_at'=>$i === 3 ? null : now()->subMinutes(4-$i)]);
        $snapshot = app(YotcHttpDriver::class)->snapshot($olt, null, 'optics');
        $samples = $snapshot['ports'][0]['onus'];
        $this->assertCount(3, $samples);
        $this->assertTrue($samples[0]['optical_attempted']);
        $this->assertArrayNotHasKey('optical_attempted', $samples[1]);
        $this->assertSame(-12.0, $samples[1]['rx_power']);
        $this->assertTrue($samples[2]['optical_attempted']);
        $targets = Http::recorded(fn ($request) => str_contains($request->url(), '/action/getOnuOpticalParam'))->map(function ($pair) { parse_str(parse_url($pair[0]->url(), PHP_URL_QUERY), $query); return (int) $query['onuId']; })->all();
        $this->assertSame([3,1], array_values($targets));
        $this->assertSame(3, $port->fresh()->total_onu);
    }

    public function test_read_client_blocks_setters_and_unvalidated_optical_addresses_before_request(): void
    {
        $this->fake(); $client = app(YotcHttpClient::class); $client->connect($this->olt());
        try {
            foreach ([['/action/setOnuAdmin',[]], ['/action/getOnuOpticalParam',['slotId'=>1,'ponId'=>1,'onuId'=>'1&reset=1']], ['/action/getSystemInformation',['url'=>'http://other']]] as [$path,$parameters]) {
                try { $client->read($path, $parameters); $this->fail('Request should be rejected'); }
                catch (OltConnectionException $error) { $this->assertContains($error->reason, ['unsupported_operation','configuration']); }
            }
            Http::assertSentCount(1);
        } finally { $client->close(); }
    }

    public function test_login_redirect_or_body_cannot_report_authenticated_session(): void
    {
        foreach ([Http::response('',302,['Location'=>'https://other.example/','Set-Cookie'=>'session=secret; Path=/']), Http::response('Password test-web-secret invalid',200,['Set-Cookie'=>'session=secret; Path=/'])] as $response) {
            $this->installFake(['*'=>$response]);
            try { app(YotcHttpDriver::class)->test($this->olt()); $this->fail('Invalid login was accepted'); }
            catch (OltConnectionException $error) {
                $this->assertSame('authentication_failed', $error->reason);
                $this->assertStringNotContainsString('test-web-secret', $error->getMessage());
            }
            Http::assertSentCount(1);
        }
    }

    public function test_redirect_is_never_followed_and_connection_exception_is_sanitized(): void
    {
        $this->fake(['/action/getSystemInformation'=>[]]);
        $this->installFake(function ($request) {
            if (str_ends_with($request->url(), '/action/login')) return Http::response('',200,['Set-Cookie'=>'session=secret; Path=/']);
            if (str_ends_with($request->url(), '/action/logout')) return Http::response('',302);
            return Http::response('',302,['Location'=>'https://external.example/secret']);
        });
        try { app(YotcHttpDriver::class)->test($this->olt()); $this->fail('Redirect accepted'); }
        catch (OltConnectionException $error) { $this->assertSame('redirect_refused', $error->reason); }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'external.example'));
        $this->installFake(fn () => throw new \RuntimeException('credentials test-web-secret cookie test-cookie-secret'));
        try { app(YotcHttpDriver::class)->test($this->olt()); $this->fail('Connection should fail'); }
        catch (OltConnectionException $error) {
            $this->assertSame('http_connection', $error->reason);
            $this->assertStringNotContainsString('test-web-secret', $error->getMessage());
            $this->assertStringNotContainsString('test-cookie-secret', $error->getMessage());
        }
    }

    public function test_http_public_or_link_local_targets_are_refused_without_network(): void
    {
        $this->fake();
        foreach ([['http','8.8.8.8'],['http','169.254.169.254'],['https','169.254.169.254'],['http','fe80::1'],['http','::ffff:127.0.0.1'],['https','fd00:ec2::254']] as [$transport,$address]) {
            try { app(YotcHttpDriver::class)->test($this->olt($transport, $address)); $this->fail('Unsafe target accepted'); }
            catch (OltConnectionException $error) { $this->assertSame('configuration', $error->reason); }
        }
        Http::assertNothingSent();
    }

    public function test_https_requests_verify_certificates_and_disable_redirects_and_proxies(): void
    {
        $fixtures = $this->fixtures(); $seen = [];
        $this->installFake(function ($request, $options) use ($fixtures, &$seen) {
            $seen[] = $options;
            if (str_ends_with($request->url(), '/action/login')) return Http::response('',200,['Set-Cookie'=>'session=secret; Path=/']);
            if (str_ends_with($request->url(), '/action/logout')) return Http::response('',302);
            return Http::response($fixtures['/action/getSystemInformation']);
        });
        app(YotcHttpDriver::class)->test($this->olt());
        foreach ($seen as $options) { $this->assertTrue($options['verify']); $this->assertFalse($options['allow_redirects']); $this->assertSame('', $options['proxy']); }
    }

    public function test_output_limits_and_missing_session_are_enforced(): void
    {
        $this->installFake(['*'=>Http::response('',200)]);
        try { app(YotcHttpDriver::class)->test($this->olt()); $this->fail('Missing session accepted'); }
        catch (OltConnectionException $error) { $this->assertSame('authentication_failed', $error->reason); }
        config(['olt.max_output_bytes'=>1024]);
        $this->fake(['/action/getSystemInformation'=>['productType'=>'C1600','padding'=>str_repeat('x',1100)]]);
        try { app(YotcHttpDriver::class)->test($this->olt()); $this->fail('Large response accepted'); }
        catch (OltConnectionException $error) { $this->assertSame('output_limit', $error->reason); }
    }

    public function test_incomplete_board_or_duplicate_onu_cannot_replace_inventory(): void
    {
        $fixtures = $this->fixtures();
        foreach ([
            ['/action/getPonPortInfoAll'=>['data'=>array_slice($fixtures['/action/getPonPortInfoAll']['data'],0,7)]],
            ['/action/getAuthOnuAll'=>['data'=>[$fixtures['/action/getAuthOnuAll']['data'][0],$fixtures['/action/getAuthOnuAll']['data'][0]]]],
            ['/action/getSlotInfo'=>['data'=>[['slotId'=>1,'installType'=>'unverified-board','expectType'=>'unknown','runStatus'=>'Normal']]]],
        ] as $replace) {
            $this->fake($replace); $called = false;
            try { app(YotcHttpDriver::class)->snapshot($this->olt(), function () use (&$called) { $called = true; }, 'inventory'); $this->fail('Incomplete inventory accepted'); }
            catch (OltConnectionException $error) { $this->assertSame('parse_failed', $error->reason); }
            $this->assertFalse($called);
        }
    }

    public function test_optical_unavailable_values_are_explicit_null_and_new_status_is_preserved(): void
    {
        $parser = app(YotcOutputParser::class);
        $this->assertSame(['rx_power'=>null,'olt_rx_power'=>null], $parser->optical(['data'=>[['Pon received power','N/A','Onu received power','--']]]));
        $fixtures = $this->fixtures();
        $registered = $fixtures['/action/getAuthOnuAll']; $registered['data'][0]['status'] = 'deregistered';
        $snapshot = $parser->inventory($fixtures['/action/getSlotInfo'], $fixtures['/action/getPonPortInfoAll'], $registered);
        $this->assertSame('deregistered', $snapshot['ports'][0]['onus'][0]['phase_state']);
        $this->assertNotEmpty($snapshot['warnings']);
        $this->expectException(OltConnectionException::class);
        $parser->optical(['data'=>[['Pon received power','not-a-number','Onu received power','-20']]]);
    }

    public function test_streamed_response_handles_short_reads_until_eof(): void
    {
        $fixtures = $this->fixtures();
        $stream = new class(\GuzzleHttp\Psr7\Utils::streamFor(json_encode($fixtures['/action/getSystemInformation']))) implements \Psr\Http\Message\StreamInterface {
            use \GuzzleHttp\Psr7\StreamDecoratorTrait;
            private \Psr\Http\Message\StreamInterface $stream;
            public function read($length): string { return $this->stream->read(min(7, $length)); }
        };
        $this->installFake(function ($request) use ($stream) {
            if (str_ends_with($request->url(), '/action/login')) return Http::response('',200,['Set-Cookie'=>'session=secret; Path=/']);
            if (str_ends_with($request->url(), '/action/logout')) return Http::response('',302);
            return Http::response($stream,200,['Content-Type'=>'application/json']);
        });
        $this->assertSame('109_V300R003B021C24', app(YotcHttpDriver::class)->test($this->olt())['version']);
    }

    public function test_login_successful_get_and_redirecting_logout_each_release_their_response_stream(): void
    {
        $login=$this->trackingStream('');
        $read=$this->trackingStream(json_encode($this->fixtures()['/action/getSystemInformation']));
        $logout=$this->trackingStream('Redirecting to login');
        $this->installFake(function ($request) use ($login, $read, $logout) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/action/login'=>Http::response($login, 200, ['Set-Cookie'=>'session=fixture; Path=/']),
                '/action/getSystemInformation'=>Http::response($read, 200),
                '/action/logout'=>Http::response($logout, 302, ['Location'=>'/pub/login.html']),
                default=>throw new \RuntimeException('Unexpected fixture endpoint'),
            };
        });
        $client=app(YotcHttpClient::class);
        $client->connect($this->olt());
        $this->assertSame(1, $login->closeCalls);
        $this->assertSame('C1600', $client->read('/action/getSystemInformation')['productType']);
        $this->assertSame(1, $read->closeCalls);
        $client->close();
        $this->assertSame(1, $logout->closeCalls);
        foreach ([$login,$read,$logout] as $stream) $this->assertFalse($stream->isReadable());
        Http::assertSentCount(3);
    }

    public function test_response_streams_are_closed_on_http_parse_limit_and_read_failures(): void
    {
        config(['olt.max_output_bytes'=>1024]);
        foreach ([
            [500, [], 'device error', false, 'http_error'],
            [401, [], 'session expired', false, 'authentication_failed'],
            [302, ['Location'=>'/pub/login.html'], '', false, 'redirect_refused'],
            [200, [], 'invalid JSON', false, 'parse_failed'],
            [200, [], '[]', false, 'parse_failed'],
            [200, [], str_repeat('x',1100), false, 'output_limit'],
            [200, ['Content-Length'=>'2048'], '', false, 'output_limit'],
            [200, [], '{"productType":"C1600"}', true, 'http_connection'],
        ] as [$status,$headers,$body,$failReads,$reason]) {
            $login=$this->trackingStream(''); $read=$this->trackingStream($body, $failReads); $logout=$this->trackingStream('');
            $this->installFake(function ($request) use ($login,$read,$logout,$status,$headers) {
                if (str_ends_with($request->url(), '/action/login')) return Http::response($login,200,['Set-Cookie'=>'session=fixture; Path=/']);
                if (str_ends_with($request->url(), '/action/logout')) return Http::response($logout,302);
                return Http::response($read,$status,$headers);
            });
            $client=app(YotcHttpClient::class); $client->connect($this->olt());
            try { $client->read('/action/getSystemInformation'); $this->fail('Invalid response accepted: '.$reason); }
            catch (OltConnectionException $error) { $this->assertSame($reason, $error->reason); }
            finally { $client->close(); }
            foreach ([$login,$read,$logout] as $stream) {
                $this->assertSame(1, $stream->closeCalls);
                $this->assertFalse($stream->isReadable());
            }
        }
    }

    public function test_rejected_login_and_header_callback_release_response_streams_before_connect_returns(): void
    {
        foreach ([
            [200, ['Set-Cookie'=>'session=fixture; Path=/'], 'Invalid credentials', false, 'authentication_failed'],
            [302, ['Set-Cookie'=>'session=fixture; Path=/'], '', false, 'authentication_failed'],
            [200, ['Content-Length'=>'2048'], '', true, 'output_limit'],
        ] as [$status,$headers,$body,$rejectHeaders,$reason]) {
            config(['olt.max_output_bytes'=>1024]);
            $stream=$this->trackingStream($body);
            $this->installFake(function ($request, $options) use ($stream,$status,$headers,$rejectHeaders) {
                if ($rejectHeaders) $options['on_headers'](new \GuzzleHttp\Psr7\Response($status,$headers,$stream));
                return Http::response($stream,$status,$headers);
            });
            try { app(YotcHttpClient::class)->connect($this->olt()); $this->fail('Invalid login accepted'); }
            catch (OltConnectionException $error) { $this->assertSame($reason, $error->reason); }
            $this->assertSame(1, $stream->closeCalls);
            $this->assertFalse($stream->isReadable());
            Http::assertSentCount($rejectHeaders ? 0 : 1);
        }
    }

    public function test_one_web_session_per_physical_ip_including_unsaved_probes_and_other_transport(): void
    {
        $this->fake(); $olt = $this->olt();
        $first = app(YotcHttpClient::class); $second = app(YotcHttpClient::class);
        $probe = new Olt(['ip_address'=>$olt->ip_address,'cli_transport'=>'http','telnet_port'=>80,'telnet_user'=>'probe','telnet_pass_enc'=>'probe-test-secret']);
        $first->connect($olt);
        try {
            try { $second->connect($probe); $this->fail('Parallel physical-host session accepted'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
            Http::assertSentCount(1);
        } finally { $first->close(); }
        $second->connect($probe); $second->close();
        Http::assertSentCount(4);
        $this->installFake(['*'=>Http::response('wrong credentials',200)]);
        try { $first->connect($olt); $this->fail('Invalid login accepted'); }
        catch (OltConnectionException $error) { $this->assertSame('authentication_failed', $error->reason); }
        $this->fake(); $second->connect($probe); $second->close();
        Http::assertSentCount(2);
    }
}
