<?php
require __DIR__ . '/config.php';

$host = getenv('MYSQLHOST') ?: DB_HOST;
$user = getenv('MYSQLUSER') ?: DB_USER;
$pass = getenv('MYSQLPASSWORD') ?: DB_PASS;
$db   = getenv('MYSQLDATABASE') ?: DB_NAME;
$port = getenv('MYSQLPORT') ?: DB_PORT;

$pdo = new PDO(
    "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
    $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec("UPDATE sessions SET exit_time = NOW() WHERE exit_time IS NULL");
$pdo->exec("UPDATE seats SET is_occupied = 0, current_student_id = NULL");

echo "✅ All seats reset successfully!\n";
