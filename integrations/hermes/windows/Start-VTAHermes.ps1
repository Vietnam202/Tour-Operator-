# Windows-native launcher for VTA Hermes RC6.2 stdio MCP.
# Reads the user-scoped DPAPI credential. Does NOT print secrets or log to stdout.
$ErrorActionPreference = 'Stop'
$root = Join-Path $env:LOCALAPPDATA 'VTA\HermesRC62'
$settingsPath = Join-Path $root 'bridge-settings.json'
$passwordPath = Join-Path $root 'agent-password.dpapi'
$serverPath = Join-Path $root 'vta_mcp_server.py'

if (-not (Test-Path -LiteralPath $settingsPath -PathType Leaf)) { throw 'VTA Hermes settings are missing. Run Install-VTAHermes.ps1.' }
if (-not (Test-Path -LiteralPath $passwordPath -PathType Leaf)) { throw 'VTA Hermes credential is missing. Run Install-VTAHermes.ps1.' }
if (-not (Test-Path -LiteralPath $serverPath -PathType Leaf)) { throw 'VTA Hermes MCP server is missing.' }

$cfg = Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json
if ($cfg.target_environment -ne 'staging' -or $cfg.enable_task_writes -ne '0') {
    throw 'Only staging with task writes disabled is supported by this launcher.'
}
$uri = [Uri]$cfg.api_url
$deny = @('v2quote.vietnamtraveladvisor.com.vn','quote.vietnamtraveladvisor.com.vn','vietnamtraveladvisor.com.vn')
if ($uri.Scheme -ne 'https' -or $uri.AbsolutePath -ne '/api/index.php' -or $deny -contains $uri.Host.ToLowerInvariant()) {
    throw 'Unsafe or invalid RC6.2 target.'
}

$secure = (Get-Content -LiteralPath $passwordPath -Raw).Trim() | ConvertTo-SecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:VTA_API_URL = [string]$cfg.api_url
    $env:VTA_TARGET_ENV = 'staging'
    $env:VTA_AGENT_EMAIL = [string]$cfg.agent_email
    $env:VTA_EXPECTED_COMPANY_ID = [string]$cfg.company_id
    $env:VTA_ENABLE_TASK_WRITES = '0'
    $env:VTA_AGENT_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    $env:PYTHONUTF8 = '1'
    $py = [string]$cfg.python_executable
    $pyArgs = @($cfg.python_args)
    & $py @pyArgs $serverPath
    $code = $LASTEXITCODE
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    Remove-Item Env:\VTA_AGENT_PASSWORD -ErrorAction SilentlyContinue
}
exit $code
