# Run the Boz News unit tests without installing anything.
#
# There is no PHP on this machine and Docker is not an option, so this script
# works with a portable PHP (a plain ZIP, extracted anywhere) and phpunit.phar
# (one file). Neither needs administrator rights, an installer, or a PATH entry.
#
#   .\tools\run-tests.ps1
#
# It looks for PHP in: -Php argument, $env:PHP_BIN, PATH, then the usual
# extraction folders. Same idea for PHPUnit.

[CmdletBinding()]
param(
    [string]$Php,
    [string]$Phpunit,
    [switch]$Setup
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

function Find-Php {
    param([string]$Explicit)

    if ($Explicit) {
        if (Test-Path $Explicit) { return (Resolve-Path $Explicit).Path }
        throw "No PHP at the path you passed: $Explicit"
    }

    if ($env:PHP_BIN -and (Test-Path $env:PHP_BIN)) { return $env:PHP_BIN }

    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }

    $candidates = @(
        "$root\.php\php.exe",
        "$env:USERPROFILE\php\php.exe",
        'C:\php\php.exe',
        'C:\tools\php\php.exe',
        'C:\xampp\php\php.exe'
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) { return $c }
    }

    # Laragon and some XAMPP layouts keep PHP in a version-named subfolder,
    # e.g. C:\laragon\bin\php\php-8.3.2-Win32-vs16-x64\php.exe. Take the
    # highest version found.
    $globs = @(
        'C:\laragon\bin\php\*\php.exe',
        "$env:USERPROFILE\laragon\bin\php\*\php.exe",
        'D:\laragon\bin\php\*\php.exe'
    )
    foreach ($g in $globs) {
        $found = Get-ChildItem -Path $g -ErrorAction SilentlyContinue |
            Sort-Object -Property FullName -Descending |
            Select-Object -First 1
        if ($found) { return $found.FullName }
    }

    return $null
}

function Find-Phpunit {
    param([string]$Explicit)

    if ($Explicit) {
        if (Test-Path $Explicit) { return (Resolve-Path $Explicit).Path }
        throw "No PHPUnit at the path you passed: $Explicit"
    }

    $candidates = @(
        "$root\vendor\bin\phpunit",
        "$root\phpunit.phar",
        "$root\tools\phpunit.phar",
        "$env:USERPROFILE\phpunit.phar"
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) { return (Resolve-Path $c).Path }
    }

    return $null
}

function Initialize-PortableIni {
    param([string]$PhpBin)

    # The Windows ZIP ships no php.ini, and PHPUnit refuses to start without
    # mbstring. Only touch the copy under .php that this script manages -
    # never a PHP the user set up themselves.
    $dir = Split-Path -Parent $PhpBin
    if ($dir -ne (Join-Path $root '.php')) { return }

    $ini = Join-Path $dir 'php.ini'
    if (Test-Path $ini) { return }

    $extDir = Join-Path $dir 'ext'
    if (-not (Test-Path $extDir)) { return }

    @"
; Written by tools/run-tests.ps1 for the portable PHP in this folder.
; Only what the test suite needs.
extension_dir = "ext"
extension = mbstring
extension = openssl
memory_limit = 512M
date.timezone = UTC
"@ | Set-Content -Path $ini -Encoding utf8

    Write-Host "Wrote a minimal php.ini for the portable PHP (mbstring enabled)." -ForegroundColor DarkGray
}

function Show-Setup {
    Write-Host ''
    Write-Host 'One-time setup - no installer, no admin rights, no Docker:' -ForegroundColor Cyan
    Write-Host ''
    Write-Host '  1. PHP (a ZIP you just extract)'
    Write-Host '     https://windows.php.net/download/'
    Write-Host '     Take the latest "VS16 x64 Thread Safe" (or VS17) ZIP under PHP 8.3,'
    Write-Host "     and extract it to:  $root\.php"
    Write-Host '     (.php is already in .gitignore, so it will not be committed.)'
    Write-Host ''
    Write-Host '  2. PHPUnit (one file)'
    Write-Host '     https://phar.phpunit.de/phpunit-9.phar'
    Write-Host "     Save it as:  $root\phpunit.phar"
    Write-Host ''
    Write-Host '  3. Run this script again.'
    Write-Host ''
    Write-Host 'Nothing else is needed. Composer is optional: phpunit.phar is'
    Write-Host 'self-contained and the test bootstrap has no dependencies.' -ForegroundColor DarkGray
    Write-Host ''
}

if ($Setup) {
    Show-Setup
    exit 0
}

$phpBin = Find-Php -Explicit $Php
$phpunitBin = Find-Phpunit -Explicit $Phpunit

if (-not $phpBin -or -not $phpunitBin) {
    if (-not $phpBin) { Write-Host 'PHP not found.' -ForegroundColor Yellow }
    if (-not $phpunitBin) { Write-Host 'PHPUnit not found.' -ForegroundColor Yellow }
    Show-Setup
    exit 1
}

Initialize-PortableIni -PhpBin $phpBin

Write-Host "PHP:     $phpBin"
& $phpBin -v | Select-Object -First 1
Write-Host "PHPUnit: $phpunitBin"
Write-Host ''

Push-Location $root
try {
    & $phpBin $phpunitBin --configuration phpunit.xml.dist
    $code = $LASTEXITCODE
}
finally {
    Pop-Location
}

exit $code
