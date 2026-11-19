# Compilation de l'APK de PRODUCTION (Windows PowerShell), pointant sur https://eftpnonformel.ins.ne/api/v1.
#
#   powershell -ExecutionPolicy Bypass -File deploy\mobile\build-apk.ps1
#
# Prérequis : Flutter installé, mobile\android\key.properties présent (clé de production), version de
# mobile\pubspec.yaml et AppConfig.appVersion (mobile\lib\src\app_config.dart) alignées et supérieures à la
# dernière version publiée. L'APK est ensuite publié depuis le back-office (Paramètres › Application mobile).
$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$mobile = Join-Path $root 'mobile'
$api = 'https://eftpnonformel.ins.ne/api/v1'

if (-not (Test-Path (Join-Path $mobile 'android\key.properties'))) {
    throw 'mobile\android\key.properties absent : un APK de production doit être signé avec la clé de production.'
}
$pub = (Select-String -Path (Join-Path $mobile 'pubspec.yaml') -Pattern '^version:\s*([0-9.]+)').Matches[0].Groups[1].Value
$cfg = (Select-String -Path (Join-Path $mobile 'lib\src\app_config.dart') -Pattern "appVersion = '([0-9.]+)'").Matches[0].Groups[1].Value
if ($pub -ne $cfg) {
    throw "Versions différentes : pubspec.yaml $pub / AppConfig.appVersion $cfg"
}

Push-Location $mobile
try {
    flutter pub get
    flutter test
    if ($LASTEXITCODE -ne 0) { throw 'Tests mobiles en échec.' }
    flutter build apk --release --dart-define=API_BASE_URL=$api
    if ($LASTEXITCODE -ne 0) { throw 'Compilation en échec.' }
} finally {
    Pop-Location
}

$apk = Join-Path $mobile 'build\app\outputs\flutter-apk\app-release.apk'
$dest = Join-Path $root "dist\eftp-collecte-$pub.apk"
New-Item -ItemType Directory -Force (Split-Path $dest) | Out-Null
Copy-Item $apk $dest -Force
$sha = (Get-FileHash $dest -Algorithm SHA256).Hash.ToLower()
Write-Host "APK de production : $dest"
Write-Host "Version $pub — serveur $api — SHA-256 $sha"
