param([int]$Port = 8000, [switch]$WorkersOnly)
$ErrorActionPreference = 'Stop'
$previousMibs = $env:MIBS
if (-not $env:MIBS) { $env:MIBS = ';' } # Windows Net-SNMP empty module list; numeric OIDs need no MIB files.
$runtimeProcesses = @()
Push-Location $PSScriptRoot
try {
    $localPhp = Join-Path $PSScriptRoot '.tools/php/php.exe'
    if (Test-Path -LiteralPath $localPhp) { $phpBinary = $localPhp }
    elseif (Get-Command php -ErrorAction SilentlyContinue) { $phpBinary = 'php' }
    else { throw 'PHP 8.2+ belum tersedia. Lihat README.md.' }
    if (-not (Test-Path -LiteralPath 'vendor/autoload.php')) { throw 'Jalankan composer install terlebih dahulu.' }
    if (-not (Test-Path -LiteralPath '.env')) { throw 'Buat .env dari .env.example dan atur database sesuai README.md.' }
    if (-not (Test-Path -LiteralPath 'public/build/manifest.json')) {
        & npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Build frontend gagal.' }
    }
    & $phpBinary artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Migrasi database gagal.' }
    $runtimeLogs = Join-Path $PSScriptRoot '.tools/runtime'
    New-Item -ItemType Directory -Path $runtimeLogs -Force | Out-Null
    # Detached supervisor keeps polling alive after the web terminal is closed.
    Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "'+(Join-Path $PSScriptRoot 'scripts/monitor-supervisor.ps1')+'"') -WindowStyle Hidden
    if ($WorkersOnly) { Write-Host 'Monitor dan dua worker aktif di background.'; return }
    Write-Host "NMS OLT ZTE: http://127.0.0.1:$Port (Ctrl+C untuk berhenti)"
    # PHP_CLI_SERVER_WORKERS is unsupported on Windows; use six independent PHP servers.
    if ($env:OS -eq 'Windows_NT') {
        & node (Join-Path $PSScriptRoot 'scripts/local-web.mjs') $phpBinary $Port
    } else {
        $previousWorkers = $env:PHP_CLI_SERVER_WORKERS
        try { $env:PHP_CLI_SERVER_WORKERS = '6'; & $phpBinary -S "127.0.0.1:$Port" -t public }
        finally { $env:PHP_CLI_SERVER_WORKERS = $previousWorkers }
    }
} finally { foreach ($runtimeProcess in $runtimeProcesses) { if (-not $runtimeProcess.HasExited) { Stop-Process -Id $runtimeProcess.Id -ErrorAction SilentlyContinue } }; $env:MIBS = $previousMibs; Pop-Location }
