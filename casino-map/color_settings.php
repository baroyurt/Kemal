<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include("config.php");

$message = '';
$error   = '';

// ── Renkleri kaydet ──────────────────────────────────────────────────────────
if (isset($_POST['save_colors'])) {
    csrf_verify();

    $allowed = ['machine_color_normal', 'machine_color_note', 'map_bg_color'];
    foreach ($allowed as $key) {
        if (isset($_POST[$key])) {
            $val = trim($_POST[$key]);
            // Basit HEX renk doğrulaması  (#rgb, #rrggbb)
            if (!preg_match('/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $val)) {
                $error = "Geçersiz renk değeri: " . htmlspecialchars($val);
                break;
            }
            $stmt = $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $stmt->bind_param("ss", $key, $val);
            $stmt->execute();
            $stmt->close();
        }
    }
    if (empty($error)) {
        $message = "Renk ayarları kaydedildi.";
    }
}

// ── Mevcut renkleri oku ──────────────────────────────────────────────────────
function get_setting(mysqli $conn, string $key, string $default): string {
    $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['value'] : $default;
}

$colorNormal  = get_setting($conn, 'machine_color_normal', '#4CAF50');
$colorNote    = get_setting($conn, 'machine_color_note',   '#40E0D0');
$colorMapBg   = get_setting($conn, 'map_bg_color',         '#e0e0e0');

// Renk kontrastı için metin rengini hesapla (açık/koyu)
function text_color(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
    // Göreli parlaklık
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.55 ? '#333333' : '#ffffff';
}

$textNormal = text_color($colorNormal);
$textNote   = text_color($colorNote);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Renk Ayarları</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 760px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px;
                  box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px;
                  display: flex; justify-content: space-between; align-items: center; }
        .header h2 { margin: 0; color: #333; }
        .card { background: white; padding: 24px; border-radius: 10px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message   { background: #e8f5e9; color: #2e7d32; padding: 10px 16px; border-radius: 5px;
                     margin-bottom: 15px; border-left: 4px solid #4CAF50; }
        .error-msg { background: #ffebee; color: #b71c1c; padding: 10px 16px; border-radius: 5px;
                     margin-bottom: 15px; border-left: 4px solid #f44336; }

        /* Renk satırları */
        .color-row { display: flex; align-items: center; gap: 16px; margin-bottom: 20px;
                     padding-bottom: 20px; border-bottom: 1px solid #f0f0f0; }
        .color-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .color-label { flex: 1; }
        .color-label strong { display: block; font-size: 14px; color: #333; margin-bottom: 4px; }
        .color-label span { font-size: 12px; color: #777; }
        .picker-wrap { display: flex; align-items: center; gap: 10px; }
        input[type="color"] {
            width: 54px; height: 38px; padding: 2px; border: 1px solid #ccc;
            border-radius: 6px; cursor: pointer; background: none;
        }
        .hex-input {
            width: 100px; padding: 8px 10px; border: 1px solid #ddd; border-radius: 5px;
            font-size: 13px; font-family: monospace;
        }

        /* Önizleme */
        .preview-section { margin-top: 10px; }
        .preview-section h4 { color: #555; font-size: 13px; margin: 0 0 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .preview-grid { display: flex; gap: 16px; flex-wrap: wrap; }
        .machine-preview {
            width: 70px; height: 70px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.6);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            box-shadow: 4px 4px 8px rgba(0,0,0,0.35); font-size: 11px; font-weight: bold;
            text-align: center; line-height: 1.3; padding: 4px; position: relative;
            transition: background 0.3s, color 0.3s;
        }
        .machine-preview::before {
            content: ''; position: absolute; top: 14px; left: 8px; right: 8px;
            height: 4px; background: rgba(0,0,0,0.45); border-radius: 2px; pointer-events: none;
        }
        .machine-preview .mp-label { margin-top: 8px; }
        .machine-preview .mp-ip { font-size: 8px; opacity: 0.85; }
        .preview-caption { font-size: 11px; color: #888; text-align: center; margin-top: 4px; }

        button[type="submit"] {
            padding: 10px 28px; background: #4CAF50; color: white; border: none;
            border-radius: 6px; cursor: pointer; font-size: 15px; margin-top: 8px;
        }
        button[type="submit"]:hover { background: #45a049; }
        a.back-link { color: #666; text-decoration: none; font-size: 13px; }
        a.back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h2>🎨 Makine Renk Ayarları</h2>
        <a class="back-link" href="dashboard.php">← Panoya Dön</a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>🖌️ Renk Paleti</h3>
        <p style="font-size:13px; color:#777; margin-top:0;">
            Seçilen renkler haritadaki <strong>tüm makinelere</strong> anında uygulanır.
        </p>

        <form method="post" id="colorForm">
            <?= csrf_field() ?>

            <!-- Normal makine rengi -->
            <div class="color-row">
                <div class="color-label">
                    <strong>Normal Makine Arka Plan Rengi</strong>
                    <span>Notu olmayan makinelerin arka plan rengi</span>
                </div>
                <div class="picker-wrap">
                    <input type="color" id="picker_normal" value="<?= htmlspecialchars($colorNormal) ?>"
                           oninput="syncColor('normal', this.value)">
                    <input type="text" name="machine_color_normal" id="hex_normal" class="hex-input"
                           value="<?= htmlspecialchars($colorNormal) ?>" maxlength="7"
                           pattern="^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$"
                           oninput="syncPicker('normal', this.value)" required>
                </div>
            </div>

            <!-- Notlu makine rengi -->
            <div class="color-row">
                <div class="color-label">
                    <strong>Notlu Makine Arka Plan Rengi</strong>
                    <span>Notu olan makinelerin arka plan rengi</span>
                </div>
                <div class="picker-wrap">
                    <input type="color" id="picker_note" value="<?= htmlspecialchars($colorNote) ?>"
                           oninput="syncColor('note', this.value)">
                    <input type="text" name="machine_color_note" id="hex_note" class="hex-input"
                           value="<?= htmlspecialchars($colorNote) ?>" maxlength="7"
                           pattern="^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$"
                           oninput="syncPicker('note', this.value)" required>
                </div>
            </div>

            <!-- Harita arka plan rengi -->
            <div class="color-row">
                <div class="color-label">
                    <strong>Harita Arka Plan Rengi</strong>
                    <span>Harita tuvalinin arka plan rengi</span>
                </div>
                <div class="picker-wrap">
                    <input type="color" id="picker_mapbg" value="<?= htmlspecialchars($colorMapBg) ?>"
                           oninput="syncColor('mapbg', this.value)">
                    <input type="text" name="map_bg_color" id="hex_mapbg" class="hex-input"
                           value="<?= htmlspecialchars($colorMapBg) ?>" maxlength="7"
                           pattern="^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$"
                           oninput="syncPicker('mapbg', this.value)" required>
                </div>
            </div>

            <button type="submit" name="save_colors">💾 Kaydet</button>
        </form>
    </div>

    <!-- Önizleme -->
    <div class="card">
        <h3>👁️ Önizleme</h3>
        <div class="preview-grid">
            <div>
                <div class="machine-preview" id="prev_normal"
                     style="background:<?= htmlspecialchars($colorNormal) ?>; color:<?= htmlspecialchars($textNormal) ?>;">
                    <span class="mp-label">MK-001</span>
                    <span class="mp-ip">192.168.1.1</span>
                </div>
                <div class="preview-caption">Normal</div>
            </div>
            <div>
                <div class="machine-preview" id="prev_note"
                     style="background:<?= htmlspecialchars($colorNote) ?>; color:<?= htmlspecialchars($textNote) ?>;">
                    <span class="mp-label">MK-002</span>
                    <span class="mp-ip">192.168.1.2</span>
                </div>
                <div class="preview-caption">Notlu</div>
            </div>
            <div>
                <div style="width:70px; height:70px; border-radius:10px; border:1px solid #ccc;
                            box-shadow:4px 4px 8px rgba(0,0,0,0.35); transition:background 0.3s;"
                     id="prev_mapbg" data-bg="1"
                     style="background:<?= htmlspecialchars($colorMapBg) ?>;"></div>
                <div class="preview-caption">Harita Zem.</div>
            </div>
        </div>
    </div>
</div>

<script>
// Renk kontrastı hesapla (JS tarafı — önizleme için)
function contrastColor(hex) {
    hex = hex.replace('#','');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    const r = parseInt(hex.slice(0,2),16);
    const g = parseInt(hex.slice(2,4),16);
    const b = parseInt(hex.slice(4,6),16);
    return (0.299*r + 0.587*g + 0.114*b)/255 > 0.55 ? '#333333' : '#ffffff';
}

function isValidHex(v) { return /^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/.test(v); }

function syncColor(type, val) {
    document.getElementById('hex_' + type).value = val;
    updatePreview(type, val);
}

function syncPicker(type, val) {
    if (isValidHex(val)) {
        document.getElementById('picker_' + type).value = val;
        updatePreview(type, val);
    }
}

function updatePreview(type, hex) {
    if (!isValidHex(hex)) return;
    const el = document.getElementById('prev_' + type);
    if (!el) return;
    el.style.background = hex;
    // mapbg preview is just a colour swatch — no text colour to update
    if (type !== 'mapbg') el.style.color = contrastColor(hex);
}

// Initialise mapbg preview on load
document.addEventListener('DOMContentLoaded', function() {
    updatePreview('mapbg', document.getElementById('hex_mapbg').value);
});
</script>
</body>
</html>
