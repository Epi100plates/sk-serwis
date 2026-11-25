# Publikacja Studio-Komp (build lokalny + wgranie na VPS).
# Uruchamiany recznie (pnpm publish:remote) albo przez zadanie systemowe
# "Studio-Komp Publish" co kilka minut. W trybie domyslnym sprawdza kolejke
# zadan z panelu i nic nie robi, jesli nie ma oczekujacej publikacji.
#
# Wymagania: Node.js + pnpm, Git, OpenSSH (ssh/scp), tar.
# Uwaga: plik jest celowo bez polskich znakow (kompatybilnosc z Windows PowerShell 5.1).
param(
  [switch]$Force,
  [switch]$Preview,
  [switch]$Quiet,
  [string]$SshHost = 'olga322.mikrus.xyz',
  [int]$SshPort = 10322,
  [string]$SshUser = 'root',
  [string]$KeyPath = "$env:USERPROFILE\.ssh\id_ed25519_providers",
  [string]$SiteUrl = 'https://sk.mati-it.pl',
  [string]$GitPath = '',
  [string]$PnpmPath = 'pnpm'
)

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$logFile = Join-Path $root 'release\publish.log'
$dataDir = '/srv/studio-komp-cms/data'

function Write-Log([string]$message) {
  $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
  $line = "[$stamp] $message"
  if (-not $Quiet -or $message -match 'ERROR|BLAD|Publikacja|Podglad') {
    Write-Host $line
  }
  try {
    $dir = Split-Path -Parent $logFile
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Add-Content -Path $logFile -Value $line -Encoding UTF8
  } catch {}
}

if (-not $GitPath) {
  $cmd = Get-Command git -ErrorAction SilentlyContinue
  if ($cmd) { $GitPath = $cmd.Source } else { $GitPath = 'C:\Program Files\Git\cmd\git.exe' }
}
if (-not (Test-Path $GitPath)) {
  Write-Log "ERROR: nie znaleziono git ($GitPath)"
  exit 1
}

$sshOpts = @('-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new', '-i', $KeyPath, '-p', "$SshPort", "$SshUser@$SshHost")
$scpOpts = @('-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new', '-i', $KeyPath, '-P', "$SshPort")
# Git musi uzywac tego samego klucza SSH co ssh/scp.
$env:GIT_SSH_COMMAND = "ssh -i `"$KeyPath`" -p $SshPort -o StrictHostKeyChecking=accept-new -o BatchMode=yes"

function Invoke-Remote([string]$Command) {
  $out = & ssh @sshOpts $Command 2>&1
  return @{ Output = ($out -join "`n"); Code = $LASTEXITCODE }
}

# --- heartbeat: panel wie, ze komputer publikujacy zyje ---
try {
  $hb = @{
    at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
    host = $env:COMPUTERNAME
  } | ConvertTo-Json -Compress
  $hb | & ssh @sshOpts "mkdir -p $dataDir && cat > $dataDir/publisher-heartbeat.json && chmod 644 $dataDir/publisher-heartbeat.json" 2>&1 | Out-Null
} catch {
  Write-Log "ERROR: brak polaczenia SSH: $($_.Exception.Message)"
  exit 1
}

# --- 1. Zlecenie z panelu ---
$reqRaw = ''
try {
  $reqRaw = (Invoke-Remote "cat $dataDir/publish-request.json 2>/dev/null || true").Output.Trim()
} catch {}

$request = $null
if ($reqRaw.StartsWith('{')) {
  try { $request = $reqRaw | ConvertFrom-Json } catch { $request = $null }
}

if (-not $request -and -not $Force -and -not $Preview) {
  Write-Log 'Brak zadan publikacji.'
  exit 0
}

$requestId = 0
$kind = 'publish'
if ($Preview) { $kind = 'preview' }
if ($request) {
  $requestId = [int]$request.id
  if (-not $Preview -and $request.kind) { $kind = [string]$request.kind }
}

Write-Log "Publikacja: kind=$kind requestId=$requestId"
Set-Location $root

# --- 2. Git: commit lokalnych zmian, scalanie z panelem, push ---
$status = & $GitPath -C $root status --porcelain 2>&1
if ($status) {
  Write-Log 'Commit lokalnych zmian...'
  & $GitPath -C $root add -A 2>&1 | Out-Null
  & $GitPath -C $root -c user.name='Mati' -c user.email='mati@studio-komp.local' commit -m "Automatyczny commit przed publikacja: $(Get-Date -Format 'yyyy-MM-dd HH:mm')" 2>&1 | Out-Null
}

& $GitPath -C $root fetch origin 2>&1 | Out-Null
$local = (& $GitPath -C $root rev-parse HEAD).Trim()
$remote = (& $GitPath -C $root rev-parse origin/main).Trim()
if ($local -ne $remote) {
  Write-Log 'Scalanie zmian z panelu...'
  & $GitPath -C $root merge --no-edit origin/main 2>&1 | ForEach-Object { Write-Log $_ }
  if ($LASTEXITCODE -ne 0) {
    & $GitPath -C $root merge --abort 2>&1 | Out-Null
    Write-Log 'ERROR: konflikt zmian (panel vs komputer). Rozwiaz recznie i opublikuj ponownie.'
    $err = @{ status = 'error'; request_id = $requestId; error = 'Konflikt zmian w repozytorium'; finished_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz') } | ConvertTo-Json
    $err | & ssh @sshOpts "cat > $dataDir/publish-state.json && chmod 644 $dataDir/publish-state.json" 2>&1 | Out-Null
    exit 1
  }
}

Write-Log 'Wysylanie repozytorium na serwer...'
& $GitPath -C $root push origin main 2>&1 | ForEach-Object { Write-Log $_ }
if ($LASTEXITCODE -ne 0) {
  Write-Log 'ERROR: git push nie powiodl sie.'
  exit 1
}
Invoke-Remote "chown -R skcms:skcms /srv/studio-komp 2>/dev/null; chmod +x /srv/studio-komp/deploy/*.sh 2>/dev/null; true" | Out-Null

# --- 3. Build (zawsze lokalnie) ---
$env:SITE_URL = $SiteUrl
$env:BASE_PATH = ''
$buildOk = $true
if ($kind -eq 'preview') {
  $env:PREVIEW = '1'
  Write-Log 'Build podgladu (z wersjami roboczymi)...'
} else {
  $env:PREVIEW = '0'
  Write-Log 'Build produkcyjny...'
}
& $PnpmPath build 2>&1 | ForEach-Object { Write-Log $_ }
if ($LASTEXITCODE -ne 0) { $buildOk = $false }

$distDir = Join-Path $root 'dist'
if ($kind -eq 'preview') { $distDir = Join-Path $root 'dist-preview' }

if ($buildOk) {
  $indexPath = Join-Path $distDir 'index.html'
  if (-not (Test-Path $indexPath)) { $buildOk = $false; Write-Log "ERROR: brak $indexPath" }
}

if (-not $buildOk) {
  Write-Log 'ERROR: build nie powiodl sie. Stara wersja pozostaje bez zmian.'
  $err = @{ status = 'error'; request_id = $requestId; error = 'Build nie powiodl sie'; finished_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz') } | ConvertTo-Json
  $err | & ssh @sshOpts "cat > $dataDir/publish-state.json && chmod 644 $dataDir/publish-state.json" 2>&1 | Out-Null
  exit 1
}

# --- 4. Pakowanie i wysylka ---
$tar = Join-Path $root 'release\site-deploy.tar.gz'
if (Test-Path $tar) { Remove-Item $tar -Force }
& tar -czf $tar -C $distDir . 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $tar)) {
  Write-Log 'ERROR: pakowanie tar nie powiodlo sie.'
  exit 1
}
$sizeMb = [math]::Round((Get-Item $tar).Length / 1MB, 2)
Write-Log "Paczka: $sizeMb MB"

& scp @scpOpts $tar "$SshUser@$SshHost`:/tmp/sk-release.tar.gz" 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
  Write-Log 'ERROR: scp nie powiodlo sie.'
  exit 1
}

$releaseName = Get-Date -Format 'yyyyMMdd-HHmmss'
if ($kind -eq 'preview') {
  $remoteCmd = "bash /srv/studio-komp/deploy/remote-preview.sh /tmp/sk-release.tar.gz $requestId"
} else {
  $remoteCmd = "bash /srv/studio-komp/deploy/remote-release.sh $releaseName /tmp/sk-release.tar.gz $requestId"
}
$result = Invoke-Remote $remoteCmd
Write-Log $result.Output
if ($result.Code -ne 0) {
  Write-Log "ERROR: publikacja na serwerze nie powiodla sie (kod $($result.Code))."
  $err = @{ status = 'error'; request_id = $requestId; error = 'Blad publikacji na serwerze'; finished_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz') } | ConvertTo-Json
  $err | & ssh @sshOpts "cat > $dataDir/publish-state.json && chmod 644 $dataDir/publish-state.json" 2>&1 | Out-Null
  exit 1
}

# --- 5. Porzadki i potwierdzenie ---
try {
  $tail = Get-Content $logFile -Tail 200 -Encoding UTF8
  if ($tail) {
    $tail | & ssh @sshOpts "cat > $dataDir/last-publish.log && chmod 644 $dataDir/last-publish.log" 2>&1 | Out-Null
  }
} catch {}
Invoke-Remote "rm -f $dataDir/publish-request.json; true" | Out-Null
try { Remove-Item $tar -Force } catch {}

$verify = Invoke-Remote "curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:8088/"
Write-Log "Weryfikacja HTTP na serwerze: $($verify.Output)"

if ($kind -eq 'preview') {
  Write-Log 'Podglad gotowy: https://sk.mati-it.pl/podglad/'
} else {
  Write-Log "Publikacja zakonczona: $releaseName"
}
exit 0
