param(
    [Parameter(Mandatory = $true)]
    [int]$SessionId,

    [Parameter(Mandatory = $true)]
    [string]$CollectorToken
)

$ErrorActionPreference = "Stop"

$resultPath = Join-Path $PSScriptRoot "perf_sample_result.json"
$tempPath = Join-Path $PSScriptRoot "perf_sample_result.tmp"

if (Test-Path $resultPath) {
    Remove-Item $resultPath -Force
}

Write-Host ""
Write-Host "============================================"
Write-Host " PCTOZ PERFORMANCE SAMPLE"
Write-Host "============================================"
Write-Host ""
Write-Host "Use the computer normally while it feels slow."
Write-Host "PCTOZ will measure performance for about 60 seconds."
Write-Host ""

$counterPaths = @(
    '\Processor(_Total)\% Processor Time',
    '\Memory\% Committed Bytes In Use',
    '\Memory\Available MBytes',
    '\Memory\Pages/sec',
    '\Memory\Pages Input/sec',
    '\Memory\Page Reads/sec',
    '\PhysicalDisk(_Total)\% Disk Time',
    '\PhysicalDisk(_Total)\Avg. Disk Queue Length',
    '\PhysicalDisk(_Total)\Avg. Disk sec/Transfer',
    '\PhysicalDisk(_Total)\Disk Bytes/sec'
)

function Get-Values {
    param(
        $CounterResult,
        [string]$Ending
    )

    return @(
        $CounterResult.CounterSamples |
        Where-Object {
            $_.Path.EndsWith(
                $Ending,
                [System.StringComparison]::OrdinalIgnoreCase
            )
        } |
        ForEach-Object {
            [double]$_.CookedValue
        }
    )
}

function Get-Average {
    param($Values)

    if (-not $Values -or $Values.Count -eq 0) {
        return $null
    }

    return [math]::Round(
        ($Values | Measure-Object -Average).Average,
        2
    )
}

function Get-Maximum {
    param($Values)

    if (-not $Values -or $Values.Count -eq 0) {
        return $null
    }

    return [math]::Round(
        ($Values | Measure-Object -Maximum).Maximum,
        2
    )
}

function Get-Minimum {
    param($Values)

    if (-not $Values -or $Values.Count -eq 0) {
        return $null
    }

    return [math]::Round(
        ($Values | Measure-Object -Minimum).Minimum,
        2
    )
}
function Get-ProcessMemorySnapshot {

    $snapshot = @{}

    Get-Process -ErrorAction SilentlyContinue |
    ForEach-Object {

        try {
            $key = "$($_.Id)|$($_.ProcessName)"

            $snapshot[$key] = [ordered]@{
                process_id        = $_.Id
                process_name      = $_.ProcessName
                working_set_mb    =
                [math]::Round($_.WorkingSet64 / 1MB, 2)
                private_memory_mb =
                [math]::Round($_.PrivateMemorySize64 / 1MB, 2)
            }
        }
        catch {
            # Process may have ended while being read.
        }
    }

    return $snapshot
}


try {
    $processStart = Get-ProcessMemorySnapshot

    $sample = Get-Counter `
        -Counter $counterPaths `
        -SampleInterval 2 `
        -MaxSamples 30
    $processEnd = Get-ProcessMemorySnapshot

    $processMemoryChanges = @()

    foreach ($key in $processEnd.Keys) {

        if ($processStart.ContainsKey($key)) {

            $start = $processStart[$key]
            $end = $processEnd[$key]

            $growth =
            [math]::Round(
                $end.private_memory_mb -
                $start.private_memory_mb,
                2
            )

            $processMemoryChanges += [PSCustomObject][ordered]@{
                process_id         = $end.process_id
                process_name       = $end.process_name
                start_private_mb   = $start.private_memory_mb
                end_private_mb     = $end.private_memory_mb
                growth_mb          = $growth
                end_working_set_mb = $end.working_set_mb
            }
        }
    }

    $topMemoryProcesses =
    @(
        $processMemoryChanges |
        Sort-Object end_private_mb -Descending |
        Select-Object -First 10
    )

    $topMemoryGrowth =
    @(
        $processMemoryChanges |
        Sort-Object growth_mb -Descending |
        Select-Object -First 10
    )

    $cpu =
    Get-Values $sample '\processor(_total)\% processor time'

    $memoryUsed =
    Get-Values $sample '\memory\% committed bytes in use'

    $memoryAvailable =
    Get-Values $sample '\memory\available mbytes'

    $pages =
    Get-Values $sample '\memory\pages/sec'
    $pagesInput =
    Get-Values $sample '\memory\pages input/sec'

    $pageReads =
    Get-Values $sample '\memory\page reads/sec'
    $diskActive =
    Get-Values $sample '\physicaldisk(_total)\% disk time'

    $diskQueue =
    Get-Values $sample '\physicaldisk(_total)\avg. disk queue length'

    $diskLatencySeconds =
    Get-Values $sample '\physicaldisk(_total)\avg. disk sec/transfer'

    $diskBytes =
    Get-Values $sample '\physicaldisk(_total)\disk bytes/sec'

    $diskLatencyMs = @(
        $diskLatencySeconds |
        ForEach-Object {
            $_ * 1000
        }
    )

    $result = [ordered]@{

        session_id               = $SessionId
        tool                     = "PERF_SAMPLE"
        status                   = "success"

        sample_interval_seconds  = 2
        sample_count             = $cpu.Count

        cpu_avg_pct              = Get-Average $cpu
        cpu_max_pct              = Get-Maximum $cpu

        memory_committed_avg_pct =
        Get-Average $memoryUsed

        memory_committed_max_pct =
        Get-Maximum $memoryUsed

        memory_available_min_mb  =
        Get-Minimum $memoryAvailable

        pages_sec_avg            =
        Get-Average $pages

        pages_sec_max            =
        Get-Maximum $pages
        pages_input_sec_avg      =
        Get-Average $pagesInput

        pages_input_sec_max      =
        Get-Maximum $pagesInput

        page_reads_sec_avg       =
        Get-Average $pageReads

        page_reads_sec_max       =
        Get-Maximum $pageReads

        disk_active_avg_pct      =
        Get-Average $diskActive

        disk_active_max_pct      =
        Get-Maximum $diskActive

        disk_queue_avg           =
        Get-Average $diskQueue

        disk_queue_max           =
        Get-Maximum $diskQueue

        disk_latency_avg_ms      =
        Get-Average $diskLatencyMs

        disk_latency_max_ms      =
        Get-Maximum $diskLatencyMs

        disk_bytes_sec_avg       =
        Get-Average $diskBytes
        top_memory_processes     = $topMemoryProcesses
        top_memory_growth        = $topMemoryGrowth
    }
 

    $json =
    $result |
    ConvertTo-Json -Depth 5

    $json |
    Out-File `
        -FilePath $tempPath `
        -Encoding utf8 `
        -Force

    Move-Item `
        -Path $tempPath `
        -Destination $resultPath `
        -Force

    Write-Host ""
    Write-Host "Performance sample completed."
    Write-Host ""
    Write-Host "Result:"
    Write-Host $resultPath
}
catch {

    Write-Host ""
    Write-Host "PERFORMANCE SAMPLE ERROR:"
    Write-Host $_.Exception.Message
    throw
}