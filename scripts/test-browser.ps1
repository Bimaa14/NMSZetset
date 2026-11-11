param([int]$Port = 8011, [string[]]$Tests = @())
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
Push-Location $projectRoot
$serverProcess = $null
$workerProcess = $null
$testDirectory = Join-Path $projectRoot 'storage/framework/testing'
$originalEnvironment = @{}
foreach ($name in @('DB_CONNECTION','DB_DATABASE','APP_ENV','NMS_DEMO','NMS_SUBSCRIPTION','PLAYWRIGHT_BASE_URL','MIBS','NMS_CACHE_PATH')) {
    $originalEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
try {
    if (-not $env:MIBS) { $env:MIBS = ';' }
    if (Test-Path -LiteralPath 'bootstrap/cache/config.php') { throw 'Bersihkan config cache sebelum pengujian database terisolasi: php artisan config:clear' }
    if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) { throw "Port $Port sedang digunakan. Pilih port pengujian lain." }
    $phpBinary = Join-Path $projectRoot '.tools/php/php.exe'
    if (-not (Test-Path -LiteralPath $phpBinary)) { $phpBinary = (Get-Command php).Source }
    New-Item -ItemType Directory -Path $testDirectory -Force | Out-Null
    $testDatabase = Join-Path $testDirectory ('browser-' + [guid]::NewGuid().ToString('N') + '.sqlite')
    New-Item -ItemType File -Path $testDatabase | Out-Null
    $env:NMS_CACHE_PATH = $testDatabase + '.cache'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $testDatabase
    $env:APP_ENV = 'local'
    $env:NMS_DEMO = 'true'
    $env:NMS_SUBSCRIPTION = 'true'
    $env:PLAYWRIGHT_BASE_URL = "http://127.0.0.1:$Port"
    & $phpBinary artisan migrate --seed --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Persiapan database browser test gagal.' }
    $serverProcess = Start-Process -FilePath $phpBinary -ArgumentList @('-S',"127.0.0.1:$Port",'-t','public') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput ($testDatabase + '.stdout.log') -RedirectStandardError ($testDatabase + '.stderr.log')
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if ($serverProcess.HasExited) { throw 'Server pengujian berhenti sebelum siap.' }
        try { $response = Invoke-WebRequest "$env:PLAYWRIGHT_BASE_URL/login" -UseBasicParsing -TimeoutSec 2; if ($response.StatusCode -eq 200) { $ready = $true; break } } catch {}
        Start-Sleep -Milliseconds 200
    }
    if (-not $ready) { throw 'Server pengujian tidak dapat diakses.' }
    $workerProcess = Start-Process -FilePath $phpBinary -ArgumentList @('artisan','queue:work','olt','--queue=olt','--sleep=1','--tries=1','--timeout=330') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput ($testDatabase+'.worker.log') -RedirectStandardError ($testDatabase+'.worker.err.log')
    & npx.cmd playwright test @Tests
    if ($LASTEXITCODE -ne 0) { throw 'Pengujian browser gagal. Lihat test-results dan log storage/framework/testing.' }
} finally {
    if ($workerProcess -and -not $workerProcess.HasExited) { Stop-Process -Id $workerProcess.Id; $workerProcess.WaitForExit() }
    if ($serverProcess -and -not $serverProcess.HasExited) { Stop-Process -Id $serverProcess.Id; $serverProcess.WaitForExit() }
    foreach ($name in $originalEnvironment.Keys) { [Environment]::SetEnvironmentVariable($name, $originalEnvironment[$name], 'Process') }
    Pop-Location
}
