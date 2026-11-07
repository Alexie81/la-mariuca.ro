param([int] $IntervalSeconds = 5)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot
$privateDirectory = Join-Path $projectRoot 'storage/private'
$logDirectory = Join-Path $projectRoot 'storage/logs'
New-Item -ItemType Directory -Force -Path $privateDirectory, $logDirectory | Out-Null
$pidFile = Join-Path $privateDirectory 'mail-worker.pid'
if (Test-Path -LiteralPath $pidFile) {
    $workerProcessId = 0
    if ([int]::TryParse([System.IO.File]::ReadAllText($pidFile).Trim(), [ref]$workerProcessId)) {
        $existing = Get-CimInstance Win32_Process -Filter "ProcessId = $workerProcessId" -ErrorAction SilentlyContinue
        if ($existing -and $existing.CommandLine -like '*php-local.ps1*' -and $existing.CommandLine -like '*outbox:work*') {
            Write-Output "Procesorul email ruleaza deja (PID $workerProcessId)."
            exit 0
        }
    }
}
$wrapper = Join-Path $PSScriptRoot 'php-local.ps1'
$console = Join-Path $PSScriptRoot 'console.php'
$worker = Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -WorkingDirectory $projectRoot `
    -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"{0}"' -f $wrapper), ('"{0}"' -f $console), 'outbox:work', [string][Math]::Max(1, [Math]::Min(60, $IntervalSeconds))) `
    -RedirectStandardOutput (Join-Path $logDirectory 'mail-worker.stdout.log') `
    -RedirectStandardError (Join-Path $logDirectory 'mail-worker.stderr.log') -PassThru
[System.IO.File]::WriteAllText($pidFile, [string]$worker.Id)
Write-Output "Procesorul email a fost pornit in fundal (PID $($worker.Id))."
