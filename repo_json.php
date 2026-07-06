<?php
require_once __DIR__ . '/config/db.php';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT name, raw_json FROM repositorios WHERE id = ?");
$stmt->execute([$id]);

$repo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$repo) {
    die("Repositorio no encontrado");
}

$json = json_decode($repo['raw_json'], true);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>JSON - <?= htmlspecialchars($repo['name']) ?></title>

<style>

body{
    font-family: monospace;
    background:#111827;
    color:#f9fafb;
    margin:0;
    padding:20px;
}

h1{
    font-size:18px;
    margin-bottom:20px;
}

pre{
    white-space:pre-wrap;
    word-break:break-word;
    background:#0f172a;
    padding:20px;
    border-radius:8px;
}

</style>
</head>

<body>

<h1><?= htmlspecialchars($repo['name']) ?></h1>

<pre>
<?= json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?>
</pre>

</body>
</html>