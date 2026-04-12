<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://v1.nitj.ac.in'); // ERP domain

require __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $occupied  = (int) $pdo->query("SELECT COUNT(*) FROM seats WHERE is_occupied = 1")->fetchColumn();
    $total     = 390;
    $available = $total - $occupied;

    echo json_encode([
        'total'     => $total,
        'occupied'  => $occupied,
        'available' => $available,
        'percent'   => round($occupied / $total * 100),
        'status'    => $available > 50 ? 'good' : ($available > 10 ? 'filling' : 'full'),
        'updated'   => date('h:i A'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not fetch status']);
}
