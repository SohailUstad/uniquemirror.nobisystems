[CmdletBinding()]
param(
    [string]$TargetRoot = 'C:\xampp\htdocs\billing-system-laravel',
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-FullPath([string]$Path) {
    return [IO.Path]::GetFullPath((Resolve-Path -LiteralPath $Path -ErrorAction Stop).Path)
}
function Test-UnderPath([string]$Path, [string]$Root) {
    $prefix = $Root.TrimEnd('\') + '\'
    return $Path.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}
function Copy-ReleaseFile([string]$RelativePath) {
    $source = Join-Path $script:Source $RelativePath
    if (!(Test-Path -LiteralPath $source -PathType Leaf)) { throw "Required release input missing: $RelativePath" }
    $destination = Join-Path $script:Stage $RelativePath
    $parent = Split-Path -Parent $destination
    [void](New-Item -ItemType Directory -Path $parent -Force)
    Copy-Item -LiteralPath $source -Destination $destination -Force
}

$expectedSource = [IO.Path]::GetFullPath('C:\xampp\htdocs\billing-system-laravel').TrimEnd('\')
$source = Get-FullPath $TargetRoot
if (!$source.Equals($expectedSource, [StringComparison]::OrdinalIgnoreCase)) {
    throw "TargetRoot is bounded to the configured Laravel target: $expectedSource"
}
if (!(Test-Path -LiteralPath (Join-Path $source 'artisan') -PathType Leaf) -or !(Test-Path -LiteralPath (Join-Path $source 'composer.lock') -PathType Leaf)) {
    throw 'Target does not look like the expected Laravel application.'
}
$packageRoot = Get-FullPath (Split-Path -Parent $PSScriptRoot)
if ([string]::IsNullOrWhiteSpace($OutputPath)) { $OutputPath = Join-Path $packageRoot 'release-artifacts\billing-system-laravel-hostinger.zip' }
$outputFull = [IO.Path]::GetFullPath($OutputPath)
$outputParent = [IO.Path]::GetFullPath((Split-Path -Parent $outputFull))
if (!(Test-UnderPath $outputParent $packageRoot)) { throw 'OutputPath must remain inside hostinger_package.' }
if ($outputFull -match '(?i)\.env($|\.)|credentials?|\.git') { throw 'Unsafe archive output filename.' }

$artifactRoot = Join-Path $packageRoot 'release-artifacts'
[void](New-Item -ItemType Directory -Path $artifactRoot -Force)
$stage = Join-Path $artifactRoot ('.stage-' + [Guid]::NewGuid().ToString('N'))
[void](New-Item -ItemType Directory -Path $stage -Force)
$script:Source = $source
$script:Stage = $stage
$archiveValidated = $false
try {
    # Application source only. Runtime storage, including uploaded media, stays on the host.
    $allowedDirectories = @('app','bootstrap','config','database','resources','routes')
    foreach ($directory in $allowedDirectories) {
        $sourceDir = Join-Path $source $directory
        if (!(Test-Path -LiteralPath $sourceDir -PathType Container)) { throw "Required directory missing: $directory" }
        Get-ChildItem -LiteralPath $sourceDir -File -Recurse -Force | ForEach-Object {
            if ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) { return }
            $relative = $_.FullName.Substring($source.Length + 1).Replace('\','/')
            if ($relative -match '(?i)(^|/)(\.git|vendor|tests?|node_modules|coverage|\.phpunit\.cache|cache|sessions|testing|logs?|storage)(/|$)' -or
                $relative -match '(?i)(^|/)\.env($|\.)|\.log$|\.cache$|\.sqlite$|\.sql$|\.dump$|\.bak$|\.orig$|\.swp$') { return }
            $destination = Join-Path $stage ($relative.Replace('/', '\'))
            [void](New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force)
            Copy-Item -LiteralPath $_.FullName -Destination $destination
        }
    }
    $vocabRelative = 'resources/tiktoken/9b5ad71b2ce5302211f9c61530b329a4922fc6a4'
    $vocabFile = Join-Path $stage ($vocabRelative.Replace('/', '\'))
    if (!(Test-Path -LiteralPath $vocabFile -PathType Leaf)) { throw 'Bundled cl100k vocabulary is missing.' }
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $vocabFile).Hash.ToLowerInvariant() -ne '223921b76ee99bde995b7ff738513eef100fb51d18c93597a113bcffe865b2a7') {
        throw 'Bundled cl100k vocabulary hash does not match the pinned tokenizer.'
    }

    # Public web root: only known entry files and static web assets are eligible.
    $publicRoot = Join-Path $source 'public'
    foreach ($relative in @('index.php','.htaccess','favicon.ico','robots.txt')) {
        $file = Join-Path $publicRoot $relative
        if (Test-Path -LiteralPath $file -PathType Leaf) {
            $dest = Join-Path $stage ('public\' + $relative)
            [void](New-Item -ItemType Directory -Path (Split-Path -Parent $dest) -Force)
            Copy-Item -LiteralPath $file -Destination $dest
        }
    }
    Get-ChildItem -LiteralPath $publicRoot -File -Recurse -Force | ForEach-Object {
        if ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) { return }
        $relative = $_.FullName.Substring($publicRoot.Length + 1).Replace('\','/')
        if ($relative -match '(?i)(^|/)(storage|\.well-known|\.git)(/|$)' -or
            $_.Extension -notmatch '(?i)^\.(css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|otf|txt|xml)$') { return }
        $dest = Join-Path $stage ('public\' + $relative.Replace('/','\'))
        [void](New-Item -ItemType Directory -Path (Split-Path -Parent $dest) -Force)
        Copy-Item -LiteralPath $_.FullName -Destination $dest
    }

    foreach ($file in @('artisan','composer.json','composer.lock')) { Copy-ReleaseFile $file }
    foreach ($file in @('.env.hostinger.example','README.md','config','public','scripts')) {
        $from = Join-Path $packageRoot $file
        $to = Join-Path $stage ('hostinger-package\' + $file)
        if (Test-Path -LiteralPath $from -PathType Container) {
            Copy-Item -LiteralPath $from -Destination $to -Recurse -Force
        } elseif (Test-Path -LiteralPath $from -PathType Leaf) {
            [void](New-Item -ItemType Directory -Path (Split-Path -Parent $to) -Force)
            Copy-Item -LiteralPath $from -Destination $to -Force
        } else { throw "Package input missing: $file" }
    }

    # Remove known unsafe paths and reject any accidental credentials or local .env values.
    $files = @(Get-ChildItem -LiteralPath $stage -File -Recurse -Force)
    foreach ($f in $files) {
        $rel = $f.FullName.Substring($stage.Length + 1).Replace('\','/')
        if ($rel -match '(?i)(^|/)(\.env(?!\.hostinger\.example(?:$|/))(?:$|\.)|\.git|vendor|tests?|node_modules|coverage|cache|logs?|sessions|testing|storage)(/|$)|(^|/)\.env(?!\.hostinger\.example(?:$|/))(?:$|\.)|\.log$|\.cache$') {
            throw "Forbidden path in staged release: $rel"
        }
        if ($f.Extension -match '(?i)^\.(php|json|xml|ya?ml|ini|conf|txt|sh|md|html|css|js)$') {
            $text = [IO.File]::ReadAllText($f.FullName)
            if ($text -match '(?im)(-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|\bAKIA[0-9A-Z]{16}\b|(?<![A-Z_])(DB_PASSWORD|MYSQL_ROOT_PASSWORD|APP_KEY|LEGACY_JWT_SECRET|FCM_PRIVATE_KEY)\s*[=:]\s*(?!CHANGE_ME(?:\s|$))[^\s"'']{5,})') {
                throw "Credential-like content found in release file: $rel"
            }
        }
    }
    $localEnv = Join-Path $source '.env'
    if (Test-Path -LiteralPath $localEnv -PathType Leaf) {
        foreach ($line in [IO.File]::ReadAllLines($localEnv)) {
            if ($line -match '^\s*(?i:DB_PASSWORD|MYSQL_ROOT_PASSWORD|APP_KEY|.*SECRET.*|.*TOKEN.*|.*PRIVATE_KEY)\s*=\s*(.+?)\s*$') {
                $value = $Matches[1].Trim('"',"'")
                if ($value.Length -ge 6) {
                    foreach ($f in $files) {
                        if ($f.Extension -match '(?i)^\.(php|json|xml|ya?ml|ini|conf|txt|sh|md|html|css|js)$' -and [IO.File]::ReadAllText($f.FullName).Contains($value)) {
                            throw "A value from the local .env appears in release file: $($f.FullName.Substring($stage.Length + 1))"
                        }
                    }
                }
            }
        }
    }
    if (!(Test-Path -LiteralPath (Join-Path $stage 'composer.lock')) -or !(Test-Path -LiteralPath (Join-Path $stage 'hostinger-package\scripts\deploy.sh'))) {
        throw 'Release is missing composer.lock or Hostinger deployment scripts.'
    }

    if (Test-Path -LiteralPath $outputFull) { Remove-Item -LiteralPath $outputFull -Force }
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::Open($outputFull, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in @(Get-ChildItem -LiteralPath $stage -File -Recurse -Force)) {
            $entryName = $file.FullName.Substring($stage.Length + 1).Replace('\','/')
            [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $entryName, [IO.Compression.CompressionLevel]::Optimal)
        }
    } finally { $archive.Dispose() }
    $zip = [IO.Compression.ZipFile]::OpenRead($outputFull)
    try {
        $entries = @($zip.Entries)
        foreach ($required in @('public/.htaccess','public/index.php','hostinger-package/.env.hostinger.example','hostinger-package/public/.htaccess','hostinger-package/public/index.php','hostinger-package/scripts/deploy.sh','hostinger-package/scripts/mariadb-preflight.php','composer.lock',$vocabRelative)) {
            if ($entries.FullName -notcontains $required) { throw "Required archive entry is missing: $required" }
        }
        if (!$entries -or $entries.FullName -match '(?i)(^|/)(\.env(?!\.hostinger\.example(?:$|/))(?:$|\.)|\.git|vendor|tests?|node_modules|cache|logs?|sessions|testing|storage)(/|$)|\.log$|\.cache$') {
            throw 'Archive inspection found a forbidden path.'
        }
        foreach ($entry in $entries) {
            if ($entry.Length -gt 0 -and $entry.FullName -match '(?i)\.(php|json|xml|ya?ml|ini|conf|txt|sh|md|html|css|js)$') {
                $reader = [IO.StreamReader]::new($entry.Open())
                try { $text = $reader.ReadToEnd() } finally { $reader.Dispose() }
                if ($text -match '(?im)(-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|\bAKIA[0-9A-Z]{16}\b|(?<![A-Z_])(DB_PASSWORD|MYSQL_ROOT_PASSWORD|APP_KEY|LEGACY_JWT_SECRET|FCM_PRIVATE_KEY)\s*[=:]\s*(?!CHANGE_ME(?:\s|$))[^\s"'']{5,})') { throw "Credential-like content in archive entry: $($entry.FullName)" }
            }
        }
        Write-Output "Created and inspected release archive: $outputFull ($($entries.Count) entries)"
        $archiveValidated = $true
    } finally { $zip.Dispose() }
} finally {
    if (!$archiveValidated -and (Test-Path -LiteralPath $outputFull -PathType Leaf)) {
        Remove-Item -LiteralPath $outputFull -Force
    }
    if (Test-Path -LiteralPath $stage -PathType Container) {
        $resolvedStage = (Resolve-Path -LiteralPath $stage).Path
        if (!(Test-UnderPath $resolvedStage $artifactRoot)) { throw 'Refusing to remove staging path outside release-artifacts.' }
        Remove-Item -LiteralPath $resolvedStage -Recurse -Force
    }
}
