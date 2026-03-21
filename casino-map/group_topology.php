<?php
session_start();

if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}

include("config.php");

$role = $_SESSION['role'];

// Load groups with their machines
$groups_data = [];
$groups_res = $conn->query("SELECT * FROM machine_groups ORDER BY group_name");
while($g = $groups_res->fetch_assoc()){
    $machines = [];
    $mres = $conn->query(
        "SELECT m.* FROM machines m
         INNER JOIN machine_group_relations mgr ON m.id = mgr.machine_id
         WHERE mgr.group_id = " . $g['id'] . " ORDER BY m.machine_no"
    );
    while($m = $mres->fetch_assoc()){
        $machines[] = $m;
    }
    $g['machines'] = $machines;
    $groups_data[] = $g;
}

// Load all machines (ungrouped too)
$all_machines = [];
$mres2 = $conn->query("SELECT * FROM machines ORDER BY machine_no");
while($m = $mres2->fetch_assoc()){ $all_machines[$m['id']] = $m; }

// Load connections
$connections = [];
$cres = $conn->query("SELECT c.*, ms.machine_no AS source_no, mt.machine_no AS target_no
    FROM connections c
    INNER JOIN machines ms ON ms.id = c.source_machine_id
    LEFT JOIN machines mt ON mt.id = c.target_machine_id");
while($c = $cres->fetch_assoc()){ $connections[] = $c; }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grup Topolojisi</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a2e;
            color: #eee;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ===== TOOLBAR ===== */
        .toolbar {
            background: #16213e;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.4);
            flex-wrap: wrap;
            z-index: 100;
        }
        .toolbar h1 { font-size: 18px; color: #40E0D0; flex: 0 0 auto; }
        .toolbar-sep { width: 1px; height: 28px; background: #444; }
        .tb-btn {
            padding: 7px 14px; border: none; border-radius: 6px; cursor: pointer;
            font-size: 13px; display: flex; align-items: center; gap: 6px;
            transition: all 0.2s; color: white;
        }
        .tb-btn:hover { filter: brightness(1.15); transform: translateY(-1px); }
        .tb-btn.green  { background: #4CAF50; }
        .tb-btn.blue   { background: #2196F3; }
        .tb-btn.orange { background: #FF9800; }
        .tb-btn.purple { background: #9C27B0; }
        .tb-btn.gray   { background: #607D8B; }
        .tb-btn.active { box-shadow: 0 0 0 2px #40E0D0; }
        .tb-input {
            padding: 7px 14px; border: 1px solid #444; border-radius: 20px;
            background: #0f3460; color: white; font-size: 13px; width: 200px;
        }
        .tb-input::placeholder { color: #888; }
        .tb-select {
            padding: 7px 14px; border: 1px solid #444; border-radius: 6px;
            background: #0f3460; color: white; font-size: 13px; cursor: pointer;
        }

        /* ===== CANVAS ===== */
        #canvas-wrapper {
            flex: 1; position: relative; overflow: auto;
        }
        #topology-canvas {
            position: relative;
            min-width: 2000px;
            min-height: 1400px;
            padding: 40px;
        }

        /* SVG connection lines */
        #conn-svg {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none; z-index: 1;
            overflow: visible;
        }
        .conn-line {
            stroke-width: 2; fill: none;
            stroke-dasharray: 8, 4;
            animation: dashFlow 1.2s linear infinite;
        }
        .conn-line.hub-sw { stroke: #FF6F00; }
        .conn-line.direct { stroke: #2196F3; }
        @keyframes dashFlow { to { stroke-dashoffset: -12; } }

        /* ===== GROUP CLUSTER ===== */
        .group-cluster {
            position: absolute;
            border-radius: 12px;
            border: 2px solid;
            padding: 38px 12px 12px 12px;
            min-width: 120px;
            transition: box-shadow 0.2s;
            z-index: 2;
        }
        .group-cluster:hover { box-shadow: 0 0 20px rgba(255,255,255,0.15); }
        .group-cluster.dimmed { opacity: 0.2; }

        .group-header {
            position: absolute;
            top: 0; left: 0; right: 0;
            padding: 6px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 10px 10px 0 0;
            font-size: 13px;
            font-weight: bold;
            color: white;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.4);
            cursor: move;
            user-select: none;
        }
        .group-header .group-count {
            margin-left: auto;
            background: rgba(0,0,0,0.3);
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
        }

        /* ===== MACHINES GRID ===== */
        .machines-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* ===== MACHINE CARD ===== */
        .machine-card {
            width: 68px;
            height: 52px;
            background: #40E0D0;
            border: 2px solid #2ab9af;
            border-radius: 7px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #003333;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.15s;
            position: relative;
            text-align: center;
            overflow: hidden;
            padding: 2px;
        }
        .machine-card:hover {
            transform: scale(1.08);
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
        }
        .machine-card.selected {
            border: 2px solid yellow !important;
            box-shadow: 0 0 12px rgba(255,255,0,0.5);
            transform: scale(1.08);
        }
        .machine-card.has-hub-sw {
            border: 2px solid #FF6F00 !important;
            box-shadow: 0 0 8px rgba(255,111,0,0.5);
        }
        .machine-card .mc-no {
            font-size: 10px;
            font-weight: bold;
            line-height: 1.1;
        }
        .machine-card .mc-ip {
            font-size: 8px;
            color: #005050;
            line-height: 1.1;
        }
        .machine-card .hub-badge {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            background: #FF6F00;
            color: white;
            font-size: 7px;
            padding: 1px;
            text-align: center;
            font-weight: bold;
        }
        .machine-card .note-dot {
            position: absolute;
            top: -4px; right: -4px;
            width: 10px; height: 10px;
            background: #ff4444;
            border-radius: 50%;
            border: 1px solid white;
        }

        /* ===== DETAIL PANEL ===== */
        #detail-panel {
            position: fixed;
            right: 0; top: 0; bottom: 0;
            width: 340px;
            background: #16213e;
            border-left: 2px solid #0f3460;
            z-index: 500;
            display: none;
            flex-direction: column;
            box-shadow: -4px 0 20px rgba(0,0,0,0.5);
        }
        #detail-panel.open { display: flex; }
        .dp-header {
            padding: 16px;
            font-size: 16px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #0f3460;
            color: white;
        }
        .dp-close { cursor: pointer; font-size: 22px; opacity: 0.7; }
        .dp-close:hover { opacity: 1; }
        .dp-body { flex: 1; overflow-y: auto; padding: 16px; }
        .dp-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #0f3460;
            font-size: 13px;
        }
        .dp-label { color: #888; min-width: 120px; flex-shrink: 0; }
        .dp-value { color: #ddd; }
        .hub-badge-dp {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
        }
        .hub-badge-dp.active { background: #FF6F00; color: white; }
        .hub-badge-dp.inactive { background: #333; color: #666; }
        .group-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 11px; margin: 2px; color: white;
        }
        .dp-actions { padding: 16px; border-top: 1px solid #0f3460; display: flex; gap: 8px; }
        .dp-actions button {
            flex: 1; padding: 9px; border: none; border-radius: 6px;
            cursor: pointer; font-size: 12px; transition: all 0.2s;
        }
        .dp-actions button:hover { filter: brightness(1.1); }

        /* ===== UNGROUPED ===== */
        #ungrouped-section {
            position: absolute;
            border: 2px dashed #555;
            border-radius: 10px;
            padding: 38px 12px 12px;
            background: rgba(255,255,255,0.03);
        }
        #ungrouped-section .group-header {
            background: #444;
        }

        /* ===== FILTER OVERLAY ===== */
        .filter-overlay {
            position: fixed; top: 60px; right: 20px;
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 10px;
            padding: 16px;
            z-index: 300;
            min-width: 200px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
            display: none;
        }
        .filter-overlay.open { display: block; }
        .filter-overlay label { display: flex; align-items: center; gap: 8px; padding: 5px 0; cursor: pointer; font-size: 13px; }
        .filter-overlay input[type=checkbox] { width: 16px; height: 16px; accent-color: #40E0D0; }

        /* ===== LEGEND ===== */
        #legend {
            position: fixed; bottom: 16px; left: 16px;
            background: rgba(22,33,62,0.95);
            border: 1px solid #0f3460;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            z-index: 200;
        }
        .legend-item { display: flex; align-items: center; gap: 8px; margin: 4px 0; }
        .legend-box { width: 20px; height: 14px; border-radius: 3px; border: 2px solid; flex-shrink: 0; }

        /* Scrollbar */
        #canvas-wrapper::-webkit-scrollbar { width: 8px; height: 8px; }
        #canvas-wrapper::-webkit-scrollbar-track { background: #1a1a2e; }
        #canvas-wrapper::-webkit-scrollbar-thumb { background: #0f3460; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="toolbar">
        <h1><i class="fas fa-sitemap"></i> Grup Topolojisi</h1>
        <div class="toolbar-sep"></div>
        <input type="text" class="tb-input" id="searchInput" placeholder="Makine ara...">
        <select class="tb-select" id="groupFilterSelect" onchange="filterByGroup(this.value)">
            <option value="all">Tüm Gruplar</option>
            <?php foreach($groups_data as $g): ?>
            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['group_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="tb-btn orange" id="hubSwFilterBtn" onclick="toggleHubSwFilter()">
            <i class="fas fa-network-wired"></i> Sadece Hub SW
        </button>
        <button class="tb-btn purple" id="connLinesBtn" onclick="toggleConnLines()">
            <i class="fas fa-project-diagram"></i> Bağlantılar
        </button>
        <div class="toolbar-sep"></div>
        <button class="tb-btn blue" onclick="autoLayout()">
            <i class="fas fa-magic"></i> Otomatik Düzen
        </button>
        <?php if($role == 'admin'): ?>
        <button class="tb-btn green" onclick="window.location='machine_groups.php'">
            <i class="fas fa-cog"></i> Grupları Yönet
        </button>
        <?php endif; ?>
        <button class="tb-btn gray" onclick="window.location='map.php'">
            <i class="fas fa-map"></i> Harita
        </button>
        <button class="tb-btn gray" onclick="window.location='dashboard.php'">
            <i class="fas fa-home"></i> Panel
        </button>
    </div>

    <div id="canvas-wrapper">
        <div id="topology-canvas">
            <svg id="conn-svg"></svg>

            <?php
            $groupColors = [];
            $col = 0;
            $row = 0;
            $colsPerRow = 3;
            $clusterW = 280;
            $clusterH = 240;
            $padX = 40;
            $padY = 60;

            foreach($groups_data as $g):
                $color   = htmlspecialchars($g['color'] ?? '#4CAF50');
                $icon    = htmlspecialchars($g['icon'] ?? 'fa-server');
                $px      = $padX + $col * ($clusterW + 60);
                $py      = $padY + $row * ($clusterH + 60);
                $groupColors[$g['id']] = $g['color'] ?? '#4CAF50';

                // Estimate cluster size
                $machCount = count($g['machines']);
                $machPerRow = 3;
                $clW = max(120, ($machPerRow * 74) + 24);
                $clH = max(80, ceil($machCount / $machPerRow) * 58 + 50);

                $col++;
                if($col >= $colsPerRow){ $col = 0; $row++; }
            ?>
            <div class="group-cluster"
                 id="cluster-<?php echo $g['id']; ?>"
                 data-group-id="<?php echo $g['id']; ?>"
                 style="left:<?php echo $px; ?>px; top:<?php echo $py; ?>px;
                        border-color:<?php echo $color; ?>;
                        background: rgba(<?php
                            // Convert hex to rgb for background
                            $hex = ltrim($g['color'] ?? '#4CAF50', '#');
                            if(strlen($hex) == 6) {
                                $r = hexdec(substr($hex,0,2));
                                $g2 = hexdec(substr($hex,2,2));
                                $b = hexdec(substr($hex,4,2));
                                echo "$r,$g2,$b";
                            } else { echo "76,175,80"; }
                        ?>, 0.08); min-width:<?php echo $clW; ?>px;">
                <div class="group-header" style="background:<?php echo $color; ?>;">
                    <i class="fas <?php echo $icon; ?>"></i>
                    <?php echo htmlspecialchars($g['group_name']); ?>
                    <span class="group-count"><?php echo count($g['machines']); ?></span>
                </div>
                <div class="machines-grid">
                    <?php foreach($g['machines'] as $m):
                        $hasHubSw = !empty($m['has_hub_sw']);
                        $hasNote  = !empty($m['note']);
                        $classes  = 'machine-card';
                        if($hasHubSw) $classes .= ' has-hub-sw';
                    ?>
                    <div class="<?php echo $classes; ?>"
                         data-id="<?php echo $m['id']; ?>"
                         data-machine-no="<?php echo htmlspecialchars($m['machine_no'], ENT_QUOTES); ?>"
                         data-smibb-ip="<?php echo htmlspecialchars($m['smibb_ip'] ?? '', ENT_QUOTES); ?>"
                         data-mac="<?php echo htmlspecialchars($m['mac'], ENT_QUOTES); ?>"
                         data-note="<?php echo htmlspecialchars($m['note'] ?? '', ENT_QUOTES); ?>"
                         data-has-hub-sw="<?php echo $hasHubSw ? '1' : '0'; ?>"
                         data-hub-sw-target="<?php echo htmlspecialchars($m['hub_sw_target'] ?? '', ENT_QUOTES); ?>"
                         data-group-id="<?php echo $g['id']; ?>"
                         onclick="selectMachine(event, this)"
                         title="<?php echo htmlspecialchars($m['machine_no']); ?> | <?php echo htmlspecialchars($m['smibb_ip'] ?? ''); ?>">
                        <div class="mc-no"><?php echo htmlspecialchars($m['machine_no']); ?></div>
                        <?php if(!empty($m['smibb_ip'])): ?>
                        <div class="mc-ip"><?php echo htmlspecialchars($m['smibb_ip']); ?></div>
                        <?php endif; ?>
                        <?php if($hasHubSw): ?><div class="hub-badge">Hub SW</div><?php endif; ?>
                        <?php if($hasNote): ?><div class="note-dot" title="<?php echo htmlspecialchars($m['note']); ?>"></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($g['machines'])): ?>
                    <div style="color:#666; font-size:12px; padding:10px;">Henüz makine yok</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php
            // Ungrouped machines
            $grouped_ids = [];
            foreach($groups_data as $g) {
                foreach($g['machines'] as $m) { $grouped_ids[] = $m['id']; }
            }
            $ungrouped = array_filter($all_machines, fn($m) => !in_array($m['id'], $grouped_ids));
            if(!empty($ungrouped)):
                $ugpx = $padX + $col * ($clusterW + 60);
                $ugpy = $padY + $row * ($clusterH + 60);
            ?>
            <div id="ungrouped-section" style="left:<?php echo $ugpx; ?>px; top:<?php echo $ugpy; ?>px;">
                <div class="group-header" style="background:#444;">
                    <i class="fas fa-question-circle"></i> Gruba Atanmamış
                    <span class="group-count"><?php echo count($ungrouped); ?></span>
                </div>
                <div class="machines-grid">
                    <?php foreach($ungrouped as $m):
                        $hasHubSw = !empty($m['has_hub_sw']);
                    ?>
                    <div class="machine-card <?php echo $hasHubSw ? 'has-hub-sw' : ''; ?>"
                         data-id="<?php echo $m['id']; ?>"
                         data-machine-no="<?php echo htmlspecialchars($m['machine_no'], ENT_QUOTES); ?>"
                         data-smibb-ip="<?php echo htmlspecialchars($m['smibb_ip'] ?? '', ENT_QUOTES); ?>"
                         data-has-hub-sw="<?php echo $hasHubSw ? '1' : '0'; ?>"
                         data-hub-sw-target="<?php echo htmlspecialchars($m['hub_sw_target'] ?? '', ENT_QUOTES); ?>"
                         data-group-id=""
                         onclick="selectMachine(event, this)"
                         style="background:#aaa; border-color:#888; color:#222;">
                        <div class="mc-no"><?php echo htmlspecialchars($m['machine_no']); ?></div>
                        <?php if(!empty($m['smibb_ip'])): ?>
                        <div class="mc-ip"><?php echo htmlspecialchars($m['smibb_ip']); ?></div>
                        <?php endif; ?>
                        <?php if($hasHubSw): ?><div class="hub-badge">Hub SW</div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Detail Panel -->
    <div id="detail-panel">
        <div class="dp-header" id="dp-header">
            <span id="dp-title">Makine Detayı</span>
            <span class="dp-close" onclick="closeDetail()">×</span>
        </div>
        <div class="dp-body" id="dp-body">
            <div style="text-align:center; padding:30px; color:#666;">Makineye tıklayın</div>
        </div>
        <div class="dp-actions" id="dp-actions">
            <?php if($role == 'admin'): ?>
            <button onclick="openHubSwModal()" style="background:#FF6F00; color:white;"><i class="fas fa-network-wired"></i> Hub SW</button>
            <button onclick="window.location='machine_groups.php'" style="background:#2196F3; color:white;"><i class="fas fa-edit"></i> Düzenle</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hub SW Modal -->
    <div id="hubSwModal" style="display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.7);">
        <div style="background:#16213e; color:white; margin:15% auto; padding:25px; border-radius:12px; width:400px; max-width:90%; border:1px solid #0f3460;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="color:#40E0D0;"><i class="fas fa-network-wired"></i> Hub SW Ayarları</h3>
                <span style="cursor:pointer; font-size:22px; opacity:0.7;" onclick="closeHubSwModal()">×</span>
            </div>
            <p id="hubSwMachineName" style="color:#aaa; margin-bottom:16px;"></p>
            <div style="margin-bottom:14px;">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px;">
                    <input type="checkbox" id="hubSwCheck" style="width:18px;height:18px; accent-color:#FF6F00;">
                    Bu makinede Hub SW var
                </label>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block; margin-bottom:6px; color:#888; font-size:13px;">Ana Bağlantı Hedefi:</label>
                <input type="text" id="hubSwTarget" placeholder="Hedef makine no veya adres..." style="width:100%; padding:8px 12px; background:#0f3460; border:1px solid #333; border-radius:6px; color:white; font-size:13px;">
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                <button onclick="closeHubSwModal()" style="padding:9px 18px; background:#444; color:white; border:none; border-radius:6px; cursor:pointer;">İptal</button>
                <button onclick="saveHubSw()" style="padding:9px 18px; background:#FF6F00; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">Kaydet</button>
            </div>
        </div>
    </div>

    <!-- Legend -->
    <div id="legend">
        <div style="font-weight:bold; margin-bottom:8px; color:#40E0D0;">Açıklama</div>
        <div class="legend-item">
            <div class="legend-box" style="background:#40E0D0; border-color:#2ab9af;"></div>
            <span>Normal Makine</span>
        </div>
        <div class="legend-item">
            <div class="legend-box" style="background:#40E0D0; border-color:#FF6F00;"></div>
            <span>Hub SW Var</span>
        </div>
        <div class="legend-item">
            <div class="legend-box" style="background:#aaa; border-color:#888;"></div>
            <span>Gruba Atanmamış</span>
        </div>
        <div class="legend-item" style="margin-top:6px;">
            <svg width="30" height="6"><line x1="0" y1="3" x2="30" y2="3" stroke="#FF6F00" stroke-width="2" stroke-dasharray="5,3"/></svg>
            <span>Hub SW Bağlantısı</span>
        </div>
    </div>

    <script>
    // ========== STATE ==========
    let selectedMachineEl = null;
    let currentMachineId = null;
    let hubSwFilterOn = false;
    let connLinesOn = true;
    
    // Group colors from PHP
    const groupColors = <?php echo json_encode($groupColors); ?>;
    
    // Connections data
    const connectionsData = <?php echo json_encode($connections); ?>;

    // ========== SEARCH ==========
    document.getElementById('searchInput').addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.machine-card').forEach(m => {
            const no = (m.getAttribute('data-machine-no') || '').toLowerCase();
            const ip = (m.getAttribute('data-smibb-ip') || '').toLowerCase();
            m.style.opacity = (!q || no.includes(q) || ip.includes(q)) ? '1' : '0.2';
        });
    });
    
    // ========== FILTER BY GROUP ==========
    function filterByGroup(groupId) {
        const clusters = document.querySelectorAll('.group-cluster');
        clusters.forEach(c => {
            if (groupId === 'all') {
                c.classList.remove('dimmed');
            } else {
                c.classList.toggle('dimmed', c.getAttribute('data-group-id') != groupId);
            }
        });
    }
    
    // ========== HUB SW FILTER ==========
    function toggleHubSwFilter() {
        hubSwFilterOn = !hubSwFilterOn;
        const btn = document.getElementById('hubSwFilterBtn');
        btn.classList.toggle('active', hubSwFilterOn);
        
        document.querySelectorAll('.machine-card').forEach(m => {
            if (hubSwFilterOn) {
                m.style.opacity = m.getAttribute('data-has-hub-sw') === '1' ? '1' : '0.15';
            } else {
                m.style.opacity = '1';
            }
        });
    }
    
    // ========== CONNECTION LINES ==========
    function toggleConnLines() {
        connLinesOn = !connLinesOn;
        const btn = document.getElementById('connLinesBtn');
        btn.style.opacity = connLinesOn ? '1' : '0.5';
        if (connLinesOn) drawConnections();
        else document.getElementById('conn-svg').innerHTML = '';
    }
    
    function drawConnections() {
        const svg = document.getElementById('conn-svg');
        svg.innerHTML = '';
        
        const canvas = document.getElementById('topology-canvas');
        const canvasRect = canvas.getBoundingClientRect();
        const scrollLeft = document.getElementById('canvas-wrapper').scrollLeft;
        const scrollTop  = document.getElementById('canvas-wrapper').scrollTop;
        
        document.querySelectorAll('.machine-card[data-has-hub-sw="1"]').forEach(srcEl => {
            const target = srcEl.getAttribute('data-hub-sw-target');
            if (!target || !target.trim()) return;
            
            const srcRect = srcEl.getBoundingClientRect();
            const srcX = srcRect.left - canvasRect.left + scrollLeft + srcRect.width / 2;
            const srcY = srcRect.top  - canvasRect.top  + scrollTop  + srcRect.height / 2;
            
            // Find target machine
            const tgtEl = findMachineByNo(target.trim());
            
            if (tgtEl) {
                const tgtRect = tgtEl.getBoundingClientRect();
                const tgtX = tgtRect.left - canvasRect.left + scrollLeft + tgtRect.width / 2;
                const tgtY = tgtRect.top  - canvasRect.top  + scrollTop  + tgtRect.height / 2;
                
                const cx = (srcX + tgtX) / 2;
                const cy = Math.min(srcY, tgtY) - 50;
                
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', `M ${srcX} ${srcY} Q ${cx} ${cy} ${tgtX} ${tgtY}`);
                path.setAttribute('class', 'conn-line hub-sw');
                svg.appendChild(path);
                
                // Arrow
                addArrow(svg, tgtX, tgtY, srcX, srcY, '#FF6F00');
                // Label
                addLabel(svg, cx, cy + 18, '→ ' + target.trim(), '#FF6F00');
            } else {
                // Draw with floating label
                const lx = srcX + 50;
                const ly = srcY - 30;
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                line.setAttribute('x1', srcX); line.setAttribute('y1', srcY);
                line.setAttribute('x2', lx); line.setAttribute('y2', ly);
                line.setAttribute('class', 'conn-line hub-sw');
                svg.appendChild(line);
                addLabel(svg, lx + 4, ly - 3, '→ ' + target.trim(), '#FF6F00');
            }
        });
    }
    
    function addArrow(svg, x, y, fromX, fromY, color) {
        const a = Math.atan2(y - fromY, x - fromX);
        const l = 12, s = 0.4;
        const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        poly.setAttribute('points',
            `${x},${y} ${x - l*Math.cos(a-s)},${y - l*Math.sin(a-s)} ${x - l*Math.cos(a+s)},${y - l*Math.sin(a+s)}`);
        poly.setAttribute('fill', color);
        svg.appendChild(poly);
    }
    
    function addLabel(svg, x, y, text, color) {
        const w = text.length * 6.2 + 8;
        const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        bg.setAttribute('x', x - 2); bg.setAttribute('y', y - 12);
        bg.setAttribute('width', w); bg.setAttribute('height', 16);
        bg.setAttribute('rx', 3); bg.setAttribute('fill', 'rgba(26,26,46,0.9)');
        svg.appendChild(bg);
        const t = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        t.setAttribute('x', x + 2); t.setAttribute('y', y);
        t.setAttribute('font-size', '10'); t.setAttribute('fill', color);
        t.setAttribute('font-family', 'Arial, sans-serif');
        t.textContent = text;
        svg.appendChild(t);
    }
    
    function findMachineByNo(no) {
        no = no.trim().toUpperCase();
        for (const m of document.querySelectorAll('.machine-card')) {
            if ((m.getAttribute('data-machine-no') || '').trim().toUpperCase() === no) return m;
        }
        return null;
    }
    
    // ========== MACHINE SELECTION & DETAIL ==========
    function selectMachine(e, el) {
        e.stopPropagation();
        
        if (selectedMachineEl) selectedMachineEl.classList.remove('selected');
        selectedMachineEl = el;
        el.classList.add('selected');
        
        const machineId = el.getAttribute('data-id');
        currentMachineId = machineId;
        openDetail(machineId, el);
    }
    
    function openDetail(machineId, el) {
        const panel = document.getElementById('detail-panel');
        const header = document.getElementById('dp-header');
        const body = document.getElementById('dp-body');
        const title = document.getElementById('dp-title');
        
        const machineNo = el.getAttribute('data-machine-no');
        title.textContent = machineNo;
        
        const gid = el.getAttribute('data-group-id');
        const hcolor = gid && groupColors[gid] ? groupColors[gid] : '#607D8B';
        header.style.background = hcolor;
        
        body.innerHTML = '<div style="text-align:center;padding:20px;color:#666;"><i class="fas fa-spinner fa-spin"></i></div>';
        panel.classList.add('open');
        
        fetch(`get_machine_detail.php?id=${machineId}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) { body.innerHTML = `<div style="color:red">${esc(data.error)}</div>`; return; }
                const m = data.machine;
                const hasHubSw = m.has_hub_sw == 1;
                
                const groupsHtml = data.groups && data.groups.length
                    ? data.groups.map(g => `<span class="group-pill" style="background:${esc(g.color||'#4CAF50')}"><i class="fas ${esc(g.icon||'fa-server')}"></i>${esc(g.name)}</span>`).join('')
                    : '<span style="color:#666;font-size:12px;">Gruba atanmamış</span>';
                
                let connsHtml = '';
                if (data.connections_out && data.connections_out.length) {
                    connsHtml += data.connections_out.map(c =>
                        `<div style="font-size:12px;padding:3px 0;">→ ${esc(c.target_machine_no||c.target_label||'?')} <small style="color:#666;">(${esc(c.connection_type)})</small></div>`
                    ).join('');
                }
                if (data.connections_in && data.connections_in.length) {
                    connsHtml += data.connections_in.map(c =>
                        `<div style="font-size:12px;padding:3px 0;color:#2196F3;">← ${esc(c.source_machine_no)} <small style="color:#666;">(gelen)</small></div>`
                    ).join('');
                }
                
                body.innerHTML = `
                    <div class="dp-row"><span class="dp-label">Makine No</span><span class="dp-value">${esc(m.machine_no)}</span></div>
                    <div class="dp-row"><span class="dp-label">SMIBB IP</span><span class="dp-value">${esc(m.smibb_ip||'-')}</span></div>
                    <div class="dp-row"><span class="dp-label">Screen IP</span><span class="dp-value">${esc(m.screen_ip||'-')}</span></div>
                    <div class="dp-row"><span class="dp-label">MAC</span><span class="dp-value">${esc(m.mac||'-')}</span></div>
                    <div class="dp-row"><span class="dp-label">Bölge (Area)</span><span class="dp-value">${m.area||'-'}</span></div>
                    <div class="dp-row"><span class="dp-label">Makine Türü</span><span class="dp-value">${esc(m.machine_type||'-')}</span></div>
                    <div class="dp-row"><span class="dp-label">Oyun Türü</span><span class="dp-value">${esc(m.game_type||'-')}</span></div>
                    <div class="dp-row"><span class="dp-label">Z Katmanı</span><span class="dp-value">${m.pos_z}</span></div>
                    <div class="dp-row"><span class="dp-label">Hub SW</span><span class="dp-value">
                        <span class="hub-badge-dp ${hasHubSw?'active':'inactive'}">${hasHubSw?'⚡ Var':'Yok'}</span>
                    </span></div>
                    ${hasHubSw ? `<div class="dp-row"><span class="dp-label">Ana Hedef</span><span class="dp-value" style="color:#FF6F00;">${esc(m.hub_sw_target||'-')}</span></div>` : ''}
                    <div class="dp-row" style="flex-direction:column;">
                        <span class="dp-label" style="margin-bottom:6px;">Gruplar</span>
                        <div>${groupsHtml}</div>
                    </div>
                    ${connsHtml ? `<div class="dp-row" style="flex-direction:column;"><span class="dp-label" style="margin-bottom:6px;">Bağlantılar</span><div>${connsHtml}</div></div>` : ''}
                    ${m.note ? `<div class="dp-row"><span class="dp-label">Not</span><span class="dp-value" style="font-style:italic;">${esc(m.note)}</span></div>` : ''}
                `;
            })
            .catch(() => { body.innerHTML = '<div style="color:red">Yüklenemedi.</div>'; });
    }
    
    function closeDetail() {
        document.getElementById('detail-panel').classList.remove('open');
        if (selectedMachineEl) selectedMachineEl.classList.remove('selected');
        selectedMachineEl = null;
        currentMachineId = null;
    }
    
    // Click on canvas background clears selection
    document.getElementById('topology-canvas').addEventListener('click', function(e){
        if (e.target === this || e.target.id === 'conn-svg') closeDetail();
    });
    
    // ========== HUB SW MODAL ==========
    function openHubSwModal() {
        if (!currentMachineId) return;
        const el = document.querySelector(`.machine-card[data-id="${currentMachineId}"]`);
        if (!el) return;
        document.getElementById('hubSwMachineName').textContent = el.getAttribute('data-machine-no');
        document.getElementById('hubSwCheck').checked = el.getAttribute('data-has-hub-sw') === '1';
        document.getElementById('hubSwTarget').value = el.getAttribute('data-hub-sw-target') || '';
        document.getElementById('hubSwModal').style.display = 'block';
    }
    
    function closeHubSwModal() {
        document.getElementById('hubSwModal').style.display = 'none';
    }
    
    function saveHubSw() {
        if (!currentMachineId) return;
        const hasHubSw  = document.getElementById('hubSwCheck').checked ? 1 : 0;
        const hubTarget = document.getElementById('hubSwTarget').value.trim();
        
        const fd = new FormData();
        fd.append('machine_id', currentMachineId);
        fd.append('has_hub_sw', hasHubSw);
        fd.append('hub_sw_target', hubTarget);
        
        fetch('update_machine_hubsw.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const el = document.querySelector(`.machine-card[data-id="${currentMachineId}"]`);
                    if (el) {
                        el.setAttribute('data-has-hub-sw', hasHubSw ? '1' : '0');
                        el.setAttribute('data-hub-sw-target', hubTarget);
                        el.classList.toggle('has-hub-sw', hasHubSw === 1);
                        
                        let badge = el.querySelector('.hub-badge');
                        if (hasHubSw && !badge) {
                            badge = document.createElement('div');
                            badge.className = 'hub-badge';
                            badge.textContent = 'Hub SW';
                            el.appendChild(badge);
                        } else if (!hasHubSw && badge) {
                            badge.remove();
                        }
                    }
                    closeHubSwModal();
                    openDetail(currentMachineId, document.querySelector(`.machine-card[data-id="${currentMachineId}"]`));
                    drawConnections();
                } else {
                    alert('Hata: ' + data.error);
                }
            });
    }
    
    // ========== AUTO LAYOUT ==========
    function autoLayout() {
        const clusters = document.querySelectorAll('.group-cluster');
        const cols = 3;
        let c = 0, r = 0;
        clusters.forEach(cl => {
            const w = cl.offsetWidth + 60;
            const h = cl.offsetHeight + 60;
            cl.style.left = (40 + c * (w)) + 'px';
            cl.style.top  = (60 + r * (h)) + 'px';
            c++;
            if (c >= cols) { c = 0; r++; }
        });
        setTimeout(drawConnections, 100);
    }
    
    // ========== CLUSTER DRAG ==========
    (function setupDrag() {
        let dragging = null, ox = 0, oy = 0, startLeft = 0, startTop = 0;
        
        document.addEventListener('mousedown', function(e) {
            const header = e.target.closest('.group-header');
            if (!header) return;
            const cluster = header.closest('.group-cluster, #ungrouped-section');
            if (!cluster) return;
            e.preventDefault();
            dragging = cluster;
            ox = e.clientX;
            oy = e.clientY;
            startLeft = parseInt(cluster.style.left) || 0;
            startTop  = parseInt(cluster.style.top)  || 0;
        });
        
        document.addEventListener('mousemove', function(e) {
            if (!dragging) return;
            dragging.style.left = (startLeft + e.clientX - ox) + 'px';
            dragging.style.top  = (startTop  + e.clientY - oy) + 'px';
            if (connLinesOn) drawConnections();
        });
        
        document.addEventListener('mouseup', function() {
            dragging = null;
        });
    })();
    
    // ========== UTIL ==========
    function esc(text) {
        if (!text) return '';
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }
    
    // ========== INIT ==========
    window.addEventListener('load', function() {
        if (connLinesOn) setTimeout(drawConnections, 200);
    });
    
    // Redraw on scroll
    document.getElementById('canvas-wrapper').addEventListener('scroll', function() {
        if (connLinesOn) drawConnections();
    });
    </script>
</body>
</html>
