<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

$db = DB::getInstance();
$stmt = $db->query("SELECT COUNT(*) as c FROM hitster_sessions");
$count = $stmt->fetch()['c'];

echo json_encode([
    "status" => "ok",
    "uptime_seconds" => -1, // Not applicable in PHP shared hosting
    "active_sessions" => $count
]);
