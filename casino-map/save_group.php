<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['group_name'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$group_name = $conn->real_escape_string($_POST['group_name']);
$machine_ids = isset($_POST['machine_ids']) ? $_POST['machine_ids'] : [];

// Auto-assign color from palette if not provided
$palette = ['#4CAF50','#2196F3','#9C27B0','#FF9800','#F44336','#00BCD4','#FF5722','#795548','#607D8B','#E91E63','#3F51B5','#009688','#CDDC39','#FFC107','#8BC34A'];
$group_count_row = $conn->query("SELECT COUNT(*) as cnt FROM machine_groups")->fetch_assoc();
$auto_color = $palette[$group_count_row['cnt'] % count($palette)];
$color = isset($_POST['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'])
    ? $conn->real_escape_string($_POST['color'])
    : $auto_color;

$conn->begin_transaction();

try {
    $conn->query("INSERT INTO machine_groups (group_name, color) VALUES ('$group_name', '$color')");
    $group_id = $conn->insert_id;
    
    if (!empty($machine_ids)) {
        foreach($machine_ids as $machine_id) {
            $machine_id = intval($machine_id);
            $conn->query("INSERT INTO machine_group_relations (machine_id, group_id) VALUES ($machine_id, $group_id)");
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'group_id' => $group_id]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>