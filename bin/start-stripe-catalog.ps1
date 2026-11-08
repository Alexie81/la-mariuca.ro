param([switch] $Run)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot
$pidFile = Join-Path $projectRoot 'storage/private/stripe-catalog.pid'
if ($Run) {
    Set-Location -LiteralPath $projectRoot
    & (Join-Path $PSScriptRoot 'php-local.ps1') bin/stripe-catalog.php work 20
    exit $LASTEXITCODE
}
if (Test-Path -LiteralPath $pidFile) {
    $workerId = 0
    if ([int]::TryParse([IO.File]::ReadAllText($pidFile).Trim(), [ref] $workerId)) {
        $existing = Get-CimInstance Win32_Process -Filter "ProcessId = $workerId" -ErrorAction SilentlyContinue
        if ($existing -and $existing.CommandLine -like '*start-stripe-catalog.ps1*') {
            Write-Output 'Sincronizarea catalogului Stripe ruleaza deja.'
            exit 0
        }
    }
}
$worker = Start-Process powershell.exe -WindowStyle Hidden -WorkingDirectory $projectRoot -ArgumentList @(
    '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"{0}"' -f $PSCommandPath), '-Run'
) -RedirectStandardOutput (Join-Path $projectRoot 'storage/logs/stripe-catalog.log') -RedirectStandardError (Join-Path $projectRoot 'storage/logs/stripe-catalog-errors.log') -PassThru
[IO.File]::WriteAllText($pidFile, [string] $worker.Id)
Write-Output 'Sincronizarea catalogului Stripe porneste in fundal.'
