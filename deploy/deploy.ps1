<#
.SYNOPSIS
  Publikacja Studio-Komp na VPS (sk.mati-it.pl) — build lokalny, wgranie dist/.

.DESCRIPTION
  Podział ról:
    * panel /edycja na VPS zapisuje zmiany w repozytorium /srv/studio-komp,
    * build i publikacja dzieją się NA TYM KOMPUTERZE (VPS ma 1 vCPU i nie
      wyrabia z buildem Astro — build na serwerze zawieszał całą maszynę).

  Ten skrypt:
    1. inicjuje lokalne repozytorium (raz) i podpina remote `vps`,
    2. commituje lokalne zmiany,
    3. pobiera commity z panelu (git pull),
    4. wypycha całość na VPS (git push),
    5. buduje stronę lokalnie (pnpm build, SITE_URL=https://sk.mati-it.pl),
    6. wysyła dist/ na serwer i podmienia /var/www/sk.mati-it.pl,
    7. sprawdza odpowiedź strony.

.PRZYKŁAD
  pnpm deploy
  pnpm pull
  pwsh -File deploy\deploy.ps1 -SkipBuild
#>
param(
  [string]$Domain   = "sk.mati-it.pl",
  [string]$SshHost  = "olga322.mikrus.xyz",
  [int]$SshPort     = 10322,
  [string]$SshUser  = "root",
  [string]$KeyPath  = "$env:USERPROFILE\.ssh\mikrus_olga322",
  [string]$RepoDir  = "/srv/studio-komp",
  [string]$WebRoot  = "/var/www/sk.mati-it.pl",
  [string]$SiteUrl  = "https://sk.mati-it.pl",
  [switch]$Pull,
  [switch]$SkipBuild,
  [switch]$NoVerify
)

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$sshOpts = @("-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", $KeyPath, "-p", "$SshPort")
$scpOpts = @("-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", $KeyPath, "-P", "$SshPort")
$remoteUrl = "ssh://$($SshUser)@$($SshHost):$($SshPort)$($RepoDir)"
$env:GIT_SSH_COMMAND = "ssh -i `"$KeyPath`" -o BatchMode=yes -o StrictHostKeyChecking=accept-new"

function Invoke-Remote([string]$Command) {
  $out = & ssh @sshOpts "$SshUser@$SshHost" $Command 2>&1
  $code = $LASTEXITCODE
  $out | ForEach-Object { Write-Host "  $_" }
  if ($code -ne 0) { throw "Polecenie na serwerze nie powiodło się (kod $code): $Command" }
  return $out
}

function Invoke-Git([string[]]$GitArgs) {
  $out = & git @GitArgs 2>&1
  $code = $LASTEXITCODE
  $out | ForEach-Object { Write-Host "  $_" }
  return $code
}

function Get-Pnpm {
  $cmd = Get-Command pnpm.cmd -ErrorAction SilentlyContinue
  if ($cmd) { return $cmd.Source }
  $cmd = Get-Command pnpm -ErrorAction SilentlyContinue
  if ($cmd) { return $cmd.Source }
  throw "Nie znaleziono pnpm w PATH."
}

Push-Location $root
try {
  if (-not (Test-Path .git)) {
    Write-Host "== 1/7 Inicjalizacja lokalnego repozytorium ==" -ForegroundColor Cyan
    if ((Invoke-Git @('init', '-b', 'main')) -ne 0) { throw "git init nie powiodło się" }
  } else {
    Write-Host "== 1/7 Repozytorium lokalne ==" -ForegroundColor Cyan
  }

  $remotes = & git remote
  if (-not ($remotes -contains 'vps')) {
    Write-Host "  dodaję remote vps -> $remoteUrl"
    if ((Invoke-Git @('remote', 'add', 'vps', $remoteUrl)) -ne 0) { throw "git remote add nie powiodło się" }
  }

  $email = & git config user.email
  if (-not $email) {
    Invoke-Git @('config', 'user.name', 'Studio-Komp') | Out-Null
    Invoke-Git @('config', 'user.email', 'deploy@sk.mati-it.pl') | Out-Null
  }

  # Historia lokalna musi mieć wspólny korzeń z repozytorium na VPS.
  & git fetch vps main 2>&1 | Out-Null
  $fetchOk = ($LASTEXITCODE -eq 0)
  & git rev-parse --verify HEAD 2>$null | Out-Null
  $hasCommit = ($LASTEXITCODE -eq 0)
  if ($fetchOk) {
    & git merge-base HEAD vps/main 2>$null | Out-Null
    $sharesHistory = ($LASTEXITCODE -eq 0)
    if (-not $hasCommit -or -not $sharesHistory) {
      Write-Host "  przejmuję historię z VPS (git reset --mixed vps/main)"
      if ((Invoke-Git @('reset', '--mixed', 'vps/main')) -ne 0) { throw "git reset nie powiodło się" }
    }
  }

  if ($Pull) {
    Write-Host "== 2/7 Pobieranie zmian z VPS ==" -ForegroundColor Cyan
    if ((Invoke-Git @('pull', '--no-rebase', 'vps', 'main')) -ne 0) { throw "git pull nie powiodło się" }
    Write-Host "GOTOWE (pull)." -ForegroundColor Green
    return
  }

  Write-Host "== 2/7 Commit lokalnych zmian ==" -ForegroundColor Cyan
  & git add -A
  $dirty = & git status --porcelain
  if ($dirty) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'
    if ((Invoke-Git @('commit', '-m', "Zmiany z laptopa $stamp")) -ne 0) { throw "git commit nie powiodło się" }
  } else {
    Write-Host "  brak lokalnych zmian"
  }

  Write-Host "== 3/7 Pobieranie zmian z panelu /edycja ==" -ForegroundColor Cyan
  if ((Invoke-Git @('pull', '--no-rebase', 'vps', 'main')) -ne 0) {
    throw "git pull nie powiodło się (konflikt?). Rozwiąż konflikt, zrób commit i uruchom ponownie."
  }

  Write-Host "== 4/7 Push na VPS ==" -ForegroundColor Cyan
  if ((Invoke-Git @('push', 'vps', 'main')) -ne 0) { throw "git push nie powiodło się" }

  if ($SkipBuild) {
    Write-Host "== 5/7 Build pominięty (-SkipBuild) ==" -ForegroundColor Cyan
  } else {
    Write-Host "== 5/7 Build lokalny ==" -ForegroundColor Cyan
    $env:SITE_URL = $SiteUrl
    $env:BASE_PATH = ''
    $pnpm = Get-Pnpm
    & $pnpm build
    if ($LASTEXITCODE -ne 0) { throw "pnpm build nie powiodło się — nic nie zostało wgrane." }
    if (-not (Test-Path 'dist\index.html')) { throw "Brak dist\index.html po buildzie." }
  }

  Write-Host "== 6/7 Wgranie dist/ na VPS ==" -ForegroundColor Cyan
  $tar = Join-Path $env:TEMP 'sk-dist.tar.gz'
  if (Test-Path $tar) { Remove-Item $tar -Force }
  & tar -czf $tar -C (Join-Path $root 'dist') .
  if ($LASTEXITCODE -ne 0) { throw "tar nie powiodło się" }
  Write-Host ("  paczka: {0:N2} MB" -f ((Get-Item $tar).Length / 1MB))
  & scp @scpOpts $tar "$($SshUser)@$($SshHost):/tmp/sk-dist.tar.gz"
  if ($LASTEXITCODE -ne 0) { throw "scp dist nie powiodło się" }
  $deployCmd = "rm -rf /tmp/sk-dist && mkdir -p /tmp/sk-dist && tar -xzf /tmp/sk-dist.tar.gz -C /tmp/sk-dist && rsync -a --delete /tmp/sk-dist/ $WebRoot/ && chown -R www-data:www-data $WebRoot && rm -rf /tmp/sk-dist /tmp/sk-dist.tar.gz && echo DEPLOY_OK"
  Invoke-Remote $deployCmd | Out-Null
  Remove-Item $tar -Force

  if (-not $NoVerify) {
    Write-Host "== 7/7 Weryfikacja ==" -ForegroundColor Cyan
    $site = "https://$Domain/"
    $r = Invoke-WebRequest -Uri $site -UseBasicParsing -TimeoutSec 25
    Write-Host ("  {0} -> HTTP {1}, {2} B" -f $site, $r.StatusCode, $r.RawContentLength)
    try {
      Invoke-WebRequest -Uri "https://$Domain/edycja/" -UseBasicParsing -TimeoutSec 25 | Out-Null
      Write-Host "  UWAGA: /edycja/ odpowiada bez hasła (basic auth nieaktywny?)" -ForegroundColor Yellow
    } catch {
      Write-Host "  /edycja/ -> HTTP $([int]$_.Exception.Response.StatusCode) (panel pod hasłem)"
    }
  }
  Write-Host "GOTOWE" -ForegroundColor Green
} finally {
  Pop-Location
}
