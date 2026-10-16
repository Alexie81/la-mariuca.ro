$ErrorActionPreference = 'Stop'
$adminEmail = Read-Host 'Email administrator'
$adminFirstName = Read-Host 'Prenume'
$adminLastName = Read-Host 'Nume'
$securePassword = Read-Host 'Parolă (minimum 14 caractere)' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)

try {
    $env:ADMIN_EMAIL = $adminEmail
    $env:ADMIN_FIRST_NAME = $adminFirstName
    $env:ADMIN_LAST_NAME = $adminLastName
    $env:ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    & (Join-Path $PSScriptRoot 'php-local.ps1') (Join-Path (Split-Path $PSScriptRoot) 'bin/console.php') 'admin:create'
    exit $LASTEXITCODE
}
finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Item Env:ADMIN_EMAIL,Env:ADMIN_FIRST_NAME,Env:ADMIN_LAST_NAME,Env:ADMIN_PASSWORD -ErrorAction SilentlyContinue
}
