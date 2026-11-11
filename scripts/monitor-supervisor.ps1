param([switch]$Once)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtimeDirectory = Join-Path $projectRoot '.tools/runtime'
New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
$hash = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($projectRoot.ToLowerInvariant()))).Replace('-','').Substring(0,16)
$mutex = [Threading.Mutex]::new($false, "Local\NMS-Monitor-$hash")
$ownsMutex = $false
$children = @{}
$env:MIBS = ';'
try {
    try { $ownsMutex = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $ownsMutex = $true }
    if (-not $ownsMutex) { exit 0 }
    $phpBinary = Join-Path $projectRoot '.tools/php/php.exe'
    if (-not (Test-Path -LiteralPath $phpBinary)) { $phpBinary = (Get-Command php).Source }
    $stopFile = Join-Path $runtimeDirectory 'monitor-stop'
    if (Test-Path -LiteralPath $stopFile) { Remove-Item -LiteralPath $stopFile }
    $definitions = @{
        monitor = @('artisan','nms:monitor')
        traps = @('artisan','nms:trap-listen')
        worker1 = @('artisan','queue:work','olt','--queue=olt','--sleep=1','--tries=1','--timeout=330')
        worker2 = @('artisan','queue:work','olt','--queue=olt','--sleep=1','--tries=1','--timeout=330')
    }
    $stateFile = Join-Path $runtimeDirectory 'monitor-supervisor.json'
    # Reattach surviving children after a supervisor crash; never trust a PID alone.
    if (Test-Path -LiteralPath $stateFile) {
        try {
            $previous = Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json
            foreach ($name in $definitions.Keys) {
                $child = Get-Process -Id $previous.children.$name -ErrorAction SilentlyContinue
                if ($child -and $child.Path -eq $phpBinary -and $child.StartTime.ToUniversalTime().Ticks.ToString() -eq $previous.started.$name) { $children[$name]=$child }
            }
        } catch { Write-Warning 'Tidak dapat memulihkan state supervisor sebelumnya.' }
    }
    do {
        foreach ($name in $definitions.Keys) {
            if (-not $children[$name] -or $children[$name].HasExited) {
                $stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
                $children[$name] = Start-Process -FilePath $phpBinary -ArgumentList $definitions[$name] -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDirectory "$name-$stamp.log") -RedirectStandardError (Join-Path $runtimeDirectory "$name-$stamp.err.log")
            }
        }
        $state = @{ supervisor_pid=$PID; checked_at=(Get-Date).ToString('o'); children=@{}; started=@{} }
        foreach ($name in $children.Keys) { $state.children[$name]=$children[$name].Id; $state.started[$name]=$children[$name].StartTime.ToUniversalTime().Ticks.ToString() }
        [IO.File]::WriteAllText(($stateFile+'.tmp'),($state | ConvertTo-Json),[Text.UTF8Encoding]::new($false))
        Move-Item -LiteralPath ($stateFile+'.tmp') -Destination $stateFile -Force
        if ($Once) { break }
        Start-Sleep -Seconds 5
    } while (-not (Test-Path -LiteralPath $stopFile))
} finally {
    foreach ($child in $children.Values) { if (-not $child.HasExited) { Stop-Process -Id $child.Id -ErrorAction SilentlyContinue } }
    if ($ownsMutex) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
