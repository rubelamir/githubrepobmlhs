$ErrorActionPreference = 'Continue'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$queue = Join-Path $projectRoot '.cursor\live-deploy-queue.txt'
$log = Join-Path $projectRoot '.cursor\live-deploy.log'
$deploy = Join-Path $projectRoot 'scripts\deploy_paths.ps1'

if (-not (Test-Path -LiteralPath $queue)) { exit 0 }
$lines = @(Get-Content -LiteralPath $queue -ErrorAction SilentlyContinue | Where-Object { $_.Trim() })
Set-Content -LiteralPath $queue -Value '' -Encoding UTF8
if ($lines.Count -eq 0) { exit 0 }

$stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
$output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $deploy @lines 2>&1 | Out-String
$entry = "[$stamp]`r`n$output`r`n"
Add-Content -LiteralPath $log -Value $entry -Encoding UTF8
exit 0
