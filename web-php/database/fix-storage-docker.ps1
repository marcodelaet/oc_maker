# Ajusta permissões de storage no container Docker (dev Windows).
# Uso: .\database\fix-storage-docker.ps1
# Container Apache/PHP: webserver_php

$ErrorActionPreference = "Stop"
$ContainerName = "webserver_php"
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path

function Find-ContainerProjectPath {
    param([string]$Container, [string]$HostRoot)

    $mountsJson = docker inspect $Container --format "{{json .Mounts}}" 2>$null
    if ($LASTEXITCODE -eq 0 -and $mountsJson) {
        $mounts = $mountsJson | ConvertFrom-Json
        $hostNorm = ($HostRoot -replace "\\", "/").TrimEnd("/").ToLowerInvariant()
        foreach ($m in $mounts) {
            $src = ([string]$m.Source -replace "\\", "/").TrimEnd("/").ToLowerInvariant()
            if ($hostNorm.StartsWith($src) -or $src.StartsWith($hostNorm) -or $src -like "*oc_maker/web-php*") {
                $rel = $hostNorm.Substring($src.Length).TrimStart("/")
                $dest = ([string]$m.Destination).TrimEnd("/")
                if ($rel) {
                    return "$dest/$rel".Replace("//", "/")
                }
                if (Test-ContainerFile $Container "$dest/database/fix-storage-docker.sh") {
                    return $dest
                }
            }
        }
        foreach ($m in $mounts) {
            $dest = ([string]$m.Destination).TrimEnd("/")
            if (Test-ContainerFile $Container "$dest/database/fix-storage-docker.sh") {
                return $dest
            }
        }
    }

    foreach ($candidate in @(
        "/var/www/html/web-php",
        "/var/www/html/oc_maker/web-php",
        "/var/www/html"
    )) {
        if (Test-ContainerFile $Container "$candidate/database/fix-storage-docker.sh") {
            return $candidate
        }
    }

    return $null
}

function Test-ContainerFile {
    param([string]$Container, [string]$Path)
    docker exec $Container sh -c "test -f '$Path'" 2>$null | Out-Null
    return $LASTEXITCODE -eq 0
}

$containerPath = Find-ContainerProjectPath -Container $ContainerName -HostRoot $ProjectRoot
if (-not $containerPath) {
    Write-Error @"
Não encontrei web-php dentro do container '$ContainerName'.
Confira o volume montado com: docker inspect webserver_php --format ""{{range .Mounts}}{{.Source}} -> {{.Destination}}{{println}}{{end}}""
Depois rode manualmente (use barras /):
  docker exec -u root webserver_php sh /caminho/no/container/database/fix-storage-docker.sh
"@
    exit 1
}

$scriptPath = "$containerPath/database/fix-storage-docker.sh"
Write-Host "Container: $ContainerName"
Write-Host "Caminho:   $scriptPath"
Write-Host ""

docker exec -u root $ContainerName sh $scriptPath
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "Storage pronto para uploads e criativos."
