param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpArgs
)

$phpExe = (Get-Command php -ErrorAction Stop).Source
$phpDir = Split-Path $phpExe
$extensionDir = Join-Path $phpDir 'ext'
$extensions = @('openssl', 'curl', 'zip', 'mbstring', 'pdo_sqlite', 'pdo_mysql', 'fileinfo', 'gd', 'sodium')
$extensionArgs = @()
foreach ($extension in $extensions) {
    $dll = Join-Path $extensionDir ("php_{0}.dll" -f $extension)
    if (Test-Path -LiteralPath $dll) {
        $extensionArgs += @('-d', "extension=$extension")
    }
}

& $phpExe -d "extension_dir=$extensionDir" @extensionArgs @PhpArgs
exit $LASTEXITCODE
