<#
.SYNOPSIS
  Konfiguracja panelu edycji /edycja na VPS (jednorazowo, idempotentnie).

.DESCRIPTION
  1. Wgrywa na serwer: źródła (tylko przy pierwszej instalacji),
     konfigurację nginx-sk, skrypt editor-setup.sh i publish.sh.
  2. Uruchamia editor-setup.sh: Node.js + pnpm + decap-server, usługi
     studio-komp-cms / studio-komp-publish, plik haseł do panelu,
     przeładowanie nginx-sk.
  3. Hasło do panelu trzyma poza repozytorium:
     %LOCALAPPDATA%\studio-komp-deploy\edycja-password.txt

.PRZYKŁAD
  pnpm provision
  pwsh -File deploy\provision.ps1 -PanelPassword "nowe-haslo"
#>
param(
  [string]$Domain   = "sk.mati-it.pl",
  [string]$SshHost  = "olga322.mikrus.xyz",
  [int]$SshPort     = 10322,
  [string]$SshUser  = "root",
  [string]$KeyPath  = "$env:USERPROFILE\.ssh\mikrus_olga322",
  [string]$RepoDir  = "/srv/studio-komp",
  [string]$PanelPassword = "",
  [switch]$NoUpload
)

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$sshOpts = @("-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", $KeyPath, "-p", "$SshPort")
$scpOpts = @("-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", $KeyPath, "-P", "$SshPort")
$target = "$SshUser@$SshHost"
$stateDir = Join-Path $env:LOCALAPPDATA 'studio-komp-deploy'
$passFile = Join-Path $stateDir 'edycja-password.txt'

function Invoke-Remote([string]$Command, [string[]]$StdinLines = @()) {
  $out = $StdinLines | & ssh @sshOpts $target $Command 2>&1
  $code = $LASTEXITCODE
  $out | ForEach-Object { Write-Host "  $_" }
  if ($code -ne 0) { throw "Polecenie na serwerze nie powiodło się (kod $code): $Command" }
  return $out
}

# --- Hasło do panelu ---------------------------------------------------------
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null
if (-not $PanelPassword) {
  if (Test-Path $passFile) {
    $PanelPassword = (Get-Content $passFile -Raw).Trim()
  } else {
    $bytes = New-Object byte[] 18
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $PanelPassword = ([Convert]::ToBase64String($bytes) -replace '\+', 'x' -replace '/', 'y' -replace '=', '')
    [System.IO.File]::WriteAllText($passFile, $PanelPassword + "`n", (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "Wygenerowano hasło do panelu: $passFile"
  }
}

Write-Host "== 1/4 Sprawdzenie stanu serwera ==" -ForegroundColor Cyan
$repoExists = ((Invoke-Remote "test -d $RepoDir/.git && echo YES || echo NO" | Out-String).Trim() -match 'YES')
Write-Host "  repozytorium na VPS: $(if ($repoExists) { 'jest' } else { 'brak — inicjalizacja' })"

if (-not $NoUpload) {
  if (-not $repoExists) {
    Write-Host "== 2/4 Wgranie źródeł (pierwsza instalacja) ==" -ForegroundColor Cyan
    $tar = Join-Path $env:TEMP 'sk-src.tar.gz'
    if (Test-Path $tar) { Remove-Item $tar -Force }
    & tar -czf $tar --exclude=node_modules --exclude=dist --exclude=release --exclude=shots --exclude=.git --exclude=.playwright-mcp --exclude=.astro -C $root .
    if ($LASTEXITCODE -ne 0) { throw "tar nie powiodło się" }
    & scp @scpOpts $tar "$($target):/tmp/sk-src.tar.gz"
    if ($LASTEXITCODE -ne 0) { throw "scp źródeł nie powiodło się" }
  } else {
    Write-Host "== 2/4 Źródła już są na VPS — pomijam ==" -ForegroundColor Cyan
  }

  Write-Host "== 3/4 Wgranie konfiguracji ==" -ForegroundColor Cyan
  $files = @(
    @{ Local = (Join-Path $root 'deploy\nginx\sk.mati-it.pl.conf'); Remote = '/tmp/sk.mati-it.pl.conf' },
    @{ Local = (Join-Path $root 'deploy\editor-setup.sh');            Remote = '/tmp/editor-setup.sh' }
  )
  foreach ($f in $files) {
    & scp @scpOpts $f.Local "$($target):$($f.Remote)"
    if ($LASTEXITCODE -ne 0) { throw "scp nie powiodło się: $($f.Local)" }
    Write-Host "  wysłano: $($f.Remote)"
  }
}

Write-Host "== 4/4 Konfiguracja usług i nginx ==" -ForegroundColor Cyan
$initRepo = ':'
if (-not $repoExists) {
  $initRepo = @'
set -e
mkdir -p /srv/studio-komp
tar -xzf /tmp/sk-src.tar.gz -C /srv/studio-komp
rm -f /tmp/sk-src.tar.gz
id -u skcms >/dev/null 2>&1 || useradd --system --create-home --home-dir /srv/studio-komp --shell /bin/bash skcms
chown -R skcms:skcms /srv/studio-komp
export PATH=/opt/node22/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
/usr/sbin/runuser -u skcms -- env HOME=/srv/studio-komp PATH="$PATH" git -C /srv/studio-komp init -b main
/usr/sbin/runuser -u skcms -- env HOME=/srv/studio-komp PATH="$PATH" git -C /srv/studio-komp config user.name "Panel Studio-Komp"
/usr/sbin/runuser -u skcms -- env HOME=/srv/studio-komp PATH="$PATH" git -C /srv/studio-komp config user.email "panel@sk.mati-it.pl"
/usr/sbin/runuser -u skcms -- env HOME=/srv/studio-komp PATH="$PATH" git -C /srv/studio-komp add -A
/usr/sbin/runuser -u skcms -- env HOME=/srv/studio-komp PATH="$PATH" git -C /srv/studio-komp commit -q -m "Stan wyjściowy strony Studio-Komp"
'@
}
$cmd = @"
set -euo pipefail
$initRepo
bash /tmp/editor-setup.sh $Domain
"@
Invoke-Remote $cmd @($PanelPassword)

Write-Host "Panel: https://$Domain/edycja/" -ForegroundColor Green
Write-Host "Hasło: $passFile" -ForegroundColor Green
