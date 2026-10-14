# Einrichtung des Zeitraffer-Abholers unter Windows (Server 2019/2022, Windows 10/11).
# PowerShell ALS ADMINISTRATOR oeffnen, in den Ordner "abholer" wechseln und starten:
#   powershell -ExecutionPolicy Bypass -File .\einrichten-windows.ps1
# Kann beliebig oft ausgefuehrt werden.

$ErrorActionPreference = "Stop"
$Ordner = $PSScriptRoot
$NeolinkVersion = "v0.6.2"
$FfmpegUrl = "https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip"
$AufgabeName = "Zeitraffer Abholer"

function Ok($t)   { Write-Host "  [OK]    $t" -ForegroundColor Green }
function Fail($t) { Write-Host "  [FEHLT] $t" -ForegroundColor Red }
function Info($t) { Write-Host "  [INFO]  $t" }
function Step($t) { Write-Host "`n== $t" -ForegroundColor Cyan }

function Laden($url, $ziel) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $ProgressPreference = "SilentlyContinue"   # macht Invoke-WebRequest deutlich schneller
    Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $ziel
}

Set-Location $Ordner

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
         ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) {
    Fail "Bitte PowerShell als Administrator starten (Rechtsklick > Als Administrator ausfuehren)."
    exit 1
}

Step "1. Python"
$python = $null
foreach ($kandidat in @("py", "python")) {
    try {
        $exe = & $kandidat -c "import sys; print(sys.executable)" 2>$null
        if ($LASTEXITCODE -eq 0 -and $exe -and (Test-Path $exe.Trim())) { $python = $exe.Trim(); break }
    } catch { }
}
if (-not $python) {
    Fail "Python nicht gefunden."
    Info "Installer von https://www.python.org/downloads/windows/ (64-bit) starten,"
    Info "'Customize installation' > 'Install Python for all users' und 'Add Python to environment variables'."
    Info "Danach PowerShell neu oeffnen und dieses Skript erneut starten."
    exit 1
}
$pythonw = Join-Path (Split-Path $python) "pythonw.exe"
if (-not (Test-Path $pythonw)) { $pythonw = $python }
Ok "Python: $python"
if ($python -like "*\Users\*") {
    Info "Python ist nur fuer einen Benutzer installiert. Funktioniert, besser ist 'for all users'."
}

Step "2. GStreamer"
$gst = @([Environment]::GetEnvironmentVariable("GSTREAMER_1_0_ROOT_MSVC_X86_64", "Machine"),
         $env:GSTREAMER_1_0_ROOT_MSVC_X86_64,
         "C:\Program Files\gstreamer\1.0\msvc_x86_64\", "C:\gstreamer\1.0\msvc_x86_64\") |
       Where-Object { $_ -and (Test-Path (Join-Path $_ "bin\gstreamer-1.0-0.dll")) } | Select-Object -First 1
if ($gst) { Ok "GStreamer: $gst" } else {
    Fail "GStreamer fehlt: https://gstreamer.freedesktop.org/download/ (Runtime, MSVC 64-bit, 'Complete')"
    exit 1
}

Step "3. Neolink"
$neolink = Join-Path $Ordner "neolink\neolink.exe"
if (-not (Test-Path $neolink)) {
    Info "Lade Neolink $NeolinkVersion ..."
    $zip = Join-Path $env:TEMP "neolink_windows.zip"
    Laden "https://github.com/QuantumEntangledAndy/neolink/releases/download/$NeolinkVersion/neolink_windows.zip" $zip
    $tmp = Join-Path $env:TEMP "neolink_entpackt"
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    Expand-Archive $zip -DestinationPath $tmp -Force
    $exe = Get-ChildItem $tmp -Recurse -Filter neolink.exe | Select-Object -First 1
    New-Item -ItemType Directory -Force (Join-Path $Ordner "neolink") | Out-Null
    Copy-Item (Join-Path $exe.DirectoryName "*") (Join-Path $Ordner "neolink") -Recurse -Force
}
Ok "Neolink: $neolink"

Step "4. ffmpeg (fuer die Videos)"
$ffmpeg = Join-Path $Ordner "ffmpeg\bin\ffmpeg.exe"
if (-not (Test-Path $ffmpeg)) {
    Info "Lade ffmpeg (ca. 100 MB) ..."
    $zip = Join-Path $env:TEMP "ffmpeg.zip"
    Laden $FfmpegUrl $zip
    $tmp = Join-Path $env:TEMP "ffmpeg_entpackt"
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    Expand-Archive $zip -DestinationPath $tmp -Force
    $exe = Get-ChildItem $tmp -Recurse -Filter ffmpeg.exe | Select-Object -First 1
    New-Item -ItemType Directory -Force (Join-Path $Ordner "ffmpeg\bin") | Out-Null
    Copy-Item (Join-Path $exe.DirectoryName "*") (Join-Path $Ordner "ffmpeg\bin") -Force
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
Ok "ffmpeg: $ffmpeg"

Step "5. Konfiguration"
$ini = Join-Path $Ordner "config.ini"
if (-not (Test-Path $ini)) {
    Copy-Item (Join-Path $Ordner "config.example.ini") $ini
    Info "config.ini angelegt. Bitte Server-URL, API-Schluessel und Kamerapasswort eintragen,"
    Info "speichern und dieses Skript danach noch einmal starten."
    Start-Process notepad.exe $ini
    exit 0
}
if ((Get-Content $ini -Raw) -match "HIER-KAMERAPASSWORT|GLEICHER-SCHLUESSEL|DEINE-DOMAIN") {
    Fail "config.ini ist noch nicht vollstaendig ausgefuellt."
    Start-Process notepad.exe $ini
    exit 1
}
# Nur der Administrator und SYSTEM duerfen config.ini lesen (enthaelt Passwoerter).
icacls $ini /inheritance:r /grant:r "*S-1-5-32-544:F" "*S-1-5-18:F" | Out-Null
Ok "config.ini vorhanden (Zugriff auf Administratoren und SYSTEM beschraenkt)"

Step "6. Test"
& $python (Join-Path $Ordner "zeitraffer.py") test
if ($LASTEXITCODE -ne 0) {
    Fail "Test nicht erfolgreich. Aufgabe wird nicht eingerichtet."
    exit 1
}

Step "7. Aufgabenplanung"
$aktion = New-ScheduledTaskAction -Execute $pythonw `
    -Argument "`"$(Join-Path $Ordner 'zeitraffer.py')`" aufnahme" -WorkingDirectory $Ordner
$ausloeser = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 5)
$einst = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
# Laeuft als SYSTEM: auch ohne angemeldeten Benutzer und nach Neustart.
$wer = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName $AufgabeName -Action $aktion -Trigger $ausloeser -Settings $einst `
    -Principal $wer -Description "Holt alle 5 Minuten ein Bild von der Reolink-Kamera (Zeitraffer)" -Force | Out-Null
Ok "Aufgabe '$AufgabeName' eingerichtet: alle 5 Minuten, auch ohne Anmeldung."
Info "Protokoll:  $(Join-Path $Ordner 'daten\zeitraffer.log')"
Info "Entfernen:  Unregister-ScheduledTask -TaskName '$AufgabeName'"
