param(
    [Parameter(Mandatory=$true)]
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

    if (-not $SessionId) {
        throw "Session ID is missing."
    }

    if (-not $CollectorToken) {
        throw "Collector token is missing."
    }

    Write-Host "Session:"
    Write-Host $SessionId

    Write-Host ""
    Write-Host "Starting PCTOZ PC Check..."
    Write-Host ""

    $PcCheckPath =
        Join-Path $PSScriptRoot "pc_check.ps1"

    & powershell.exe `
        -NoProfile `
        -ExecutionPolicy Bypass `
        -File $PcCheckPath `
        -SessionId $SessionId `
        -CollectorToken $CollectorToken
}
catch {

    Write-Host ""
    Write-Host "PCTOZCheck launch error:"
    Write-Host $_.Exception.Message

    Write-Host ""
    Read-Host "Press ENTER to close"
}
