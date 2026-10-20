<#
.SYNOPSIS
    Runs the live billing system on this PC (compose.production.yaml).

.EXAMPLE
    .\docker\production.ps1 start -Quick   # start + free trycloudflare.com address (trying it out)
    .\docker\production.ps1 start          # start + your own domain (needs TUNNEL_TOKEN), else local only
    .\docker\production.ps1 url            # show the quick tunnel's public address
    .\docker\production.ps1 apply-url      # make emails and links use that address
    .\docker\production.ps1 status         # what's running
    .\docker\production.ps1 logs queue     # follow one service's log (app, scheduler, queue, db, quick-tunnel, tunnel)
    .\docker\production.ps1 test-router    # log in to the MikroTik once
    .\docker\production.ps1 sync-router    # first run after connecting: create missing PPPoE accounts, apply every status
    .\docker\production.ps1 backup         # database backup right now (one also runs every night)
    .\docker\production.ps1 stop
#>
param(
    [Parameter(Position = 0)]
    [ValidateSet('start', 'stop', 'status', 'url', 'apply-url', 'logs', 'test-router', 'sync-router', 'backup')]
    [string]$Action = 'status',

    [Parameter(Position = 1)]
    [string]$Service = 'app',

    [switch]$Quick
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root '.env.production'
Set-Location $root

if (-not (Test-Path $envFile)) {
    Write-Host 'Missing .env.production. Copy .env.production.example to .env.production and fill it in.' -ForegroundColor Red
    exit 1
}

function Get-Setting([string]$name) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^$name=" } | Select-Object -Last 1
    if ($line) { return ($line -replace "^$name=", '').Trim('"') }
    return ''
}

function Invoke-Compose([string[]]$arguments) {
    & docker compose --env-file .env.production -f compose.production.yaml @arguments
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Get-QuickUrl {
    $log = & docker compose --env-file .env.production -f compose.production.yaml --profile quick-tunnel logs quick-tunnel 2>&1 | Out-String
    $found = [regex]::Matches($log, 'https://[a-z0-9-]+\.trycloudflare\.com')
    if ($found.Count -gt 0) { return $found[$found.Count - 1].Value }
    return $null
}

switch ($Action) {
    'start' {
        $profiles = @()
        $windowsTunnel = Get-Service -Name Cloudflared -ErrorAction SilentlyContinue
        if ($Quick) {
            $profiles = @('--profile', 'quick-tunnel')
        } elseif (Get-Setting 'TUNNEL_TOKEN') {
            $profiles = @('--profile', 'tunnel')
        } elseif ($windowsTunnel) {
            # Installed with Cloudflare's "cloudflared service install" command:
            # it runs outside Docker, so its public hostname must point at
            # http://localhost:<APP_PORT>, not app:80.
            Write-Host "Public address: the Cloudflared Windows service ($($windowsTunnel.Status))." -ForegroundColor Green
        } else {
            Write-Host 'No TUNNEL_TOKEN set and -Quick not given: starting without a public address (this PC only).' -ForegroundColor Yellow
        }
        Invoke-Compose ($profiles + @('up', '-d', '--build'))

        $port = Get-Setting 'APP_PORT'; if (-not $port) { $port = '8080' }
        Write-Host ''
        Write-Host "On this PC: http://localhost:$port" -ForegroundColor Green
        if ($Quick) {
            Write-Host 'Waiting for the public address...'
            for ($i = 0; $i -lt 30 -and -not ($url = Get-QuickUrl); $i++) { Start-Sleep -Seconds 2 }
            if ($url) {
                Write-Host "Public:      $url" -ForegroundColor Green
                Write-Host 'Run ".\docker\production.ps1 apply-url" so emails and payment links use it.'
            } else {
                Write-Host 'The tunnel is still starting; run ".\docker\production.ps1 url" in a moment.' -ForegroundColor Yellow
            }
        }
    }
    'stop' {
        Invoke-Compose @('--profile', 'quick-tunnel', '--profile', 'tunnel', 'down')
    }
    'status' {
        Invoke-Compose @('--profile', 'quick-tunnel', '--profile', 'tunnel', 'ps')
        $url = Get-QuickUrl
        if ($url) { Write-Host "Quick tunnel: $url" -ForegroundColor Green }
    }
    'url' {
        $url = Get-QuickUrl
        if ($url) { Write-Host $url -ForegroundColor Green } else { Write-Host 'No quick tunnel running (start with: start -Quick).' -ForegroundColor Yellow }
    }
    'apply-url' {
        $url = Get-QuickUrl
        if (-not $url) { Write-Host 'No quick tunnel running (start with: start -Quick).' -ForegroundColor Yellow; exit 1 }
        $lines = Get-Content $envFile | ForEach-Object { if ($_ -match '^APP_URL=') { "APP_URL=$url" } else { $_ } }
        Set-Content -Path $envFile -Value $lines -Encoding ascii
        # Recreates only app, scheduler and queue; the tunnel keeps its address.
        Invoke-Compose @('up', '-d', 'app', 'scheduler', 'queue')
        Write-Host "APP_URL is now $url" -ForegroundColor Green
        Write-Host "PayMongo webhook (if you use one): $url/webhooks/paymongo"
    }
    'logs' {
        Invoke-Compose @('--profile', 'quick-tunnel', '--profile', 'tunnel', 'logs', '-f', '--tail', '100', $Service)
    }
    'test-router' {
        Invoke-Compose @('exec', 'app', 'php', 'artisan', 'mikrotik:test')
    }
    'sync-router' {
        Invoke-Compose @('exec', 'app', 'php', 'artisan', 'mikrotik:sync', '--now', '--create-missing')
    }
    'backup' {
        Invoke-Compose @('exec', 'backup', 'bash', '/backup.sh', 'now')
        $dir = Get-Setting 'BACKUP_DIR'; if (-not $dir) { $dir = '.\backups' }
        Write-Host "Backups are in $dir - copy them off this PC too (USB drive, Google Drive, OneDrive)." -ForegroundColor Green
    }
}
