param(
    [Parameter(Mandatory = $true)]
    [string]$Url
)

Write-Host ""
Write-Host "PCTOZCheck launcher"
Write-Host ""

try {

    $uri = [System.Uri]$Url

    $query = $uri.Query.TrimStart('?')

    $pairs = @{}

    foreach ($part in $query -split '&') {

        if ($part -match '=') {

            $name, $value = $part -split '=', 2

            $pairs[$name] =
            [System.Uri]::UnescapeDataString($value)
        }
    }

    $SessionId = $pairs['session']
    $CollectorToken = $pairs['token']
    $Tool = $pairs['tool']

    if (-not $Tool) {
        $Tool = 'PC_CHECK'
    }

    if (-not $SessionId) {
        throw "Session ID is missing."
    }

    if (-not $CollectorToken) {
        throw "Collector token is missing."
    }

    Write-Host "Session:"
    Write-Host $SessionId

    Write-Host ""
    Write-Host "Tool:"
    Write-Host $Tool
    Write-Host ""

    switch ($Tool.ToUpper()) {

        'PC_CHECK' {
            $ScriptPath =
            Join-Path $PSScriptRoot "pc_check.ps1"
        }

        'PERF_SAMPLE' {
            $ScriptPath =
            Join-Path $PSScriptRoot "perf_sample.ps1"
        }

        default {
            throw "Unknown PCTOZCheck tool: $Tool"
        }
    }

    if (-not (Test-Path $ScriptPath)) {
        throw "PCTOZCheck tool file not found: $ScriptPath"
    }

    Write-Host "Starting PCTOZCheck tool..."
    Write-Host ""
    Write-Host ""
    Write-Host "Tool:"
    Write-Host $Tool
    Write-Host ""

    switch ($Tool.ToUpper()) {

        'PC_CHECK' {
            $ScriptPath =
            Join-Path $PSScriptRoot "pc_check.ps1"
        }

        'PERF_SAMPLE' {
            $ScriptPath =
            Join-Path $PSScriptRoot "perf_sample.ps1"
        }

        default {
            throw "Unknown PCTOZCheck tool: $Tool"
        }
    }

    if (-not (Test-Path $ScriptPath)) {
        throw "PCTOZCheck tool file not found: $ScriptPath"
    }

    Write-Host "Starting PCTOZCheck tool..."
    Write-Host ""

    $arguments = @(
        '-NoProfile'
        '-ExecutionPolicy', 'Bypass'
        '-File', "`"$ScriptPath`""
        '-SessionId', $SessionId
        '-CollectorToken', "`"$CollectorToken`""
    )

    Start-Process powershell.exe `
        -Verb RunAs `
        -ArgumentList $arguments `
        -Wait
}
catch {

    Write-Host ""
    Write-Host "PCTOZCheck launch error:"
    Write-Host $_.Exception.Message

    Write-Host ""
    Read-Host "Press ENTER to close"
}
