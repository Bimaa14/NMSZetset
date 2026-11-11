$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtimeDirectory = Join-Path $projectRoot '.tools/runtime'
New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
[IO.File]::WriteAllText((Join-Path $runtimeDirectory 'monitor-stop'),'stop')
$shortcutPath = Join-Path ([Environment]::GetFolderPath('Startup')) 'NMS Monitor.lnk'
if (Test-Path -LiteralPath $shortcutPath) { Remove-Item -LiteralPath $shortcutPath }
Write-Host 'Autostart dinonaktifkan. Supervisor akan menghentikan monitor/worker dalam sekitar 5 detik.'
