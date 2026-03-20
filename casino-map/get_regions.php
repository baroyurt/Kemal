<?php
session_start();
if (!isset($_SESSION['login'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("config.php");

$regions = [];
$result = $conn->query("SELECT * FROM regions ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $regions[] = $row;
}

header('Content-Type: application/json');
echo json_encode($regions);
?>
