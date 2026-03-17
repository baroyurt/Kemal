<?php
session_start();
if (!isset($_SESSION['login'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Missing id']);
    exit;
}

$machine_id = intval($_GET['id']);
$machine = $conn->query("SELECT * FROM machines WHERE id = $machine_id")->fetch_assoc();

if (!$machine) {
    echo json_encode(['error' => 'Machine not found']);
    exit;
}

// Groups this machine belongs to
$groups = [];
$gres = $conn->query(
    "SELECT mg.* FROM machine_groups mg
     INNER JOIN machine_group_relations mgr ON mg.id = mgr.group_id
     WHERE mgr.machine_id = $machine_id"
);
while ($g = $gres->fetch_assoc()) {
    $groups[] = [
        'id'    => $g['id'],
        'name'  => $g['group_name'],
        'color' => $g['color'] ?? '#4CAF50',
        'icon'  => $g['icon'] ?? 'fa-server'
    ];
}

// Connections where this machine is the source
$connections_out = [];
$cres = $conn->query(
    "SELECT c.*, m.machine_no AS target_machine_no
     FROM connections c
     LEFT JOIN machines m ON m.id = c.target_machine_id
     WHERE c.source_machine_id = $machine_id"
);
while ($c = $cres->fetch_assoc()) {
    $connections_out[] = $c;
}

// Connections where this machine is the target
$connections_in = [];
$cres2 = $conn->query(
    "SELECT c.*, m.machine_no AS source_machine_no
     FROM connections c
     INNER JOIN machines m ON m.id = c.source_machine_id
     WHERE c.target_machine_id = $machine_id"
);
while ($c = $cres2->fetch_assoc()) {
    $connections_in[] = $c;
}

header('Content-Type: application/json');
echo json_encode([
    'machine'         => $machine,
    'groups'          => $groups,
    'connections_out' => $connections_out,
    'connections_in'  => $connections_in
]);
?>
