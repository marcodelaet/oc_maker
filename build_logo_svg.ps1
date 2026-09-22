$pngPath = Join-Path $PSScriptRoot "logo_converta.png"
$bytes = [System.IO.File]::ReadAllBytes($pngPath)

# PNG IHDR chunk: width/height at bytes 16-23 (big-endian)
$width = ([uint32]0).GetType().Assembly  # dummy
$width = [System.BitConverter]::ToUInt32([byte[]]@($bytes[16], $bytes[17], $bytes[18], $bytes[19] + 0), 0)
# Fix big-endian manually
$width = ($bytes[16] -shl 24) -bor ($bytes[17] -shl 16) -bor ($bytes[18] -shl 8) -bor $bytes[19]
$height = ($bytes[20] -shl 24) -bor ($bytes[21] -shl 16) -bor ($bytes[22] -shl 8) -bor $bytes[23]

$b64 = [Convert]::ToBase64String($bytes)
$svg = @"
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="$width" height="$height" viewBox="0 0 $width $height" role="img" aria-label="Converta Ads by Retail Media">
  <title>Converta Ads by Retail Media</title>
  <image width="$width" height="$height" preserveAspectRatio="xMidYMid meet" xlink:href="data:image/png;base64,$b64"/>
</svg>
"@

$targets = @(
    (Join-Path $PSScriptRoot "logo_converta.svg"),
    (Join-Path $PSScriptRoot "web-js\assets\logo_converta.svg"),
    (Join-Path $PSScriptRoot "web-node\public\assets\logo_converta.svg"),
    (Join-Path $PSScriptRoot "web-php\public\assets\logo_converta.svg")
)

foreach ($target in $targets) {
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($target, $svg, [System.Text.UTF8Encoding]::new($false))
    Write-Host "OK $target (${width}x${height})"
}

$pngTargets = @(
    (Join-Path $PSScriptRoot "web-js\assets\logo_converta.png"),
    (Join-Path $PSScriptRoot "web-node\public\assets\logo_converta.png"),
    (Join-Path $PSScriptRoot "web-php\public\assets\logo_converta.png")
)
foreach ($target in $pngTargets) {
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item $pngPath $target -Force
    Write-Host "OK $target (png copy)"
}
