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
    '\PhysicalDisk(_Total)\Disk Bytes/sec',
    '\Process(*)\% Processor Time',
    '\Process(*)\IO Read Bytes/sec',
    '\Process(*)\IO Write Bytes/sec'
)

function Get-Values {
    param(
        $CounterResult,
        [string]$Ending
    )

    return @(
        $CounterResult.CounterSamples |
        Where-Object {

            (
                $_.Status -eq 0 -or
                $_.Status -eq 1
            ) -and

            $_.Path.EndsWith(
                $Ending,
                [System.StringComparison]::OrdinalIgnoreCase
            ) -and

            -not [double]::IsNaN([double]$_.CookedValue) -and
            -not [double]::IsInfinity([double]$_.CookedValue)
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
                working_set_mb    = [math]::Round($_.WorkingSet64 / 1MB, 2)
                private_memory_mb = [math]::Round($_.PrivateMemorySize64 / 1MB, 2)
            }
        }
        catch {
            # Process may have ended while being read.
        }
    }

    return $snapshot
}

function Get-ProcessCounterSummary {
    param(
        $CounterResult,
        [int]$SampleCount,
        [int]$LogicalProcessors
    )

    # First aggregate all instances of the same application within each
    # individual 2-second sample. This lets short-lived processes still
    # contribute even if they start and stop during the 60-second test.
    $perSample = @{}

    foreach ($counterSample in $CounterResult.CounterSamples) {

        $path = [string]$counterSample.Path

        if ($path -notmatch '\\process\(') {
            continue
        }

        if (
            ($counterSample.Status -ne 0 -and $counterSample.Status -ne 1) -or
            [double]::IsNaN([double]$counterSample.CookedValue) -or
            [double]::IsInfinity([double]$counterSample.CookedValue)
        ) {
            continue
        }

        $instance = [string]$counterSample.InstanceName

        if (
            [string]::IsNullOrWhiteSpace($instance) -or
            $instance -eq '_total' -or
            $instance -eq 'idle'
        ) {
            continue
        }

        # Windows may expose chrome, chrome#1, chrome#2, etc.
        # Aggregate those instances under one application name.
        $processName = ($instance -replace '#\d+$', '').ToLowerInvariant()

        $sampleTicks = 0
        try {
            $sampleTicks = $counterSample.Timestamp.Ticks
        }
        catch {
            $sampleTicks = 0
        }

        $key = "$sampleTicks|$processName"

        if (-not $perSample.ContainsKey($key)) {
            $perSample[$key] = [ordered]@{
                process_name = $processName
                cpu_raw      = 0.0
                io_read      = 0.0
                io_write     = 0.0
            }
        }

        $value = [double]$counterSample.CookedValue

        if ($path.EndsWith(
                '\% processor time',
                [System.StringComparison]::OrdinalIgnoreCase
            )) {
            $perSample[$key].cpu_raw += $value
        }
        elseif ($path.EndsWith(
                '\io read bytes/sec',
                [System.StringComparison]::OrdinalIgnoreCase
            )) {
            $perSample[$key].io_read += $value
        }
        elseif ($path.EndsWith(
                '\io write bytes/sec',
                [System.StringComparison]::OrdinalIgnoreCase
            )) {
            $perSample[$key].io_write += $value
        }
    }

    # Then summarize each application across all samples in which it appeared.
    $summary = @{}

    foreach ($sampleItem in $perSample.Values) {

        $processName = [string]$sampleItem.process_name

        if (-not $summary.ContainsKey($processName)) {
            $summary[$processName] = [ordered]@{
                process_name   = $processName
                active_samples = 0
                cpu_sum_pct    = 0.0
                cpu_peak_pct   = 0.0
                io_read_sum    = 0.0
                io_write_sum   = 0.0
                io_total_peak  = 0.0
            }
        }

        $cpuPct = 0.0

        if ($LogicalProcessors -gt 0) {
            $cpuPct = [double]$sampleItem.cpu_raw / $LogicalProcessors
        }

        $ioTotal =
        [double]$sampleItem.io_read +
        [double]$sampleItem.io_write

        $summary[$processName].active_samples += 1
        $summary[$processName].cpu_sum_pct += $cpuPct
        $summary[$processName].io_read_sum += [double]$sampleItem.io_read
        $summary[$processName].io_write_sum += [double]$sampleItem.io_write

        if ($cpuPct -gt $summary[$processName].cpu_peak_pct) {
            $summary[$processName].cpu_peak_pct = $cpuPct
        }

        if ($ioTotal -gt $summary[$processName].io_total_peak) {
            $summary[$processName].io_total_peak = $ioTotal
        }
    }

    $rows = @()

    foreach ($processName in $summary.Keys) {

        $item = $summary[$processName]
        $activeSamples = [int]$item.active_samples

        $cpuAverage = $null
        $cpuActiveAverage = $null
        $readAverage = $null
        $writeAverage = $null
        $ioActiveAverage = $null

        if ($SampleCount -gt 0) {
            $cpuAverage = [math]::Round(
                $item.cpu_sum_pct / $SampleCount,
                2
            )

            $readAverage = [math]::Round(
                $item.io_read_sum / $SampleCount,
                2
            )

            $writeAverage = [math]::Round(
                $item.io_write_sum / $SampleCount,
                2
            )
        }

        if ($activeSamples -gt 0) {
            $cpuActiveAverage = [math]::Round(
                $item.cpu_sum_pct / $activeSamples,
                2
            )

            $ioActiveAverage = [math]::Round(
                ($item.io_read_sum + $item.io_write_sum) / $activeSamples,
                2
            )
        }

        $rows += [PSCustomObject][ordered]@{
            process_name            = $processName
            active_sample_count     = $activeSamples

            cpu_avg_pct             = $cpuAverage
            cpu_active_avg_pct      = $cpuActiveAverage
            cpu_peak_pct            = [math]::Round($item.cpu_peak_pct, 2)

            io_read_bytes_sec_avg   = $readAverage
            io_write_bytes_sec_avg  = $writeAverage
            io_total_bytes_sec_avg  = [math]::Round(
                ($readAverage + $writeAverage),
                2
            )

            io_active_bytes_sec_avg = $ioActiveAverage
            io_peak_bytes_sec       = [math]::Round(
                $item.io_total_peak,
                2
            )
        }
    }

    return $rows
}

try {
    $processStart = Get-ProcessMemorySnapshot

    # Collect one 2-second sample at a time.
    # A bad Windows counter sample must not abort the whole 60-second test.
    $allCounterSamples = @()
    $successfulSampleRounds = 0
    $failedSampleRounds = 0

    for ($i = 1; $i -le 30; $i++) {

        try {
            $oneSample = Get-Counter `
                -Counter $counterPaths `
                -MaxSamples 1 `
                -ErrorAction Stop

            if ($oneSample -and $oneSample.CounterSamples) {
                $allCounterSamples += @($oneSample.CounterSamples)
                $successfulSampleRounds++
            }
            else {
                $failedSampleRounds++
            }
        }
        catch {
            $failedSampleRounds++
        }

        if ($i -lt 30) {
            Start-Sleep -Seconds 2
        }
    }

    $sample = [PSCustomObject]@{
        CounterSamples = @($allCounterSamples)
    }

    $processEnd = Get-ProcessMemorySnapshot

    $processMemoryChanges = @()

    foreach ($key in $processEnd.Keys) {
        if ($processStart.ContainsKey($key)) {
            $start = $processStart[$key]
            $end = $processEnd[$key]

            $growth = [math]::Round(
                $end.private_memory_mb - $start.private_memory_mb,
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

    $topMemoryProcesses = @(
        $processMemoryChanges |
        Sort-Object end_private_mb -Descending |
        Select-Object -First 10
    )

    $topMemoryGrowth = @(
        $processMemoryChanges |
        Sort-Object growth_mb -Descending |
        Select-Object -First 10
    )

    $cpu = Get-Values $sample '\processor(_total)\% processor time'
    $memoryUsed = Get-Values $sample '\memory\% committed bytes in use'
    $memoryAvailable = Get-Values $sample '\memory\available mbytes'
    $pages = Get-Values $sample '\memory\pages/sec'
    $pagesInput = Get-Values $sample '\memory\pages input/sec'
    $pageReads = Get-Values $sample '\memory\page reads/sec'
    $diskActive = Get-Values $sample '\physicaldisk(_total)\% disk time'
    $diskQueue = Get-Values $sample '\physicaldisk(_total)\avg. disk queue length'
    $diskLatencySeconds = Get-Values $sample '\physicaldisk(_total)\avg. disk sec/transfer'
    $diskBytes = Get-Values $sample '\physicaldisk(_total)\disk bytes/sec'

    $diskLatencyMs = @(
        $diskLatencySeconds |
        ForEach-Object {
            $_ * 1000
        }
    )

    $logicalProcessors = [Environment]::ProcessorCount
    $actualSampleCount = $cpu.Count

    $processCounterSummary = @(
        Get-ProcessCounterSummary `
            -CounterResult $sample `
            -SampleCount $actualSampleCount `
            -LogicalProcessors $logicalProcessors
    )

    $topCpuApplications = @(
        $processCounterSummary |
        Sort-Object cpu_avg_pct -Descending |
        Select-Object -First 10
    )

    $topCpuPeakApplications = @(
        $processCounterSummary |
        Sort-Object cpu_peak_pct -Descending |
        Select-Object -First 10
    )

    $topIoApplications = @(
        $processCounterSummary |
        Sort-Object io_total_bytes_sec_avg -Descending |
        Select-Object -First 10
    )

    $topIoPeakApplications = @(
        $processCounterSummary |
        Sort-Object io_peak_bytes_sec -Descending |
        Select-Object -First 10
    )
    $expectedSampleCount = 30

    $validSampleCount = $actualSampleCount

    $sampleQualityPct = 0

    if ($expectedSampleCount -gt 0) {
        $sampleQualityPct =
        [math]::Round(
            ($validSampleCount / $expectedSampleCount) * 100,
            1
        )
    }

    $result = [ordered]@{
        session_id                = $SessionId
        tool                      = "PERF_SAMPLE"
        status                    = "success"

        sample_interval_seconds   = 2
        sample_count              = $actualSampleCount
        expected_sample_count     = $expectedSampleCount
        sample_quality_pct        = $sampleQualityPct

        logical_processors        = $logicalProcessors

        cpu_avg_pct               = Get-Average $cpu
        cpu_max_pct               = Get-Maximum $cpu

        memory_committed_avg_pct  = Get-Average $memoryUsed
        memory_committed_max_pct  = Get-Maximum $memoryUsed
        memory_available_min_mb   = Get-Minimum $memoryAvailable

        pages_sec_avg             = Get-Average $pages
        pages_sec_max             = Get-Maximum $pages
        pages_input_sec_avg       = Get-Average $pagesInput
        pages_input_sec_max       = Get-Maximum $pagesInput
        page_reads_sec_avg        = Get-Average $pageReads
        page_reads_sec_max        = Get-Maximum $pageReads

        disk_active_avg_pct       = Get-Average $diskActive
        disk_active_max_pct       = Get-Maximum $diskActive
        disk_queue_avg            = Get-Average $diskQueue
        disk_queue_max            = Get-Maximum $diskQueue
        disk_latency_avg_ms       = Get-Average $diskLatencyMs
        disk_latency_max_ms       = Get-Maximum $diskLatencyMs
        disk_bytes_sec_avg        = Get-Average $diskBytes

        top_memory_processes      = $topMemoryProcesses
        top_memory_growth         = $topMemoryGrowth

        # Full-period averages identify sustained contributors.
        top_cpu_applications      = $topCpuApplications
        top_io_applications       = $topIoApplications

        # Peak lists help catch short-lived programs such as compilers.
        top_cpu_peak_applications = $topCpuPeakApplications
        top_io_peak_applications  = $topIoPeakApplications
    }

    $json = $result | ConvertTo-Json -Depth 6

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
    $UploadUrl = "http://192.168.0.104/pctoz/perf_sample_receive.php"

    Write-Host ""
    Write-Host "Uploading performance sample..."

    $curlArgs = @(
        '-sS',
        '-X', 'POST',
        '-F', "session_id=$SessionId",
        '-F', "collector_token=$CollectorToken",
        '-F', "json=<$resultPath",
        $UploadUrl
    )

    $response = & curl.exe @curlArgs

    Write-Host ""
    Write-Host "Server response:"
    Write-Host $response
}
catch {
    Write-Host ""
    Write-Host "PERFORMANCE SAMPLE ERROR:"
    Write-Host $_.Exception.Message
    throw
}
