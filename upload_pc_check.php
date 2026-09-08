<?php
session_start();
require 'config.php';

if (!isset($_SESSION['pctoz_session_id'])) {
    die("No active diagnostic session.");
}

$sessionId = (int)$_SESSION['pctoz_session_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request.");
}

if (
    !isset($_FILES['pc_check_file']) ||
    $_FILES['pc_check_file']['error'] !== UPLOAD_ERR_OK
) {
    die("PC Check result file was not uploaded correctly.");
}

$tmpFile = $_FILES['pc_check_file']['tmp_name'];

$json = file_get_contents($tmpFile);

/*
|--------------------------------------------------------------------------
| Remove possible UTF-8 BOM
|--------------------------------------------------------------------------
*/

$json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

$data = json_decode($json, true);

if (!is_array($data)) {
    die("The uploaded file is not a valid PCTOZ PC Check result.");
}

if (
    !isset($data['collector_status']) ||
    $data['collector_status'] !== 'success'
) {
    die("The PC Check result is incomplete or unsuccessful.");
}

/*
|--------------------------------------------------------------------------
| Remove previous PC_CHECK observations from THIS session
| Allows technician to rerun collector.
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
| Store collector values
|--------------------------------------------------------------------------
*/

foreach ($data as $fieldCode => $value) {

    /*
    | V0.1:
    | Store simple scalar values.
    | Arrays such as RAM modules can be handled next.
    */

    if (is_array($value) || is_object($value)) {
        continue;
    }

    $valueNumber = null;
    $valueText = null;

    if ($value === null) {
        continue;
    }

    if (is_bool($value)) {
        $valueText = $value ? 'true' : 'false';
    }
    elseif (is_numeric($value)) {
        $valueNumber = $value;
    }
    else {
        $valueText = trim((string)$value);
    }

    $stmt = $pdo->prepare("
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

    $stmt->execute([
        $sessionId,
        $fieldCode,
        $valueNumber,
        $valueText
    ]);
}

/*
|--------------------------------------------------------------------------
| Generate automatic findings
|--------------------------------------------------------------------------
|
| P already added generateSessionFindings().
| If that function is currently inside diagnose.php,
| move it later to a common functions.php.
|--------------------------------------------------------------------------
*/
/*
if (function_exists('generateSessionFindings')) {
    generateSessionFindings($pdo, $sessionId);
}
*/
/*
|--------------------------------------------------------------------------
| Record completion of ROOT-RUN010
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COALESCE(MAX(seq_no), 0) + 1 AS next_seq
    FROM node_visits
    WHERE session_id = ?
");

$stmt->execute([$sessionId]);

$seq = (int)$stmt->fetch()['next_seq'];

$stmt = $pdo->prepare("
    INSERT INTO node_visits
    (
        session_id,
        seq_no,
        node_id,
        entered_at,
        completed_at,
        next_node_id,
        route_reason
    )
    VALUES
    (
        ?, ?,
        'ROOT-RUN010',
        NOW(),
        NOW(),
        'ROOT-S015',
        'PCTOZ PC Check JSON uploaded and stored'
    )
");

$stmt->execute([
    $sessionId,
    $seq
]);

/*
|--------------------------------------------------------------------------
| Move session to findings display
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    UPDATE diagnostic_sessions
    SET current_node_id = 'ROOT-S015'
    WHERE session_id = ?
");

$stmt->execute([$sessionId]);

header("Location: diagnose.php");
exit;