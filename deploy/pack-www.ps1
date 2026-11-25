<#
.SYNOPSIS
  Paczka "przenosna" dla hostingu www: jeden build dziala w katalogu glownym
  domeny i w dowolnym podkatalogu (np. https://epii.pl/sk/).

.DESCRIPTION
  1. Buduje strone (SITE_URL).
  2. Zamienia absolutne odnosniki "/..." na wzgledne (scripts/relativize.mjs),
     dzieki czemu te same pliki dzialaja pod / oraz pod /sk/ bez przebudowy.
  3. Pakuje dist/ do release\studio-komp-www.zip i sprawdza paczke.

.PRZYKLAD
  powershell -File deploy\pack-www.ps1
  powershell -File deploy\pack-www.ps1 -SiteUrl https://studio-komp.pl
  powershell -File deploy\pack-www.ps1 -SkipBuild   # tylko przepakowanie
#>
param(
  [string]$SiteUrl  = "https://studio-komp.pl",
  [string]$OutFile  = "",
  [switch]$SkipBuild,
  [switch]$SkipRelativize
)

# Windows PowerShell 5.1 zamienia stderr programow na wyjatki przy Stop,
# dlatego bledy sprawdzamy jawnie przez $LASTEXITCODE / throw.
$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
if (-not $OutFile) { $OutFile = Join-Path $root "release\studio-komp-www.zip" }
$dist = Join-Path $root "dist"
$SiteUrl = $SiteUrl.TrimEnd('/')

Write-Host "== 1/4 Build ==" -ForegroundColor Cyan
if (-not $SkipBuild) {
  Push-Location $root
  try {
    $env:BASE_PATH = ""
    $env:SITE_URL = $SiteUrl
    & pnpm build
    if ($LASTEXITCODE -ne 0) { throw "pnpm build nie powiodlo sie" }
  } finally { Pop-Location }
} else { Write-Host "  pominieto (-SkipBuild)" }

Write-Host "== 2/4 Sciezki wzgledne (build przenosny) ==" -ForegroundColor Cyan
if (-not $SkipRelativize) {
  Push-Location $root
  try {
    & node scripts/relativize.mjs
    if ($LASTEXITCODE -ne 0) { throw "scripts/relativize.mjs nie powiodlo sie" }
  } finally { Pop-Location }
} else { Write-Host "  pominieto (-SkipRelativize)" }

Write-Host "== 3/4 Pakowanie ==" -ForegroundColor Cyan
if (-not (Test-Path (Join-Path $dist "index.html"))) { throw "Brak dist\index.html - uruchom build" }
if (-not (Test-Path (Join-Path $dist ".htaccess"))) { throw "Brak dist\.htaccess - hosting FTP nie bedzie mial przekierowan 301" }
if (-not (Test-Path $OutFile)) { New-Item -ItemType Directory -Path (Split-Path -Parent $OutFile) -Force | Out-Null }
if (Test-Path $OutFile) { Remove-Item $OutFile -Force }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$files = @(Get-ChildItem $dist -Recurse -File -Force)
# Nazwy wpisow z ukosnikami "/" - inaczej .NET Framework zapisuje "\" i panel
# hostingu rozpakowuje pliki o blednych nazwach.
$zip = [System.IO.Compression.ZipFile]::Open($OutFile, 'Create')
try {
  foreach ($f in $files) {
    $rel = $f.FullName.Substring($dist.Length).TrimStart('\', '/') -replace '\\', '/'
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
      $zip, $f.FullName, $rel, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
  }
} finally { $zip.Dispose() }

$size = [math]::Round((Get-Item $OutFile).Length / 1KB)
Write-Host ("  " + $files.Count + " plikow, " + $size + " KB")

Write-Host "== 4/4 Weryfikacja paczki ==" -ForegroundColor Cyan
$zip = [System.IO.Compression.ZipFile]::OpenRead($OutFile)
try {
  $names = @($zip.Entries | ForEach-Object { $_.FullName })
  foreach ($required in @("index.html", ".htaccess", "404.html", "robots.txt", "sitemap-index.xml", "site.webmanifest")) {
    if ($names -notcontains $required) { throw "Brak pliku w paczce: $required" }
  }

  function Read-ZipEntry([string]$name) {
    $e = $zip.GetEntry($name)
    $sr = New-Object System.IO.StreamReader($e.Open())
    try { return $sr.ReadToEnd() } finally { $sr.Dispose() }
  }

  $index = Read-ZipEntry "index.html"
  $canon = [regex]::Match($index, '<link rel="canonical" href="([^"]+)"').Groups[1].Value
  if (-not $canon) { throw "Brak canonical w index.html" }
  if ($canon -ne "$SiteUrl/") { throw "Canonical '$canon' != '$SiteUrl/'" }

  $robots = Read-ZipEntry "robots.txt"
  if ($robots -notmatch [regex]::Escape("$SiteUrl/sitemap-index.xml")) { throw "robots.txt nie wskazuje $SiteUrl/sitemap-index.xml" }

  # Build przenosny: brak odnosnikow zakotwiczonych do katalogu glownego domeny.
  $absolute = [regex]::Matches($index, '(?:href|src)="/(?!/)').Count
  if ($absolute -gt 0) { throw "index.html ma $absolute odnosnikow absolutnych - uruchom scripts/relativize.mjs" }
  $pageInDir = Read-ZipEntry "cennik/index.html"
  if ($pageInDir -notmatch 'href="\.\./') { throw "cennik/index.html nie ma wzglednych odnosnikow (../)" }

  $smName = "sitemap-0.xml"
  if ($names -notcontains $smName) { throw "Brak $smName" }
  $loc = @([regex]::Matches((Read-ZipEntry $smName), '<loc>([^<]+)</loc>') | ForEach-Object { $_.Groups[1].Value })
  $missing = @()
  foreach ($url in $loc) {
    $path = $url -replace [regex]::Escape($SiteUrl), ''
    $path = $path.TrimStart('/')
    if ($path -eq '' -or $path.EndsWith('/')) { $path = $path + 'index.html' }
    if ($names -notcontains $path) { $missing += $path }
  }
  if ($missing.Count -gt 0) { throw ("Brak plikow dla adresow z sitemapy: " + ($missing -join ', ')) }

  $html = @($names | Where-Object { $_.EndsWith('/index.html') -or $_ -eq 'index.html' })
  Write-Host ("  adresy w sitemapie: " + $loc.Count + ", pliki HTML: " + $html.Count + ", brakujace: 0")
} finally { $zip.Dispose() }

Write-Host ""
Write-Host "PACZKA GOTOWA: $OutFile" -ForegroundColor Green
Write-Host ("  wgraj zawartosc do public_html/ (" + $SiteUrl + "/) ALBO do public_html/sk/ (" + $SiteUrl + " -> https://twoj-hosting.pl/sk/)")
Write-Host "  ten sam build dziala w katalogu glownym i w podkatalogu"
Write-Host "  instrukcja: docs/wdrozenie-hosting-www.md"
