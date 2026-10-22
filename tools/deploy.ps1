#Requires -Version 7.0
<#
.SYNOPSIS
    Build and deploy REG.RU Control to a server directory.

.DESCRIPTION
    Reads deployment settings from `.env.deploy` (gitignored, see
    `.env.deploy.example`), builds the deployable tree with tools/build.php,
    packs it without .env/var, uploads over ssh and unpacks it. Existing .env
    and var/ are preserved; the previous release is backed up on the server.

.EXAMPLE
    pwsh -NoProfile -File tools/deploy.ps1
    pwsh -NoProfile -File tools/deploy.ps1 -NoNginx
    pwsh -NoProfile -File tools/deploy.ps1 -ConfigFile C:\secrets\deploy.env
#>
[CmdletBinding()]
param(
    [string]$ConfigFile = (Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) '.env.deploy'),
    [switch]$NoNginx,
    [switch]$NoBuild
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.Encoding]::UTF8

$repo = Split-Path -Parent $PSScriptRoot
$workspace = Split-Path -Parent $repo
$stage = Join-Path $workspace 'build/prod'
$tarPath = Join-Path $workspace 'build/regru-control.tar'

# --- configuration -----------------------------------------------------------

$config = @{}

if (Test-Path $ConfigFile) {
    foreach ($line in Get-Content $ConfigFile) {
        $line = $line.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { continue }
        $index = $line.IndexOf('=')
        if ($index -lt 1) { continue }
        $config[$line.Substring(0, $index).Trim()] = $line.Substring($index + 1).Trim().Trim('"')
    }
} else {
    Write-Warning "Config file not found: $ConfigFile (copy .env.deploy.example)"
}

function Value([string]$name, [string]$default = '') {
    $envValue = [Environment]::GetEnvironmentVariable($name)
    if ($envValue) { return $envValue }
    if ($config.ContainsKey($name) -and $config[$name] -ne '') { return $config[$name] }
    return $default
}

$Server = Value 'REGRU_HOST'
$User = Value 'REGRU_SSH_USER' 'root'
$Key = Value 'REGRU_SSH_KEY'
$RemotePath = Value 'REGRU_REMOTE_PATH'
$SiteUser = Value 'REGRU_SITE_USER' 'site_user'
$AppUrl = Value 'REGRU_APP_URL'
$PhpBin = Value 'REGRU_PHP_BIN' '/usr/bin/php'
$FpmSocket = Value 'REGRU_FPM_SOCKET'
$AllowCidr = Value 'REGRU_ALLOW_CIDR'
$AllowIp = Value 'REGRU_ALLOW_IP'

if (-not $Server) { throw 'REGRU_HOST is required in .env.deploy' }
if (-not $Key) { throw 'REGRU_SSH_KEY is required in .env.deploy' }
if (-not $RemotePath) { throw 'REGRU_REMOTE_PATH is required in .env.deploy' }
if (-not $AppUrl) { throw 'REGRU_APP_URL is required in .env.deploy' }
if (-not $NoNginx -and -not $FpmSocket) { throw 'REGRU_FPM_SOCKET is required for the nginx step (or pass -NoNginx)' }

$ssh = 'C:\Program Files\Git\usr\bin\ssh.exe'
$scp = 'C:\Program Files\Git\usr\bin\scp.exe'
$tar = 'C:\Program Files\Git\usr\bin\tar.exe'
$php = 'C:\tools\php85\php.exe'

foreach ($tool in @($ssh, $scp, $tar, $php)) {
    if (-not (Test-Path $tool)) { throw "Tool not found: $tool" }
}
if (-not (Test-Path $Key)) { throw "SSH key not found: $Key" }

function Invoke-Remote {
    param([string]$Script, [string]$Title)
    $local = Join-Path ([IO.Path]::GetTempPath()) ('regru-remote-' + [guid]::NewGuid().ToString('N') + '.sh')
    [IO.File]::WriteAllText($local, ($Script -replace "`r`n", "`n"), [Text.UTF8Encoding]::new($false))
    $remote = '/tmp/' + (Split-Path -Leaf $local)
    & $scp -q -i $Key -o BatchMode=yes $local "${User}@${Server}:$remote"
    if ($LASTEXITCODE -ne 0) { Remove-Item $local -ErrorAction SilentlyContinue; throw "scp failed ($Title)" }
    & $ssh -i $Key -o BatchMode=yes "${User}@${Server}" "bash $remote; rc=`$?; rm -f $remote; exit `$rc"
    $code = $LASTEXITCODE
    Remove-Item $local -ErrorAction SilentlyContinue
    if ($code -ne 0) { throw "Remote step failed ($Title), exit $code" }
}

Write-Host '==> Build' -ForegroundColor Cyan
if (-not $NoBuild) {
    if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
    & $php (Join-Path $repo 'tools/build.php') "--target=$stage"
    if ($LASTEXITCODE -ne 0) { throw 'Build failed' }
}

Write-Host '==> Pack' -ForegroundColor Cyan
New-Item -ItemType Directory -Force (Split-Path $tarPath) | Out-Null
if (Test-Path $tarPath) { Remove-Item $tarPath -Force }
& $tar --force-local -cf $tarPath -C $stage '--exclude=.env' '--exclude=var' '--exclude=.regru-build' '.'
if ($LASTEXITCODE -ne 0) { throw 'tar failed' }
Write-Host ("    archive: {0:N1} KiB" -f ((Get-Item $tarPath).Length / 1KB))

Write-Host '==> Upload' -ForegroundColor Cyan
& $scp -q -i $Key -o BatchMode=yes $tarPath "${User}@${Server}:/tmp/regru-control.tar"
if ($LASTEXITCODE -ne 0) { throw 'upload failed' }

Write-Host '==> Unpack' -ForegroundColor Cyan
$unpack = @'
set -euo pipefail
APP="__APP__"
TAR="/tmp/regru-control.tar"
STAMP=$(date +%Y%m%d-%H%M%S)
APP_URL="__APP_URL__"

if [ -d "$APP" ] && [ -f "$APP/index.php" ]; then
    mkdir -p /root/regru-backup
    tar -czf "/root/regru-backup/regru-$STAMP.tgz" -C "$APP" --exclude=var --exclude=.env . || true
    echo "backup: /root/regru-backup/regru-$STAMP.tgz"
fi

mkdir -p "$APP"
tar -xf "$TAR" -C "$APP"
mkdir -p "$APP/var/log" "$APP/var/sessions" "$APP/var/backups"

if [ ! -f "$APP/.env" ]; then
    chown -R __USER__:__USER__ "$APP"
    su -s /bin/bash __USER__ -c "cd $APP && __PHP__ backend/bin/regru key:generate"
    sed -i \
        -e 's|^APP_ENV=.*|APP_ENV=production|' \
        -e 's|^APP_DEBUG=.*|APP_DEBUG=0|' \
        -e "s|^APP_URL=.*|APP_URL=$APP_URL|" \
        -e 's|^SESSION_SECURE=.*|SESSION_SECURE=1|' \
        "$APP/.env"
    echo "created .env"
fi

chown -R __USER__:__USER__ "$APP"
find "$APP" -type d -exec chmod 755 {} \;
find "$APP" -type f -exec chmod 644 {} \;
chmod 600 "$APP/.env"
chmod -R 770 "$APP/var"
rm -f "$TAR"
echo DEPLOY_OK
'@
$unpack = $unpack.Replace('__APP__', $RemotePath).Replace('__USER__', $SiteUser).Replace('__PHP__', $PhpBin).Replace('__APP_URL__', $AppUrl)
Invoke-Remote -Script $unpack -Title 'unpack'

Write-Host '==> Migrate' -ForegroundColor Cyan
Invoke-Remote -Script @"
set -euo pipefail
su -s /bin/bash $SiteUser -c "cd $RemotePath && $PhpBin backend/bin/regru migrate"
su -s /bin/bash $SiteUser -c "cd $RemotePath && $PhpBin backend/bin/regru doctor" || true
"@ -Title 'migrate'

if (-not $NoNginx) {
    Write-Host '==> nginx' -ForegroundColor Cyan
    $nginxBlock = @'
set -euo pipefail
INC="__INCLUDES__"
MARK="# --- REG.RU Control ---"

if ! grep -qF "$MARK" "$INC"; then
    cp "$INC" "/root/regru-backup/$(basename "$INC").$(date +%Y%m%d-%H%M%S)"
    cat >> "$INC" <<NGINX

$MARK
# Панель управления доменами, DNS и хостингами.
# Доступ ограничен списком сетей/адресов; внутри хранятся пароли.
location = __PREFIX__ {
    return 301 __PREFIX__/;
}

location ^~ __PREFIX__/ {
__ALLOW__
    deny all;

    index index.php;
    try_files $uri $uri/ __PREFIX__/index.php?$query_string;

    location ~ __PREFIX__/\.(?!well-known/) {
        deny all;
    }

    location ~ \.php$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_pass unix:__SOCKET__;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }
}

location ^~ __PREFIX__/backend/  { deny all; }
location ^~ __PREFIX__/frontend/ { deny all; }
location ^~ __PREFIX__/var/      { deny all; }
location ^~ __PREFIX__/docs/     { deny all; }
NGINX
    chown __USER__:__USER__ "$INC" || true
    echo "nginx block appended"
else
    echo "nginx block already present"
fi

nginx -t
systemctl reload nginx
echo NGINX_OK
'@
    $allow = '    allow 127.0.0.1;'
    if ($AllowCidr) { $allow = "    allow $AllowCidr;`n    allow 127.0.0.1;" }
    if ($AllowIp) { $allow = $allow + "`n    allow $AllowIp;" }

    $nginxBlock = $nginxBlock
        .Replace('__INCLUDES__', (Value 'REGRU_NGINX_INCLUDES' '/etc/nginx/sites-enabled/regru-control.conf'))
        .Replace('__PREFIX__', (Value 'REGRU_URL_PREFIX' '/regru'))
        .Replace('__SOCKET__', $FpmSocket)
        .Replace('__USER__', $SiteUser)
        .Replace('__ALLOW__', $allow)

    Invoke-Remote -Script $nginxBlock -Title 'nginx'
}

Write-Host '==> Health check' -ForegroundColor Cyan
$health = $null
for ($attempt = 1; $attempt -le 5; $attempt++) {
    try {
        $health = Invoke-WebRequest -Uri ($AppUrl.TrimEnd('/') + '/api/v1/health') -TimeoutSec 15 -SkipHttpErrorCheck
        if ($health.StatusCode -eq 200) { break }
    } catch {
        Start-Sleep -Seconds 2
    }
}
if ($health -and $health.StatusCode -eq 200) {
    Write-Host "    $($health.Content)" -ForegroundColor Green
} else {
    Write-Warning 'Health check did not return 200; check nginx error log and .env'
}

Write-Host "==> Done: $AppUrl" -ForegroundColor Green
