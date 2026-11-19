# Fabrique le ZIP à téléverser dans httpdocs du serveur (Plesk / cPanel / VPS) : UNIQUEMENT ce qui fait tourner
# la plateforme (API + back-office web). Depuis le poste Windows :
#
#   powershell -ExecutionPolicy Bypass -File deploy\scripts\package-zip.ps1
#   → dist/eftp-nonformel-httpdocs-AAAAMMJJ-HHMM.zip   (à décompresser tel quel dans httpdocs/)
#
# Contenu du ZIP (= contenu de httpdocs/) :
#   .htaccess          envoie toutes les adresses vers backend/public/ (seul dossier servi) et force HTTPS
#   backend/           code PHP + vendor (sans paquets de développement) ; storage/ vide ; config/local.php.example
#   database/          migrations SQL (bin/migrate.php, bin/check.php)
#   shared/            définitions du questionnaire (bin/seed.php)
#   renacom.xlsx, listes_metiers.xlsx   territoire et métiers (bin/seed.php, base neuve uniquement)
# Non inclus : tests, application mobile, documentation, guides, clé app.key et données du poste.
# Après envoi : renommer backend/config/local.php.example en local.php et le compléter (deploy/DEPLOIEMENT-HEBERGEMENT.md §4).
param([string]$Out = "dist")
$ErrorActionPreference = "Stop"
$root = (Resolve-Path "$PSScriptRoot\..\..").Path
Set-Location $root

$stamp = Get-Date -Format "yyyyMMdd-HHmm"
$name = "eftp-nonformel-httpdocs-$stamp"
$tmp = Join-Path $env:TEMP $name
if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
New-Item -ItemType Directory -Path "$tmp\backend" | Out-Null

Write-Host "==> Copie des fichiers"
$exclude = @("storage", "tests", "vendor", "backend.zip", ".phpunit.cache", ".phpunit.result.cache")
Get-ChildItem "$root\backend" -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
    Copy-Item $_.FullName -Destination "$tmp\backend" -Recurse -Force
}
Remove-Item "$tmp\backend\config\local.php" -ErrorAction SilentlyContinue
foreach ($d in @("database", "shared")) { Copy-Item "$root\$d" -Destination $tmp -Recurse -Force }
foreach ($f in @("renacom.xlsx", "listes_metiers.xlsx")) { if (Test-Path "$root\$f") { Copy-Item "$root\$f" $tmp } }
Copy-Item "$root\deploy\hebergement\racine-public_html.htaccess" "$tmp\.htaccess"
Copy-Item "$root\deploy\hebergement\local.php.example" "$tmp\backend\config\local.php.example"

Write-Host "==> Stockage vide (droits d'écriture à donner sur le serveur)"
foreach ($d in @("exports", "imports", "templates", "releases", "logs")) {
    New-Item -ItemType Directory -Path "$tmp\backend\storage\$d" -Force | Out-Null
    Set-Content -Path "$tmp\backend\storage\$d\.gitkeep" -Value "" -Encoding ascii
}
# storage n'est jamais servi (hors backend/public) ; accès direct fermé en plus, au cas où la racine serait mal réglée.
Set-Content -Path "$tmp\backend\storage\.htaccess" -Value "Require all denied" -Encoding ascii

Write-Host "==> Dépendances PHP (sans paquets de développement)"
if (Get-Command composer -ErrorAction SilentlyContinue) {
    & composer install -d "$tmp\backend" --no-dev --optimize-autoloader --no-interaction --no-progress
    if ($LASTEXITCODE -ne 0) { throw "composer install a échoué" }
} else {
    Write-Warning "composer introuvable : copie du dossier vendor du poste (contient aussi PHPUnit : sans danger, mais plus lourd)."
    Copy-Item "$root\backend\vendor" -Destination "$tmp\backend" -Recurse -Force
}

$rev = (git rev-parse --short HEAD 2>$null)
if (-not $rev) { $rev = "inconnu" }
Set-Content -Path "$tmp\REVISION" -Value "$rev ($stamp)" -Encoding ascii
if ((git status --porcelain 2>$null)) { Write-Warning "Modifications non committées : le paquet contient l'état ACTUEL des fichiers ($rev + modifications)." }

New-Item -ItemType Directory -Path "$root\$Out" -Force | Out-Null
$zip = "$root\$Out\$name.zip"
if (Test-Path $zip) { Remove-Item $zip }
Write-Host "==> Compression"
# Compress-Archive ignore les fichiers cachés avec « * » : les .htaccess sont ajoutés via la liste complète.
$items = Get-ChildItem $tmp -Force | ForEach-Object { $_.FullName }
Compress-Archive -Path $items -DestinationPath $zip -CompressionLevel Optimal
Remove-Item $tmp -Recurse -Force
$mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
Write-Host ""
Write-Host "Paquet prêt : $zip ($mb Mo)"
Write-Host "A décompresser dans httpdocs/, puis : backend/config/local.php.example → local.php (deploy\DEPLOIEMENT-HEBERGEMENT.md)."
