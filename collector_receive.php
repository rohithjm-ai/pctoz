<?php

require 'config.php';

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| Only POST allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'ok' => false,
        'error' => 'POST required'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Read session/token
|--------------------------------------------------------------------------
*/

$sessionId = isset($_POST['session_id'])
    ? (int)$_POST['session_id']
    : 0;

$collectorToken = trim(
    $_POST['collector_token'] ?? ''
);


/*
|--------------------------------------------------------------------------
| JSON can come either as uploaded file or raw field
|--------------------------------------------------------------------------
*/

$json = null;

if (
    isset($_FILES['pc_check_file']) &&
    $_FILES['pc_check_file']['error'] === UPLOAD_ERR_OK
) {

    $json = file_get_contents(
        $_FILES['pc_check_file']['tmp_name']
    );
} elseif (isset($_POST['json'])) {

    $json = $_POST['json'];
}


/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/

if (
    $sessionId <= 0 ||
    $collectorToken === '' ||
    $json === null
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Missing session, token or JSON'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Validate session + temporary token
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM diagnostic_sessions
    WHERE session_id = ?
      AND collector_token = ?
      AND collector_token_expires_at >= NOW()
");

$stmt->execute([
    $sessionId,
    $collectorToken
]);

$session = $stmt->fetch();

if (!$session) {

    http_response_code(403);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid or expired collector session'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Decode collector JSON
|--------------------------------------------------------------------------
*/

$json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

$data = json_decode($json, true);

if (!is_array($data)) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON'
    ]);

    exit;
}


if (
    ($data['collector_status'] ?? '') !== 'success'
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Collector did not report success'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Begin transaction
|--------------------------------------------------------------------------
*/

$pdo->beginTransaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Remove previous collector observations for this session
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM observations
        WHERE session_id = ?
          AND source_type = 'COLLECTOR'
          AND source_detail = 'PC_CHECK'
    ");

    $stmt->execute([$sessionId]);


    /*
    |--------------------------------------------------------------------------
    | Store all scalar collector values
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
    DELETE FROM session_ram_modules
    WHERE session_id = ?
");
    $stmt->execute([$sessionId]);

    $stmt = $pdo->prepare("
    DELETE FROM session_disks
    WHERE session_id = ?
");
    $stmt->execute([$sessionId]);


    $insertObservation = $pdo->prepare("
        INSERT INTO observations
        (
            session_id,
            field_code,
            value_number,
            value_text,
            source_type,
            source_detail,
            confidence,
            observed_at
        )
        VALUES
        (
            ?, ?, ?, ?,
            'COLLECTOR',
            'PC_CHECK',
            'HIGH',
            NOW()
        )
    ");

    foreach ($data as $fieldCode => $value) {

        if (
            is_array($value) ||
            is_object($value) ||
            $value === null
        ) {
            continue;
        }

        $valueNumber = null;
        $valueText = null;

        if (is_bool($value)) {

            $valueText = $value
                ? 'true'
                : 'false';
        } elseif (is_numeric($value)) {

            $valueNumber = $value;
        } else {

            $valueText = trim(
                (string)$value
            );
        }

        $insertObservation->execute([
            $sessionId,
            $fieldCode,
            $valueNumber,
            $valueText
        ]);
    }
    if (!empty($data['ram_modules']) && is_array($data['ram_modules'])) {

        $stmtRam = $pdo->prepare("
        INSERT INTO session_ram_modules
        (
            session_id,
            device_locator,
            bank_label,
            capacity_gb,
            rated_speed_mhz,
            configured_speed_mhz,
            manufacturer,
            part_number
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?)
    ");

        foreach ($data['ram_modules'] as $ram) {

            $stmtRam->execute([
                $sessionId,
                $ram['device_locator'] ?? null,
                $ram['bank_label'] ?? null,
                $ram['capacity_gb'] ?? null,
                $ram['rated_speed_mhz'] ?? null,
                $ram['configured_speed_mhz'] ?? null,
                $ram['manufacturer'] ?? null,
                $ram['part_number'] ?? null
            ]);
        }
    }
    if (!empty($data['physical_disks']) && is_array($data['physical_disks'])) {

        $stmtDisk = $pdo->prepare("
INSERT INTO session_disks
(
    session_id,
    model,
    media_type,
    bus_type,
    capacity_gb,
    health_status,
    is_system_disk,
    temperature_c,
power_on_hours,
wear,
read_errors_total,
read_errors_corrected,
read_errors_uncorrected,
write_errors_total,
write_errors_corrected,
write_errors_uncorrected,
reliability_status
)
VALUES
(?, ?, ?, ?, ?, ?, ?)
    ");

        foreach ($data['physical_disks'] as $disk) {
            $isSystemDisk =
                (
                    !empty($data['system_disk_model'])
                    &&
                    isset($disk['model'])
                    &&
                    trim($disk['model']) === trim($data['system_disk_model'])
                )
                ? 1
                : 0;

            $stmtDisk->execute([
                $sessionId,
                $disk['model'] ?? null,
                $disk['media_type'] ?? null,
                $disk['bus_type'] ?? null,
                $disk['capacity_gb'] ?? null,
                $disk['health_status'] ?? null,
                $isSystemDisk
            ]);
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Mark collector as received
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE diagnostic_sessions
        SET
            collector_status = 'RECEIVED',
            collector_received_at = NOW(),
            current_node_id = 'ROOT-S015'
        WHERE session_id = ?
    ");

    $stmt->execute([$sessionId]);


    $pdo->commit();


    echo json_encode([
        'ok' => true,
        'session_id' => $sessionId,
        'collector_status' => 'RECEIVED',
        'next_node' => 'ROOT-S015'
    ]);
} catch (Throwable $e) {

    $pdo->rollBack();

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
