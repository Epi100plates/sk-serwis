# Rejestracja zadania systemowego Windows, ktore co 5 minut sprawdza kolejke
# publikacji i publikuje strone (build lokalny + wgranie na VPS).
# Uzycie: powershell -ExecutionPolicy Bypass -File deploy\install-publisher-task.ps1
#        powershell ... -File deploy\install-publisher-task.ps1 -Remove
param(
  [switch]$Remove,
  [int]$IntervalMinutes = 5,
  [string]$TaskName = 'Studio-Komp Publish'
)

$ErrorActionPreference = 'Stop'
$script = Join-Path (Split-Path -Parent $PSScriptRoot) 'deploy\publish.ps1'

if ($Remove) {
  schtasks /Delete /TN "$TaskName" /F 2>&1 | Out-Null
  Write-Host "Usunieto zadanie: $TaskName"
  exit 0
}

if (-not (Test-Path $script)) {
  throw "Brak skryptu: $script"
}

$tr = 'powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $script + '" -Quiet'
schtasks /Create /TN "$TaskName" /TR $tr /SC MINUTE /MO $IntervalMinutes /F 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
  throw 'Nie udalo sie utworzyc zadania. Uruchom PowerShell jako administrator.'
}

Write-Host "Zadanie '$TaskName' utworzone (co $IntervalMinutes min)."
Write-Host "Podglad: schtasks /Query /TN `"$TaskName`" /V /FO LIST"
Write-Host "Uruchom teraz: schtasks /Run /TN `"$TaskName`""
Write-Host "Usun: powershell -File deploy\install-publisher-task.ps1 -Remove"
