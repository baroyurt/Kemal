<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Yetkisiz erişim.']);
    exit;
}

include("config.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Geçersiz istek.']);
    exit;
}

csrf_verify();

$machine_no   = trim($_POST['machine_no']   ?? '');
$smibb_ip     = trim($_POST['smibb_ip']     ?? '');
$screen_ip    = trim($_POST['screen_ip']    ?? '');
$mac          = trim($_POST['mac']          ?? '');
$pos_z        = intval($_POST['pos_z']      ?? 0);
$note         = trim($_POST['note']         ?? '');
$game_type    = trim($_POST['game_type']    ?? '');
$machine_type = trim($_POST['machine_type'] ?? '');
// Validate game_type — all floors can have a type (slot = normal machine, others = live tables)
$allowed_types = ['', 'slot', 'poker', 'rulet', 'barbut'];
if (!in_array($game_type, $allowed_types, true)) {
    $game_type = '';
}

if ($machine_no === '' || $smibb_ip === '' || $mac === '') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Makine No, Smibb IP ve MAC Adresi zorunludur.']);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO machines (machine_no, smibb_ip, screen_ip, mac, pos_z, pos_x, pos_y, rotation, note, game_type, machine_type) VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?)"
);
$stmt->bind_param("ssssisss", $machine_no, $smibb_ip, $screen_ip, $mac, $pos_z, $note, $game_type, $machine_type);

if ($stmt->execute()) {
    $new_id = $conn->insert_id;
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'id'           => $new_id,
        'machine_no'   => $machine_no,
        'smibb_ip'     => $smibb_ip,
        'screen_ip'    => $screen_ip,
        'mac'          => $mac,
        'pos_z'        => $pos_z,
        'note'         => $note,
        'game_type'    => $game_type,
        'machine_type' => $machine_type,
    ]);
} else {
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Veritabanı hatası: ' . $conn->error]);
}
