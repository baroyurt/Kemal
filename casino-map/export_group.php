<?php
session_start();

if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}

// Sadece admin Excel aktarabilir
if($_SESSION['role'] !== 'admin'){
    header("Location: map.php");
    exit;
}

include("config.php");
include("xlsx_helper.php");

if(!isset($_GET['group_id'])){
    die("Grup ID gerekli!");
}

$group_id = intval($_GET['group_id']);

$group = $conn->query("SELECT * FROM machine_groups WHERE id = $group_id")->fetch_assoc();
if(!$group){
    die("Grup bulunamadı!");
}

$machines = $conn->query("
    SELECT m.* 
    FROM machines m
    INNER JOIN machine_group_relations mgr ON m.id = mgr.machine_id
    WHERE mgr.group_id = $group_id
    ORDER BY m.machine_no
");

$z_levels = [
    0 => 'Yüksek Tavan',
    1 => 'Alçak Tavan',
    2 => 'Yeni VIP Salon',
    3 => 'Alt Salon'
];

$headers = ['Makine No', 'Machine PC', 'SMIBB IP', 'Screen IP', 'MAC Adresi', 'Seri Numarası', 'Bölge (Area)', 'Makine Türü', 'Oyun Türü', 'Z Katmanı', 'X Koordinatı', 'Y Koordinatı', 'Döndürme', 'Not'];
$rows = [];

while($row = $machines->fetch_assoc()){
    $rows[] = [
        $row['machine_no'],
        $row['machine_pc'] ?? '',
        $row['smibb_ip'] ?? '',
        $row['screen_ip'] ?? '',
        $row['mac'],
        $row['machine_seri_number'] ?? '',
        $row['area'] !== null ? $row['area'] : '',
        $row['machine_type'] ?? '',
        $row['game_type'] ?? '',
        $z_levels[$row['pos_z']] ?? ('Kat ' . $row['pos_z']),
        $row['pos_x'],
        $row['pos_y'],
        $row['rotation'] . '°',
        $row['note']
    ];
}

$filename = $group['group_name'] . '_' . date('Y-m-d') . '.xlsx';
send_xlsx($filename, $headers, $rows);
