$ErrorActionPreference = 'Stop'
$scriptPath = Join-Path $PSScriptRoot 'monitor-supervisor.ps1'
$powershellPath = Join-Path $PSHOME 'powershell.exe'
$arguments = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $scriptPath + '"'
# Per-user logon startup; no administrator account or stored password required.
$startupDirectory = [Environment]::GetFolderPath('Startup')
$shortcutPath = Join-Path $startupDirectory 'NMS Monitor.lnk'
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $powershellPath
$shortcut.Arguments = $arguments
$shortcut.WorkingDirectory = Split-Path $PSScriptRoot -Parent
$shortcut.WindowStyle = 7
$shortcut.Save()
Start-Process -FilePath $powershellPath -ArgumentList $arguments -WindowStyle Hidden
Write-Host "Monitor aktif di background dan otomatis mulai saat login Windows. Startup: $shortcutPath"
