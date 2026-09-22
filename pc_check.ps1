param(
    [Parameter(Mandatory = $true)]
    [int]$SessionId,

    [Parameter(Mandatory = $true)]
    [string]$CollectorToken
)
$ErrorActionPreference = "SilentlyContinue"

$ReportFile = Join-Path $PSScriptRoot "pc_check_report.txt"

Start-Transcript -Path $ReportFile -Force | Out-Null

function GB($bytes) {
    if ($null -eq $bytes) { return $null }
    return [math]::Round($bytes / 1GB, 1)
}

function Percent($part, $whole) {
    if (-not $whole -or $whole -eq 0) { return $null }
    return [math]::Round(($part / $whole) * 100, 1)
}

Write-Host ""
Write-Host "============================================"
Write-Host " PC BASIC DIAGNOSTIC COLLECTOR"
Write-Host "============================================"
Write-Host ""

# ------------------------------------------------------------
# WINDOWS / MACHINE
# ------------------------------------------------------------

$os = Get-CimInstance Win32_OperatingSystem
$computer = Get-CimInstance Win32_ComputerSystem
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
$bios = Get-CimInstance Win32_BIOS

$bootTime = $os.LastBootUpTime
$uptime = (Get-Date) - $bootTime

# ------------------------------------------------------------
# RAM
# ------------------------------------------------------------

$ramModules = @(Get-CimInstance Win32_PhysicalMemory)
$ramArrays = @(Get-CimInstance Win32_PhysicalMemoryArray)

$totalRamBytes = ($ramModules | Measure-Object Capacity -Sum).Sum
$totalRamGB = GB $totalRamBytes

$totalSlots = ($ramArrays | Measure-Object MemoryDevices -Sum).Sum
$usedSlots = $ramModules.Count

if ($totalSlots) {
    $freeSlots = [math]::Max(0, $totalSlots - $usedSlots)
}
else {
    $freeSlots = $null
}

$osTotalMemoryGB = [math]::Round($os.TotalVisibleMemorySize / 1MB, 1)
$osFreeMemoryGB = [math]::Round($os.FreePhysicalMemory / 1MB, 1)

$ramUsedGB = [math]::Round(
    $osTotalMemoryGB - $osFreeMemoryGB, 1
)

$ramUsedPct = Percent $ramUsedGB $osTotalMemoryGB

# RAM match evaluation
$ramCapacities = @(
    $ramModules | ForEach-Object {
        [math]::Round($_.Capacity / 1GB, 1)
    }
)

$ramConfiguredSpeeds = @(
    $ramModules |
    Where-Object { $_.ConfiguredClockSpeed } |
    Select-Object -ExpandProperty ConfiguredClockSpeed
)

$ramManufacturers = @(
    $ramModules |
    Where-Object { $_.Manufacturer } |
    Select-Object -ExpandProperty Manufacturer
)

$ramPartNumbers = @(
    $ramModules |
    Where-Object { $_.PartNumber } |
    ForEach-Object { $_.PartNumber.Trim() }
)

$capacityMatch =
(($ramCapacities | Select-Object -Unique).Count -le 1)

$speedMatch =
(($ramConfiguredSpeeds | Select-Object -Unique).Count -le 1)

$manufacturerMatch =
(($ramManufacturers | Select-Object -Unique).Count -le 1)

$partNumberMatch =
(($ramPartNumbers | Select-Object -Unique).Count -le 1)

# ------------------------------------------------------------
# STORAGE
# ------------------------------------------------------------

$physicalDisks = @()

try {
    $physicalDisks = @(Get-PhysicalDisk)
}
catch {
}

$diskDrives = @(Get-CimInstance Win32_DiskDrive)

$systemDriveLetter = $os.SystemDrive

$logicalDisk = Get-CimInstance Win32_LogicalDisk `
    -Filter "DeviceID='$systemDriveLetter'"

$systemDriveSizeGB = GB $logicalDisk.Size
$systemDriveFreeGB = GB $logicalDisk.FreeSpace
$systemDriveFreePct = Percent $logicalDisk.FreeSpace $logicalDisk.Size

# Try to determine the physical disk containing C:
$systemPhysicalDisk = $null

try {
    $partition = Get-Partition -DriveLetter $systemDriveLetter.TrimEnd(':')
    $systemPhysicalDisk = Get-Disk -Number $partition.DiskNumber
}
catch {
}

# ------------------------------------------------------------
# CURRENT PERFORMANCE
# ------------------------------------------------------------

$cpuCurrent = $null

try {
    $cpuCurrent = (
        Get-CimInstance Win32_Processor |
        Measure-Object LoadPercentage -Average
    ).Average
}
catch {
}

$diskPerf = $null

try {
    $diskPerf = Get-CimInstance `
        Win32_PerfFormattedData_PerfDisk_PhysicalDisk |
    Where-Object { $_.Name -eq "_Total" }
}
catch {
}

# ------------------------------------------------------------
# DISPLAY
# ------------------------------------------------------------

Write-Host "SYSTEM"
Write-Host "------"
Write-Host ("Computer        : {0}" -f $computer.Model)
Write-Host ("Manufacturer    : {0}" -f $computer.Manufacturer)
Write-Host ("Windows         : {0}" -f $os.Caption)
Write-Host ("Windows build   : {0}" -f $os.BuildNumber)
Write-Host ("CPU             : {0}" -f $cpu.Name.Trim())
Write-Host ("Uptime          : {0:N1} hours" -f $uptime.TotalHours)

Write-Host ""
Write-Host "MEMORY"
Write-Host "------"
Write-Host ("Installed RAM   : {0} GB" -f $totalRamGB)
Write-Host ("RAM in use      : {0} GB ({1}%)" -f $ramUsedGB, $ramUsedPct)

if ($totalSlots) {
    Write-Host ("Slots reported  : {0}" -f $totalSlots)
    Write-Host ("Slots populated : {0}" -f $usedSlots)
    Write-Host ("Slots free      : {0}" -f $freeSlots)
}
else {
    Write-Host "Slots reported  : Unknown"
}

Write-Host ""

$i = 1
foreach ($m in $ramModules) {

    Write-Host ("RAM module {0}" -f $i)
    Write-Host ("  Slot          : {0}" -f $m.DeviceLocator)
    Write-Host ("  Bank          : {0}" -f $m.BankLabel)
    Write-Host ("  Capacity      : {0} GB" -f (GB $m.Capacity))
    Write-Host ("  Speed rated   : {0} MHz" -f $m.Speed)
    Write-Host ("  Running speed : {0} MHz" -f $m.ConfiguredClockSpeed)
    Write-Host ("  Manufacturer  : {0}" -f $m.Manufacturer)
    Write-Host ("  Part number   : {0}" -f $m.PartNumber.Trim())
    Write-Host ""

    $i++
}

if ($usedSlots -gt 1) {
    Write-Host "RAM MATCH CHECK"
    Write-Host "---------------"
    Write-Host ("Same capacity   : {0}" -f $capacityMatch)
    Write-Host ("Same speed      : {0}" -f $speedMatch)
    Write-Host ("Same maker      : {0}" -f $manufacturerMatch)
    Write-Host ("Same part no.   : {0}" -f $partNumberMatch)

    if (-not $capacityMatch) {
        Write-Host ("Configuration   : {0}" -f (
                ($ramCapacities | ForEach-Object { "$_ GB" }) -join " + "
            ))
    }
}

Write-Host ""
Write-Host "STORAGE"
Write-Host "-------"
Write-Host ("System drive    : {0}" -f $systemDriveLetter)
Write-Host ("Capacity        : {0} GB" -f $systemDriveSizeGB)
Write-Host ("Free            : {0} GB ({1}%)" `
        -f $systemDriveFreeGB, $systemDriveFreePct)

if ($systemPhysicalDisk) {
    Write-Host ("Disk model      : {0}" -f $systemPhysicalDisk.FriendlyName)
    Write-Host ("Bus type        : {0}" -f $systemPhysicalDisk.BusType)
}

Write-Host ""
Write-Host "PHYSICAL DISKS"
Write-Host "--------------"

foreach ($d in $physicalDisks) {

    Write-Host ("Disk            : {0}" -f $d.FriendlyName)
    Write-Host ("  Media type    : {0}" -f $d.MediaType)
    Write-Host ("  Bus type      : {0}" -f $d.BusType)
    Write-Host ("  Size          : {0} GB" -f (GB $d.Size))
    Write-Host ("  Health        : {0}" -f $d.HealthStatus)
    Write-Host ""
}

Write-Host "CURRENT LOAD"
Write-Host "------------"
Write-Host ("CPU             : {0}%" -f $cpuCurrent)
Write-Host ("RAM             : {0}%" -f $ramUsedPct)

if ($diskPerf) {
    Write-Host ("Disk active     : {0}%" -f $diskPerf.PercentDiskTime)
    Write-Host ("Disk reads/sec  : {0}" -f $diskPerf.DiskReadsPersec)
    Write-Host ("Disk writes/sec : {0}" -f $diskPerf.DiskWritesPersec)
}

# ------------------------------------------------------------
# SIMPLE NEXT-STAGE FLAGS
# These are NOT diagnoses.
# ------------------------------------------------------------

Write-Host ""
Write-Host "============================================"
Write-Host " NEXT DIAGNOSTIC STAGE"
Write-Host "============================================"

$nextStages = @()

if ($systemDriveFreePct -lt 10) {
    $nextStages += "DISK-SPACE : system drive has very low free space"
}

if ($ramUsedPct -ge 85) {
    $nextStages += "MEM-001 : high current memory utilisation"
}

if ($diskPerf -and $diskPerf.PercentDiskTime -ge 90) {
    $nextStages += "DISK-005 : investigate sustained disk activity"
}

if ($cpuCurrent -ge 85) {
    $nextStages += "CPU-001 : investigate high CPU utilisation"
}

if ($systemPhysicalDisk -and
    $systemPhysicalDisk.BusType -eq "SATA") {

    $nextStages += "DISK-TYPE : SATA storage detected"
}

if ($systemPhysicalDisk -and
    $systemPhysicalDisk.BusType -eq "NVMe") {

    $nextStages += "DISK-TYPE : NVMe storage detected"
}

if ($nextStages.Count -eq 0) {
    Write-Host "No obvious bottleneck from this one snapshot."
    Write-Host "Next: collect measurements WHILE the user says the PC is slow."
}
else {
    foreach ($stage in $nextStages) {
        Write-Host ("- {0}" -f $stage)
    }
}

Write-Host ""
Write-Host "IMPORTANT:"
Write-Host "This snapshot does not itself prove the cause of slowness."
Write-Host "Technician should compare measurements while the problem is occurring."
Write-Host ""

Stop-Transcript | Out-Null

Write-Host ""
Write-Host "Report saved to:"
Write-Host $ReportFile
Write-Host ""

try {

    $jsonPath = Join-Path $PSScriptRoot "pc_check_result.json"
    $ramModuleJson = @()

    foreach ($m in $ramModules) {
        $ramModuleJson += [ordered]@{
            device_locator       = [string]$m.DeviceLocator
            bank_label           = [string]$m.BankLabel
            capacity_gb          = [math]::Round($m.Capacity / 1GB, 1)
            rated_speed_mhz      = $m.Speed
            configured_speed_mhz = $m.ConfiguredClockSpeed
            manufacturer         = [string]$m.Manufacturer
            part_number          = if ($m.PartNumber) {
                $m.PartNumber.Trim()
            }
            else {
                ""
            }
        }
    }

    $reliability = $null

    try {
        $reliability = $d | Get-StorageReliabilityCounter
    }
    catch {
        $reliability = $null
    }

    $physicalDiskJson = @()

    foreach ($d in $physicalDisks) {

        $reliability = $null

        try {
            $reliability = $d | Get-StorageReliabilityCounter
        }
        catch {
            $reliability = $null
        }

        $diskItem = [PSCustomObject][ordered]@{
            model                    = [string]$d.FriendlyName
            serial_number            = [string]$d.SerialNumber
            media_type               = [string]$d.MediaType
            bus_type                 = [string]$d.BusType
            capacity_gb              = [math]::Round($d.Size / 1GB, 1)
            health_status            = [string]$d.HealthStatus

            reliability_status       = if ($reliability) { "success" } else { "unavailable" }

            temperature_c            = if ($reliability) { $reliability.Temperature } else { $null }
            power_on_hours           = if ($reliability) { $reliability.PowerOnHours } else { $null }
            wear                     = if ($reliability) { $reliability.Wear } else { $null }

            read_errors_total        = if ($reliability) { $reliability.ReadErrorsTotal } else { $null }
            read_errors_corrected    = if ($reliability) { $reliability.ReadErrorsCorrected } else { $null }
            read_errors_uncorrected  = if ($reliability) { $reliability.ReadErrorsUncorrected } else { $null }

            write_errors_total       = if ($reliability) { $reliability.WriteErrorsTotal } else { $null }
            write_errors_corrected   = if ($reliability) { $reliability.WriteErrorsCorrected } else { $null }
            write_errors_uncorrected = if ($reliability) { $reliability.WriteErrorsUncorrected } else { $null }
        }

        $physicalDiskJson += $diskItem
    }
    # ------------------------------------------------------------
    # MERGE SMART SUMMARY INTO PHYSICAL DISKS BY SERIAL NUMBER
    # ------------------------------------------------------------

    $smartBySerial = @{}

    foreach ($s in $smartDiskSummary) {

        $serial = ([string]$s.serial).Trim()

        if (-not [string]::IsNullOrWhiteSpace($serial)) {
            $smartBySerial[$serial] = $s
        }
    }

    foreach ($d in $physicalDiskJson) {

        $serial = ([string]$d.serial_number).Trim()

        if (
            -not [string]::IsNullOrWhiteSpace($serial) -and
            $smartBySerial.ContainsKey($serial)
        ) {

            $s = $smartBySerial[$serial]

            $d['smart_available'] = $true
            $d['smart_passed'] = $s.smart_passed

            $d['smart_reallocated_sectors'] =
            $s.reallocated_sectors

            $d['smart_pending_sectors'] =
            $s.pending_sectors

            $d['smart_offline_uncorrectable'] =
            $s.offline_uncorrectable

            $d['smart_lifetime_remaining_pct'] =
            $s.lifetime_remaining_pct

            $d['smart_lifetime_used_pct'] =
            $s.lifetime_used_pct
        }
        else {

            $d['smart_available'] = $false
            $d['smart_passed'] = $null
            $d['smart_reallocated_sectors'] = $null
            $d['smart_pending_sectors'] = $null
            $d['smart_offline_uncorrectable'] = $null
            $d['smart_lifetime_remaining_pct'] = $null
            $d['smart_lifetime_used_pct'] = $null
        }
    }

    # ------------------------------------------------------------
    # OPTIONAL SMART / NVME DATA COLLECTION
    # ------------------------------------------------------------

    $smartctlCandidates = @(
        (Join-Path $PSScriptRoot "smartctl.exe"),
        "C:\Program Files\smartmontools\bin\smartctl.exe"
    )

    $smartctlPath = $null

    foreach ($candidate in $smartctlCandidates) {
        if (Test-Path $candidate) {
            $smartctlPath = $candidate
            break
        }
    }

    $smartData = @()
    $smartDiskSummary = @()
    $seenSmartSerials = @{}
    function Get-SmartRawNumber {
        param($Attribute)

        if ($null -eq $Attribute) {
            return $null
        }

        $rawString = [string]$Attribute.raw.string

        if ($rawString -match '^\s*(-?\d+)') {
            return [long]$matches[1]
        }

        if ($null -ne $Attribute.raw.value) {
            return [long]$Attribute.raw.value
        }

        return $null
    }

    if ($smartctlPath) {

        try {
            $scanText = & $smartctlPath --scan-open 2>&1

            foreach ($line in $scanText) {

                if ($line -notmatch '^(\S+)') {
                    continue
                }

                $devicePath = $matches[1]

                try {

                    $jsonText = & $smartctlPath `
                        -a `
                        -j `
                        $devicePath 2>&1

                    $jsonText = $jsonText -join "`n"

                    $smartObject = $jsonText |
                    ConvertFrom-Json -ErrorAction Stop
                    $model = [string]$smartObject.model_name
                    $serial = [string]$smartObject.serial_number
                    if (
                        [string]::IsNullOrWhiteSpace($model) -and
                        [string]::IsNullOrWhiteSpace($serial)
                    ) {
                        continue
                    }
                    if (
                        $null -ne $smartObject.smart_support.available -and
                        $smartObject.smart_support.available -eq $false
                    ) {
                        continue
                    }

                    if ([string]::IsNullOrWhiteSpace($serial)) {
                        $uniqueKey = $devicePath
                    }
                    else {
                        $uniqueKey = $serial
                    }

                    if ($seenSmartSerials.ContainsKey($uniqueKey)) {
                        continue
                    }

                    $seenSmartSerials[$uniqueKey] = $true
                    $attrs = @{}

                    if ($smartObject.ata_smart_attributes.table) {

                        foreach ($a in $smartObject.ata_smart_attributes.table) {

                            $attrs[[int]$a.id] = $a
                        }
                    }
                    $smartDiskSummary += [PSCustomObject]@{

                        device_path            = $devicePath
                        model                  = $model
                        serial                 = $serial

                        protocol               = [string]$smartObject.device.protocol

                        smart_passed           =
                        if ($null -ne $smartObject.smart_status.passed) {
                            [bool]$smartObject.smart_status.passed
                        }
                        else {
                            $null
                        }

                        temperature_c          =
                        if ($null -ne $smartObject.temperature.current) {
                            $smartObject.temperature.current
                        }
                        else {
                            $null
                        }

                        power_on_hours         =
                        if ($null -ne $smartObject.power_on_time.hours) {
                            $smartObject.power_on_time.hours
                        }
                        else {
                            $null
                        }

                        reallocated_sectors    =
                        if ($attrs.ContainsKey(5)) {
                            Get-SmartRawNumber $attrs[5]
                        }
                        else {
                            $null
                        }

                        pending_sectors        =
                        if ($attrs.ContainsKey(197)) {
                            Get-SmartRawNumber $attrs[197]
                        }
                        else {
                            $null
                        }

                        offline_uncorrectable  =
                        if ($attrs.ContainsKey(198)) {
                            Get-SmartRawNumber $attrs[198]
                        }
                        else {
                            $null
                        }

                        reported_uncorrectable =
                        if ($attrs.ContainsKey(187)) {
                            Get-SmartRawNumber $attrs[187]
                        }
                        else {
                            $null
                        }

                        udma_crc_errors        =
                        if ($attrs.ContainsKey(199)) {
                            Get-SmartRawNumber $attrs[199]
                        }
                        else {
                            $null
                        }

                        lifetime_remaining_pct =
                        if ($attrs.ContainsKey(202)) {
                            $attrs[202].value
                        }
                        else {
                            $null
                        }

                        lifetime_used_pct      =
                        if ($attrs.ContainsKey(202)) {
                            $attrs[202].raw.value
                        }
                        else {
                            $null
                        }
                    }
 



                    $smartData += [PSCustomObject]@{
                        device_path = $devicePath
                        raw         = $smartObject
                    }
                }
                catch {

                    $smartData += [PSCustomObject]@{
                        device_path = $devicePath
                        raw         = $null
                        error       = $_.Exception.Message
                    }
                }
            }
        }
        catch {
            # smartctl exists, but scanning failed
        }
    }

    $result = [ordered]@{
        collector_status         = "success"

        manufacturer             = [string]$computer.Manufacturer
        model                    = [string]$computer.Model

        windows_name             = [string]$os.Caption
        windows_build            = [string]$os.BuildNumber

        cpu_model                = [string]$cpu.Name

        uptime_hours             = [math]::Round($uptime.TotalHours, 1)
        
        ram_modules              = $ramModuleJson
        physical_disks           = @($physicalDiskJson)
        smart_disk_summary       = @($smartDiskSummary)


        ram_total_gb             = $totalRamGB
        ram_used_pct             = $ramUsedPct

        ram_slots_reported       = $totalSlots
        ram_slots_used           = $usedSlots
        ram_slots_free           = $freeSlots

        system_drive             = [string]$systemDriveLetter
        smartctl_available       = [bool]$smartctlPath
        smart_devices            = @($smartData)


        system_drive_size_gb     = $systemDriveSizeGB
        system_drive_free_gb     = $systemDriveFreeGB
        system_drive_free_pct    = $systemDriveFreePct

        system_disk_model        = if ($systemPhysicalDisk) {
            [string]$systemPhysicalDisk.FriendlyName
        }
        else {
            ""
        }

        system_disk_bus          = if ($systemPhysicalDisk) {
            [string]$systemPhysicalDisk.BusType
        }
        else {
            ""
        }
        physical_disk_size_gb    = if ($physicalDisks.Count -gt 0) {
            [math]::Round($physicalDisks[0].Size / 1GB, 1)
        }
        else {
            $null
        }

        physical_disk_media_type = if ($physicalDisks.Count -gt 0) {
            [string]$physicalDisks[0].MediaType
        }
        else {
            ""
        }

        physical_disk_health     = if ($physicalDisks.Count -gt 0) {
            [string]$physicalDisks[0].HealthStatus
        }
        else {
            ""
        }

        cpu_current_pct          = $cpuCurrent

        disk_current_pct         = if ($diskPerf) {
            $diskPerf.PercentDiskTime
        }
        else {
            $null
        }
    }

    $jsonText = $result | ConvertTo-Json -Depth 10

    [System.IO.File]::WriteAllText(
        $jsonPath,
        $jsonText,
        [System.Text.UTF8Encoding]::new($false)
    )

    Write-Host "JSON CREATED:"
    Write-Host $jsonPath
    Write-Host "Exists:" (Test-Path $jsonPath)
}
catch {

    Write-Host ""
    Write-Host "JSON CREATION ERROR:"
    Write-Host $_.Exception.Message
}
Write-Host ""
Write-Host "Uploading PCTOZ result..."

$UploadUrl = "http://192.168.0.103/pctoz/collector_receive.php"
$JsonFile = Join-Path $PSScriptRoot "pc_check_result.json"

$response = & curl.exe `
    -X POST `
    -F "session_id=$SessionId" `
    -F "collector_token=$CollectorToken" `
    -F "pc_check_file=@$JsonFile" `
    $UploadUrl

Write-Host ""
Write-Host "Server response:"
Write-Host $response
$response | Out-File -FilePath (Join-Path $PSScriptRoot "collector_response.txt") -Encoding utf8
