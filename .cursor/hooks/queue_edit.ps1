$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$queue = Join-Path $projectRoot '.cursor\live-deploy-queue.txt'

$raw = [Console]::In.ReadToEnd()
if (-not $raw) { exit 0 }

try {
    $payload = $raw | ConvertFrom-Json
} catch {
    exit 0
}

$found = New-Object System.Collections.Generic.List[string]
function Add-Path($value) {
    if ($value -is [string] -and $value.Trim()) {
        $script:found.Add($value.Trim())
    }
}

Add-Path $payload.file_path
Add-Path $payload.filePath
Add-Path $payload.path
Add-Path $payload.uri
if ($payload.tool_input) {
    Add-Path $payload.tool_input.path
    Add-Path $payload.tool_input.file_path
    Add-Path $payload.tool_input.filePath
}
if ($payload.file_paths) {
    foreach ($item in $payload.file_paths) { Add-Path $item }
}

if ($found.Count -eq 0) { exit 0 }
$dir = Split-Path -Parent $queue
if (-not (Test-Path -LiteralPath $dir)) {
    New-Item -ItemType Directory -Path $dir | Out-Null
}
Add-Content -LiteralPath $queue -Value $found -Encoding UTF8
exit 0
