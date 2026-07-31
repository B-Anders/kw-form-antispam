<#
.SYNOPSIS
    Builds the distributable ZIP for the KW Form Antispam plugin.

.DESCRIPTION
    Stages plugin/ into a temporary directory named kw-form-antispam/, drops
    development-only files, and compresses it to
    deploy/kw-form-antispam-<version>.zip.

    The archive contains exactly one top-level directory, kw-form-antispam/,
    which is what the WordPress admin uploader expects. An archive whose files
    sit at the root installs into a directory named after the ZIP and breaks
    updates.

.PARAMETER Version
    Overrides the version. Defaults to the "Version:" header in
    plugin/kw-form-antispam.php.

.PARAMETER OutputDirectory
    Where to write the ZIP. Defaults to <repo>/deploy.

.EXAMPLE
    pwsh -File tools/build-zip.ps1
#>

[CmdletBinding()]
param(
    [string] $Version,
    [string] $OutputDirectory
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$slug        = 'kw-form-antispam'
$repoRoot    = Split-Path -Parent $PSScriptRoot
$pluginRoot  = Join-Path $repoRoot 'plugin'
$mainFile    = Join-Path $pluginRoot "$slug.php"

if (-not (Test-Path -LiteralPath $mainFile)) {
    throw "Main plugin file not found: $mainFile"
}

# --- Version -----------------------------------------------------------------

if (-not $Version) {
    $header = Get-Content -LiteralPath $mainFile -TotalCount 40 -Encoding UTF8
    $match  = $header | Select-String -Pattern '^\s*\*\s*Version:\s*(.+?)\s*$' | Select-Object -First 1

    if (-not $match) {
        throw "Could not read the Version header from $mainFile"
    }

    $Version = $match.Matches[0].Groups[1].Value
}

if ($Version -notmatch '^\d+\.\d+(\.\d+)*$') {
    throw "Version '$Version' does not look like a plugin version."
}

# --- Cross-check readme.txt Stable tag ---------------------------------------

$readme = Join-Path $pluginRoot 'readme.txt'
if (Test-Path -LiteralPath $readme) {
    $stable = Get-Content -LiteralPath $readme -Encoding UTF8 |
        Select-String -Pattern '^Stable tag:\s*(.+?)\s*$' |
        Select-Object -First 1

    if ($stable) {
        $stableTag = $stable.Matches[0].Groups[1].Value
        if ($stableTag -ne $Version) {
            Write-Warning "readme.txt Stable tag ($stableTag) does not match the plugin version ($Version)."
        }
    }
}

# --- Paths -------------------------------------------------------------------

if (-not $OutputDirectory) {
    $OutputDirectory = Join-Path $repoRoot 'deploy'
}

if (-not (Test-Path -LiteralPath $OutputDirectory)) {
    New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
}

$zipPath  = Join-Path $OutputDirectory "$slug-$Version.zip"
$stageDir = Join-Path ([System.IO.Path]::GetTempPath()) ("kwfa-build-" + [Guid]::NewGuid().ToString('N'))
$payload  = Join-Path $stageDir $slug

New-Item -ItemType Directory -Path $payload -Force | Out-Null

# --- Copy --------------------------------------------------------------------

# Development-only artefacts that must never reach a wp.org review or a site.
# Note: 'vendor' is deliberately NOT excluded — assets/vendor/altcha/ is the
# bundled widget and must ship. No Composer vendor directory exists here.
$excludedDirectories = @(
    '.git', '.github', '.vscode', '.idea',
    'node_modules', 'tests', 'test', 'coverage'
)

$excludedFilePatterns = @(
    '*.map', '*.min.js.map', '*.log', '*.zip', '*.tar', '*.tar.gz',
    '.DS_Store', 'Thumbs.db', '.gitignore', '.gitattributes', '.editorconfig',
    'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'phpcs.xml', 'phpcs.xml.dist', 'phpunit.xml', 'phpunit.xml.dist',
    '*.dist', '*.orig', '*.rej', '*.bak', '*~'
)

Write-Host "Staging $slug $Version ..."

try {
    $files = Get-ChildItem -LiteralPath $pluginRoot -Recurse -File -Force

    foreach ($file in $files) {
        $relative = $file.FullName.Substring($pluginRoot.Length).TrimStart('\', '/')
        $segments = $relative -split '[\\/]'

        $inExcludedDir = $false
        foreach ($segment in $segments[0..([Math]::Max($segments.Length - 2, 0))]) {
            if ($segments.Length -gt 1 -and $excludedDirectories -contains $segment) {
                $inExcludedDir = $true
                break
            }
        }
        if ($inExcludedDir) { continue }

        $excluded = $false
        foreach ($pattern in $excludedFilePatterns) {
            if ($file.Name -like $pattern) { $excluded = $true; break }
        }
        if ($excluded) { continue }

        $destination = Join-Path $payload $relative
        $destParent  = Split-Path -Parent $destination

        if (-not (Test-Path -LiteralPath $destParent)) {
            New-Item -ItemType Directory -Path $destParent -Force | Out-Null
        }

        Copy-Item -LiteralPath $file.FullName -Destination $destination -Force
    }

    # --- Sanity checks -------------------------------------------------------

    $required = @(
        "$slug.php",
        'readme.txt',
        'uninstall.php',
        'includes/class-plugin.php',
        'includes/class-gate.php',
        'assets/js/kwfa-widget.js',
        'assets/vendor/altcha/altcha.umd.js',
        'assets/vendor/altcha/LICENSE.txt'
    )

    foreach ($item in $required) {
        $path = Join-Path $payload $item
        if (-not (Test-Path -LiteralPath $path)) {
            throw "Missing from the build: $item"
        }
    }

    $protocol = Join-Path $payload 'includes/altcha'
    if (-not (Test-Path -LiteralPath $protocol)) {
        Write-Warning "includes/altcha/ is missing — the protocol core is not in this build."
    }

    # Nothing outside plugin/ is ever staged, so the dev toolchain (PHPCS in
    # tools/lint, PHPUnit in tools/wp-tests, this script) cannot reach the ZIP.
    # Assert it rather than assume it.
    $forbidden = @('tools', 'research', 'docs', 'deploy', '.git')
    foreach ($name in $forbidden) {
        if (Test-Path -LiteralPath (Join-Path $payload $name)) {
            throw "Dev-only directory '$name' leaked into the build."
        }
    }

    $strays = Get-ChildItem -LiteralPath $payload -Recurse -File -Force |
        Where-Object { $_.Name -in @('composer.json', 'composer.lock', 'phpunit.xml', 'phpcs.xml') -or $_.Name -like '*.ps1' }
    if ($strays) {
        throw "Dev-only files leaked into the build: $($strays.Name -join ', ')"
    }

    # --- Compress ------------------------------------------------------------

    if (Test-Path -LiteralPath $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    Compress-Archive -Path $payload -DestinationPath $zipPath -CompressionLevel Optimal

    $size = [Math]::Round((Get-Item -LiteralPath $zipPath).Length / 1KB, 1)
    $count = (Get-ChildItem -LiteralPath $payload -Recurse -File).Count

    Write-Host ""
    Write-Host "Built $zipPath"
    Write-Host "  version : $Version"
    Write-Host "  files   : $count"
    Write-Host "  size    : $size KB"
    Write-Host "  root    : $slug/"
}
finally {
    if (Test-Path -LiteralPath $stageDir) {
        Remove-Item -LiteralPath $stageDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
