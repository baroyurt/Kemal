<?php
session_start();

if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}

include("config.php");

$role = $_SESSION['role'];
$result = $conn->query("SELECT * FROM machines ORDER BY pos_z, machine_no");
$groups = $conn->query("SELECT * FROM machine_groups ORDER BY group_name");
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Makine Haritası</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: all 0.3s ease;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        body.light-theme { background: #f0f0f0; color: #333; }
        body.light-theme .toolbar { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        /* Left sidebar */
        #sidebar {
            position: fixed; left: 0; top: 0; bottom: 0; width: 240px;
            background: white; box-shadow: 3px 0 15px rgba(0,0,0,0.15); z-index: 3000;
            transform: translateX(-100%); transition: transform 0.3s ease;
            display: flex; flex-direction: column; overflow-y: auto;
        }
        #sidebar.open { transform: translateX(0); }
        .dark-theme #sidebar { background: #2d2d2d; color: #eee; }
        .sidebar-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 2999;
            display: none;
        }
        .sidebar-overlay.open { display: block; }
        .sidebar-header {
            padding: 16px 18px; background: #4CAF50; color: white;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .sidebar-header h3 { font-size: 15px; margin: 0; }
        .sidebar-close {
            background: none; border: none; color: white; font-size: 22px;
            cursor: pointer; line-height: 1; padding: 0;
        }
        .sidebar-user {
            padding: 12px 18px; font-size: 13px; color: #888;
            border-bottom: 1px solid #eee; flex-shrink: 0;
        }
        .dark-theme .sidebar-user { border-color: #444; color: #aaa; }
        .sidebar-menu { flex: 1; padding: 8px 0; }
        .sidebar-item { border-bottom: 1px solid #f0f0f0; }
        .dark-theme .sidebar-item { border-color: #3a3a3a; }
        .sidebar-item a {
            display: block; padding: 12px 18px; color: #333; text-decoration: none;
            transition: background 0.2s; font-size: 14px;
        }
        .dark-theme .sidebar-item a { color: #eee; }
        .sidebar-item a:hover { background: #f0f9f0; }
        .dark-theme .sidebar-item a:hover { background: #3a3a3a; }
        .sidebar-item small { color: #888; display: block; font-size: 11px; margin-top: 2px; }
        .dark-theme .sidebar-item small { color: #aaa; }
        .sidebar-item a.active-page { background: #e8f5e9; color: #2e7d32; font-weight: bold; }
        .dark-theme .sidebar-item a.active-page { background: #1b4a1e; color: #81c784; }
        .sidebar-footer {
            padding: 12px 18px; border-top: 1px solid #eee; flex-shrink: 0;
        }
        .dark-theme .sidebar-footer { border-color: #444; }
        .sidebar-footer a { color: #f44336; text-decoration: none; font-size: 13px; }
        body.light-theme #map { background-color: #e0e0e0; }
        body.dark-theme { background: #1a1a1a; color: #fff; }
        body.dark-theme .toolbar { background: #2d2d2d; box-shadow: 0 2px 10px rgba(0,0,0,0.3); color: #fff; }
        body.dark-theme #map { background-color: #333; }
        body.dark-theme .machine { background: #4CAF50; color: white; border-color: #666; }
        body.dark-theme .machine.has-note { background: #40E0D0; }
        body.dark-theme .machine.selected { border: 3px solid yellow !important; }
        body.dark-theme input, body.dark-theme select, body.dark-theme textarea { background: #444; color: #fff; border-color: #666; }
        
        .toolbar { padding: 6px 10px; transition: all 0.3s ease; z-index: 1000; display: flex; flex-wrap: nowrap; gap: 4px; align-items: center; justify-content: space-between; overflow: hidden; flex-shrink: 0; position: sticky; top: 0; }
        .toolbar-left { display: flex; gap: 4px; flex-wrap: nowrap; align-items: center; flex: 1; min-width: 0; overflow: visible; }
        .toolbar-right { display: flex; gap: 4px; align-items: center; flex-shrink: 0; }
        .toolbar-btn {
            width: 34px; height: 34px; border-radius: 7px; background: #4CAF50; color: white;
            border: none; cursor: pointer; font-size: 15px; display: flex; align-items: center;
            justify-content: center; transition: all 0.2s; position: relative; flex-shrink: 0;
        }
        .toolbar-btn:hover { background: #45a049; transform: translateY(-1px); box-shadow: 0 3px 6px rgba(0,0,0,0.2); }
        .toolbar-btn.dashboard { background: #FF9800; }
        .toolbar-btn.theme { background: #666; }
        .toolbar-btn.zoom { background: #2196F3; width: 30px; height: 30px; }
        .toolbar-btn.purple { background: #9C27B0; }
        .toolbar-btn.orange { background: #FF9800; }
        .toolbar-btn.red { background: #f44336; }
        .toolbar-divider { width: 1px; height: 26px; background: #ccc; margin: 0 4px; flex-shrink: 0; }
        .dark-theme .toolbar-divider { background: #555; }
        .toolbar-group { display: flex; gap: 2px; align-items: center; background: rgba(0,0,0,0.05); padding: 2px; border-radius: 8px; flex-shrink: 0; }
        .tooltip-text {
            position: absolute; bottom: -30px; left: 50%; transform: translateX(-50%);
            background: rgba(0,0,0,0.8); color: white; padding: 4px 8px; border-radius: 4px;
            font-size: 11px; white-space: nowrap; visibility: hidden; opacity: 0;
            transition: all 0.2s; z-index: 10000; pointer-events: none;
        }
        .toolbar-btn:hover .tooltip-text { visibility: visible; opacity: 1; bottom: -40px; }
        
        #search, #z-filter, #group-filter { padding: 5px 10px; border: 1px solid #ddd; border-radius: 16px; font-size: 12px; min-width: 110px; height: 34px; }
        
        #map-container { flex: 1; position: relative; overflow: hidden; cursor: default; }
        #map { width: 100%; height: 100%; position: relative; background-size: cover; transition: background-color 0.3s ease; transform-origin: 0 0; }
        
        .machine {
            width: 60px; height: 60px; background: #4CAF50; border: 1px solid rgba(255,255,255,0.6);
            position: absolute; display: flex; flex-direction: column; align-items: center; justify-content: center;
            <?php if($role == 'admin'): ?>cursor: move;<?php else: ?>cursor: default;<?php endif; ?>
            transition: all 0.2s ease; user-select: none; font-weight: bold;
            box-shadow: 5px 5px 8px rgba(0,0,0,0.5); border-radius: 10px; font-size: 12px;
            color: white; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); z-index: 1;
        }
        .machine.selected { border: 3px solid yellow !important; box-shadow: 0 0 20px rgba(255,255,0,0.5), 5px 5px 8px rgba(0,0,0,0.5); z-index: 1000; }
        .machine.has-note { background: #40E0D0; }
        .machine[data-z="0"] { opacity: 1; } .machine[data-z="1"] { opacity: 0.9; } .machine[data-z="2"] { opacity: 0.8; } .machine[data-z="3"] { opacity: 0.7; }
        .machine:hover { filter: brightness(1.2); z-index: 1001; }
        /* Orijinal SVG'deki separator çizgisi — ikonun üst kısmında siyah yatay bar */
        .machine::before {
            content: ''; position: absolute; top: 14px; left: 10px; right: 10px;
            height: 4px; background: rgba(0,0,0,0.55); border-radius: 2px; pointer-events: none;
        }
        /* Counter-rotate inner content so labels stay upright regardless of machine rotation */
        .machine-inner {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            width: 100%; height: 100%; pointer-events: none; transition: transform 0.2s;
            overflow: hidden; padding-top: 10px;
        }
        .machine-label { font-size: 11px; font-weight: bold; line-height: 1.2; text-align: center; max-width: 56px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .machine-ip    { font-size: 8px;  opacity: 0.85; text-align: center; line-height: 1.2; max-width: 56px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .z-level { font-size: 8px; background: rgba(0,0,0,0.4); padding: 1px 3px; border-radius: 3px; margin-top: 1px; }
        .machine-rot { font-size: 8px; opacity: 0.9; text-align: center; line-height: 1.2; letter-spacing: 0.3px; }
        /* Hub SW */
        .machine.has-hub-sw { border: 3px solid #FF9800 !important; box-shadow: 0 0 12px rgba(255,152,0,0.5); }
        .hub-sw-badge { position: absolute; top: -6px; left: -6px; width: 16px; height: 16px; background: #FF9800; border-radius: 50%; border: 2px solid white; font-size: 9px; display: flex; align-items: center; justify-content: center; color: white; z-index: 10; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .dark-theme .machine.has-hub-sw { border-color: #FF9800 !important; }
        .floor-tabs { display: flex; gap: 2px; flex-shrink: 0; }
        .floor-tab { padding: 4px 8px; border: none; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: bold; background: #e0e0e0; color: #555; transition: all 0.2s; height: 30px; white-space: nowrap; }
        .floor-tab.active { background: #4CAF50; color: white; box-shadow: 0 2px 8px rgba(76,175,80,0.4); }
        .floor-tab:hover:not(.active) { background: #c8e6c9; color: #2e7d32; }
        .dark-theme .floor-tab { background: #444; color: #ccc; }
        .dark-theme .floor-tab.active { background: #4CAF50; color: white; }
        /* Casino tab — distinct red/gold style */
        .floor-tab.casino-tab { background: #B71C1C; color: #FFD700; border: 1px solid #FFD700; }
        .floor-tab.casino-tab.active { background: #7B1FA2; color: #FFD700; box-shadow: 0 2px 8px rgba(123,31,162,0.5); border-color: #FFD700; }
        .floor-tab.casino-tab:hover:not(.active) { background: #c62828; color: #FFD700; }
        .dark-theme .floor-tab.casino-tab { background: #4a0000; color: #FFD700; }
        /* ===== CASINO MODE — slot machines locked (not live tables) ===== */
        #map-container.casino-mode .machine:not([data-z="10"]):not([data-game-type="poker"]):not([data-game-type="rulet"]):not([data-game-type="barbut"]) {
            pointer-events: none !important;
            opacity: 0.20 !important;
            filter: grayscale(70%);
            cursor: default !important;
        }
        /* All live tables in casino mode (any floor) — elevated "upper floor" appearance */
        #map-container.casino-mode .machine[data-z="10"],
        #map-container.casino-mode .machine[data-game-type="poker"],
        #map-container.casino-mode .machine[data-game-type="rulet"],
        #map-container.casino-mode .machine[data-game-type="barbut"] {
            z-index: 100 !important;
            transform: scale(1.10);
            box-shadow: 0 10px 28px rgba(0,0,0,0.65), 0 3px 8px rgba(0,0,0,0.4) !important;
            filter: drop-shadow(0 0 7px rgba(255,215,0,0.35));
        }
        /* Upper-floor label: horizontal, positioned by JS to the left of Balkon zone */
        #casino-upper-floor-label {
            position: absolute;
            background: linear-gradient(90deg, rgba(123,31,162,0.92), rgba(183,28,28,0.92));
            color: #FFD700; font-weight: bold; font-size: 11px; letter-spacing: 0.8px;
            padding: 4px 14px; border-radius: 6px; z-index: 200;
            border: 1px solid rgba(255,215,0,0.5); pointer-events: none; white-space: nowrap;
            box-shadow: 0 3px 12px rgba(0,0,0,0.4);
        }
        /* Casino zone overlay boxes (Balkon / VIP / Eski VIP) */
        .casino-zone-overlay {
            position: absolute; pointer-events: none; z-index: 50;
            border-radius: 10px;
        }
        .casino-zone-label {
            position: absolute; pointer-events: none; z-index: 52;
            font-size: 12px; font-weight: bold; white-space: nowrap;
            padding: 3px 10px; border-radius: 5px;
            letter-spacing: 0.5px;
        }

        /* ===== CASINO TABLE TYPES ===== */
        /* Remove the black separator bar for casino tables */
        .machine[data-game-type="poker"]::before,
        .machine[data-game-type="rulet"]::before,
        .machine[data-game-type="barbut"]::before { display: none; }
        /* Casino table labels: hide IP, show name in white */
        .machine[data-game-type="poker"] .machine-ip,
        .machine[data-game-type="rulet"] .machine-ip,
        .machine[data-game-type="barbut"] .machine-ip { display: none; }
        .machine[data-game-type="poker"] .machine-label,
        .machine[data-game-type="rulet"] .machine-label,
        .machine[data-game-type="barbut"] .machine-label { font-size: 8px; color: #fff; font-weight: bold; }
        .machine[data-game-type="poker"] .z-level,
        .machine[data-game-type="rulet"] .z-level,
        .machine[data-game-type="barbut"] .z-level { font-size: 7px; }
        /* Poker — D-shaped (yarım daire): flat bottom, curved top */
        .machine[data-game-type="poker"] {
            width: 60px; height: 40px; border-radius: 30px 30px 0 0;
            background: linear-gradient(160deg, #256b3a 0%, #1B5E20 100%);
            border: 2px solid #795548 !important;
            box-shadow: 0 0 10px rgba(27,94,32,0.7), 2px 2px 5px rgba(0,0,0,0.5);
        }
        .machine[data-game-type="poker"]::after {
            content: '♠ POKER'; position: absolute; top: 3px; left: 50%; transform: translateX(-50%);
            font-size: 7px; font-weight: bold; color: rgba(255,255,255,0.85); letter-spacing: 0.5px; white-space: nowrap;
        }
        /* Rulet — rounded rectangle (barbut kadar geniş) */
        .machine[data-game-type="rulet"] {
            width: 70px; height: 38px; border-radius: 5px;
            background: linear-gradient(135deg, #7B1FA2 0%, #B71C1C 100%);
            border: 2px solid #FFD700 !important;
            box-shadow: 0 0 10px rgba(183,28,28,0.6), 2px 2px 5px rgba(0,0,0,0.5);
        }
        .machine[data-game-type="rulet"]::after {
            content: '⭕ RULET'; position: absolute; top: 3px; left: 50%; transform: translateX(-50%);
            font-size: 7px; font-weight: bold; color: rgba(255,255,255,0.85); letter-spacing: 0.5px; white-space: nowrap;
        }
        /* Barbut — craps oval blue table */
        .machine[data-game-type="barbut"] {
            width: 70px; height: 42px; border-radius: 21px;
            background: linear-gradient(160deg, #1565C0 0%, #0D47A1 100%);
            border: 2px solid #42A5F5 !important;
            box-shadow: 0 0 10px rgba(13,71,161,0.7), 2px 2px 5px rgba(0,0,0,0.5);
        }
        .machine[data-game-type="barbut"]::after {
            content: '🎲 BARBUT'; position: absolute; top: 3px; left: 50%; transform: translateX(-50%);
            font-size: 7px; font-weight: bold; color: rgba(255,255,255,0.85); letter-spacing: 0.5px; white-space: nowrap;
        }
        /* inner padding-top: leave room for the type badge then show name */
        .machine[data-game-type="poker"] .machine-inner,
        .machine[data-game-type="rulet"] .machine-inner,
        .machine[data-game-type="barbut"] .machine-inner { padding-top: 14px; }
        /* Tüm Katlar — tek kanvas görünümü, salon sınırları çizgilerle */
        #multi-floor-container {
            width: 100%; height: 100%;
            display: none;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr 1fr;
            gap: 3px;
            background: rgba(76,175,80,0.35); /* ince bölücü çizgiler = gap rengi */
            padding: 0;
            box-sizing: border-box;
        }
        .floor-panel {
            background: #ececec;
            overflow: hidden; display: flex; flex-direction: column;
            cursor: pointer; transition: background 0.15s;
            position: relative;
            border: none; border-radius: 0;
        }
        .floor-panel:hover { background: #e2f0e2; }
        .floor-panel-title {
            padding: 4px 12px; font-weight: bold; font-size: 11px;
            background: rgba(76,175,80,0.12); color: #2e7d32;
            text-align: center; flex-shrink: 0; letter-spacing: 0.3px;
            border-bottom: 1px solid rgba(76,175,80,0.2);
        }
        .floor-panel-map { flex: 1; position: relative; overflow: hidden; background: transparent; }
        .dark-theme #multi-floor-container { background: rgba(76,175,80,0.25); }
        .dark-theme .floor-panel { background: #1e1e1e; }
        .dark-theme .floor-panel:hover { background: #222; }
        .dark-theme .floor-panel-title { background: rgba(76,175,80,0.10); color: #81c784; border-color: rgba(76,175,80,0.15); }
        .note-indicator {
            position: absolute; top: -5px; right: -5px; width: 14px; height: 14px; background: #ff4444;
            border-radius: 50%; border: 2px solid white; animation: pulse 1.5s infinite; z-index: 10; box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }
        
        /* Tooltip */
        .machine-tooltip {
            position: fixed; background: rgba(0, 0, 0, 0.95); color: white; padding: 10px;
            border-radius: 8px; font-size: 12px; min-width: 180px; max-width: 250px;
            z-index: 10000; pointer-events: none; box-shadow: 0 5px 20px rgba(0,0,0,0.5);
            border: 1px solid #40E0D0; line-height: 1.4; transition: opacity 0.2s, visibility 0.2s;
            opacity: 0; visibility: hidden; transform: none !important; backdrop-filter: blur(2px);
        }
        .machine-tooltip::before {
            content: ""; position: absolute; top: 100%; left: 20px; border-width: 6px;
            border-style: solid; border-color: rgba(0, 0, 0, 0.95) transparent transparent transparent;
        }
        .machine-tooltip hr { margin: 5px 0; border: 0; height: 1px; background: #444; }
        
        .selection-box {
            position: absolute; border: 2px solid #4CAF50; background: rgba(76, 175, 80, 0.1);
            pointer-events: none; z-index: 1000;
        }
        
        /* Grup stilleri */
        .group-icon {
    position: absolute;
    width: 30px;
    height: 30px;
    background: #4CAF50;
    color: white;
    border: 2px solid white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    transition: all 0.3s;
    z-index: 1000;
    transform: translateX(-50%); /* Yatayda ortalamak için */
}
        .group-icon:hover {
    transform: translateX(-50%) scale(1.2);
    background: #45a049;
    box-shadow: 0 6px 20px rgba(0,0,0,0.4);
}
       .group-icon.has-note {
    background: #40E0D0;
}
        .group-icon .tooltip-text {
    position: absolute;
    top: -28px;
    bottom: auto; /* override base .tooltip-text bottom:-30px which would otherwise stretch height to 88px */
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.85);
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    white-space: nowrap;
    width: max-content;   /* background wraps the text exactly */
    visibility: hidden;
    opacity: 0;
    transition: all 0.2s;
    pointer-events: auto; /* allow click → navigates to group in panel */
    z-index: 1001;
}

.group-icon:hover .tooltip-text {
    visibility: visible;
    opacity: 1;
    top: -36px;
}

/* Grup vurgulama çerçevesi */
.group-highlight {
    position: absolute;
    border: 2px dashed #4CAF50;
    border-radius: 10px;
    background: rgba(76, 175, 80, 0.1);
    pointer-events: none;
    z-index: 5;
    transition: all 0.3s;
}

.group-highlight:hover {
    background: rgba(76, 175, 80, 0.2);
    border-color: #45a049;
}
		.group-highlight {
            position: absolute; border: 2px dashed #4CAF50; border-radius: 10px;
            background: rgba(76, 175, 80, 0.1); pointer-events: none; z-index: 5;
        }
        
        .groups-panel {
            position: fixed; right: 20px; top: 80px; width: 260px; background: white;
            border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); z-index: 2000;
            max-height: 55vh; overflow-y: auto;
        }
        .dark-theme .groups-panel { background: #2d2d2d; color: white; border: 1px solid #444; }
        .groups-panel-header { padding: 8px 12px; border-bottom: 1px solid #eee; font-weight: bold; font-size: 13px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: inherit; z-index: 1; }
        .groups-panel-header button { background: #4CAF50; color: white; border: none; border-radius: 5px; padding: 4px 8px; cursor: pointer; font-size: 11px; }
        .groups-panel-header .panel-close-btn { background: #f44336; padding: 4px 7px; }
        .groups-list { padding: 6px; }
        
        .group-item-panel {
            padding: 8px 10px; border-bottom: 1px solid #eee; margin-bottom: 6px;
            background: #f9f9f9; border-radius: 6px;
        }
        .dark-theme .group-item-panel { background: #3d3d3d; }
        .group-item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .group-item-name { font-weight: bold; font-size: 13px; }
        .group-item-count { background: #4CAF50; color: white; padding: 1px 6px; border-radius: 10px; font-size: 11px; }
        .group-machine-input { display: flex; gap: 4px; margin-bottom: 6px; }
        .group-machine-input input { flex: 1; padding: 5px 7px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; }
        .group-machine-input button { padding: 5px 9px; background: #2196F3; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .group-machine-list { max-height: 110px; overflow-y: auto; margin-bottom: 6px; font-size: 11px; border: 1px solid #eee; border-radius: 5px; padding: 4px; }
        .group-machine-item { display: flex; justify-content: space-between; align-items: center; padding: 3px 4px; border-bottom: 1px solid #eee; }
        .group-machine-item button { background: #f44336; color: white; border: none; border-radius: 3px; padding: 1px 4px; cursor: pointer; font-size: 10px; }
        
        .group-actions { display: flex; gap: 4px; margin-top: 6px; flex-wrap: wrap; }
        .group-actions button {
            flex: 1; padding: 5px 3px; border: none; border-radius: 5px; cursor: pointer;
            font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.2s;
        }
        .group-actions .assign-btn { background: #9C27B0; color: white; flex: 2; }
        .group-actions .export-btn { background: #4CAF50; color: white; }
        .group-actions .show-btn { background: #2196F3; color: white; }
        .group-actions .delete-btn { background: #f44336; color: white; }
        .group-actions button:hover { transform: translateY(-2px); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        
        .toggle-groups-panel {
            position: fixed; right: 20px; top: 80px; width: 45px; height: 45px;
            background: #4CAF50; color: white; border: none; border-radius: 50%; cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 2000; font-size: 20px;
            display: flex; align-items: center; justify-content: center;
        }
        .toggle-groups-panel:hover { transform: scale(1.1); }
        
        .status-bar {
            position: fixed; bottom: 20px; left: 20px; background: #4CAF50; color: white;
            padding: 12px 24px; border-radius: 30px; font-size: 14px; z-index: 2000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3); display: none; animation: slideIn 0.3s ease;
        }
        @keyframes slideIn { from { transform: translateX(-100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .modal {
            display: none; position: fixed; z-index: 10000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(5px);
        }
        .modal-content {
            background-color: #fefefe; margin: 10% auto; padding: 25px; border-radius: 15px;
            width: 450px; max-width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3); animation: modalSlide 0.3s ease;
        }
        @keyframes modalSlide { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .dark-theme .modal-content { background-color: #2d2d2d; color: white; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.2s; }
        .close:hover { color: black; }
        .dark-theme .close:hover { color: white; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
        .form-group textarea { height: 150px; resize: vertical; }
        .modal-footer { margin-top: 20px; text-align: right; }
        .modal-footer button { padding: 10px 20px; margin-left: 10px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; transition: all 0.2s; }
        .save-btn { background: #4CAF50; color: white; }
        .cancel-btn { background: #999; color: white; }
        
        .group-create-toolbar {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
            background: #4CAF50; color: white; padding: 15px 30px; border-radius: 50px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 10000; display: flex;
            gap: 20px; align-items: center; animation: slideUp 0.3s ease;
        }
        @keyframes slideUp { from { transform: translate(-50%, 100%); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
        .group-create-toolbar input { padding: 10px; border-radius: 25px; border: 1px solid #ddd; width: 200px; }
        .group-create-toolbar button { padding: 10px 20px; border: none; border-radius: 25px; cursor: pointer; font-weight: bold; transition: all 0.2s; }
        .group-create-toolbar .save-btn { background: white; color: #4CAF50; }
        .group-create-toolbar .cancel-btn { background: #f44336; color: white; }
        .group-create-toolbar .count { font-size: 18px; font-weight: bold; }
        
        body.group-create-mode { cursor: crosshair; }
        body.group-create-mode .machine { cursor: pointer; opacity: 0.7; }
        body.group-create-mode .machine.selected-for-group {
            opacity: 1; border: 3px solid #4CAF50 !important;
            box-shadow: 0 0 20px rgba(76, 175, 80, 0.5); transform: scale(1.05);
        }
        /* Right-click context menu */
        #context-menu {
            position: fixed; background: white; border: 1px solid #ddd; border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.18); z-index: 50000; min-width: 190px;
            padding: 4px 0; display: none; font-size: 13px;
        }
        .dark-theme #context-menu { background: #2d2d2d; border-color: #444; }
        .context-menu-item {
            padding: 8px 16px; cursor: pointer; display: flex; align-items: center;
            gap: 9px; color: #333; transition: background 0.12s;
        }
        .context-menu-item:hover { background: #f0f9f0; }
        .dark-theme .context-menu-item { color: #eee; }
        .dark-theme .context-menu-item:hover { background: #3a3a3a; }
        .context-menu-separator { height: 1px; background: #e0e0e0; margin: 4px 0; }
        .dark-theme .context-menu-separator { background: #444; }
        /* Machine info side panel */
        #machine-info-panel {
            position: absolute; right: 0; top: 0; bottom: 0; width: 270px;
            background: white; border-left: 1px solid #ddd;
            box-shadow: -4px 0 20px rgba(0,0,0,0.12); z-index: 2000;
            transform: translateX(100%); transition: transform 0.3s ease;
            overflow-y: auto; padding: 16px; box-sizing: border-box;
        }
        #machine-info-panel.open { transform: translateX(0); }
        .dark-theme #machine-info-panel { background: #2d2d2d; border-color: #444; color: #eee; }
        .info-panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .info-panel-title { font-size: 15px; font-weight: bold; color: #4CAF50; }
        .info-panel-close {
            background: none; border: none; font-size: 22px; cursor: pointer;
            color: #888; line-height: 1; padding: 0;
        }
        .info-panel-close:hover { color: #333; }
        .dark-theme .info-panel-close:hover { color: #fff; }
        .info-row { margin-bottom: 11px; }
        .info-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
        .info-value { font-size: 13px; font-weight: 500; word-break: break-all; }
        .dark-theme .info-label { color: #aaa; }
        .dark-theme .info-value { color: #eee; }
        .info-group-tag {
            display: inline-block; padding: 2px 8px; border-radius: 12px;
            font-size: 11px; color: white; margin: 2px 2px 2px 0;
        }
        .info-panel-btn {
            width: 100%; padding: 8px; border-radius: 6px; border: none; cursor: pointer;
            font-size: 13px; display: flex; align-items: center; gap: 6px;
            justify-content: center; margin-bottom: 6px; transition: opacity 0.15s;
        }
        .info-panel-btn:hover { opacity: 0.85; }

        /* Gruplar paneli — kat başlıkları */
        .floor-section-header {
            padding: 5px 10px 3px; font-size: 11px; font-weight: bold;
            letter-spacing: 0.6px; text-transform: uppercase;
            background: rgba(76,175,80,0.18); color: #81c784;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: 4px; cursor: pointer; user-select: none;
            display: flex; align-items: center; justify-content: space-between;
        }
        .floor-section-header::after { content: '▲'; font-size: 9px; opacity: 0.7; transition: transform 0.2s; }
        .floor-section-header.collapsed::after { transform: rotate(180deg); }
        .floor-section-body { overflow: hidden; transition: max-height 0.3s ease; }
        .floor-section-body.collapsed { max-height: 0 !important; }
        .dark-theme .floor-section-header { background: rgba(76,175,80,0.12); }

        /* Düzenle — sağa kayan araç çubuğu */
        #editToolsWrapper { display: flex; align-items: center; flex-shrink: 0; gap: 2px; }
        #editDropdownBtn { width: 34px; height: 34px; padding: 0; gap: 0; font-size: 15px; }
        #editToolsSlide {
            display: flex; gap: 2px; align-items: center; overflow: hidden;
            max-width: 0; opacity: 0;
            transition: max-width 0.35s ease, opacity 0.25s ease;
        }
        #editToolsSlide.open { max-width: 700px; opacity: 1; }
        .edit-slide-group { display: flex; gap: 2px; align-items: center; background: rgba(0,0,0,0.05); padding: 2px; border-radius: 8px; flex-shrink: 0; }
        .edit-slide-divider { width: 1px; height: 22px; background: #ccc; margin: 0 2px; flex-shrink: 0; }
        .dark-theme .edit-slide-divider { background: #555; }
        /* Makina Ekle + Grup butonları — sağa kayan wrapper */
        #addMachineWrapper { display: flex; align-items: center; flex-shrink: 0; gap: 2px; }
        #addMachineBtn { width: 34px; height: 34px; padding: 0; gap: 0; background: #4CAF50; }
        #addMachineSlide {
            display: flex; gap: 2px; align-items: center; overflow: hidden;
            max-width: 0; opacity: 0;
            transition: max-width 0.35s ease, opacity 0.25s ease;
        }
        #addMachineSlide.open { max-width: 300px; opacity: 1; }
    </style>
</head>

<body class="light-theme">
    <div class="toolbar">
        <div class="toolbar-left">
            <input id="search" placeholder="Makine ara...">
            <div class="floor-tabs" id="floor-tabs">
                <button class="floor-tab casino-tab" data-z="casino" onclick="switchFloor('casino')">🎲 Tüm Casino</button>
                <button class="floor-tab active" data-z="all" onclick="switchFloor('all')">Tüm Slot</button>
                <button class="floor-tab" data-z="0" onclick="switchFloor('0')">Yüksek Tavan</button>
                <button class="floor-tab" data-z="1" onclick="switchFloor('1')">Alçak Tavan</button>
                <button class="floor-tab" data-z="2" onclick="switchFloor('2')">Yeni VIP Salon</button>
                <button class="floor-tab" data-z="3" onclick="switchFloor('3')">Alt Salon</button>
            </div>

            <?php if($role == 'admin'): ?>
                <!-- Düzenle butonu + sağa kayan araç çubuğu -->
                <div id="editToolsWrapper">
                    <button class="toolbar-btn" id="editDropdownBtn" onclick="toggleEditDropdown()" title="Düzenle araçları">
                        <i class="fas fa-edit"></i>
                        <span class="tooltip-text">Düzenle</span>
                    </button>
                    <div id="editToolsSlide">
                        <div class="edit-slide-group">
                            <button class="toolbar-btn" onclick="rotateSelected(45)" title="45° döndür"><i class="fas fa-redo-alt"></i><span class="tooltip-text">45° döndür</span></button>
                            <button class="toolbar-btn" onclick="rotateSelected(90)" title="90° döndür"><i class="fas fa-undo-alt"></i><span class="tooltip-text">90° döndür</span></button>
                            <button class="toolbar-btn" onclick="rotateSelected(180)" title="180° döndür"><i class="fas fa-sync-alt"></i><span class="tooltip-text">180° döndür</span></button>
                        </div>
                        <div class="edit-slide-divider"></div>
                        <div class="edit-slide-group">
                            <button class="toolbar-btn purple" onclick="alignSelected('left')" title="Sola hizala"><i class="fas fa-align-left"></i><span class="tooltip-text">Sola hizala</span></button>
                            <button class="toolbar-btn purple" onclick="alignSelected('right')" title="Sağa hizala"><i class="fas fa-align-right"></i><span class="tooltip-text">Sağa hizala</span></button>
                            <button class="toolbar-btn purple" onclick="alignSelected('top')" title="Üste hizala"><i class="fas fa-align-justify" style="transform:rotate(90deg);"></i><span class="tooltip-text">Üste hizala</span></button>
                            <button class="toolbar-btn purple" onclick="alignSelected('bottom')" title="Alta hizala"><i class="fas fa-align-justify" style="transform:rotate(-90deg);"></i><span class="tooltip-text">Alta hizala</span></button>
                            <button class="toolbar-btn purple" onclick="alignSelected('centerH')" title="Yatay ortala"><i class="fas fa-arrows-alt-h"></i><span class="tooltip-text">Yatay ortala</span></button>
                            <button class="toolbar-btn purple" onclick="alignSelected('centerV')" title="Dikey ortala"><i class="fas fa-arrows-alt-v"></i><span class="tooltip-text">Dikey ortala</span></button>
                            <button class="toolbar-btn purple" onclick="alignSelected('distributeH')" title="Yatay dağıt"><i class="fas fa-arrows-alt-h" style="transform:rotate(90deg);"></i><span class="tooltip-text">Yatay dağıt</span></button>
                            <button class="toolbar-btn purple" onclick="alignSelected('distributeV')" title="Dikey dağıt"><i class="fas fa-arrows-alt-v" style="transform:rotate(90deg);"></i><span class="tooltip-text">Dikey dağıt</span></button>
                        </div>
                        <div class="edit-slide-divider"></div>
                        <div class="edit-slide-group">
                            <button class="toolbar-btn orange" onclick="openPositionModal()" title="Konum ayarla"><i class="fas fa-map-marker-alt"></i><span class="tooltip-text">Konum ayarla</span></button>
                            <button class="toolbar-btn orange" onclick="openNoteModal()" title="Not ekle"><i class="fas fa-sticky-note"></i><span class="tooltip-text">Not ekle</span></button>
                            <button class="toolbar-btn orange" onclick="openHubSwModal()" title="Hub SW ayarla"><i class="fas fa-network-wired"></i><span class="tooltip-text">Hub SW</span></button>
                            <button class="toolbar-btn orange" onclick="autoArrangeSelected()" title="Seçilileri düzenle (üst üste gelmesin)"><i class="fas fa-th"></i><span class="tooltip-text">Seçilileri düzenle</span></button>
                            <button class="toolbar-btn orange" onclick="saveAllPositions()" title="Kaydet"><i class="fas fa-save"></i><span class="tooltip-text">Kaydet</span></button>
                        </div>
                    </div>
                </div>

                <!-- Makina Ekle + Grup butonları sağa kayan panel -->
                <div id="addMachineWrapper">
                    <button class="toolbar-btn" id="addMachineBtn" onclick="toggleAddMachineSlide()" title="Ekle">
                        <i class="fas fa-plus"></i>
                        <span class="tooltip-text">Ekle</span>
                    </button>
                    <div id="addMachineSlide">
                        <button class="toolbar-btn" onclick="openAddMachineModal()" title="Makina Ekle"><i class="fas fa-desktop"></i><span class="tooltip-text">Makina Ekle</span></button>
                        <button class="toolbar-btn purple" onclick="toggleGroupsPanel()" title="Gruplar"><i class="fas fa-users"></i><span class="tooltip-text">Gruplar</span></button>
                        <button class="toolbar-btn orange" onclick="startGroupCreate()" title="Grup oluştur"><i class="fas fa-plus-circle"></i><span class="tooltip-text">Grup oluştur</span></button>
                        <button class="toolbar-btn purple" onclick="showAssignGroupModal()" title="Seçilileri gruba ata"><i class="fas fa-object-group"></i><span class="tooltip-text">Gruba Ata</span></button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="toolbar-right">
            <?php if($role == 'admin'): ?>
            <div style="position:relative; display:flex; align-items:center; gap:2px;">
                <input id="group-filter" type="text" list="group-filter-list"
                    placeholder="Tüm Gruplar" autocomplete="off"
                    oninput="filterByGroupInput(this.value)"
                    style="padding:5px 10px; border:1px solid #ddd; border-radius:16px; font-size:12px; min-width:130px; height:34px; box-sizing:border-box;">
                <datalist id="group-filter-list">
                    <option value="Tüm Gruplar">
                </datalist>
            </div>
            <?php endif; ?>
            <button class="toolbar-btn dashboard" onclick="toggleSidebar()" title="Menü"><i class="fas fa-bars"></i><span class="tooltip-text">Menü</span></button>
            <button class="toolbar-btn theme" onclick="toggleTheme()" title="Tema değiştir"><i class="fas fa-moon"></i><span class="tooltip-text">Tema değiştir</span></button>
            
            <div class="toolbar-group">
                <button class="toolbar-btn zoom" onclick="zoomIn()" title="Yakınlaştır"><i class="fas fa-search-plus"></i><span class="tooltip-text">Yakınlaştır</span></button>
                <button class="toolbar-btn zoom" onclick="zoomOut()" title="Uzaklaştır"><i class="fas fa-search-minus"></i><span class="tooltip-text">Uzaklaştır</span></button>
                <button class="toolbar-btn zoom" onclick="resetZoom()" title="Sıfırla"><i class="fas fa-expand-arrows-alt"></i><span class="tooltip-text">Sıfırla</span></button>
            </div>
        </div>
    </div>

    <!-- Sidebar overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sol Menü Sidebar -->
    <div id="sidebar">
        <div class="sidebar-header">
            <h3>🎰 Casino Map</h3>
            <button class="sidebar-close" onclick="toggleSidebar()">&#215;</button>
        </div>
        <div class="sidebar-user">
            Hoşgeldiniz, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
            <span style="display:inline-block; padding:1px 6px; border-radius:3px; font-size:11px; margin-left:4px;
                background:<?php echo ($role==='admin') ? '#4CAF50' : '#2196F3'; ?>; color:white;">
                <?php echo htmlspecialchars($role); ?>
            </span>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-item">
                <a href="map.php" class="active-page">
                    🗺️ Makine Haritası
                    <small>Makineleri görüntüle</small>
                </a>
            </div>
            <?php if($role == 'admin'): ?>
            <div class="sidebar-item">
                <a href="excel_import.php">
                    📁 CSV / Excel Yükle & İndir
                    <small>Makineleri toplu yükle veya aktar</small>
                </a>
            </div>
            <div class="sidebar-item">
                <a href="machine_settings.php">
                    ⚙️ Makine Ayarları
                    <small>Makine düzenle ve grup yönetimi</small>
                </a>
            </div>
            <div class="sidebar-item">
                <a href="users.php">
                    👤 Kullanıcı Yönetimi
                    <small>Kullanıcı ekle, sil ve şifre değiştir</small>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div class="sidebar-footer">
            <a href="logout.php">🚪 Çıkış Yap</a>
        </div>
    </div>

    <button class="toggle-groups-panel" onclick="toggleGroupsPanel()" id="toggleGroupsBtn"><i class="fas fa-users"></i></button>

    <div class="groups-panel" id="groupsPanel" style="display: none;">
        <div class="groups-panel-header">
            <span>Makine Grupları</span>
            <div style="display:flex; gap:5px;">
                <?php if($role === 'admin'): ?>
                <button onclick="startGroupCreate()"><i class="fas fa-plus"></i> Yeni</button>
                <?php endif; ?>
                <button class="panel-close-btn" onclick="toggleGroupsPanel()" title="Kapat"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <!-- Bölge filtre butonları -->
        <div id="regionFilters" style="display:flex;flex-wrap:wrap;gap:5px;padding:8px 10px;border-bottom:1px solid rgba(255,255,255,0.15);">
            <!-- JS tarafından doldurulur -->
        </div>
        <div class="groups-list" id="groupsList"><div style="padding: 20px; text-align: center;">Yükleniyor...</div></div>
    </div>

    <div id="map-container">
        <!-- Machine info side panel -->
        <div id="machine-info-panel">
            <div class="info-panel-header">
                <span class="info-panel-title" id="info-machine-no">-</span>
                <button class="info-panel-close" onclick="closeMachineInfoPanel()">&#215;</button>
            </div>
            <div id="info-panel-body"></div>
        </div>
        <!-- 6-panel multi-floor overview (shown when "Tüm Katlar" is active) -->
        <div id="multi-floor-container">
            <div class="floor-panel" onclick="switchFloor('0')">
                <div class="floor-panel-title">🏛 Yüksek Tavan</div>
                <div class="floor-panel-map" id="mini-map-0"></div>
            </div>
            <div class="floor-panel" onclick="switchFloor('1')">
                <div class="floor-panel-title">🏠 Alçak Tavan</div>
                <div class="floor-panel-map" id="mini-map-1"></div>
            </div>
            <div class="floor-panel" onclick="switchFloor('2')">
                <div class="floor-panel-title">👑 Yeni VIP Salon</div>
                <div class="floor-panel-map" id="mini-map-2"></div>
            </div>
            <div class="floor-panel" onclick="switchFloor('3')">
                <div class="floor-panel-title">🎰 Alt Salon</div>
                <div class="floor-panel-map" id="mini-map-3"></div>
            </div>
            <div class="floor-panel" onclick="switchFloor('4')">
                <div class="floor-panel-title">🌿 Balkon</div>
                <div class="floor-panel-map" id="mini-map-4"></div>
            </div>
            <div class="floor-panel" onclick="switchFloor('5')">
                <div class="floor-panel-title">👑 Eski VIP Salon</div>
                <div class="floor-panel-map" id="mini-map-5"></div>
            </div>
        </div>
        <div id="map">
            <?php 
            $result = $conn->query("SELECT * FROM machines ORDER BY pos_z, machine_no");
            while($row = $result->fetch_assoc()): 
                $hasNote = !empty($row['note']);
                $hasHubSw = !empty($row['hub_sw']);
                $drscreenIp = htmlspecialchars($row['drscreen_ip'] ?? '', ENT_QUOTES);
            ?>
                <div class="machine <?php echo $hasNote ? 'has-note' : ''; ?> <?php echo $hasHubSw ? 'has-hub-sw' : ''; ?>"
                     data-id="<?php echo $row['id']; ?>"
                     data-rotation="<?php echo $row['rotation']; ?>"
                     data-note="<?php echo htmlspecialchars($row['note'] ?? '', ENT_QUOTES); ?>"
                     data-x="<?php echo $row['pos_x']; ?>"
                     data-y="<?php echo $row['pos_y']; ?>"
                     data-z="<?php echo $row['pos_z']; ?>"
                     data-game-type="<?php echo htmlspecialchars($row['game_type'] ?? '', ENT_QUOTES); ?>"
                     data-machine-no="<?php echo htmlspecialchars($row['machine_no'], ENT_QUOTES); ?>"
                     data-ip="<?php echo htmlspecialchars($row['ip'], ENT_QUOTES); ?>"
                     data-mac="<?php echo htmlspecialchars($row['mac'], ENT_QUOTES); ?>"
                     data-drscreen-ip="<?php echo $drscreenIp; ?>"
                     data-hub-sw="<?php echo $hasHubSw ? '1' : '0'; ?>"
                     data-hub-sw-cable="<?php echo htmlspecialchars($row['hub_sw_cable'] ?? '', ENT_QUOTES); ?>"
                     style="left: <?php echo $row['pos_x']; ?>px; top: <?php echo $row['pos_y']; ?>px; transform: rotate(<?php echo $row['rotation']; ?>deg);">
                    
                    <?php if($hasHubSw): ?>
                        <div class="hub-sw-badge" title="Hub SW: <?php echo htmlspecialchars($row['hub_sw_cable'] ?? '', ENT_QUOTES); ?>">🔌</div>
                    <?php endif; ?>

                    <div class="machine-inner">
                        <span class="machine-label"><?php echo htmlspecialchars($row['machine_no']); ?></span>
                        <span class="machine-ip"><?php echo htmlspecialchars($row['ip']); ?></span>
                        <?php if(!empty($drscreenIp)): ?>
                            <span class="machine-ip" style="color:#90CAF9;"><?php echo $drscreenIp; ?></span>
                        <?php endif; ?>
                        <span class="z-level">Z:<?php echo $row['pos_z']; ?></span>
                    </div>
                    
                    <?php if(!empty($row['note'])): ?>
                        <div class="note-indicator" title="Not var"></div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <div id="status" class="status-bar"></div>

    <!-- Not Ekleme Modal -->
    <div id="noteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sticky-note"></i> Makine Notu</h3>
                <span class="close" onclick="closeNoteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="noteSelectedCount">0 makine seçildi</p>
                <div class="form-group">
                    <label>Not:</label>
                    <textarea id="noteText" placeholder="Makine için not girin..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeNoteModal()">İptal</button>
                <button class="save-btn" onclick="saveNote()">Kaydet</button>
            </div>
        </div>
    </div>

    <!-- Konum Ayarla Modal -->
    <div id="positionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-map-marker-alt"></i> Makine Konumu</h3>
                <span class="close" onclick="closePositionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="positionSelectedCount">0 makine seçildi</p>
                <div class="form-group">
                    <label>X Koordinatı:</label>
                    <input type="number" id="posX" placeholder="X koordinatı">
                </div>
                <div class="form-group">
                    <label>Y Koordinatı:</label>
                    <input type="number" id="posY" placeholder="Y koordinatı">
                </div>
                <div class="form-group">
                    <label>Z Katmanı:</label>
                    <select id="posZ">
                        <option value="0">Yüksek Tavan</option>
                        <option value="1">Alçak Tavan</option>
                        <option value="2">Yeni VIP Salon</option>
                        <option value="3">Alt Salon</option>
                        <option value="4">🌿 Balkon</option>
                        <option value="5">👑 Eski VIP Salon</option>
                        <option value="10">🎲 Canlı Masa (Casino)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closePositionModal()">İptal</button>
                <button class="save-btn" onclick="savePosition()">Kaydet</button>
            </div>
        </div>
    </div>

    <!-- Grup Atama Modal -->
    <div id="assignGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-object-group"></i> Gruba Makine Ata</h3>
                <span class="close" onclick="closeAssignGroupModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="selectedCountText">0 makine seçildi</p>
                <div class="form-group">
                    <label>Grup Seçin:</label>
                    <select id="assignGroupSelect" style="width: 100%; padding: 10px;">
                        <option value="">Grup seçin</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeAssignGroupModal()">İptal</button>
                <button class="save-btn" onclick="assignToSelectedGroup()">Gruba Ata</button>
            </div>
        </div>
    </div>

    <!-- Hub SW Modal -->
    <div id="hubSwModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-network-wired"></i> Hub SW Bilgileri</h3>
                <span class="close" onclick="closeHubSwModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="hubSwMachineInfo" style="color:#666; margin-bottom:10px;"></p>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" id="hubSwCheck" style="width:18px; height:18px;">
                        Bu makinede Hub SW (Hub/Switch) var
                    </label>
                </div>
                <div class="form-group">
                    <label>Ana Hat Bağlantısı (kablo nereye gidiyor?):</label>
                    <input type="text" id="hubSwCable" placeholder="Örn: Marketing Port 4">
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeHubSwModal()">İptal</button>
                <button class="save-btn" onclick="saveHubSw()">Kaydet</button>
            </div>
        </div>
    </div>

    <script>
        // PHP → JS yetki köprüsü
        const IS_ADMIN = <?php echo ($role === 'admin') ? 'true' : 'false'; ?>;
        const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    </script>
    <script src="assets/js/map.js"></script>

    <!-- Right-click context menu (global, outside map-container) -->
    <div id="context-menu">
        <!-- Dinamik grup işlemleri — showContextMenu() tarafından doldurulur -->
        <div id="ctx-group-ops"></div>

        <?php if($role == 'admin'): ?>
        <div class="context-menu-item" onclick="contextMenuAction('note')"><i class="fas fa-sticky-note" style="color:#FF9800"></i> Not ekle / düzenle</div>
        <div class="context-menu-item" onclick="contextMenuAction('createGroup')"><i class="fas fa-plus-circle" style="color:#4CAF50"></i> Grup oluştur</div>
        <div class="context-menu-separator"></div>
        <div class="context-menu-item" onclick="contextMenuAction('position')"><i class="fas fa-map-marker-alt" style="color:#2196F3"></i> Konum ayarla</div>
        <div class="context-menu-item" onclick="contextMenuAction('hubsw')"><i class="fas fa-network-wired" style="color:#FF9800"></i> Hub SW ayarla</div>
        <div class="context-menu-separator"></div>
        <?php endif; ?>

        <div class="context-menu-item" onclick="contextMenuAction('info')"><i class="fas fa-info-circle" style="color:#4CAF50"></i> Makine bilgileri</div>
    </div>

    <!-- Gruba Dahil Et mini-modal -->
    <div id="addToGroupModal" class="modal">
        <div class="modal-content" style="width:340px;">
            <div class="modal-header">
                <h3><i class="fas fa-object-group"></i> Gruba Dahil Et</h3>
                <span class="close" onclick="closeAddToGroupModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="addToGroupMachineInfo" style="color:#666; margin-bottom:10px; font-size:13px;"></p>
                <div class="form-group">
                    <label>Grup adını girin:</label>
                    <input type="text" id="addToGroupName" placeholder="Grup adı..." list="groupNameList" autocomplete="off">
                    <datalist id="groupNameList"></datalist>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeAddToGroupModal()">İptal</button>
                <button class="save-btn" onclick="confirmAddToGroup()">Ekle</button>
            </div>
        </div>
    </div>

    <!-- Makina Ekle Modal -->
    <div id="addMachineModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Yeni Makine Ekle / Düzenle</h3>
                <span class="close" onclick="closeAddMachineModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Makine No:</label>
                    <input type="text" id="newMachineNo" placeholder="">
                </div>
                <div class="form-group">
                    <label>IP Adresi:</label>
                    <input type="text" id="newMachineIp" placeholder="">
                </div>
                <div class="form-group">
                    <label>MAC Adresi:</label>
                    <input type="text" id="newMachineMac" placeholder="">
                </div>
                <div class="form-group">
                    <label>Z Koordinatı (Kat):</label>
                    <select id="newMachineZ" onchange="onZChange(this.value)">
                        <option value="0">Yüksek Tavan</option>
                        <option value="1">Alçak Tavan</option>
                        <option value="2">Yeni VIP Salon</option>
                        <option value="3">Alt Salon</option>
                        <option value="4" selected>🌿 Balkon</option>
                        <option value="5">👑 Eski VIP Salon</option>
                        <option value="10">🎲 Canlı Masa (Casino)</option>
                    </select>
                </div>
                <div class="form-group" id="gameTypeGroup">
                    <label>Masa Tipi:</label>
                    <select id="newMachineGameType">
                        <option value="slot" selected>🎰 Slot</option>
                        <option value="poker">♠ Poker</option>
                        <option value="rulet">⭕ Rulet</option>
                        <option value="barbut">🎲 Barbut</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Not:</label>
                    <textarea id="newMachineNote" placeholder="Makine hakkında notlar..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="clearAddMachineForm()">Temizle</button>
                <button class="save-btn" onclick="submitAddMachine()">Kaydet</button>
            </div>
        </div>
    </div>

    <script>
    // ── Düzenle sağa kayan açma/kapama ─────────────────────────────────────
    function toggleEditDropdown() {
        var slide = document.getElementById('editToolsSlide');
        if (!slide) return;
        slide.classList.toggle('open');
        // Diğer slide açıksa kapat
        document.getElementById('addMachineSlide')?.classList.remove('open');
    }

    // ── Ekle sağa kayan açma/kapama ────────────────────────────────────────
    function toggleAddMachineSlide() {
        var slide = document.getElementById('addMachineSlide');
        if (!slide) return;
        slide.classList.toggle('open');
        // Diğer slide açıksa kapat
        document.getElementById('editToolsSlide')?.classList.remove('open');
    }

    // Dışarı tıklanınca her iki slide'ı da kapat
    document.addEventListener('click', function(e) {
        var editWrapper = document.getElementById('editToolsWrapper');
        var editSlide   = document.getElementById('editToolsSlide');
        if (editSlide && editWrapper && !editWrapper.contains(e.target)) {
            editSlide.classList.remove('open');
        }
        var addWrapper = document.getElementById('addMachineWrapper');
        var addSlide   = document.getElementById('addMachineSlide');
        if (addSlide && addWrapper && !addWrapper.contains(e.target)) {
            addSlide.classList.remove('open');
        }
    });

    // ── Makina Ekle Modal ───────────────────────────────────────────────────
    function openAddMachineModal() {
        document.getElementById('addMachineModal').style.display = 'block';
    }
    function closeAddMachineModal() {
        document.getElementById('addMachineModal').style.display = 'none';
    }
    function clearAddMachineForm() {
        ['newMachineNo','newMachineIp','newMachineMac','newMachineNote'].forEach(function(id) {
            document.getElementById(id).value = '';
        });
        document.getElementById('newMachineZ').value = '4';
        document.getElementById('newMachineGameType').value = 'slot';
    }
    // Kat seçimi değişince: Masa Tipi'ni güncelle + haritada o kata geç
    function onZChange(zVal) {
        var gameTypeEl = document.getElementById('newMachineGameType');
        if (zVal === '10') {
            // Casino: Slot seçiliyse Poker'e geç (casino masaları için)
            if (gameTypeEl.value === 'slot') gameTypeEl.value = 'poker';
        } else {
            // Diğer katlar: varsayılan Slot
            gameTypeEl.value = 'slot';
        }
        // Haritada seçilen kata geç (casino özel görünüm, diğerleri direkt kat)
        if (typeof window.switchFloor === 'function') {
            window.switchFloor(zVal === '10' ? 'casino' : zVal);
        }
    }
    // Backward compat alias
    function toggleGameTypeField(zVal) { onZChange(zVal); }
    function submitAddMachine() {
        var no       = document.getElementById('newMachineNo').value.trim();
        var ip       = document.getElementById('newMachineIp').value.trim();
        var mac      = document.getElementById('newMachineMac').value.trim();
        var z        = document.getElementById('newMachineZ').value;
        var note     = document.getElementById('newMachineNote').value.trim();
        var gameType = document.getElementById('newMachineGameType').value;

        if (!no || !ip || !mac) {
            alert('Makine No, IP Adresi ve MAC Adresi zorunludur!');
            return;
        }

        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('machine_no', no);
        fd.append('ip', ip);
        fd.append('mac', mac);
        fd.append('pos_z', z);
        fd.append('note', note);
        fd.append('game_type', gameType);

        fetch('add_machine_ajax.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    closeAddMachineModal();
                    clearAddMachineForm();
                    _addMachineToMap(data);
                    if (typeof showStatus === 'function') showStatus('Makine eklendi: ' + data.machine_no);
                } else {
                    alert(data.error || 'Hata oluştu.');
                }
            })
            .catch(function() { alert('Bağlantı hatası.'); });
    }

    function _addMachineToMap(data) {
        var map = document.getElementById('map');
        if (!map) return;
        // Escape helper (may not yet be defined when this runs)
        function esc(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        var div = document.createElement('div');
        div.className = 'machine';
        div.setAttribute('data-id', data.id);
        div.setAttribute('data-rotation', '0');
        div.setAttribute('data-note', data.note || '');
        div.setAttribute('data-x', '0');
        div.setAttribute('data-y', '0');
        div.setAttribute('data-z', String(data.pos_z));
        div.setAttribute('data-game-type', data.game_type || '');
        div.setAttribute('data-machine-no', data.machine_no);
        div.setAttribute('data-ip', data.ip);
        div.setAttribute('data-mac', data.mac);
        div.setAttribute('data-drscreen-ip', '');
        div.setAttribute('data-hub-sw', '0');
        div.setAttribute('data-hub-sw-cable', '');

        // Auto-position: place near the bottom-most existing machine on the same floor
        var zStr = String(data.pos_z);
        var existing = Array.from(document.querySelectorAll('#map .machine[data-z="' + zStr + '"]'));
        var placeX = 20, placeY = 20;
        if (existing.length > 0) {
            var bestY = -Infinity, bestX = 20;
            existing.forEach(function(m) {
                var y = parseFloat(m.style.top)  || 0;
                var x = parseFloat(m.style.left) || 0;
                if (y > bestY) { bestY = y; bestX = x; }
            });
            placeX = bestX;
            placeY = bestY + 70; // below the bottom-most machine
        }
        div.style.left = placeX + 'px';
        div.style.top  = placeY + 'px';
        div.style.transform = 'rotate(0deg)';
        div.innerHTML =
            '<div class="machine-inner">' +
            '<span class="machine-label">' + esc(data.machine_no) + '</span>' +
            '<span class="machine-ip">' + esc(data.ip) + '</span>' +
            '<span class="z-level">Z:' + esc(data.pos_z) + '</span>' +
            '</div>';
        map.appendChild(div);
        // Attach all event listeners (drag, click, contextmenu, tooltip)
        if (typeof window._setupMachineEl === 'function') {
            window._setupMachineEl(div);
        }
        // Switch to the floor where the machine was added and focus it
        if (typeof window.switchFloor === 'function') {
            var isLive = ['poker', 'rulet', 'barbut'].indexOf(data.game_type) >= 0;
            window.switchFloor((zStr === '10' || isLive) ? 'casino' : zStr);
        }
    }
    </script>
</body>
</html>