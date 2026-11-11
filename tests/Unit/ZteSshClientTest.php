<?php

namespace Tests\Unit;

use App\Models\Olt;
use App\Services\Olt\{OltConnectionException, ZteSshClient};
use Mockery;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class ZteSshClientTest extends TestCase
{
    private function connection(array $reads, array $writes): ZteSshClient
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('getServerPublicHostKey')->once()->andReturn('ssh-rsa '.base64_encode('fixture-key'));
        $ssh->shouldReceive('login')->once()->with('admin', 'test-secret')->andReturn(true);
        $ssh->shouldReceive('setTimeout')->andReturnNull();
        $ssh->shouldReceive('isTimeout')->andReturn(false);
        $ssh->shouldReceive('disconnect')->andReturnNull();
        $ssh->shouldReceive('read')->times(count($reads))->andReturn(...$reads);
        foreach ($writes as $input) $ssh->shouldReceive('write')->once()->with($input)->andReturn(true);
        $client = new class($ssh) extends ZteSshClient {
            public function __construct(private SSH2 $fixture) {}
            protected function createSsh(Olt $olt): SSH2 { return $this->fixture; }
        };
        $olt = new Olt(['ip_address'=>'127.0.0.1','telnet_port'=>22,'telnet_user'=>'admin','connection_timeout'=>2,
            'ssh_host_key_sha256'=>'SHA256:'.rtrim(base64_encode(hash('sha256','fixture-key',true)),'=')]);
        $olt->telnet_pass_enc='test-secret';
        $client->connect($olt);
        return $client;
    }

    public function test_ssh_user_mode_enable_password_and_rejected_paging_setup_still_reads_all_pages(): void
    {
        $client = $this->connection([
            "Welcome\nZXAN>", "\nZXAN>", "enable\nPassword:", "\nZXAN#",
            "terminal length 0\nCommand not found\nZXAN#",
            "show version\nZTE C320\n--More--", "Software Version: V2.1.0\nZXAN#",
        ], ["\n", "enable\n", "test-secret\n", "terminal length 0\n", "show version\n", ' ']);
        try {
            $output=$client->command('show version');
            $this->assertStringContainsString('Software Version: V2.1.0',$output);
            $this->assertStringNotContainsString('--More--',$output);
        } finally { $client->close(); }
    }

    public function test_ssh_rejection_reports_command_and_redacts_password(): void
    {
        $client = $this->connection([
            "ZXAN#", "\nZXAN#", "terminal length 0\nZXAN#",
            "show bad test-secret\n%Error: Invalid input test-secret\nZXAN#",
        ], ["\n", "terminal length 0\n", "show bad test-secret\n"]);
        try {
            $client->command('show bad test-secret');
            $this->fail('Expected command rejection');
        } catch (OltConnectionException $e) {
            $this->assertSame('command_rejected',$e->reason);
            $this->assertSame("Perintah 'show bad [REDACTED]' ditolak OLT: %Error: Invalid input [REDACTED]",$e->getMessage());
        } finally { $client->close(); }
    }
}
