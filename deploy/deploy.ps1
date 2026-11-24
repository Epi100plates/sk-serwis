<#
.SYNOPSIS
  Publikacja Studio-Komp na VPS (sk.mati-it.pl).

.DESCRIPTION
  Źródłem prawdy jest repozytorium git na VPS: /srv/studio-komp (branch main).
  Panel /edycja zapisuje zmiany jako commity w tym samym repozytorium, a usługa
  studio-komp-publish przebudowuje i publikuje stronę.

  Ten skrypt:
    1. inicjuje lokalne repozytorium (raz) i podpina remote `vps`,
    2. commituje lokalne zmiany,
    3. wypycha je na VPS (git push),
    4. czeka, aż usługa publish opublikuje dokładnie ten commit,
    5. sprawdza odpowiedź strony.

  Zanim wypchniesz własne zmiany po edycji w panelu, zrób `pnpm pull`.

.PRZYKŁAD
  pnpm deploy
  pnpm pull
  pwsh -File deploy\deploy.ps1 -Pull
#>
param(
  [string]$Domain   = "sk.mati-it.pl",
  [string]$SshHost  = "olga322.mikrus.xyz",
  [int]$SshPort     = 10322,
  [string]$SshUser  = "root",
  [string]$KeyPath  = "$env:USERPROFILE\.ssh\mikrus_olga322",
  [string]$RepoDir  = "/srv/studio-komp",
  [int]$PublishTimeoutSec = 900,
  [switch]$Pull,
  [switch]$NoVerify
)

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$sshOpts = @("-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-i", $KeyPath, "-p", "$SshPort")
$remoteUrl = "ssh://$($SshUser)@$($SshHost):$($SshPort)$($RepoDir)"
$env:GIT_SSH_COMMAND = "ssh -i `"$KeyPath`" -o BatchMode=yes -o StrictHostKeyChecking=accept-new"

function Invoke-Remote([string]$Command) {
  $out = & ssh @sshOpts "$SshUser@$SshHost" $Command 2>&1
  if ($LASTEXITCODE -ne 0) { throw "Polecenie na serwerze nie powiodło się (kod $LASTEXITCODE): $Command`n$out" }
  return $out
}

function Invoke-Git([string[]]$GitArgs) {
  $out = & git @GitArgs 2>&1
  $code = $LASTEXITCODE
  $out | ForEach-Object { Write-Host "  $_" }
  return $code
}

Push-Location $root
try {
  if (-not (Test-Path .git)) {
    Write-Host "== 1/5 Inicjalizacja lokalnego repozytorium ==" -ForegroundColor Cyan
    if ((Invoke-Git @('init', '-b', 'main')) -ne 0) { throw "git init nie powiodło się" }
  } else {
    Write-Host "== 1/5 Repozytorium lokalne ==" -ForegroundColor Cyan
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

  # Historia lokalna musi mieć wspólny korzeń z repozytorium na VPS —
  # inaczej push zostałby odrzucony jako niepowiązany.
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
    Write-Host "== 2/5 Pobieranie zmian z VPS ==" -ForegroundColor Cyan
    if ((Invoke-Git @('pull', '--no-rebase', 'vps', 'main')) -ne 0) { throw "git pull nie powiodło się" }
    Write-Host "GOTOWE (pull)." -ForegroundColor Green
    return
  }

  Write-Host "== 2/5 Commit lokalnych zmian ==" -ForegroundColor Cyan
  & git add -A
  $dirty = & git status --porcelain
  if ($dirty) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'
    if ((Invoke-Git @('commit', '-m', "Zmiany z laptopa $stamp")) -ne 0) { throw "git commit nie powiodło się" }
  } else {
    Write-Host "  brak zmian do commita"
  }

  Write-Host "== 3/5 Push na VPS ==" -ForegroundColor Cyan
  if ((Invoke-Git @('push', 'vps', 'main')) -ne 0) {
    throw "git push odrzucony. Ktoś edytował treść w panelu /edycja — uruchom najpierw 'pnpm pull'."
  }
  $sha = (& git rev-parse HEAD).Trim()

  Write-Host "== 4/5 Czekam na build i publikację ($sha) ==" -ForegroundColor Cyan
  $deadline = (Get-Date).AddSeconds($PublishTimeoutSec)
  $published = $false
  while ((Get-Date) -lt $deadline) {
    $line = (Invoke-Remote "cat /var/lib/studio-komp/last-publish 2>/dev/null || true" | Out-String).Trim()
    if ($line -and ($line -split '\s+')[0] -eq $sha) { $published = $true; break }
    Start-Sleep -Seconds 3
  }
  if (-not $published) {
    throw "Brak potwierdzenia publikacji w $PublishTimeoutSec s. Sprawdź: ssh ... 'journalctl -u studio-komp-publish -n 50 --no-pager'"
  }
  Write-Host "  opublikowano: $sha"

  if (-not $NoVerify) {
    Write-Host "== 5/5 Weryfikacja ==" -ForegroundColor Cyan
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
