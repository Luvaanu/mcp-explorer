<?php
$host = "localhost";
$db   = "mcp_explorer";
$user = "root";
$pass = "";

$github_token = getenv('GITHUB_TOKEN');

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error conexión DB: " . $e->getMessage());
}