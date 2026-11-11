param(
  [string]$PackageRoot = (Split-Path -Parent $PSScriptRoot)
)
$ErrorActionPreference = 'Stop'
$package = (Resolve-Path $PackageRoot).Path
$checks = @(
  @{ Path = 'public/index.php'; Contains = "dirname(__DIR__) . '/billing-system-laravel'" },
  @{ Path = 'public/.htaccess'; Contains = 'RewriteRule ^ index.php [L]' },
  @{ Path = 'public/.htaccess'; Contains = 'Options -Indexes' },
  @{ Path = 'scripts/deploy.sh'; Contains = 'install --no-dev --prefer-dist' },
  @{ Path = 'scripts/deploy.sh'; Contains = '$PACKAGE_ROOT/public/index.php' },
  @{ Path = 'scripts/deploy.sh'; Contains = '$PACKAGE_ROOT/public/.htaccess' },
  @{ Path = 'scripts/build-release.ps1'; Contains = 'C:\xampp\htdocs\billing-system-laravel' },
  @{ Path = 'scripts/build-release.ps1'; Contains = 'Credential-like content' },
  @{ Path = 'scripts/build-release.ps1'; Contains = 'composer.lock' },
  @{ Path = 'scripts/mariadb-preflight.php'; Contains = 'CREATE TEMPORARY TABLE' },
  @{ Path = 'scripts/mariadb-preflight.php'; Contains = 'DROP TEMPORARY TABLE IF EXISTS' },
  @{ Path = 'scripts/mariadb-preflight.php'; Contains = 'details suppressed to protect credentials' },
  @{ Path = '.env.hostinger.example'; Contains = 'DB_CONNECTION=mysql' },
  @{ Path = '.env.hostinger.example'; Contains = 'LEGACY_JWT_SECRET=CHANGE_ME' },
  @{ Path = 'README.md'; Contains = '/files/{filename}' }
)
foreach ($check in $checks) {
  $file = Join-Path $package $check.Path
  if (!(Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing package file: $($check.Path)" }
  $content = Get-Content -Raw -LiteralPath $file
  if (!$content.Contains($check.Contains)) { throw "Expected text missing from $($check.Path): $($check.Contains)" }
}
$envTemplate = Get-Content -Raw (Join-Path $package '.env.hostinger.example')
if ($envTemplate -match '(?im)^(APP_KEY|DB_PASSWORD)=(?!CHANGE_ME$).+') { throw 'Template must not contain populated secrets.' }
if ($envTemplate -match '(?im)^LEGACY_JWT_SECRET=(?!CHANGE_ME$).+') { throw 'Template must not contain a JWT signing secret.' }
$index = Get-Content -Raw (Join-Path $package 'public/index.php')
if ($index -match 'vendor/autoload\.php' -and $index -notmatch '\$appRoot . ''/vendor/autoload\.php''') { throw 'Public entrypoint must load autoload from private app root.' }
Write-Output "Hostinger package validation passed ($($checks.Count) file/content checks)."
