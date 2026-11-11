<?php
// Loopback-only C1600 peer. Logs event names and commands, never credential values.
$scenario = $argv[1] ?? 'normal';
$server = stream_socket_server('tcp://127.0.0.1:0');
echo json_encode(['port'=>(int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1)])."\n"; flush();
function event(array $value): void { echo json_encode($value)."\n"; flush(); }
function discardEarlyCredential($client, string $phase): void {
    stream_set_blocking($client, false); $early = stream_get_contents($client); stream_set_blocking($client, true);
    $plain = preg_replace('/\xff[\xfb-\xfe]./s', '', $early ?: '');
    if (trim($plain) !== '') event(['event'=>'early_'.$phase.'_password']);
}
function line($client, string $prefix = ''): ?string {
    $out = $prefix;
    if (($newline = strpos($out, "\n")) !== false) return rtrim(substr($out, 0, $newline), "\r");
    while (! feof($client)) {
        $c = fread($client, 1);
        if ($c === '') return null;
        if (ord($c) === 255) {
            $verb = fread($client, 1); $option = fread($client, 1);
            event(['iac'=>ord($verb),'option'=>ord($option)]); continue;
        }
        if ($c === "\n") return rtrim($out, "\r");
        $out .= $c;
    }
    return null;
}
function debugChallenge($client): string {
    $received = '';
    for ($i=0; $i<8; $i++) {
        fwrite($client, "\r\n2026/10/20 23:58:36 [pon] [DE"); usleep(10000);
        fwrite($client, "BUG] ponHalGetOnuFwVer noise\r\n"); usleep(50000);
        stream_set_blocking($client, false); $bytes = stream_get_contents($client); stream_set_blocking($client, true);
        $received .= preg_replace('/\xff[\xfb-\xfe]./s', '', $bytes ?: '');
    }
    if (str_contains($received, "\n")) event(['event'=>'credential_during_debug']);
    return $received;
}
function prompt($client, string $value, bool $debug, string &$pending): void {
    fwrite($client, $value);
    if ($debug) { fwrite($client, "\r\n2026/10/20 23:58:36 [pon] [DE"); $pending = "BUG] ponHalGetOnuCapability continuation\r\n"; }
}
while ($client = @stream_socket_accept($server, 20)) {
    $debug = $scenario === 'debug-stream'; $pendingDebug = '';
    stream_set_timeout($client, 3);
    fwrite($client, chr(255)); usleep(2000); fwrite($client, chr(251)); usleep(2000);
    fwrite($client, chr(1)."\r\nPON-OS\r\n".($scenario === 'username' ? 'Username:' : 'Pass'));
    if ($scenario !== 'username') { usleep(2000); fwrite($client, 'word:'); }
    if ($scenario === 'challenge-settle') {
        usleep(60000); discardEarlyCredential($client, 'login'); fwrite($client, '         Password: ');
    }
    if ($scenario === 'username') { line($client); event(['event'=>'username']); fwrite($client, 'Password:'); }
    $prefix = $debug ? debugChallenge($client) : '';
    if (line($client, $prefix) === null) { fclose($client); continue; }
    event(['event'=>'login_password']);
    if ($scenario === 'auth') { fwrite($client, "\r\nPassword:"); usleep(200000); fclose($client); continue; }
    if ($scenario === 'auth-quiet') { fwrite($client, "\r\nPassword:"); usleep(1600000); fclose($client); continue; }
    if ($scenario === 'auth-explicit') { fwrite($client, "\r\nAuthentication failed\r\n"); usleep(200000); fclose($client); continue; }
    if ($scenario === 'login-redraw') { fwrite($client, '         Password: '); usleep(150000); }
    if ($scenario === 'login-history') { fwrite($client, "\r\nLast login failed: previous session\r\n"); usleep(150000); }
    prompt($client, "\r\nC1600-OLT_TEST>", $debug, $pendingDebug);
    while (($command = line($client)) !== null) {
        event(['command'=>$command]);
        if ($pendingDebug !== '') { fwrite($client, $pendingDebug); $pendingDebug = ''; }
        if ($command === 'enable') {
            fwrite($client, "enable\r\nEnable Password:");
            if ($scenario === 'challenge-settle') {
                usleep(60000); discardEarlyCredential($client, 'enable'); fwrite($client, '         Enable Password: ');
            }
            $prefix = $debug ? debugChallenge($client) : '';
            if (line($client, $prefix) === null) break;
            event(['event'=>'enable_password']);
            if ($scenario === 'enable-auth') { fwrite($client, "\r\nEnable Password:"); break; }
            if ($scenario === 'enable-redraw') { fwrite($client, '         Enable Password: '); usleep(150000); }
            prompt($client, "\r\nC1600-OLT_TEST#", $debug, $pendingDebug); continue;
        }
        if ($command === 'configure terminal') { prompt($client, $command."\r\nC1600-OLT_TEST(config)#", $debug, $pendingDebug); continue; }
        if ($command === 'end') { fwrite($client, "end\r\nC1600-OLT_TEST#"); continue; }
        if ($command === 'exit') { if ($scenario === 'slow-logout') usleep(700000); else fwrite($client, "exit\r\nGoodbye\r\n"); break; }
        if ($command === 'show cpu statistics') { fwrite($client, "show cpu statistics\r\n1 17%\r\nC1600-OLT_TEST(config)#"); continue; }
        if (! preg_match('/^show perf current ([1-9]\d?\/[1-9]\d?)$/D', $command, $m)) {
            event(['forbidden'=>$command]); break;
        }
        if ($scenario === 'timeout') { usleep(1200000); break; }
        if ($scenario === 'output-limit') { fwrite($client, str_repeat('X', 8192)); break; }
        if ($scenario === 'command-rejected') { fwrite($client, "%Error: permission denied\r\nC1600-OLT_TEST(config)#"); continue; }
        if ($scenario === 'disabled') { fwrite($client, $command."\r\nPerformance : disable\r\nC1600-OLT_TEST(config)#"); continue; }
        $port = $scenario === 'wrong-location' ? '1/2' : $m[1];
        fwrite($client, $command."\r\n\x1b[32mPerformance : enable\x1b[0m\r\nLocation : ".$port."\r\n--Mo");
        usleep(2000); fwrite($client, 're--');
        if ($debug) {
            fwrite($client, "\r\n2026/10/20 23:58:36 [pon] [DE"); usleep(10000);
            fwrite($client, "BUG] ponHalGetOnuChipset pager noise\r\n");
        }
        // Skip IAC replies if any before the pager continuation.
        while (! feof($client)) {
            $c = fread($client, 1);
            if ($c === ' ') break;
            if ($c === '') break;
            if (ord($c) === 255) { fread($client, 2); }
        }
        $rates = $scenario === 'missing' ? 'DS Rate(bps): 15,872,377' : ($scenario === 'zero' ? 'DS Rate(bps): 0 US Rate(bps): 0' : 'DS Rate(bps): 15,872,377 US Rate(bps): 1,476,037');
        $extra = $scenario === 'secrets' ? "fixture-traffic-secret fixture-enable-secret fixture-web-secret fixture-snmp-secret\r\n" : '';
        fwrite($client, "\r\nLapse Time : 30 s\r\nDS Octets: 16,531,692 US Octets: 619,703\r\n");
        if ($debug) { fwrite($client, "2026/10/20 23:58:36 [pon] [DE"); usleep(10000); fwrite($client, "BUG] Authentication failed in old diagnostic\r\n"); }
        fwrite($client, $rates."\r\n".$extra);
        prompt($client, "\x1b[32mC1600-OLT_TEST(config)#\x1b[0m", $debug, $pendingDebug);
    }
    fclose($client);
}
