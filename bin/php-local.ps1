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

$caCandidates = @(
    $env:SSL_CERT_FILE,
    (Join-Path $phpDir 'extras\ssl\cacert.pem'),
    'C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt',
    'C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt'
)
$caBundle = $caCandidates |
    Where-Object { $_ -is [string] -and $_ -ne '' -and (Test-Path -LiteralPath $_) } |
    Select-Object -First 1
if ($caBundle) {
    $extensionArgs += @('-d', "curl.cainfo=$caBundle", '-d', "openssl.cafile=$caBundle")
}

& $phpExe -d "extension_dir=$extensionDir" @extensionArgs @PhpArgs
exit $LASTEXITCODE
