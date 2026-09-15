<?php
session_start();

require 'config.php';

function fmtNumber($value, $decimals = 1)
{
    if ($value === null || $value === '') {
        return '';
    }

    $n = (float)$value;

    if (abs($n - round($n)) < 0.0001) {
        return number_format($n, 0);
    }

    return number_format($n, $decimals);
}

function getNode(PDO $pdo, string $nodeId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM tree_nodes
        WHERE node_id = ?
          AND active = 1
    ");
    $stmt->execute([$nodeId]);

    return $stmt->fetch() ?: null;
}

function getOptions(PDO $pdo, string $nodeId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM tree_options
        WHERE node_id = ?
          AND active = 1
        ORDER BY option_seq
    ");
    $stmt->execute([$nodeId]);

    return $stmt->fetchAll();
}

function createSession(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        INSERT INTO diagnostic_sessions
        (
            started_at,
            current_node_id,
            status
        )
        VALUES
        (
            NOW(),
            'ROOT-Q001',
            'OPEN'
        )
    ");
    $stmt->execute();

    return (int)$pdo->lastInsertId();
}

function nextSequence(PDO $pdo, int $sessionId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(seq_no), 0) + 1 AS next_seq
        FROM node_visits
        WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);

    return (int)$stmt->fetch()['next_seq'];
}

function getLatestObservation(PDO $pdo, int $sessionId, string $fieldCode)
{
    $stmt = $pdo->prepare("
        SELECT value_number, value_text
        FROM observations
        WHERE session_id = ?
          AND field_code = ?
        ORDER BY observed_at DESC,
                 observation_id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $sessionId,
        $fieldCode
    ]);

    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if ($row['value_number'] !== null) {
        return $row['value_number'];
    }

    return $row['value_text'];
}

function includeFindingBlock(array $f)
{
?>
    <div style="
        margin-bottom:20px;
        padding-bottom:15px;
        border-bottom:1px solid #ddd;
    ">

        <div style="
            font-size:18px;
            font-weight:bold;
            margin-bottom:5px;
        ">
            <?php
            echo htmlspecialchars(
                $f['rendered_title'] ?? ''
            );
            ?>
        </div>

        <div>
            <?php
            echo nl2br(
                htmlspecialchars(
                    $f['rendered_body'] ?? ''
                )
            );
            ?>
            <?php if (!empty($f['rendered_recommendation'])): ?>

                <div style="margin-top:8px;">

                    <strong>Recommendation:</strong>

                    <?php
                    echo nl2br(
                        htmlspecialchars(
                            $f['rendered_recommendation']
                        )
                    );
                    ?>

                </div>

            <?php endif; ?>
        </div>

        <?php
        $showTechValidation = true;
        ?>

        <?php if ($showTechValidation): ?>

            <div style="
                margin-top:12px;
                padding:12px;
                background:#f6f6f6;
                border-left:4px solid #888;
            ">

                <strong>Technician validation</strong>

                <div style="
                    margin-top:8px;
                    font-size:13px;
                    color:#555;
                ">

                    Finding:
                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $f['finding_code'] ?? ''
                        );
                        ?>
                    </strong>

                    <br>

                    Severity:
                    <?php
                    echo htmlspecialchars(
                        $f['severity'] ?? ''
                    );
                    ?>

                </div>

                <form method="post" style="margin-top:10px;">

                    <input
                        type="hidden"
                        name="action"
                        value="tech_feedback">

                    <input
                        type="hidden"
                        name="node_id"
                        value="ROOT-S015">

                    <input
                        type="hidden"
                        name="review_area"
                        value="FINDING">

                    <input
                        type="hidden"
                        name="current_value"
                        value="<?php
                                echo htmlspecialchars(
                                    $f['finding_code'] ?? ''
                                );
                                ?>">

                    <label>Review</label>

                    <select name="issue_type" required>

                        <option value="CORRECT">Correct</option>

                        <option value="RULE_WRONG">
                            Rule is wrong
                        </option>

                        <option value="SEVERITY_WRONG">
                            Severity is wrong
                        </option>

                        <option value="MESSAGE_WRONG">
                            Message is wrong
                        </option>

                        <option value="RECOMMENDATION_WRONG">
                            Recommendation is wrong
                        </option>

                        <option value="MISSING_EVIDENCE">
                            Important evidence is missing
                        </option>

                    </select>

                    <label>
                        Suggested change / comment
                    </label>

                    <textarea
                        name="comment"
                        placeholder="Explain what should change and why."></textarea>

                    <button
                        type="submit"
                        class="save-button">
                        Save finding review
                    </button>

                </form>

            </div>

        <?php endif; ?>

    </div>
<?php
}

function compareFindingValue($actual, string $operator, $expected): bool
{
    switch ($operator) {

        case '=':
            return (string)$actual === (string)$expected;

        case '!=':
            return (string)$actual !== (string)$expected;

        case '<':
            return (float)$actual < (float)$expected;

        case '<=':
            return (float)$actual <= (float)$expected;

        case '>':
            return (float)$actual > (float)$expected;

        case '>=':
            return (float)$actual >= (float)$expected;

        default:
            return false;
    }
}


function renderFindingTemplate(
    PDO $pdo,
    int $sessionId,
    string $template
): string {
    return preg_replace_callback(
        '/\{([a-zA-Z0-9_]+)\}/',
        function ($matches) use ($pdo, $sessionId) {

            $value = getLatestObservation(
                $pdo,
                $sessionId,
                $matches[1]
            );
            if (is_numeric($value)) {
                $value = fmtNumber($value);
            }

            if ($value === null) {
                return '?';
            }

            return (string)$value;
        },
        $template
    );
}

function generateSessionFindings(PDO $pdo, int $sessionId): void
{
    /*
    |--------------------------------------------------------------------------
    | Remove previously generated findings for this validation session
    |--------------------------------------------------------------------------
    |
    | This makes refreshing ROOT-S015 safe during development.
    |
    */

    $stmt = $pdo->prepare("
        DELETE FROM session_findings
        WHERE session_id = ?
    ");

    $stmt->execute([$sessionId]);


    /*
    |--------------------------------------------------------------------------
    | Get all active finding rules
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT *
        FROM finding_rules
        WHERE active = 1
        ORDER BY priority, finding_rule_id
    ");

    $rules = $stmt->fetchAll();


    foreach ($rules as $rule) {

        /*
        |--------------------------------------------------------------------------
        | Get conditions for this finding
        |--------------------------------------------------------------------------
        */

        $stmtCond = $pdo->prepare("
            SELECT *
            FROM finding_rule_conditions
            WHERE finding_rule_id = ?
            ORDER BY condition_group, condition_seq
        ");

        $stmtCond->execute([
            $rule['finding_rule_id']
        ]);

        $conditions = $stmtCond->fetchAll();

        if (!$conditions) {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Evaluate:
        |
        | conditions inside one group = AND
        | separate groups = OR
        |--------------------------------------------------------------------------
        */

        $groups = [];

        foreach ($conditions as $condition) {

            $groupName =
                $condition['condition_group'] ?: 'A';

            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [];
            }

            $groups[$groupName][] = $condition;
        }

        $ruleMatched = false;

        foreach ($groups as $groupConditions) {

            $groupMatched = true;

            foreach ($groupConditions as $condition) {

                $actual = getLatestObservation(
                    $pdo,
                    $sessionId,
                    $condition['field_code']
                );

                if ($actual === null) {
                    $groupMatched = false;
                    break;
                }

                if (!compareFindingValue(
                    $actual,
                    $condition['operator'],
                    $condition['compare_value']
                )) {
                    $groupMatched = false;
                    break;
                }
            }

            /*
            | Any complete group being true means rule matched.
            */

            if ($groupMatched) {
                $ruleMatched = true;
                break;
            }
        }


        if (!$ruleMatched) {
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Obtain message template
        |--------------------------------------------------------------------------
        */

        $stmtMsg = $pdo->prepare("
            SELECT     title_template,body_template,recommendation_template
            FROM finding_messages
            WHERE message_code = ?
        ");

        $stmtMsg->execute([
            $rule['message_code']
        ]);

        $message = $stmtMsg->fetch();

        if (!$message) {
            continue;
        }


        $renderedTitle = renderFindingTemplate(
            $pdo,
            $sessionId,
            $message['title_template']
        );

        $replacements = [
            '{ram_slots_reported}' => fmtNumber(
                getLatestObservation($pdo, $sessionId, 'ram_slots_reported'),
                0
            ),

            '{ram_slots_used}' => fmtNumber(
                getLatestObservation($pdo, $sessionId, 'ram_slots_used'),
                0
            ),

            '{ram_slots_free}' => fmtNumber(
                getLatestObservation($pdo, $sessionId, 'ram_slots_free'),
                0
            )
        ];

        $renderedBody = strtr(
            $message['body_template'],
            $replacements
        );

        /*
        |--------------------------------------------------------------------------
        | Store generated finding
        |--------------------------------------------------------------------------
        */
        $renderedTitle = renderFindingTemplate(
            $pdo,
            $sessionId,
            $message['title_template']
        );

        $renderedBody = renderFindingTemplate(
            $pdo,
            $sessionId,
            $message['body_template']
        );
        $renderedRecommendation = null;

        if (!empty($message['recommendation_template'])) {
            $renderedRecommendation = renderFindingTemplate(
                $pdo,
                $sessionId,
                $message['recommendation_template']
            );
        }
        $stmtInsert = $pdo->prepare("
            INSERT INTO session_findings
            (
                session_id,
                finding_rule_id,
                finding_code,
                severity,
                rendered_title,
                rendered_body,
                rendered_recommendation,
                generated_at
            )
            VALUES
            (?, ?, ?, ?, ?, ?,?, NOW())
        ");

        $stmtInsert->execute([
            $sessionId,
            $rule['finding_rule_id'],
            $rule['finding_code'],
            $rule['severity'],
            $renderedTitle,
            $renderedBody,
            $renderedRecommendation
        ]);
    }
    /*
|--------------------------------------------------------------------------
| DISK-SPECIFIC FINDINGS
|--------------------------------------------------------------------------
*/

    $stmtDisks = $pdo->prepare("
    SELECT *
    FROM session_disks
    WHERE session_id = ?
");

    $stmtDisks->execute([$sessionId]);

    $disks = $stmtDisks->fetchAll();


    $stmtDiskRules = $pdo->prepare("
    SELECT *
    FROM disk_finding_rules
    WHERE active = 1
    ORDER BY priority, disk_finding_rule_id
");

    $stmtDiskRules->execute();

    $diskRules = $stmtDiskRules->fetchAll();


    foreach ($disks as $disk) {

        foreach ($diskRules as $rule) {

            $fieldCode = $rule['field_code'];

            if (!array_key_exists($fieldCode, $disk)) {
                continue;
            }

            $actual = $disk[$fieldCode];

            if ($actual === null) {
                continue;
            }

            $matched = compareFindingValue(
                $actual,
                $rule['operator'],
                $rule['compare_value']
            );

            if (!$matched) {
                continue;
            }


            /*
        |--------------------------------------------------------------------------
        | Load message
        |--------------------------------------------------------------------------
        */

            $stmtMessage = $pdo->prepare("
            SELECT
                title_template,
                body_template,
                recommendation_template
            FROM finding_messages
            WHERE message_code = ?
        ");

            $stmtMessage->execute([
                $rule['message_code']
            ]);

            $message = $stmtMessage->fetch();

            if (!$message) {
                continue;
            }


            /*
        |--------------------------------------------------------------------------
        | Render disk-specific placeholders
        |--------------------------------------------------------------------------
        */

            $replacements = [
                '{disk_model}' =>
                $disk['model'] ?? '',

                '{read_errors_total}' =>
                fmtNumber($disk['read_errors_total'] ?? 0),

                '{read_errors_corrected}' =>
                fmtNumber($disk['read_errors_corrected'] ?? 0),

                '{read_errors_uncorrected}' =>
                fmtNumber($disk['read_errors_uncorrected'] ?? 0),

                '{temperature_c}' =>
                fmtNumber($disk['temperature_c'] ?? 0),

                '{power_on_hours}' =>
                fmtNumber($disk['power_on_hours'] ?? 0)
            ];

            $renderedTitle = strtr(
                $message['title_template'],
                $replacements
            );

            $renderedBody = strtr(
                $message['body_template'],
                $replacements
            );

            $renderedRecommendation = null;

            if (!empty($message['recommendation_template'])) {

                $renderedRecommendation = strtr(
                    $message['recommendation_template'],
                    $replacements
                );
            }


            /*
        |--------------------------------------------------------------------------
        | Avoid duplicate finding for same disk
        |--------------------------------------------------------------------------
        */

            $stmtCheck = $pdo->prepare("
            SELECT session_finding_id
            FROM session_findings
            WHERE session_id = ?
              AND finding_code = ?
              AND source_entity_type = 'DISK'
              AND source_entity_id = ?
            LIMIT 1
        ");

            $stmtCheck->execute([
                $sessionId,
                $rule['finding_code'],
                $disk['session_disk_id']
            ]);

            if ($stmtCheck->fetch()) {
                continue;
            }


            /*
        |--------------------------------------------------------------------------
        | Insert disk finding
        |--------------------------------------------------------------------------
        */

            $stmtInsert = $pdo->prepare("
            INSERT INTO session_findings
            (
                session_id,
                finding_rule_id,
                disk_finding_rule_id,
                finding_code,
                severity,
                rendered_title,
                rendered_body,
                rendered_recommendation,
                source_entity_type,
                source_entity_id,
                generated_at
            )
            VALUES
            (
                ?,
                NULL,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                'DISK',
                ?,
                NOW()
            )
        ");

            $stmtInsert->execute([
                $sessionId,
                $rule['disk_finding_rule_id'],
                $rule['finding_code'],
                $rule['severity'],
                $renderedTitle,
                $renderedBody,
                $renderedRecommendation,
                $disk['session_disk_id']
            ]);
        }
    }
}

/*
|--------------------------------------------------------------------------
| New session
|--------------------------------------------------------------------------
*/

if (isset($_GET['new'])) {
    unset($_SESSION['pctoz_session_id']);
    header("Location: diagnose.php");
    exit;
}

if (!isset($_SESSION['pctoz_session_id'])) {
    $_SESSION['pctoz_session_id'] = createSession($pdo);
}

$sessionId = (int)$_SESSION['pctoz_session_id'];

$message = null;

/*
|--------------------------------------------------------------------------
| TECHNICIAN FEEDBACK
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'tech_feedback'
) {

    $nodeId = trim($_POST['node_id'] ?? '');
    $reviewArea = trim($_POST['review_area'] ?? '');
    $issueType = trim($_POST['issue_type'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $suggestedValue = trim($_POST['suggested_value'] ?? '');
    $suggestedNextNode = trim($_POST['suggested_next_node'] ?? '');
    $technicianName = trim($_POST['technician_name'] ?? '');

    if ($nodeId !== '' && $reviewArea !== '' && $issueType !== '') {

        $stmt = $pdo->prepare("
            INSERT INTO technician_suggestions
            (
                session_id,
                node_id,
                review_area,
                issue_type,
                current_value,
                suggested_value,
                suggested_next_node,
                comment,
                technician_name,
                created_at,
                status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW(),
                'OPEN'
            )
        ");

        $stmt->execute([
            $sessionId,
            $nodeId,
            $reviewArea,
            $issueType,
            $_POST['current_value'] ?? null,
            $suggestedValue ?: null,
            $suggestedNextNode ?: null,
            $comment ?: null,
            $technicianName ?: null
        ]);

        $message = "Technician feedback saved.";
    }
}
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'regenerate_findings'
) {

    generateSessionFindings($pdo, $sessionId);

    header("Location: diagnose.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| CONTINUE NON-QUESTION NODE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'continue_node'
) {

    $nodeId = trim($_POST['node_id'] ?? '');

    $node = getNode($pdo, $nodeId);

    if (!$node) {
        die("Invalid node.");
    }

    $nextNodeId = $node['default_next_node'];

    if (!$nextNodeId) {
        die("This node has no default next node.");
    }

    $seq = nextSequence($pdo, $sessionId);

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
        (?, ?, ?, NOW(), NOW(), ?, ?)
    ");

    $stmt->execute([
        $sessionId,
        $seq,
        $nodeId,
        $nextNodeId,
        'Continued from ' . $node['node_type'] . ' node'
    ]);

    $stmt = $pdo->prepare("
        UPDATE diagnostic_sessions
        SET current_node_id = ?
        WHERE session_id = ?
    ");

    $stmt->execute([
        $nextNodeId,
        $sessionId
    ]);

    header("Location: diagnose.php");
    exit;
}



if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'select_option'
) {

    $optionId = (int)($_POST['option_id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT *
        FROM tree_options
        WHERE option_id = ?
          AND active = 1
    ");
    $stmt->execute([$optionId]);

    $option = $stmt->fetch();

    if (!$option) {
        die("Invalid option.");
    }

    $currentNodeId = $option['node_id'];
    $nextNodeId = $option['next_node_id'];

    /*
    |--------------------------------------------------------------------------
    | Observation
    |--------------------------------------------------------------------------
    */

    if (
        !empty($option['store_field_code'])
        && $option['store_value'] !== null
    ) {

        $stmt = $pdo->prepare("
            INSERT INTO observations
            (
                session_id,
                field_code,
                value_text,
                source_type,
                source_detail,
                confidence,
                observed_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                'CUSTOMER',
                ?,
                'HIGH',
                NOW()
            )
        ");

        $stmt->execute([
            $sessionId,
            $option['store_field_code'],
            $option['store_value'],
            $currentNodeId
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Node visit
    |--------------------------------------------------------------------------
    */

    $seq = nextSequence($pdo, $sessionId);

    $stmt = $pdo->prepare("
        INSERT INTO node_visits
        (
            session_id,
            seq_no,
            node_id,
            entered_at,
            completed_at,
            selected_option_id,
            next_node_id,
            route_reason
        )
        VALUES
        (
            ?,
            ?,
            ?,
            NOW(),
            NOW(),
            ?,
            ?,
            ?
        )
    ");

    $stmt->execute([
        $sessionId,
        $seq,
        $currentNodeId,
        $optionId,
        $nextNodeId,
        'Selected option: ' . $option['option_text']
    ]);

    /*
    |--------------------------------------------------------------------------
    | Update current node
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE diagnostic_sessions
        SET current_node_id = ?
        WHERE session_id = ?
    ");

    $stmt->execute([
        $nextNodeId,
        $sessionId
    ]);

    header("Location: diagnose.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| BACK ONE DIAGNOSTIC STEP
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'back_node'
) {

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM node_visits
            WHERE session_id = ?
            ORDER BY seq_no DESC
            LIMIT 1
        ");

        $stmt->execute([$sessionId]);

        $lastVisit = $stmt->fetch();

        if ($lastVisit) {

            $previousNodeId = $lastVisit['node_id'];

            /*
            | Remove observations produced by the step being undone.
            | Current V0.1 uses source_detail/node code for user answers.
            */

            $stmt = $pdo->prepare("
                DELETE FROM observations
                WHERE session_id = ?
                  AND source_detail = ?
            ");

            $stmt->execute([
                $sessionId,
                $previousNodeId
            ]);

            /*
            | Remove that traversal
            */

            $stmt = $pdo->prepare("
                DELETE FROM node_visits
                WHERE node_visit_id = ?
            ");

            $stmt->execute([
                $lastVisit['node_visit_id']
            ]);

            /*
            | Return session to previous node
            */

            $stmt = $pdo->prepare("
                UPDATE diagnostic_sessions
                SET current_node_id = ?
                WHERE session_id = ?
            ");

            $stmt->execute([
                $previousNodeId,
                $sessionId
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {

        $pdo->rollBack();
        throw $e;
    }

    header("Location: diagnose.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| GET CURRENT NODE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM diagnostic_sessions
    WHERE session_id = ?
");
$stmt->execute([$sessionId]);

$sessionRow = $stmt->fetch();

if (!$sessionRow) {
    die("Diagnostic session not found.");
}

$currentNodeId = $sessionRow['current_node_id'];
if (
    $currentNodeId === 'ROOT-RUN010'
    && empty($sessionRow['collector_token'])
) {

    $collectorToken = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        UPDATE diagnostic_sessions
        SET
            collector_token = ?,
            collector_token_expires_at = DATE_ADD(NOW(), INTERVAL 2 HOUR),
            collector_status = 'WAITING'
        WHERE session_id = ?
    ");

    $stmt->execute([
        $collectorToken,
        $sessionId
    ]);

    $sessionRow['collector_token'] = $collectorToken;
    $sessionRow['collector_status'] = 'WAITING';
}

$node = getNode($pdo, $currentNodeId);
/*
if (
    $currentNodeId === 'ROOT-RUN010'
    && ($sessionRow['collector_status'] ?? '') === 'RECEIVED'
) {

    header("Location: diagnose.php");
    exit;
}
    */
/*
|--------------------------------------------------------------------------
| ROOT FINDINGS
|--------------------------------------------------------------------------
*/

$pcSummary = [];
$sessionFindings = [];

if (
    $node
    && $node['node_id'] === 'ROOT-S015'
) {

    generateSessionFindings(
        $pdo,
        $sessionId
    );
    $ramTotal = getLatestObservation(
        $pdo,
        $sessionId,
        'ram_total_gb'
    );

    $ramSlotsReported = getLatestObservation(
        $pdo,
        $sessionId,
        'ram_slots_reported'
    );

    $ramSlotsUsed = getLatestObservation(
        $pdo,
        $sessionId,
        'ram_slots_used'
    );

    $ramSlotsFree = getLatestObservation(
        $pdo,
        $sessionId,
        'ram_slots_free'
    );

    $systemDriveSize = getLatestObservation(
        $pdo,
        $sessionId,
        'system_drive_size_gb'
    );

    $systemDriveFree = getLatestObservation(
        $pdo,
        $sessionId,
        'system_drive_free_gb'
    );

    $systemDriveFreePct = getLatestObservation(
        $pdo,
        $sessionId,
        'system_drive_free_pct'
    );

    $physicalDiskSize = getLatestObservation(
        $pdo,
        $sessionId,
        'physical_disk_size_gb'
    );

    $physicalDiskType = getLatestObservation(
        $pdo,
        $sessionId,
        'physical_disk_media_type'
    );

    $physicalDiskHealth = getLatestObservation(
        $pdo,
        $sessionId,
        'physical_disk_health'
    );

    $diskBus = getLatestObservation(
        $pdo,
        $sessionId,
        'system_disk_bus'
    );
    $stmt = $pdo->prepare("
    SELECT *
    FROM session_disks
    WHERE session_id = ?
    ORDER BY session_disk_id
");

    $stmt->execute([$sessionId]);

    $sessionDisks = $stmt->fetchAll();


    $stmt = $pdo->prepare("
    SELECT *
    FROM session_ram_modules
    WHERE session_id = ?
    ORDER BY session_ram_id
");

    $stmt->execute([$sessionId]);

    $sessionRamModules = $stmt->fetchAll();
    /*
    |--------------------------------------------------------------------------
    | Basic machine summary
    |--------------------------------------------------------------------------
    */

    $summaryFields = [
        'manufacturer',
        'model',
        'windows_name',
        'cpu_model',
        'ram_total_gb',
        'ram_slots_reported',
        'ram_slots_used',
        'ram_slots_free',
        'system_drive_size_gb',
        'system_drive_free_gb',
        'system_drive_free_pct',
        'system_disk_model',
        'system_disk_bus'
    ];

    foreach ($summaryFields as $field) {

        $pcSummary[$field] =
            getLatestObservation(
                $pdo,
                $sessionId,
                $field
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Generated findings
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT *
        FROM session_findings
        WHERE session_id = ?
        ORDER BY
            CASE severity
                WHEN 'CRITICAL' THEN 1
                WHEN 'IMPORTANT' THEN 2
                WHEN 'WARNING' THEN 3
                WHEN 'INFO' THEN 4
                ELSE 5
            END,
            session_finding_id
    ");

    $stmt->execute([$sessionId]);

    $sessionFindings = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| RUN NODE EXECUTION
|--------------------------------------------------------------------------
*/

if (
    $node
    && $node['node_type'] === 'RUN'
    && $node['tool_code'] === 'PC_CHECK'
) {

    $psFile = __DIR__ . DIRECTORY_SEPARATOR . 'pc_check.ps1';
    $jsonFile = __DIR__ . DIRECTORY_SEPARATOR . 'pc_check_result.json';

    if (file_exists($jsonFile)) {
        unlink($jsonFile);
    }

    $command =
        'powershell.exe -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($psFile)
        . ' 2>&1';

    $output = shell_exec($command);

    if (!file_exists($jsonFile)) {
        die("PC collector did not create pc_check_result.json.<br><br>"
            . nl2br(htmlspecialchars($output ?? '')));
    }

    $json = file_get_contents($jsonFile);

    /*
|--------------------------------------------------------------------------
| Remove UTF-8 BOM if PowerShell wrote one
|--------------------------------------------------------------------------
*/

    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);
    $json = trim($json);

    $data = json_decode($json, true);

    if (!is_array($data)) {

        die("Collector JSON could not be read.<br><br>" .
            "JSON error: " .
            htmlspecialchars(json_last_error_msg()) .
            "<br><br>" .
            "<pre>" .
            htmlspecialchars($json) .
            "</pre>");
    }
    /*
    |--------------------------------------------------------------------------
    | Store every simple collector value as an observation
    |--------------------------------------------------------------------------
    */

    foreach ($data as $fieldCode => $value) {

        if (is_array($value) || is_object($value)) {
            continue;
        }

        $valueNumber = null;
        $valueText = null;

        if (is_numeric($value)) {
            $valueNumber = $value;
        } elseif ($value !== null) {
            $valueText = (string)$value;
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
    | Record RUN node visit
    |--------------------------------------------------------------------------
    */

    $nextNodeId = $node['default_next_node'];

    $seq = nextSequence($pdo, $sessionId);

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
        (?, ?, ?, NOW(), NOW(), ?, ?)
    ");

    $stmt->execute([
        $sessionId,
        $seq,
        $node['node_id'],
        $nextNodeId,
        'PC_CHECK collector completed'
    ]);

    $stmt = $pdo->prepare("
        UPDATE diagnostic_sessions
        SET current_node_id = ?
        WHERE session_id = ?
    ");

    $stmt->execute([
        $nextNodeId,
        $sessionId
    ]);

    header("Location: diagnose.php");
    exit;
}

if (!$node) {
    $nodeError =
        "Node '" .
        htmlspecialchars($currentNodeId) .
        "' does not exist or is inactive.";

    $options = [];
} else {
    $nodeError = null;

    if ($node['node_type'] === 'Q') {
        $options = getOptions($pdo, $currentNodeId);
    } else {
        $options = [];
    }
}
if ($node && $node['node_type'] === 'DECIDE') {

    $stmt = $pdo->prepare("
        SELECT *
        FROM tree_rules
        WHERE node_id = ?
          AND active = 1
        ORDER BY priority, rule_id
    ");

    $stmt->execute([$node['node_id']]);

    $rules = $stmt->fetchAll();

    $matchedRule = null;

    foreach ($rules as $rule) {

        $stmtObs = $pdo->prepare("
            SELECT value_text, value_number
            FROM observations
            WHERE session_id = ?
              AND field_code = ?
            ORDER BY observed_at DESC,
                     observation_id DESC
            LIMIT 1
        ");

        $stmtObs->execute([
            $sessionId,
            $rule['field_code']
        ]);

        $obs = $stmtObs->fetch();

        if (!$obs) {
            continue;
        }

        $actual =
            $obs['value_text'] !== null
            ? $obs['value_text']
            : $obs['value_number'];

        $match = false;

        switch ($rule['operator']) {

            case '=':
                $match =
                    ((string)$actual ===
                        (string)$rule['compare_value']);
                break;

            case '!=':
                $match =
                    ((string)$actual !==
                        (string)$rule['compare_value']);
                break;

            case '<':
                $match =
                    ((float)$actual <
                        (float)$rule['compare_value']);
                break;

            case '<=':
                $match =
                    ((float)$actual <=
                        (float)$rule['compare_value']);
                break;

            case '>':
                $match =
                    ((float)$actual >
                        (float)$rule['compare_value']);
                break;

            case '>=':
                $match =
                    ((float)$actual >=
                        (float)$rule['compare_value']);
                break;
        }

        if ($match) {
            $matchedRule = $rule;
            break;
        }
    }

    if ($matchedRule) {

        $seq = nextSequence($pdo, $sessionId);

        $routeReason =
            "Rule " .
            $matchedRule['rule_id'] .
            " matched: " .
            $matchedRule['field_code'] .
            " " .
            $matchedRule['operator'] .
            " " .
            $matchedRule['compare_value'] .
            ". Result: " .
            $matchedRule['result_code'];

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
            (?, ?, ?, NOW(), NOW(), ?, ?)
        ");

        $stmt->execute([
            $sessionId,
            $seq,
            $node['node_id'],
            $matchedRule['next_node_id'],
            $routeReason
        ]);

        $stmt = $pdo->prepare("
            UPDATE diagnostic_sessions
            SET current_node_id = ?
            WHERE session_id = ?
        ");

        $stmt->execute([
            $matchedRule['next_node_id'],
            $sessionId
        ]);

        header("Location: diagnose.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Previous suggestions for current node
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM technician_suggestions
    WHERE node_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$currentNodeId]);

$previousSuggestions = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>PCTOZ Diagnostic</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .header {
            margin-bottom: 20px;
        }

        .logo {
            font-size: 28px;
            font-weight: bold;
        }

        .session {
            color: #666;
            margin-top: 5px;
        }

        .card {
            background: white;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .node-id {
            font-size: 14px;
            color: #555;
            margin-bottom: 10px;
        }

        .question {
            font-size: 22px;
            margin-bottom: 25px;
        }

        .option {
            margin-bottom: 10px;
        }

        .option button {
            width: 100%;
            padding: 14px;
            text-align: left;
            font-size: 16px;
            cursor: pointer;
            border: 1px solid #bbb;
            background: #fafafa;
            border-radius: 6px;
        }

        .option button:hover {
            background: #eeeeee;
        }

        .error {
            background: #ffe5e5;
            padding: 14px;
            border-radius: 6px;
        }

        .success {
            background: #e7f7e7;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .info {
            background: #eef5ff;
            padding: 15px;
            border-radius: 6px;
        }

        .review-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-top: 12px;
            margin-bottom: 5px;
        }

        select,
        input[type=text],
        textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            font-size: 15px;
        }

        textarea {
            min-height: 90px;
        }

        .save-button {
            margin-top: 15px;
            padding: 12px 20px;
            cursor: pointer;
        }

        .suggestion {
            border-top: 1px solid #ddd;
            padding-top: 12px;
            margin-top: 12px;
        }

        .small {
            color: #666;
            font-size: 13px;
        }

        .footer {
            margin-top: 20px;
        }
    </style>

</head>

<body>

    <div class="container">

        <div class="header">

            <div class="logo">
                PCTOZ
            </div>

            <div class="session">
                Session <?php echo $sessionId; ?>
            </div>

        </div>


        <div class="card">

            <?php if ($message): ?>

                <div class="success">
                    <?php echo htmlspecialchars($message); ?>
                </div>

            <?php endif; ?>


            <div class="node-id">

                Current node:
                <strong>
                    <?php echo htmlspecialchars($currentNodeId); ?>
                </strong>

                <?php if ($node): ?>
                    |
                    Type:
                    <?php echo htmlspecialchars($node['node_type']); ?>
                <?php endif; ?>

            </div>


            <?php if ($nodeError): ?>

                <div class="error">
                    <?php echo $nodeError; ?>
                </div>

            <?php else: ?>

                <div class="question">
                    <?php
                    echo nl2br(
                        htmlspecialchars($node['display_text'])
                    );
                    ?>

                </div>
                <?php if ($node['node_id'] === 'ROOT-S015'): ?>

                    <div class="info" style="margin-bottom:20px;">

                        <h3 style="margin-top:0;">
                            This computer
                        </h3>

                        <strong>
                            <?php
                            echo htmlspecialchars(
                                trim(
                                    ($pcSummary['manufacturer'] ?? '')
                                        . ' '
                                        . ($pcSummary['model'] ?? '')
                                )
                            );
                            ?>
                        </strong>

                        <br><br>

                        <strong>Windows drive C:</strong>

                        <?php echo fmtNumber($systemDriveSize); ?> GB total ·

                        <?php echo fmtNumber($systemDriveFree); ?> GB free

                        <?php if ($systemDriveFreePct !== null): ?>

                            (
                            <?php echo fmtNumber($systemDriveFreePct); ?>%
                            )

                        <?php endif; ?>

                        <br>

                        <strong>CPU:</strong>
                        <?php
                        echo htmlspecialchars(
                            $pcSummary['cpu_model'] ?? 'Unknown'
                        );
                        ?>

                        <br>

                        <strong>RAM:</strong>
                        <?php echo fmtNumber($ramTotal); ?> GB

                        <?php if (
                            $ramSlotsReported !== null &&
                            $ramSlotsUsed !== null
                        ): ?>

                            —
                            <?php echo fmtNumber($ramSlotsUsed, 0); ?>
                            of
                            <?php echo fmtNumber($ramSlotsReported, 0); ?>
                            slots used

                            <?php if ($ramSlotsFree !== null): ?>
                                ·
                                <?php echo fmtNumber($ramSlotsFree, 0); ?>
                                reported free
                            <?php endif; ?>

                        <?php endif; ?>
                        <?php if (count($sessionRamModules) > 0): ?>

                            <div style="margin-top:10px;">

                                <strong>RAM modules:</strong>

                                <?php foreach ($sessionRamModules as $ram): ?>

                                    <div style="margin-top:5px;">

                                        <?php echo htmlspecialchars($ram['device_locator'] ?? 'Slot'); ?>:

                                        <?php echo fmtNumber($ram['capacity_gb']); ?> GB

                                        <?php if (!empty($ram['configured_speed_mhz'])): ?>
                                            —
                                            <?php echo (int)$ram['configured_speed_mhz']; ?> MHz
                                        <?php endif; ?>

                                        <?php if (!empty($ram['manufacturer'])): ?>
                                            —
                                            <?php echo htmlspecialchars($ram['manufacturer']); ?>
                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>


                        <br>

                        <?php if (count($sessionDisks) > 0): ?>

                            <div style="margin-top:12px;">

                                <strong>Storage devices:</strong>

                                <div style="margin-top:6px;">

                                    <?php foreach ($sessionDisks as $disk): ?>
                                        <?php
                                        $diskCondition = 'No warning detected';

                                        foreach ($sessionFindings as $sf) {

                                            if (
                                                ($sf['source_entity_type'] ?? '') === 'DISK'
                                                &&
                                                (int)($sf['source_entity_id'] ?? 0)
                                                === (int)$disk['session_disk_id']
                                            ) {

                                                $severity = strtoupper($sf['severity'] ?? '');

                                                if ($severity === 'CRITICAL') {
                                                    $diskCondition = 'Urgent';
                                                } elseif (in_array($severity, ['ATTENTION', 'IMPORTANT', 'WARNING'])) {
                                                    $diskCondition = 'Needs attention';
                                                } elseif ($severity === 'ADVISORY') {
                                                    $diskCondition = 'Monitor';
                                                }
                                            }
                                        } ?>
                                        <div style="margin-bottom:6px;">

                                            <?php echo htmlspecialchars($disk['model'] ?? 'Unknown disk'); ?>

                                            —
                                            <?php echo fmtNumber($disk['capacity_gb']); ?> GB

                                            <?php if (!empty($disk['bus_type'])): ?>
                                                <?php echo htmlspecialchars($disk['bus_type']); ?>
                                            <?php endif; ?>

                                            <?php if (!empty($disk['media_type'])): ?>
                                                <?php echo htmlspecialchars($disk['media_type']); ?>
                                            <?php endif; ?>

                                            <?php if (!empty($disk['health_status'])): ?>
                                                —
                                                Windows status:
                                                <?php echo htmlspecialchars($disk['health_status']); ?>
                                            <?php endif; ?>
                                            <br>
                                            <strong>PCTOZ condition:</strong>
                                            <?php echo htmlspecialchars($diskCondition); ?>

                                        </div>

                                        <?php if (($disk['reliability_status'] ?? '') === 'success'): ?>

                                            <div style="margin-left:18px;font-size:13px;color:#555;">

                                                <?php if ($disk['temperature_c'] !== null): ?>
                                                    Temperature:
                                                    <?php echo (int)$disk['temperature_c']; ?>°C
                                                    &nbsp;·&nbsp;
                                                <?php endif; ?>

                                                <?php if ($disk['power_on_hours'] !== null): ?>
                                                    Power-on:
                                                    <?php echo number_format((int)$disk['power_on_hours']); ?> hours
                                                    &nbsp;·&nbsp;
                                                <?php endif; ?>

                                                <?php if ($disk['read_errors_total'] !== null): ?>
                                                    Read errors:
                                                    <?php echo number_format((int)$disk['read_errors_total']); ?>

                                                    <?php if ($disk['read_errors_corrected'] !== null): ?>
                                                        (
                                                        <?php echo number_format((int)$disk['read_errors_corrected']); ?>
                                                        corrected
                                                        )
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                            </div>

                                        <?php endif; ?>


                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endif; ?>

                    </div>


                    <?php
                    $hasAttention = false;
                    $hasInfo = false;

                    foreach ($sessionFindings as $f) {
                        $severity = strtoupper($f['severity'] ?? '');

                        if (in_array($severity, ['CRITICAL', 'ATTENTION', 'IMPORTANT', 'WARNING'])) {
                            $hasAttention = true;
                        }

                        if (in_array($severity, ['INFO', 'ADVISORY'])) {
                            $hasInfo = true;
                        }
                    }
                    ?>
                    <?php
                    $attentionFindings = [];
                    $infoFindings = [];

                    foreach ($sessionFindings as $finding) {

                        $severity = strtoupper(
                            $finding['severity'] ?? ''
                        );

                        if (in_array(
                            $severity,
                            ['CRITICAL', 'ATTENTION', 'IMPORTANT', 'WARNING']
                        )) {
                            $attentionFindings[] = $finding;
                        } else {
                            $infoFindings[] = $finding;
                        }
                    }
                    ?>

                    <?php if (count($sessionFindings) === 0): ?>

                        <div class="info">

                            No immediate issue was identified by
                            the current basic checks.

                            <br><br>

                            This does not mean the computer has
                            no problem. We can continue with the
                            problem you came to diagnose.

                        </div>

                    <?php else: ?>


                        <?php if (count($attentionFindings) > 0): ?>

                            <h3>Needs attention</h3>

                            <?php foreach ($attentionFindings as $f): ?>

                                <?php includeFindingBlock($f); ?>

                            <?php endforeach; ?>

                        <?php endif; ?>


                        <?php if (count($infoFindings) > 0): ?>

                            <h3>Useful information</h3>

                            <?php foreach ($infoFindings as $f): ?>

                                <?php includeFindingBlock($f); ?>

                            <?php endforeach; ?>

                        <?php endif; ?>


                    <?php endif; ?>


                <?php endif; ?>
                <?php if ($node['node_type'] === 'Q'): ?>

                    <?php if (count($options) === 0): ?>

                        <div class="error">
                            This question has no active options.
                        </div>

                    <?php else: ?>

                        <?php foreach ($options as $option): ?>

                            <form method="post"
                                class="option">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="select_option">

                                <input
                                    type="hidden"
                                    name="option_id"
                                    value="<?php
                                            echo (int)$option['option_id'];
                                            ?>">

                                <button type="submit">

                                    <?php
                                    echo htmlspecialchars(
                                        $option['option_text']
                                    );
                                    ?>

                                </button>

                            </form>

                        <?php endforeach; ?>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="info">

                        Node type:
                        <strong>
                            <?php
                            echo htmlspecialchars(
                                $node['node_type']
                            );
                            ?>
                        </strong>

                        <br><br>

                        Purpose:
                        <?php
                        echo htmlspecialchars(
                            $node['purpose'] ?? ''
                        );
                        ?>

                    </div>
                    <?php if (
                        in_array(
                            $node['node_type'],
                            ['SHOW', 'ACTION', 'ROUTE']
                        )
                        && !empty($node['default_next_node'])
                    ): ?>

                        <form method="post" style="margin-top:20px;">

                            <input type="hidden"
                                name="action"
                                value="continue_node">

                            <input type="hidden"
                                name="node_id"
                                value="<?php echo htmlspecialchars($node['node_id']); ?>">

                            <button type="submit">
                                Continue
                            </button>

                        </form>

                    <?php endif; ?>
                <?php endif; ?>

            <?php endif; ?>
            <?php if (
                $node['node_id'] === 'ROOT-RUN010'
                && $node['tool_code'] === 'PC_CHECK_UPLOAD'
            ): ?>

                <div class="info">

                    <strong>PCTOZ PC Check</strong>

                    <p>
                        Run <strong>pc_check.ps1</strong> on the computer
                        that has the problem.
                    </p>

                    <p>
                        It will create:
                        <strong>pc_check_result.json</strong>
                    </p>

                    <p>
                        Upload that file below.
                    </p>
                    <?php if (($sessionRow['collector_status'] ?? '') !== 'RECEIVED'): ?>
                        <form
                            method="post"
                            action="upload_pc_check.php"
                            enctype="multipart/form-data">

                            <input
                                type="file"
                                name="pc_check_file"
                                accept=".json,application/json"
                                required>

                            <br><br>

                            <button type="submit">
                                Upload PC Check Result
                            </button>

                        </form>
                    <?php endif; ?>
                </div>
                <script>
                    setTimeout(function() {
                        window.location.reload();
                    }, 3000);
                </script>
                <div class="info">

                    <strong>PCTOZ PC Check</strong>

                    <p>
                        Session:
                        <strong><?php echo $sessionId; ?></strong>
                    </p>

                    <p>
                        Collector status:
                        <strong>
                            <?php
                            echo htmlspecialchars(
                                $sessionRow['collector_status'] ?? 'WAITING'
                            );
                            ?>
                        </strong>
                    </p>
                    <small>
                        Validation token:
                        <?php
                        echo htmlspecialchars(
                            $sessionRow['collector_token'] ?? ''
                        );
                        ?>
                    </small>
                    <?php
                    $launchUrl =
                        'pctozcheck://run?session=' .
                        urlencode((string)$sessionId) .
                        '&token=' .
                        urlencode((string)($sessionRow['collector_token'] ?? ''));
                    ?>

                    <p>
                        <a
                            href="<?php echo htmlspecialchars($launchUrl); ?>"
                            style="
            display:inline-block;
            padding:12px 18px;
            background:#222;
            color:white;
            text-decoration:none;
            border-radius:6px;
            font-weight:bold;
        ">
                            Check this PC
                        </a>
                    </p>

                    <p>
                        For validation, PCTOZCheck will eventually receive
                        this session automatically and return the PC data.
                    </p>

                </div>
            <?php endif; ?>

        </div>


        <div class="card">

            <div class="review-title">
                Technician Review — <?php echo htmlspecialchars($currentNodeId); ?>
            </div>

            <form method="post">

                <input
                    type="hidden"
                    name="action"
                    value="tech_feedback">

                <input
                    type="hidden"
                    name="node_id"
                    value="<?php echo htmlspecialchars($currentNodeId); ?>">

                <label>Technician</label>

                <input
                    type="text"
                    name="technician_name"
                    placeholder="Technician name or code">


                <label>What are you reviewing?</label>

                <select name="review_area" required>

                    <option value="">Select</option>

                    <option value="QUESTION">
                        Question
                    </option>

                    <option value="RULE">
                        Rule / condition
                    </option>

                    <option value="FINDING">
                        Finding / interpretation
                    </option>

                    <option value="NEXT_ACTION">
                        Next action / next node
                    </option>

                    <option value="MISSING_CHECK">
                        Missing check
                    </option>

                    <option value="OTHER">
                        Other
                    </option>

                </select>


                <label>Issue</label>

                <select name="issue_type" required>

                    <option value="">Select</option>

                    <option value="CORRECT">
                        Correct
                    </option>

                    <option value="MISSING">
                        Something is missing
                    </option>

                    <option value="REDUNDANT">
                        Redundant / unnecessary
                    </option>

                    <option value="WRONG">
                        Wrong
                    </option>

                    <option value="UNCLEAR">
                        Unclear wording
                    </option>

                    <option value="WRONG_THRESHOLD">
                        Threshold should change
                    </option>

                    <option value="WRONG_ROUTE">
                        Should go to another node
                    </option>

                    <option value="NEEDS_ESCALATION">
                        Should escalate
                    </option>

                </select>


                <label>Suggested change</label>

                <textarea
                    name="suggested_value"
                    placeholder="Example: Ask whether the PC freezes before checking disk usage."></textarea>


                <label>Suggested next node, if applicable</label>

                <input
                    type="text"
                    name="suggested_next_node"
                    placeholder="Example: MEM-Q010">


                <label>Comment / reason</label>

                <textarea
                    name="comment"
                    placeholder="Why do you recommend this change?"></textarea>


                <button
                    class="save-button"
                    type="submit">
                    Save Technician Feedback
                </button>

            </form>

        </div>


        <?php if (count($previousSuggestions) > 0): ?>

            <div class="card">

                <div class="review-title">
                    Previous Feedback for <?php echo htmlspecialchars($currentNodeId); ?>
                </div>

                <?php foreach ($previousSuggestions as $s): ?>

                    <div class="suggestion">

                        <strong>
                            <?php echo htmlspecialchars($s['review_area']); ?>
                            /
                            <?php echo htmlspecialchars($s['issue_type']); ?>
                        </strong>

                        <div class="small">

                            <?php echo htmlspecialchars($s['technician_name'] ?? 'Unknown'); ?>

                            |
                            <?php echo htmlspecialchars($s['created_at']); ?>

                            |
                            <?php echo htmlspecialchars($s['status']); ?>

                        </div>

                        <?php if (!empty($s['suggested_value'])): ?>

                            <p>
                                Suggested:
                                <?php echo nl2br(
                                    htmlspecialchars($s['suggested_value'])
                                ); ?>
                            </p>

                        <?php endif; ?>

                        <?php if (!empty($s['suggested_next_node'])): ?>

                            <p>
                                Suggested next:
                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $s['suggested_next_node']
                                    );
                                    ?>
                                </strong>
                            </p>

                        <?php endif; ?>

                        <?php if (!empty($s['comment'])): ?>

                            <p>
                                Comment:
                                <?php echo nl2br(
                                    htmlspecialchars($s['comment'])
                                ); ?>
                            </p>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <div class="footer">
            <form method="post" style="display:inline-block; margin-right:15px;">

                <input
                    type="hidden"
                    name="action"
                    value="back_node">

                <button type="submit">
                    ← Back
                </button>

            </form>

            <a href="diagnose.php?new=1">
                Start new diagnostic session
            </a>

        </div>

    </div>

</body>

</html>