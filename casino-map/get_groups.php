<?php
session_start();
if (!isset($_SESSION['login'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("config.php");

$groups = [];
$result = $conn->query("SELECT * FROM machine_groups ORDER BY group_name");

while($group = $result->fetch_assoc()) {
    $machine_ids = [];
    $machine_result = $conn->query("SELECT machine_id FROM machine_group_relations WHERE group_id = " . $group['id']);
    while($row = $machine_result->fetch_assoc()) {
        $machine_ids[] = intval($row['machine_id']);
    }
    
    $groups[$group['id']] = [
        'id' => $group['id'],
        'name' => $group['group_name'],
        'description' => $group['description'],
        'color' => $group['color'] ?? '#4CAF50',
        'machines' => $machine_ids
    ];
}

header('Content-Type: application/json');
echo json_encode($groups);
?>