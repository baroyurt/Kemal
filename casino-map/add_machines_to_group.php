<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['group_id']) || !isset($_POST['machine_ids'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$group_id = intval($_POST['group_id']);
$machine_ids = $_POST['machine_ids'];

$conn->begin_transaction();

try {
    $added_count = 0;
    foreach($machine_ids as $machine_id) {
        $machine_id = intval($machine_id);
        $check = $conn->query("SELECT * FROM machine_group_relations WHERE group_id = $group_id AND machine_id = $machine_id");
        if ($check->num_rows == 0) {
            $conn->query("INSERT INTO machine_group_relations (machine_id, group_id) VALUES ($machine_id, $group_id)");
            $added_count++;
        }
    }
    $conn->commit();
    echo json_encode(['success' => true, 'added' => $added_count]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>