# Ephemeral CI-only test: real Windows DPAPI installer -> launcher -> MCP stdio.
# No network calls, live credentials, CyberPanel, or production modifications.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$env:LOCALAPPDATA = Join-Path $env:RUNNER_TEMP 'vta-hermes-runtime-smoke'
New-Item -ItemType Directory -Force -Path $env:LOCALAPPDATA | Out-Null

function Read-Host {
    param([string]$Prompt, [switch]$AsSecureString)
    if ($AsSecureString) {
        return ConvertTo-SecureString 'CI-only-fake-password-12345' -AsPlainText -Force
    }
    return 'ISOLATED-STAGING'
}

& (Join-Path $PSScriptRoot 'Install-VTAHermes.ps1') -StagingApiUrl 'https://isolated-staging.example.org/api/index.php' -AgentEmail 'mock-staging-agent@example.org' -CompanyId 12 -SkipHermesRegistration

$root = Join-Path $env:LOCALAPPDATA 'VTA\HermesRC62'
foreach ($file in @('vta_mcp_server.py','bridge-settings.json','agent-password.dpapi','Start-VTAHermes.ps1','hermes-config-snippet.yaml')) {
    if (-not (Test-Path (Join-Path $root $file))) { throw ('Missing install output: ' + $file) }
}
$secure = (Get-Content -LiteralPath (Join-Path $root 'agent-password.dpapi') -Raw).Trim() | ConvertTo-SecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $decoded = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    if ($decoded -ne 'CI-only-fake-password-12345') { throw 'DPAPI credential roundtrip mismatch.' }
} finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }

$request = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}'
$toolList = '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
$results = @($request, $toolList) | & (Join-Path $root 'Start-VTAHermes.ps1')
if ($LASTEXITCODE -ne 0) { throw 'Windows launcher returned a failure.' }
$jsonLines = @($results | Where-Object { $_ -match '^\{' } | ForEach-Object { $_ | ConvertFrom-Json })
if ($jsonLines.Count -ne 2) { throw 'Windows launcher returned an unexpected MCP transcript.' }
if ($jsonLines[0].result.serverInfo.name -ne 'vta-rc62-operations') { throw 'Unexpected server identity.' }
$toolNames = @($jsonLines[1].result.tools | ForEach-Object { $_.name })
if ($toolNames -notcontains 'vta_operations_overview') { throw 'Missing safe operations tool.' }
if ($toolNames -contains 'vta_create_task') { throw 'Unexpected write tool exposed.' }
Write-Host 'WINDOWS INSTALL -> DPAPI -> LAUNCHER -> MCP STDIO PASS (offline only)'
