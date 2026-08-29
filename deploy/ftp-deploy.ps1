<#
  ftp-deploy.ps1 - quick code-only FTP push for kapriol.bg
  ---------------------------------------------------------------------------
  Uploads ONLY code files (HTML / CSS / JS / PHP + the admin/ folder) over
  plain FTP (port 21). It NEVER touches the host's live data, photos, video,
  or secrets:
        EXCLUDED:  assets/data/   assets/img/   assets/video/   private/
  so host-side product edits, uploaded photos and the admin password are
  always safe.

  Credentials are read from deploy/ftp.config, which is GITIGNORED because it
  may hold a real secret: if that file contains a "Pass=" line the script uses
  it verbatim and does NOT prompt. Delete the Pass= line to be asked for the
  password on every run instead. deploy/ftp.config.example is the tracked
  template. If ftp.config is missing entirely the script prompts for host /
  user / remote base and offers to save those (never the password).

  Usage (from the project root):
        powershell -ExecutionPolicy Bypass -File deploy/ftp-deploy.ps1
        powershell -ExecutionPolicy Bypass -File deploy/ftp-deploy.ps1 -DryRun
#>

[CmdletBinding()]
param(
    [switch]$DryRun,                       # list what would upload, transfer nothing
    [string]$ConfigPath                    # defaults to deploy/ftp.config (resolved below)
)

$ErrorActionPreference = 'Stop'

# $PSScriptRoot is the folder this script lives in (deploy/). It is NOT reliably
# populated inside the param() defaults on Windows PowerShell 5.1, so resolve
# paths here in the body instead.
$ScriptDir = $PSScriptRoot
if (-not $ScriptDir) { $ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $ConfigPath) { $ConfigPath = Join-Path $ScriptDir 'ftp.config' }

# --- project root (the parent of this deploy/ folder) -----------------------
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path

# --- CODE-ONLY allowlist ----------------------------------------------------
# Individual files at the project root:
$IncludeFiles = @(
    'index.html', 'products.html', 'product.html', 'contact.html',
    'send-mail.php', 'track.php', 'sitemap.php', '.htaccess', 'robots.txt', 'llms.txt',
    # Static, code-managed images (NOT product photos). Listed individually so we
    # never sync the whole assets/img/ tree and clobber host-uploaded photos.
    'assets/img/ergotrade-logo.png',
    'assets/img/logo-kapriol.svg',
    # Site favicon set (code-managed, safe to overwrite).
    'assets/img/favicon.ico',
    'assets/img/favicon-16.png',
    'assets/img/favicon-32.png',
    'assets/img/apple-touch-icon.png'
    # NB favicon-512.png is intentionally NOT here — nothing references it (no web
    # manifest), so it's dead weight on the inode-limited host. If a PWA manifest
    # is added later that needs a 512px icon, re-add it here.
)
# Folders uploaded recursively (these contain no host data):
# NB 'old/' (the FROZEN ARCHIVE of the previous design) is deliberately NOT
# deployed — it was uploaded once with the redesign switch and never changes,
# so re-syncing its ~35 files on every deploy is pure wasted time. If the
# archive ever needs re-uploading, add 'old' back here plus 'old/.htaccess' to
# $IncludeFiles and 'old/assets/video' to $ExcludeDirs.
$IncludeDirs  = @('assets/css', 'assets/js', 'assets/fonts', 'admin', 'error')

# Sub-paths skipped inside $IncludeDirs (relative, forward slashes).
$ExcludeDirs  = @()

# ---------------------------------------------------------------------------
# Load or prompt for credentials
# ---------------------------------------------------------------------------
function Read-Config([string]$path) {
    $cfg = @{}
    if (Test-Path $path) {
        foreach ($line in Get-Content $path) {
            $t = $line.Trim()
            if ($t -eq '' -or $t.StartsWith('#')) { continue }
            $kv = $t -split '=', 2
            if ($kv.Count -eq 2) { $cfg[$kv[0].Trim()] = $kv[1].Trim() }
        }
    }
    return $cfg
}

$cfg = Read-Config $ConfigPath

if (-not $cfg.Host -or -not $cfg.User -or -not $cfg.RemoteBase) {
    Write-Host "No (complete) deploy/ftp.config found - let's set it up." -ForegroundColor Yellow
    if (-not $cfg.Host)       { $cfg.Host       = Read-Host 'FTP host (e.g. ftp.kapriol.bg or the server IP)' }
    if (-not $cfg.User)       { $cfg.User       = Read-Host 'FTP username' }
    if (-not $cfg.RemoteBase) { $cfg.RemoteBase = Read-Host 'Remote target dir (/ for a chrooted subdomain account, else /public_html)' }

    $save = Read-Host 'Save host/user/remote-base to deploy/ftp.config for next time? (y/N)'
    if ($save -match '^(y|yes)$') {
        @(
            "# kapriol.bg FTP deploy config - gitignored, do NOT commit",
            "Host=$($cfg.Host)",
            "User=$($cfg.User)",
            "RemoteBase=$($cfg.RemoteBase)",
            "# Password is NOT stored here; it is prompted each run."
        ) | Set-Content -Path $ConfigPath -Encoding UTF8
        Write-Host "Saved $ConfigPath (password still prompted each run)." -ForegroundColor Green
    }
}

$FtpHost    = $cfg.Host.TrimEnd('/')
$RemoteBase = '/' + $cfg.RemoteBase.Trim('/')
if ($RemoteBase -eq '/') { $RemoteBase = '' }
$User       = $cfg.User

# ---------------------------------------------------------------------------
# Build the file list (relative paths from project root, forward slashes)
# ---------------------------------------------------------------------------
$files = New-Object System.Collections.Generic.List[string]

foreach ($f in $IncludeFiles) {
    $p = Join-Path $Root $f
    if (Test-Path $p) { $files.Add($f) } else { Write-Host "  (skip, not found) $f" -ForegroundColor DarkGray }
}

foreach ($d in $IncludeDirs) {
    $base = Join-Path $Root $d
    if (-not (Test-Path $base)) { Write-Host "  (skip, not found) $d/" -ForegroundColor DarkGray; continue }
    Get-ChildItem -Path $base -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($Root.Length + 1) -replace '\\', '/'
        foreach ($x in $ExcludeDirs) {
            if ($rel.StartsWith("$x/", [System.StringComparison]::OrdinalIgnoreCase)) { return }
        }
        $files.Add($rel)
    }
}

if ($files.Count -eq 0) { throw "Nothing to upload - no matching files found." }

# A path can be reached both explicitly and through a recursive include (e.g.
# test1/.htaccess). Upload each file once.
$files = New-Object System.Collections.Generic.List[string] (,[string[]]($files | Select-Object -Unique))

Write-Host ""
Write-Host "Code-only deploy  ->  ftp://$FtpHost$RemoteBase/" -ForegroundColor Cyan
Write-Host "$($files.Count) files:" -ForegroundColor Cyan
$files | ForEach-Object { Write-Host "  $_" }

if ($DryRun) {
    Write-Host ""
    Write-Host "DRY RUN - nothing was uploaded." -ForegroundColor Yellow
    return
}

Write-Host ""
$go = Read-Host "Upload these $($files.Count) files? (y/N)"
if ($go -notmatch '^(y|yes)$') { Write-Host 'Aborted.' -ForegroundColor Yellow; return }

# Password: taken verbatim from a Pass= line in ftp.config when one exists
# (convenience; that file is gitignored for exactly this reason), else prompted.
if ($cfg.Pass) {
    Write-Host "Using the stored password from $ConfigPath." -ForegroundColor DarkGray
    $Password = $cfg.Pass
} else {
    $sec = Read-Host "FTP password for $User@$FtpHost" -AsSecureString
    $Password = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
        [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
}

# ---------------------------------------------------------------------------
# FTP helpers (plain FTP, passive, binary) using .NET FtpWebRequest
# ---------------------------------------------------------------------------
$cred = New-Object System.Net.NetworkCredential($User, $Password)

function Ensure-FtpDir([string]$remoteDir) {
    if (-not $remoteDir -or $remoteDir -eq '/') { return }
    $uri = "ftp://$FtpHost$remoteDir"
    try {
        $req = [System.Net.FtpWebRequest]::Create($uri)
        $req.Method      = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $req.Credentials = $cred
        $req.UsePassive  = $true
        $req.KeepAlive   = $false
        $resp = $req.GetResponse(); $resp.Close()
    } catch [System.Net.WebException] {
        # 550 = already exists -> fine; rethrow anything else
        $r = $_.Exception.Response
        if (-not ($r -and [int]$r.StatusCode -eq 550 -or "$($_.Exception.Message)" -match '550')) { throw }
    }
}

# Pre-create every remote directory we need, shallow -> deep
$dirs = $files | ForEach-Object {
    $segs = $_.Split('/'); if ($segs.Count -gt 1) { ($segs[0..($segs.Count-2)] -join '/') }
} | Where-Object { $_ } | Sort-Object -Unique

$dirPrefixes = New-Object System.Collections.Generic.HashSet[string]
foreach ($d in $dirs) {
    $acc = ''
    foreach ($seg in $d.Split('/')) {
        $acc = if ($acc) { "$acc/$seg" } else { $seg }
        [void]$dirPrefixes.Add($acc)
    }
}
foreach ($d in ($dirPrefixes | Sort-Object { ($_ -split '/').Count }, { $_ })) {
    Ensure-FtpDir "$RemoteBase/$d"
}

# Upload each file (with retries — shared FTP servers occasionally drop a
# connection mid-handshake: "protocol violation" / "connection was closed").
$maxTries = 3
$ok = 0; $fail = 0
foreach ($rel in $files) {
    $local  = Join-Path $Root ($rel -replace '/', '\')
    $remote = "ftp://$FtpHost$RemoteBase/$rel"
    $bytes  = [System.IO.File]::ReadAllBytes($local)

    $uploaded = $false
    $lastErr  = ''
    for ($try = 1; $try -le $maxTries -and -not $uploaded; $try++) {
        try {
            $req = [System.Net.FtpWebRequest]::Create($remote)
            $req.Method      = [System.Net.WebRequestMethods+Ftp]::UploadFile
            $req.Credentials = $cred
            $req.UseBinary   = $true
            $req.UsePassive  = $true
            $req.KeepAlive   = $false

            $req.ContentLength = $bytes.Length
            $stream = $req.GetRequestStream()
            $stream.Write($bytes, 0, $bytes.Length)
            $stream.Close()
            $resp = $req.GetResponse(); $resp.Close()

            # Verify the remote size matches — a flaky connection can truncate the
            # transfer while the server still reports success (this happened to the
            # logo). If SIZE is unsupported the check is skipped; a real mismatch
            # throws and triggers a retry.
            try {
                $sreq = [System.Net.FtpWebRequest]::Create($remote)
                $sreq.Method      = [System.Net.WebRequestMethods+Ftp]::GetFileSize
                $sreq.Credentials = $cred
                $sreq.UsePassive  = $true
                $sreq.KeepAlive   = $false
                $sresp = $sreq.GetResponse()
                $remoteLen = $sresp.ContentLength
                $sresp.Close()
                if ($remoteLen -ge 0 -and $remoteLen -ne $bytes.Length) {
                    throw "size mismatch (local $($bytes.Length) vs remote $remoteLen) - truncated"
                }
            } catch [System.Net.WebException] {
                # SIZE command not supported by this server -> skip verification
            }
            $uploaded = $true
        } catch {
            $lastErr = $_.Exception.Message
            if ($try -lt $maxTries) {
                Write-Host ("  retry {0}/{1} {2}" -f $try, ($maxTries-1), $rel) -ForegroundColor DarkYellow
                Start-Sleep -Milliseconds 800
            }
        }
    }

    if ($uploaded) {
        Write-Host ("  OK   {0}" -f $rel) -ForegroundColor Green
        $ok++
    } else {
        Write-Host ("  FAIL {0}  -> {1}" -f $rel, $lastErr) -ForegroundColor Red
        $fail++
    }
}

Write-Host ""
Write-Host ("Done. $ok uploaded, $fail failed.") -ForegroundColor $(if ($fail) { 'Yellow' } else { 'Green' })
if ($fail -eq 0) {
    Write-Host "Reminder: bump the ?v=N cache strings on CSS/JS if you changed style.css or main.js." -ForegroundColor DarkCyan
}
