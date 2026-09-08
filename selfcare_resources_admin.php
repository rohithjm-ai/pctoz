<?php
require 'config.php';

$message = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'add_resource'
) {

    $title = trim($_POST['title'] ?? '');
    $provider = trim($_POST['provider'] ?? '');
    $resourceType = trim($_POST['resource_type'] ?? 'WEB');
    $url = trim($_POST['url'] ?? '');
    $status = trim($_POST['status'] ?? 'SUGGESTED');
    $technicianComment = trim($_POST['technician_comment'] ?? '');
    $addedBy = trim($_POST['added_by'] ?? '');

    if ($title !== '' && $url !== '') {

        $stmt = $pdo->prepare("
            INSERT INTO selfcare_resources
            (
                title,
                provider,
                resource_type,
                url,
                status,
                added_by,
                added_at,
                last_verified_at,
                technician_comment
            )
            VALUES
            (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
        ");

        $stmt->execute([
            $title,
            $provider ?: null,
            $resourceType,
            $url,
            $status,
            $addedBy ?: null,
            $technicianComment ?: null
        ]);

        $message = 'Resource added.';
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_comment'
) {

    $resourceId = (int)($_POST['resource_id'] ?? 0);
    $comment = trim($_POST['technician_comment'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE selfcare_resources
        SET technician_comment = ?
        WHERE resource_id = ?
    ");

    $stmt->execute([
        $comment ?: null,
        $resourceId
    ]);

    $message = 'Comment updated.';
}

$stmt = $pdo->query("
    SELECT *
    FROM selfcare_resources
    ORDER BY
        FIELD(status,'SUGGESTED','REVIEWED','ACTIVE','RETIRED'),
        resource_id DESC
");

$resources = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PCTOZ Self-care Resources</title>

<style>
body {
    font-family: Arial, sans-serif;
    background:#f4f6f8;
    margin:0;
    padding:30px;
}

.container {
    max-width:1000px;
    margin:auto;
}

.card {
    background:white;
    padding:20px;
    margin-bottom:20px;
    border-radius:8px;
}

input,
select,
textarea {
    width:100%;
    box-sizing:border-box;
    padding:9px;
    margin-bottom:10px;
}

textarea {
    min-height:70px;
}

button {
    padding:10px 16px;
    cursor:pointer;
}

.resource {
    border-top:1px solid #ddd;
    padding-top:15px;
    margin-top:15px;
}

.small {
    color:#666;
    font-size:13px;
}
</style>
</head>

<body>

<div class="container">

<h1>PCTOZ Self-care Resources</h1>

<?php if ($message): ?>
<div class="card">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<div class="card">

<h2>Add resource</h2>

<form method="post">

<input type="hidden"
       name="action"
       value="add_resource">

<label>Title</label>
<input type="text"
       name="title"
       required>

<label>Provider</label>
<input type="text"
       name="provider">

<label>Type</label>
<select name="resource_type">
    <option value="WEB">Web page</option>
    <option value="VIDEO">Video</option>
    <option value="TOOL">Tool</option>
    <option value="DOCUMENT">Document</option>
    <option value="OTHER">Other</option>
</select>

<label>URL</label>
<input type="url"
       name="url"
       required>

<label>Status</label>
<select name="status">
    <option value="SUGGESTED">Suggested</option>
    <option value="REVIEWED">Reviewed</option>
    <option value="ACTIVE">Active</option>
    <option value="RETIRED">Retired</option>
</select>

<label>Technician</label>
<input type="text"
       name="added_by">

<label>Comment</label>
<textarea name="technician_comment"></textarea>

<button type="submit">
    Add resource
</button>

</form>

</div>

<div class="card">

<h2>Existing resources</h2>

<?php foreach ($resources as $r): ?>

<div class="resource">

<strong>
<?php echo htmlspecialchars($r['title']); ?>
</strong>

<div class="small">
ID <?php echo (int)$r['resource_id']; ?>
|
<?php echo htmlspecialchars($r['provider'] ?? ''); ?>
|
<?php echo htmlspecialchars($r['resource_type']); ?>
|
<?php echo htmlspecialchars($r['status']); ?>
</div>

<p>
<a href="<?php echo htmlspecialchars($r['url']); ?>"
   target="_blank"
   rel="noopener noreferrer">
    Open resource
</a>
</p>

<form method="post">

<input type="hidden"
       name="action"
       value="update_comment">

<input type="hidden"
       name="resource_id"
       value="<?php echo (int)$r['resource_id']; ?>">

<label>Technician comment</label>

<textarea name="technician_comment"><?php
echo htmlspecialchars(
    $r['technician_comment'] ?? ''
);
?></textarea>

<button type="submit">
    Save comment
</button>

</form>

</div>

<?php endforeach; ?>

</div>

</div>

</body>
</html>