<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| POST only
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
| Read session + token + JSON
|--------------------------------------------------------------------------
*/

$sessionId = isset($_POST['session_id'])
    ? (int)$_POST['session_id']
    : 0;

$collectorToken = trim(
    $_POST['collector_token'] ?? ''
);

$json = $_POST['json'] ?? null;


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
| Validate session/token
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
| Decode JSON
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


/*
|--------------------------------------------------------------------------
| Validate PERF_SAMPLE
|--------------------------------------------------------------------------
*/

if (
    ($data['tool'] ?? '') !== 'PERF_SAMPLE' ||
    ($data['status'] ?? '') !== 'success'
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid PERF_SAMPLE result'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Store PERF_SAMPLE scalar observations
|--------------------------------------------------------------------------
*/

$pdo->beginTransaction();

try {

    /*
    | Remove an earlier PERF_SAMPLE result for this session.
    | Do NOT touch PC_CHECK observations.
    */

    $stmt = $pdo->prepare("
        DELETE FROM observations
        WHERE session_id = ?
          AND source_type = 'COLLECTOR'
          AND source_detail = 'PERF_SAMPLE'
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
            'PERF_SAMPLE',
            'HIGH',
            NOW()
        )
    ");


    foreach ($data as $fieldCode => $value) {

        /*
        | Process/application arrays will be stored separately later.
        | For now retain all scalar diagnostic measurements.
        */

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

            $valueText = $value ? 'true' : 'false';
        } elseif (is_numeric($value)) {

            $valueNumber = $value;
        } else {

            $valueText = trim((string)$value);
        }


        $insertObservation->execute([
            $sessionId,
            $fieldCode,
            $valueNumber,
            $valueText
        ]);
    }
    /*
|--------------------------------------------------------------------------
| Store PERF_SAMPLE application/process summaries
|--------------------------------------------------------------------------
*/

    $stmt = $pdo->prepare("
    DELETE FROM session_perf_apps
    WHERE session_id = ?
");

    $stmt->execute([$sessionId]);


    $insertPerfApp = $pdo->prepare("
    INSERT INTO session_perf_apps
    (
        session_id,
        category,
        process_name,
        active_sample_count,

        cpu_avg_pct,
        cpu_active_avg_pct,
        cpu_peak_pct,

        io_read_bytes_sec_avg,
        io_write_bytes_sec_avg,
        io_total_bytes_sec_avg,
        io_active_bytes_sec_avg,
        io_peak_bytes_sec,

        start_private_mb,
        end_private_mb,
        growth_mb,
        end_working_set_mb
    )
    VALUES
    (
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?
    )
");


    $perfArrays = [

        'TOP_MEMORY' =>
        $data['top_memory_processes'] ?? [],

        'MEMORY_GROWTH' =>
        $data['top_memory_growth'] ?? [],

        'TOP_CPU' =>
        $data['top_cpu_applications'] ?? [],

        'TOP_IO' =>
        $data['top_io_applications'] ?? [],

        'CPU_PEAK' =>
        $data['top_cpu_peak_applications'] ?? [],

        'IO_PEAK' =>
        $data['top_io_peak_applications'] ?? []
    ];


    foreach ($perfArrays as $category => $rows) {

        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {

            if (!is_array($row)) {
                continue;
            }

            $processName =
                trim((string)($row['process_name'] ?? ''));

            if ($processName === '') {
                continue;
            }

            $insertPerfApp->execute([
                $sessionId,
                $category,
                $processName,

                $row['active_sample_count'] ?? null,

                $row['cpu_avg_pct'] ?? null,
                $row['cpu_active_avg_pct'] ?? null,
                $row['cpu_peak_pct'] ?? null,

                $row['io_read_bytes_sec_avg'] ?? null,
                $row['io_write_bytes_sec_avg'] ?? null,
                $row['io_total_bytes_sec_avg'] ?? null,
                $row['io_active_bytes_sec_avg'] ?? null,
                $row['io_peak_bytes_sec'] ?? null,

                $row['start_private_mb'] ?? null,
                $row['end_private_mb'] ?? null,
                $row['growth_mb'] ?? null,
                $row['end_working_set_mb'] ?? null
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Move diagnostic flow to PERF_SAMPLE decision node
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE diagnostic_sessions
        SET current_node_id = 'GENERAL-R025'
        WHERE session_id = ?
    ");

    $stmt->execute([$sessionId]);


    $pdo->commit();


    echo json_encode([
        'ok' => true,
        'session_id' => $sessionId,
        'tool' => 'PERF_SAMPLE',
        'next_node' => 'GENERAL-R025'
    ]);
} catch (Throwable $e) {

    $pdo->rollBack();

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
