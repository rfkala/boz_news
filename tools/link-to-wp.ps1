# Point a local WordPress at this checkout, so editing here is live there.
#
#   .\tools\link-to-wp.ps1 -WordPress C:\laragon\www\mysite
#
# Uses a directory junction, which on Windows does NOT require administrator
# rights or Developer Mode (unlike a symbolic link). Nothing is copied: save a
# file here and WordPress serves it immediately, so there is no upload step
# while developing.
#
# -Unlink removes it again.

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$WordPress,

    [string]$Name = 'wp-news-collector',

    [switch]$Unlink
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

if (-not (Test-Path $WordPress)) {
    throw "That WordPress path does not exist: $WordPress"
}

$pluginsDir = Join-Path $WordPress 'wp-content\plugins'
if (-not (Test-Path $pluginsDir)) {
    throw @"
No wp-content\plugins under: $WordPress

Point -WordPress at the folder that contains wp-config.php, for example:
  C:\laragon\www\mysite
"@
}

$target = Join-Path $pluginsDir $Name

if ($Unlink) {
    if (-not (Test-Path $target)) {
        Write-Host "Nothing linked at $target"
        exit 0
    }

    $item = Get-Item $target -Force
    if ($item.LinkType -ne 'Junction') {
        throw "$target is a real folder, not a junction. Refusing to delete it - remove it yourself if you meant to."
    }

    # Deleting a junction removes the link, not what it points at. Doing it
    # this way rather than Remove-Item -Recurse, which has historically been
    # willing to follow the link.
    [System.IO.Directory]::Delete($target, $false)
    Write-Host "Unlinked $target" -ForegroundColor Green
    exit 0
}

if (Test-Path $target) {
    $item = Get-Item $target -Force

    if ($item.LinkType -eq 'Junction') {
        $current = $item.Target | Select-Object -First 1
        if ($current -eq $root) {
            Write-Host "Already linked: $target -> $root" -ForegroundColor Green
            exit 0
        }
        throw "$target is already a junction pointing somewhere else ($current). Run with -Unlink first."
    }

    throw @"
$target already exists as a real folder.

That is probably a previously uploaded copy of the plugin. Move or delete it
yourself, then run this again - this script will not remove a real folder.
"@
}

# mklink /J is the one that works without elevation.
$null = cmd /c mklink /J "`"$target`"" "`"$root`"" 2>&1
if (-not (Test-Path $target)) {
    throw "Creating the junction failed. Try running the shell as administrator, or copy the folder instead."
}

Write-Host "Linked: $target -> $root" -ForegroundColor Green
Write-Host ''
Write-Host 'Activate "Boz News" on the WordPress Plugins screen. From now on,'
Write-Host 'saving a file in this repo takes effect immediately - no upload.'
Write-Host ''
Write-Host 'Note: WordPress will also see tests/, tools/ and .github/ inside the' -ForegroundColor DarkGray
Write-Host 'plugin folder. Harmless locally, and they are never in the release'  -ForegroundColor DarkGray
Write-Host 'zip - build that with: python tools/build-zip.py'                    -ForegroundColor DarkGray
