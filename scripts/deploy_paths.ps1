# Uploads project-relative paths to the live FTPS account in .vscode/sftp.json.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File scripts/deploy_paths.ps1 bmlhs/contact.php
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Paths
)

$ErrorActionPreference = 'Continue'
$projectRoot = Split-Path -Parent $PSScriptRoot
$log = Join-Path $projectRoot '.cursor\live-deploy.log'

function Write-Log([string]$message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message
    Write-Output $line
    $dir = Split-Path -Parent $log
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir | Out-Null
    }
    [System.IO.File]::AppendAllText($log, $line + [Environment]::NewLine)
}

try {
    Write-Log "deploy start"
    $configPath = Join-Path $projectRoot '.vscode\sftp.json'
    if (-not (Test-Path -LiteralPath $configPath)) {
        Write-Log "FAILED missing .vscode/sftp.json"
        exit 1
    }

    $config = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $hostName = [string]$config.host
    $userName = [string]$config.username
    $password = [string]$config.password
    $port = 21
    if ($config.port) { $port = [int]$config.port }
    if (-not $hostName -or -not $userName -or -not $password) {
        Write-Log "FAILED FTP settings incomplete"
        exit 1
    }

    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (-not (Test-Path -LiteralPath $curl)) {
        Write-Log "FAILED curl.exe not found"
        exit 1
    }

    $skipPrefixes = @(
        '.git/', '.github/', '.cursor/', '.vscode/', 'android/', 'node_modules/',
        'scripts/', 'src/', 'tests/', 'docs/'
    )
    $skipSuffixes = @('.sql', '.md', '.pdf', '.csv', '.log')

    function Normalize-Relative([string]$raw) {
        if (-not $raw) { return $null }
        $full = $raw
        if (-not [System.IO.Path]::IsPathRooted($full)) {
            $full = Join-Path $projectRoot $raw
        }
        $full = [System.IO.Path]::GetFullPath($full)
        $root = [System.IO.Path]::GetFullPath($projectRoot).TrimEnd('\') + '\'
        if (-not $full.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)) {
            return $null
        }
        $rel = $full.Substring($root.Length).Replace('\', '/')
        if (-not $rel -or $rel.EndsWith('/')) { return $null }
        $lower = $rel.ToLowerInvariant()
        if ($lower -eq '.vscode/sftp.json') { return $null }
        foreach ($prefix in $skipPrefixes) {
            if ($lower.StartsWith($prefix)) { return $null }
        }
        foreach ($suffix in $skipSuffixes) {
            if ($lower.EndsWith($suffix)) { return $null }
        }
        return $rel
    }

    $unique = New-Object System.Collections.Generic.List[string]
    $seen = @{}
    foreach ($item in $Paths) {
        foreach ($piece in ($item -split '\r?\n')) {
            $rel = Normalize-Relative $piece.Trim()
            if (-not $rel) { continue }
            $key = $rel.ToLowerInvariant()
            if ($seen.ContainsKey($key)) { continue }
            $seen[$key] = $true
            $unique.Add($rel)
        }
    }

    if ($unique.Count -eq 0) {
        Write-Log "No uploadable website files."
        exit 0
    }

    $auth = "${userName}:${password}"
    $failed = 0
    foreach ($rel in $unique) {
        $local = Join-Path $projectRoot ($rel.Replace('/', '\'))
        if (-not (Test-Path -LiteralPath $local -PathType Leaf)) {
            $failed++
            Write-Log "FAILED $rel :: missing local file"
            continue
        }
        $url = "ftp://${hostName}:${port}/$rel"
        $output = & $curl --ssl-reqd --ftp-pasv --ftp-create-dirs --insecure --connect-timeout 20 --max-time 90 --silent --show-error -T $local $url --user $auth 2>&1 | Out-String
        if ($LASTEXITCODE -ne 0) {
            $failed++
            $detail = ($output -replace $password, '***').Trim()
            Write-Log "FAILED $rel :: $detail"
            continue
        }
        $bytes = (Get-Item -LiteralPath $local).Length
        Write-Log "uploaded $rel ($bytes bytes)"
    }

    if ($failed -gt 0) {
        Write-Log "deploy finished with $failed failure(s)"
        exit 1
    }
    Write-Log "deploy finished ok"
    exit 0
} catch {
    Write-Log ("FAILED " + $_.Exception.Message)
    exit 1
}
