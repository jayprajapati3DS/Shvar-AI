<#
    Start Shvar AI Copilot.

    This exists because of a PATH collision. There are three PHP installations on
    this machine and Windows picks the wrong one:

        C:\xampp\php                     8.1.25   <- machine PATH, wins
        C:\laragon\bin\php\php-8.1.10    8.1.10   <- user PATH
        C:\php                           8.4.23   <- what this needs, on no PATH

    Laravel 13 requires 8.4, so `php artisan` fails with a platform_check error
    before it starts. The obvious fix - putting C:\php first in the machine PATH -
    would change which PHP every other project on this laptop gets, which is a
    high price for one application.

    So the PATH is set here instead, for this process only. Nothing outside this
    window is affected, and XAMPP and Laragon keep the PHP they expect.

    Usage:  .\start.ps1
    Stop:   Ctrl+C

    If PowerShell refuses to run it ("running scripts is disabled"), allow local
    scripts once, for your account only:

        Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
#>

$ErrorActionPreference = 'Stop'

$phpDir = 'C:\php'
$php = Join-Path $phpDir 'php.exe'

if (-not (Test-Path $php)) {
    Write-Host "PHP 8.4 was not found at $php." -ForegroundColor Red
    Write-Host "Install it there, or edit the `$phpDir line at the top of this script." -ForegroundColor Red
    exit 1
}

# Prepended, not replaced: everything else on the PATH still works, this PHP just
# gets found first.
$env:PATH = "$phpDir;$env:PATH"

# Run from the script's own directory, so it works from anywhere.
Set-Location -Path $PSScriptRoot

$version = (& $php -r 'echo PHP_VERSION;')
Write-Host "PHP $version  ($php)" -ForegroundColor DarkGray

# Ollama is what every AI feature talks to. Not fatal - the rest of the CRM works
# without it and the AI screens say so plainly - but worth knowing up front
# rather than discovering when an analysis fails.
try {
    $null = Invoke-WebRequest -Uri 'http://127.0.0.1:11434/api/tags' -TimeoutSec 3 -UseBasicParsing
    Write-Host 'Ollama    running' -ForegroundColor DarkGray
} catch {
    Write-Host 'Ollama    NOT running - AI features will be unavailable until you start it' -ForegroundColor Yellow
}

Write-Host ''
Write-Host '  Shvar AI Copilot   http://127.0.0.1:8000' -ForegroundColor Cyan
Write-Host '  web server + background AI worker + Vite. Ctrl+C to stop.' -ForegroundColor DarkGray
Write-Host ''

# composer dev, not `artisan serve`: it starts the queue worker too, and without
# one every AI action sits in the queue untouched.
composer dev
