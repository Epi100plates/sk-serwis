<#
.SYNOPSIS
  Wdrożenie Studio-Komp na VPS (root@olga322.mikrus.xyz) + Cloudflare Tunnel.

.DESCRIPTION
  1. Buduje stronę (SITE_URL), pakuje dist/ do tar.gz.
  2. Wgrywa paczkę, konfigurację nginx, skrypt setup i token tunelu przez SCP.
  3. Na serwerze: nginx vhost (127.0.0.1:8088), cloudflared jako usługa systemd.
  4. Weryfikuje odpowiedź HTTP lokalnie i przez domenę publiczną.

.PRZYKŁAD
  pwsh -File deploy\deploy.ps1
  pwsh -File deploy\deploy.ps1 -SkipBuild
#>
param(
  [string]$Domain   = "sk.mati-it.pl",
  [string]$SiteUrl  = "https://sk.mati-it.pl",
  [string]$SshHost  = "olga322.mikrus.xyz",
  [int]$SshPort     = 10322,
  [string]$SshUser  = "root",
  [string]$KeyPath  = "$env:USERPROFILE\.ssh\id_ed25519_providers",
  [string]$TokenFile = "$env:LOCALAPPDATA\studio-komp-deploy\tunnel.token",
  [switch]$SkipBuild,
  [switch]$SkipUpload
)

# Windows PowerShell 5.1 zamienia stderr programów na wyjątki przy Stop,
# dlatego błędy sprawdzamy jawnie przez $LASTEXITCODE / throw.
$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$sshOpts = @("-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", $KeyPath, "-p", "$SshPort")
$scpOpts = @("-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", $KeyPath, "-P", "$SshPort")

function Invoke-Remote([string]$Command) {
  $out = & ssh @sshOpts "$SshUser@$SshHost" $Command 2>&1
  $code = $LASTEXITCODE
  $out | ForEach-Object { Write-Host "  $_" }
  if ($code -ne 0) { throw "Polecenie na serwerze nie powiodło się (kod $code): $Command" }
}

Write-Host "== 1/5 Build ==" -ForegroundColor Cyan
if (-not $SkipBuild) {
  Push-Location $root
  try {
    $env:BASE_PATH = ""
    $env:SITE_URL = $SiteUrl
    & pnpm build
    if ($LASTEXITCODE -ne 0) { throw "pnpm build nie powiodło się" }
  } finally { Pop-Location }
} else { Write-Host "  pominięto (-SkipBuild)" }

Write-Host "== 2/5 Pakowanie ==" -ForegroundColor Cyan
$tar = Join-Path $root "release\site-sk.tar.gz"
if (Test-Path $tar) { Remove-Item $tar -Force }
& tar -czf $tar --exclude=.htaccess --exclude=_redirects -C (Join-Path $root "dist") .
if ($LASTEXITCODE -ne 0) { throw "tar nie powiodło się" }
Write-Host ("  " + [math]::Round((Get-Item $tar).Length / 1MB, 2) + " MB")

if (-not $SkipUpload) {
  Write-Host "== 3/5 Upload ==" -ForegroundColor Cyan
  $files = @(
    @{ Local = $tar;                                 Remote = "/tmp/site-sk.tar.gz" },
    @{ Local = "$root\deploy\nginx\sk.mati-it.pl.conf"; Remote = "/tmp/$Domain.conf" },
    @{ Local = "$root\deploy\server-setup.sh";          Remote = "/tmp/server-setup.sh" },
    @{ Local = $TokenFile;                              Remote = "/tmp/sk.token" }
  )
  foreach ($f in $files) {
    if (-not (Test-Path $f.Local)) { throw "Brak pliku lokalnego: $($f.Local)" }
    & scp @scpOpts $f.Local "$($SshUser)@$($SshHost):$($f.Remote)"
    if ($LASTEXITCODE -ne 0) { throw "scp nie powiodło się: $($f.Local)" }
    Write-Host "  wysłano: $($f.Remote)"
  }

  Write-Host "== 4/5 Konfiguracja serwera ==" -ForegroundColor Cyan
  Invoke-Remote @"
set -euo pipefail
mkdir -p /var/www/$Domain
tar -xzf /tmp/site-sk.tar.gz -C /var/www/$Domain
chown -R www-data:www-data /var/www/$Domain
bash /tmp/server-setup.sh $Domain 8088
rm -f /tmp/sk.token /tmp/site-sk.tar.gz /tmp/$Domain.conf /tmp/server-setup.sh
echo '--- lokalnie:'
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/
"@
}

Write-Host "== 5/5 Weryfikacja ==" -ForegroundColor Cyan
try {
  $r = Invoke-WebRequest -Uri $SiteUrl -UseBasicParsing -TimeoutSec 25
  Write-Host ("  $SiteUrl -> HTTP " + $r.StatusCode + ", " + $r.RawContentLength + " B")
} catch {
  Write-Host ("  UWAGA: $SiteUrl jeszcze nie odpowiada: " + $_.Exception.Message) -ForegroundColor Yellow
}
Write-Host "GOTOWE" -ForegroundColor Green
