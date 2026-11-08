param([switch] $Run, [int] $Port = 0)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot
$privateDirectory = Join-Path $projectRoot 'storage/private'
$cli = Join-Path $privateDirectory 'stripe-cli/stripe.exe'
$suffix = if ($Port -gt 0) { "-$Port" } else { '' }
$pidFile = Join-Path $privateDirectory "stripe-listener$suffix.pid"
if (-not (Test-Path -LiteralPath $cli)) { throw 'Instaleaza Stripe CLI in storage/private/stripe-cli/stripe.exe.' }

if (-not $Run) {
    if (Test-Path -LiteralPath $pidFile) {
        $listenerId = 0
        if ([int]::TryParse([IO.File]::ReadAllText($pidFile).Trim(), [ref] $listenerId)) {
            $existing = Get-CimInstance Win32_Process -Filter "ProcessId = $listenerId" -ErrorAction SilentlyContinue
            if ($existing -and $existing.CommandLine -like '*start-stripe-listener.ps1*') {
                Write-Output 'Conexiunea Stripe test ruleaza deja.'
                exit 0
            }
        }
    }
    $listener = Start-Process powershell.exe -WindowStyle Hidden -WorkingDirectory $projectRoot -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"{0}"' -f $PSCommandPath), '-Run', '-Port', [string] $Port
    ) -RedirectStandardOutput (Join-Path $projectRoot "storage/logs/stripe-listener$suffix.log") -RedirectStandardError (Join-Path $projectRoot "storage/logs/stripe-listener$suffix-errors.log") -PassThru
    [IO.File]::WriteAllText($pidFile, [string] $listener.Id)
    Write-Output 'Conexiunea Stripe test porneste in fundal.'
    exit 0
}

$settings = @{}
Get-Content -LiteralPath (Join-Path $projectRoot '.env') | ForEach-Object {
    if ($_ -match '^(STRIPE_SECRET_KEY|STRIPE_MODE|APP_ENV|APP_URL|APP_BASE_PATH)=(.*)$') {
        $settings[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
    }
}
if ($settings['STRIPE_MODE'] -ne 'test' -or $settings['STRIPE_SECRET_KEY'] -notmatch '^(sk|rk)_test_') {
    throw 'Acest listener accepta exclusiv chei Stripe de test.'
}
if ($settings['APP_ENV'] -eq 'production') { throw 'Listenerul local nu se foloseste in productie.' }
$target = $settings['APP_URL'].TrimEnd('/') + '/' + $settings['APP_BASE_PATH'].Trim('/')
$target = $target.TrimEnd('/') + '/stripe/webhook'
$targetUri = [Uri] $target
if ($targetUri.Host -notin @('localhost', '127.0.0.1', '::1')) { throw 'Listenerul local necesita APP_URL localhost.' }
if ($Port -gt 0) { $builder = New-Object UriBuilder($target); $builder.Port = $Port; $target = $builder.Uri.AbsoluteUri }
$env:STRIPE_API_KEY = $settings['STRIPE_SECRET_KEY']
try {
    # Windows PowerShell wraps native stderr progress messages as non-terminating errors.
    $ErrorActionPreference = 'Continue'
    $secretOutput = & $cli listen --print-secret 2>&1
    $secret = ($secretOutput | ForEach-Object { if ([string] $_ -match '(whsec_[A-Za-z0-9]+)') { $matches[1] } } | Select-Object -First 1)
    if (-not $secret) { throw 'Nu s-a putut obtine semnatura webhook Stripe test. Verifica cheia si conexiunea.' }
    [IO.File]::WriteAllText((Join-Path $privateDirectory 'stripe-webhook-secret'), $secret)
    & $cli listen --events 'checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.expired,checkout.session.async_payment_failed,payment_intent.canceled' --forward-to $target 2>&1 | ForEach-Object {
        $line = [string] $_
        if ($line -match 'Ready!') { Write-Output 'Stripe test: conexiune activa, semnatura salvata privat.' }
        elseif ($line -notmatch '(whsec_|sk_test_|rk_test_)') { Write-Output $line }
    }
} finally {
    $ErrorActionPreference = 'Stop'
    Remove-Item Env:STRIPE_API_KEY -ErrorAction SilentlyContinue
}
