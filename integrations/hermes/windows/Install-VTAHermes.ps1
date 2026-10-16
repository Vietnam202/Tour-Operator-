<#
.SYNOPSIS
Installs the RC6.2 Hermes MCP bridge for a Windows-native Hermes installation.
.DESCRIPTION
Copies the bridge and skill into user-local folders, stores a password via
Windows DPAPI and registers one stdio server without changing production.
.EXAMPLE
.\Install-VTAHermes.ps1 -StagingApiUrl https://staging.example.com/api/index.php -AgentEmail hermes-staging@example.com -CompanyId 12
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$StagingApiUrl,
    [Parameter(Mandatory=$true)][string]$AgentEmail,
    [Parameter(Mandatory=$true)][ValidateRange(1,2147483647)][int]$CompanyId,
    [switch]$SkipHermesRegistration
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$uri = $null
if (-not [Uri]::TryCreate($StagingApiUrl,[UriKind]::Absolute,[ref]$uri)) {
    throw 'Invalid staging API URL.'
}
$deny = @('v2quote.vietnamtraveladvisor.com.vn','quote.vietnamtraveladvisor.com.vn','vietnamtraveladvisor.com.vn')
if ($uri.Scheme -ne 'https' -or $uri.AbsolutePath -ne '/api/index.php' -or
    $uri.UserInfo -ne '' -or $uri.Query -ne '' -or $uri.Fragment -ne '' -or
    $deny -contains $uri.Host.ToLowerInvariant()) {
    throw 'The bridge accepts only a dedicated HTTPS staging /api/index.php endpoint.'
}
if ($AgentEmail -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') {
    throw 'Invalid staging agent email.'
}
$check = Read-Host 'Confirm independent staging DB/docroot and staff account: type ISOLATED-STAGING'
if ($check -cne 'ISOLATED-STAGING') { throw 'Operator did not attest isolated staging.' }

$source = Split-Path -Parent $PSScriptRoot
$client = Join-Path $source 'vta_mcp_server.py'
$launcher = Join-Path $PSScriptRoot 'Start-VTAHermes.ps1'
$skill = Join-Path $source 'SKILL.md'
foreach ($path in @($client,$launcher,$skill)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw ('Required source file missing: ' + $path)
    }
}
$pythonCommand = Get-Command python.exe -ErrorAction SilentlyContinue
$pythonArgs = @()
if ($pythonCommand) {
    $pythonExe = $pythonCommand.Source
} else {
    $pythonCommand = Get-Command py.exe -ErrorAction SilentlyContinue
    if (-not $pythonCommand) { throw 'Python 3.11+ is required on Windows.' }
    $pythonExe = $pythonCommand.Source
    $pythonArgs = @('-3')
}
$versionOutput = & $pythonExe @pythonArgs -c 'import sys; print(str(sys.version_info.major)+"."+str(sys.version_info.minor))'
if ($LASTEXITCODE -ne 0 -or [version]$versionOutput -lt [version]'3.11') {
    throw 'Python 3.11+ is required.'
}

$root = Join-Path $env:LOCALAPPDATA 'VTA\HermesRC62'
$skillTarget = Join-Path $HOME '.hermes\skills\vta-rc62-operations'
New-Item -ItemType Directory -Force -Path $root | Out-Null
New-Item -ItemType Directory -Force -Path $skillTarget | Out-Null

$pw = Read-Host 'Enter dedicated STAGING agent password' -AsSecureString
if ($pw.Length -lt 8) { throw 'Empty/short staging password refused.' }
$sealed = $pw | ConvertFrom-SecureString
# On native Windows, default ConvertFrom-SecureString uses user-scoped DPAPI.
Set-Content -LiteralPath (Join-Path $root 'agent-password.dpapi') -Value $sealed -NoNewline
$cfg = @{
    api_url = $StagingApiUrl.TrimEnd('/')
    target_environment = 'staging'
    agent_email = $AgentEmail
    company_id = $CompanyId
    python_executable = $pythonExe
    python_args = $pythonArgs
    enable_task_writes = '0'
}
$cfg | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $root 'bridge-settings.json') -Encoding UTF8

Copy-Item -LiteralPath $client -Destination (Join-Path $root 'vta_mcp_server.py') -Force
Copy-Item -LiteralPath $launcher -Destination (Join-Path $root 'Start-VTAHermes.ps1') -Force
$skillDestination = Join-Path $skillTarget 'SKILL.md'
if (-not (Test-Path -LiteralPath $skillDestination)) {
    Copy-Item -LiteralPath $skill -Destination $skillDestination
} else {
    Write-Host 'Existing VTA skill preserved (not overwritten).'
}
& $pythonExe @pythonArgs -m py_compile (Join-Path $root 'vta_mcp_server.py')
if ($LASTEXITCODE -ne 0) { throw 'Python validation failed.' }

$installedLauncher = (Join-Path $root 'Start-VTAHermes.ps1').Replace('\','/')
$snippet = @"
mcp_servers:
  vta_rc62:
    command: "powershell.exe"
    args: ["-NoProfile", "-NonInteractive", "-File", "$installedLauncher"]
    supports_parallel_tool_calls: false
    tools:
      include: [vta_connection_status, vta_operations_overview, vta_sales_queues, vta_list_inquiries, vta_list_master_tours, vta_get_master_tour, vta_list_bookings, vta_booking_readiness, vta_operations_day, vta_list_tasks]
      resources: false
      prompts: false
"@
$snippetPath = Join-Path $root 'hermes-config-snippet.yaml'
Set-Content -LiteralPath $snippetPath -Value $snippet -Encoding UTF8

$registered = $false
if (-not $SkipHermesRegistration) {
    $hermes = Get-Command hermes -ErrorAction SilentlyContinue
    if ($hermes) {
        & $hermes.Source mcp add vta_rc62 --command powershell.exe --args -NoProfile -NonInteractive -File $installedLauncher
        $registered = $LASTEXITCODE -eq 0
        if ($registered) {
            Write-Host 'Hermes registration attempted successfully. Apply read-only tool filter from snippet if needed.'
        }
    }
}
if (-not $registered) {
    Write-Host 'Manual registration is required: merge snippet into ~/.hermes/config.yaml.'
    Write-Host ('Snippet: ' + $snippetPath)
}
Write-Host 'Local bridge files and DPAPI staging credential installed.'
Write-Host 'Writes remain disabled. Restart Hermes or use /reload-mcp.'
Write-Host 'Run: hermes mcp test vta_rc62 (requires a reachable real staging API).'
