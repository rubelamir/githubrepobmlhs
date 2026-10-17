# Daily-friendly wrapper around scripts/backup_database.php
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts/backup_database.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts/backup_database.ps1 -KeepDays 21
param(
    [string]$BackupDir = '',
    [int]$KeepDays = 30,
    [switch]$NoGzip,
    [switch]$RegisterSchedule
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $php)) {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCmd) { $php = $phpCmd.Source }
}
if (-not (Test-Path -LiteralPath $php)) {
    Write-Error 'PHP not found. Install XAMPP or add php to PATH.'
    exit 1
}

$script = Join-Path $PSScriptRoot 'backup_database.php'
if (-not (Test-Path -LiteralPath $script)) {
    Write-Error "Missing $script"
    exit 1
}

if ($RegisterSchedule) {
    $taskName = 'BMLHS-Database-Backup'
    $args = "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`" -KeepDays $KeepDays"
    if ($BackupDir) { $args += " -BackupDir `"$BackupDir`"" }
    if ($NoGzip) { $args += ' -NoGzip' }

    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $args -WorkingDirectory $projectRoot
    $trigger = New-ScheduledTaskTrigger -Daily -At 2:00AM
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Force | Out-Null
    Write-Output "Scheduled task '$taskName' registered for daily 2:00 AM."
    exit 0
}

$phpArgs = @($script, "--keep-days=$KeepDays")
if ($BackupDir) { $phpArgs += "--dir=$BackupDir" }
if ($NoGzip) { $phpArgs += '--no-gzip' }

& $php @phpArgs
exit $LASTEXITCODE
