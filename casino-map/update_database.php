<?php
/**
 * update_database.php
 * Veritabanı şemasını günceller ve CSV'den makine verilerini yükler.
 * Sadece admin kullanıcıları erişebilir.
 */
session_start();

if (!isset($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] != 'admin') {
    die("Bu sayfaya erişim için yönetici yetkisi gereklidir.");
}

include("config.php");

// ─── Salon adı → pos_z eşleştirmesi ───────────────────────────────────────────
$salonMap = [
    'YÜKSEK TAVAN'    => 0,
    'ALÇAK TAVAN'     => 1,
    'YENİ VİP SALON'  => 2,
    'ALT SALON'       => 3,
];

$log      = [];   // Yapılan işlemlerin Türkçe özeti
$errors   = [];   // Hata mesajları

// ─── 1. ADIM: Şema Migrasyonları ──────────────────────────────────────────────
function addColumnIfMissing($conn, $table, $column, $definition) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result->num_rows === 0) {
        if ($conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
            return "✅ '{$table}' tablosuna '{$column}' sütunu eklendi.";
        } else {
            return "❌ '{$column}' sütunu eklenemedi: " . $conn->error;
        }
    }
    return "ℹ️ '{$table}.{$column}' sütunu zaten mevcut, atlandı.";
}

if (isset($_POST['run'])) {

    // machine_groups tablosu
    $log[] = addColumnIfMissing($conn, 'machine_groups', 'color', "VARCHAR(20) DEFAULT '#4CAF50'");

    // machines tablosu
    $log[] = addColumnIfMissing($conn, 'machines', 'hub_sw',       "TINYINT(1) DEFAULT 0");
    $log[] = addColumnIfMissing($conn, 'machines', 'hub_sw_cable', "VARCHAR(255) DEFAULT NULL");
    $log[] = addColumnIfMissing($conn, 'machines', 'brand',        "VARCHAR(100) DEFAULT NULL");
    $log[] = addColumnIfMissing($conn, 'machines', 'model',        "VARCHAR(100) DEFAULT NULL");
    $log[] = addColumnIfMissing($conn, 'machines', 'game_type',    "VARCHAR(100) DEFAULT NULL");
    $log[] = addColumnIfMissing($conn, 'machines', 'drscreen_ip',  "VARCHAR(50) DEFAULT NULL");

    // ─── 2. ADIM: Harita Konumları (machine_no => [x, y, rotation]) ──────────────
    // Koordinatlar machines.sql'den alınmıştır (gerçek yerleşim).
    // machine_no => [pos_x, pos_y, rotation]
    $positions = [
        // ── YÜKSEK TAVAN (Z=0) ──────────────────────────────────────────────────
        // 11 referans noktadan türetilen bölge bazlı formüllerle hesaplanmıştır.
        2134 => [2036, 1340,  90],   2138 => [2037, 1265,  90],
        2426 => [2164, 2020,  90],   2459 => [2036, 2110,  90],
        2460 => [2166, 2108,  90],   2597 => [2154, 1357,  90],
        2602 => [1124, 1261,   0],   2603 => [1187, 1261,   0],
        2605 => [1250, 1261,   0],   2606 => [1313, 1261,   0],
        2760 => [ 858, 1540, 315],   2761 => [ 795, 1445, 270],
        2762 => [ 795, 1382, 270],   2763 => [ 795, 1319, 270],
        2764 => [ 855, 1308, 225],   2765 => [ 773, 1308, 135],
        2766 => [ 722, 1360,  90],   2767 => [ 629, 1379,  90],
        2768 => [ 722, 1487,  90],   2769 => [ 774, 1541,  45],
        2799 => [ 683, 2190, 180],   2800 => [ 746, 2190, 180],
        2803 => [1095, 2190, 180],   2804 => [ 809, 2190, 180],
        2807 => [1221, 2190, 180],   2808 => [1158, 2190, 180],
        2811 => [1635, 2190, 180],   2812 => [1572, 2190, 180],
        2813 => [1509, 2190, 180],   2925 => [ 430, 1732,  90],
        2926 => [ 430, 1795,  90],   2927 => [ 430, 1858,  90],
        2928 => [ 430, 1921,  90],   2929 => [ 430, 1984,  90],
        2930 => [ 493, 1984, 270],   2931 => [ 493, 1921, 270],
        2932 => [ 493, 1858, 270],   2933 => [ 493, 1795, 270],
        2934 => [ 493, 1732, 270],   2951 => [2157, 1456,  90],
        2952 => [2157, 1556,  90],   2953 => [2157, 1647,  90],
        2954 => [2157, 1742,  90],   2955 => [2160, 1837,  90],
        2956 => [2162, 1929,  90],   2972 => [1446, 2190, 180],
        2973 => [1383, 2190, 180],   2974 => [1032, 2190, 180],
        2975 => [ 969, 2190, 180],   2976 => [ 620, 2190, 180],
        2977 => [ 557, 2190, 180],   3014 => [ 493, 1576, 270],
        3015 => [ 493, 1513, 270],   3016 => [ 493, 1450, 270],
        3017 => [ 493, 1387, 270],   3018 => [ 493, 1324, 270],
        3019 => [ 493, 1261, 270],   3020 => [ 430, 1261,  90],
        3021 => [ 430, 1324,  90],   3022 => [ 430, 1387,  90],
        3023 => [ 430, 1450,  90],   3024 => [ 430, 1513,  90],
        3025 => [ 430, 1576,  90],   3033 => [1376, 1261,   0],
        3034 => [1439, 1261,   0],   3035 => [1502, 1261,   0],
        3036 => [1565, 1261,   0],   3057 => [1816, 1350, 180],
        3058 => [1880, 1350, 180],   3059 => [1816, 1424,   0],
        3060 => [1880, 1424,   0],   3061 => [1816, 1631, 180],
        3062 => [1880, 1631, 180],   3063 => [1816, 1705,   0],
        3064 => [1880, 1705,   0],   3065 => [1816, 1900, 180],
        3066 => [1880, 1900, 180],   3067 => [1880, 1974,   0],
        3068 => [1816, 1974,   0],   3069 => [1847, 2190, 180],
        3070 => [1910, 2190, 180],   3071 => [1973, 2190, 180],
        3080 => [ 721, 1667,  90],   3081 => [ 623, 1630,  90],
        3082 => [ 623, 1693,  90],   3083 => [ 720, 1858,  90],
        3084 => [ 782, 1917,   0],   3085 => [ 735, 1814,   0],
        3086 => [ 912, 1859, 270],   3087 => [ 791, 1696, 270],
        3088 => [ 791, 1633, 270],   3089 => [ 910, 1668, 270],

        // ── ALÇAK TAVAN (Z=1) ───────────────────────────────────────────────────
        // 17 referans noktadan türetilen satır/kolon bazlı formüllerle hesaplanmıştır.
        2126 => [1166,  191,   0],   2127 => [ 436,  947, 270],
        2325 => [1103,  191,   0],   2334 => [1419,  409, 180],
        2335 => [1298,  473,   0],   2336 => [ 436,  883, 270],
        2337 => [1229,  191,   0],   2338 => [ 436,  674, 270],
        2339 => [ 436,  738, 270],   2340 => [1482,  409, 180],
        2341 => [1361,  473,   0],   2342 => [1356,  409, 180],
        2343 => [1545,  409, 180],   2365 => [1480,  948, 180],
        2366 => [1417,  948, 180],   2367 => [1354,  948, 180],
        2368 => [1291,  948, 180],   2369 => [1228,  948, 180],
        2370 => [1165,  948, 180],   2371 => [1102,  948, 180],
        2372 => [1108, 1012,   0],   2373 => [1171, 1012,   0],
        2374 => [1234, 1012,   0],   2375 => [1297, 1012,   0],
        2376 => [1360, 1012,   0],   2377 => [1423, 1012,   0],
        2378 => [1486, 1012,   0],   2383 => [1550,  473,   0],
        2384 => [1235,  473,   0],   2386 => [1293,  409, 180],
        2387 => [1230,  409, 180],   2389 => [1172,  473,   0],
        2390 => [1167,  409, 180],   2410 => [1487,  473,   0],
        2411 => [1424,  473,   0],   2462 => [ 436, 1011, 270],
        2622 => [1109,  473,   0],   2623 => [1104,  409, 180],
        2802 => [ 988,  674, 180],   2857 => [ 713,   37,   0],
        2858 => [ 777,   37,   0],   2859 => [ 841,   37,   0],
        2860 => [ 905,   37,   0],   2861 => [ 969,   37,   0],
        2862 => [1033,   37,   0],   2863 => [1097,   37,   0],
        2864 => [1161,   37,   0],   2865 => [1225,   37,   0],
        2866 => [1289,   37,   0],   2957 => [ 615, 1012,   0],
        2958 => [ 678, 1012,   0],   2959 => [ 741, 1012,   0],
        2960 => [ 804, 1012,   0],   2961 => [ 867, 1012,   0],
        2962 => [ 930, 1012,   0],   2963 => [ 926,  948, 180],
        2964 => [ 863,  948, 180],   2965 => [ 800,  948, 180],
        2966 => [ 737,  948, 180],   2967 => [ 674,  948, 180],
        2968 => [ 611,  948, 180],   2984 => [ 739,  473,   0],
        2985 => [ 805,  473,   0],   2986 => [ 868,  473,   0],
        2987 => [ 931,  473,   0],   2988 => [ 928,  409, 180],
        2989 => [ 865,  409, 180],   2990 => [ 802,  409, 180],
        2991 => [ 739,  409, 180],   2992 => [ 925,  674, 180],
        2993 => [ 862,  674, 180],   2994 => [ 799,  674, 180],
        2995 => [ 736,  674, 180],   2996 => [ 673,  674, 180],
        2997 => [ 610,  674, 180],   2998 => [ 610,  191,   0],
        2999 => [ 673,  191,   0],   3000 => [ 736,  191,   0],
        3001 => [ 799,  191,   0],   3002 => [ 862,  191,   0],
        3003 => [ 925,  191,   0],   3004 => [1292,  191,   0],
        3005 => [1355,  191,   0],   3006 => [1418,  191,   0],
        3026 => [ 616,  738,   0],   3027 => [ 679,  738,   0],
        3028 => [ 742,  738,   0],   3029 => [ 805,  738,   0],
        3030 => [ 868,  738,   0],   3031 => [ 931,  738,   0],
        3032 => [ 994,  738,   0],

        // ── YENİ VİP SALON (Z=2) ────────────────────────────────────────────────
        // 11 referans noktadan türetilen bölge bazlı formüllerle hesaplanmıştır.
        2192 => [2108,  796, 270],   2194 => [2108,  733, 270],
        2257 => [2380,  798, 270],   2258 => [2380,  735, 270],
        2259 => [2380,  672, 270],   2360 => [2317,  609,  90],
        2361 => [2317,  672,  90],   2362 => [2317,  735,  90],
        2363 => [2317,  798,  90],   2364 => [2380,  609, 270],   // CSV'de 2364 = fiziksel 2260
        2443 => [2981,  230,   0],   2604 => [3044,  230,   0],
        2607 => [3107,  230,   0],   2635 => [2108,  670, 270],
        2722 => [3170,  230,   0],   2723 => [3233,  230,   0],
        2724 => [3296,  230,   0],   2725 => [3359,  230,   0],
        2726 => [3422,  230,   0],   2727 => [3485,  230,   0],
        2728 => [3548,  230,   0],   2729 => [3611,  230,   0],
        2730 => [3674,  230,   0],   2731 => [3737,  230,   0],
        2732 => [3800,  230,   0],   2738 => [2896,  543, 270],
        2770 => [2108,  607, 270],   2946 => [2896,  670, 270],
        2947 => [2896,  606, 270],   2948 => [2896,  480, 270],
        2949 => [2896,  416, 270],   2969 => [3885,  416,  90],
        2970 => [3885,  480,  90],   2971 => [3885,  544,  90],
        3037 => [3885,  607,  90],   3038 => [3885,  671,  90],
        3051 => [3885,  735,  90],   3052 => [3885,  798,  90],
        3053 => [3885,  862,  90],   3054 => [3885,  926,  90],
        3055 => [3734, 1053, 180],   3056 => [3671, 1053, 180],
        3072 => [2701,  798, 270],   3073 => [2701,  735, 270],
        3074 => [2701,  672, 270],   3075 => [2701,  609, 270],
        3076 => [2637,  607,  90],   3077 => [2637,  670,  90],
        3078 => [2637,  733,  90],   3079 => [2637,  796,  90],

        // ── ALT SALON (Z=3) ──────────────────────────────────────────────────────
        // Koordinatlar 9 kullanıcı-onaylı referans noktadan türetilen
        // bölge bazlı doğrusal formüllerle hesaplanmıştır:
        //   Üst sıra (Y=67):      X = round(1.01587·X + 1842),  Y = 1347
        //   Alt sol  (Y=851):     X = round(1.01587·X + 1849),  Y = 2097
        //   Alt sağ  (Y=850):     X = round(1.01587·X + 1771),  Y = 2097
        //   Kolon X (X=679-1937): X = round(2501 + 0.9221·(X−679))
        //   Kolon Y (Y=310-633):  Y = round(1534 + 1.0127·(Y−315))
        2131 => [2281, 1852, 270],   2132 => [2281, 1788, 270],
        2133 => [2281, 1724, 270],   2139 => [2281, 1660, 270],
        2195 => [2757, 2097, 180],   2196 => [2693, 2097, 180],
        2197 => [2629, 2097, 180],   2199 => [2565, 2097, 180],   2200 => [2501, 2097, 180],
        2221 => [2501, 1534,  90],   2222 => [2779, 1598,  90],
        2224 => [2501, 1598,  90],   2225 => [2501, 1662,  90],
        2226 => [2838, 1598, 270],   2227 => [2838, 1534, 270],
        2228 => [2501, 1725,  90],   2229 => [2501, 1789,  90],   2230 => [2501, 1853,  90],
        2245 => [2838, 1725, 270],   2246 => [2838, 1789, 270],
        2247 => [2838, 1853, 270],   2248 => [2779, 1853,  90],
        2249 => [2779, 1789,  90],   2250 => [2779, 1725,  90],
        2290 => [2559, 1853, 270],   2291 => [2559, 1789, 270],   2292 => [2559, 1725, 270],
        2293 => [2559, 1662,   0],   2294 => [2559, 1598, 270],   2295 => [2559, 1534, 270],
        2298 => [2779, 1662,  90],   2299 => [2779, 1534,  90],   2324 => [2838, 1662, 270],
        2584 => [3052, 1537,  90],   2585 => [3052, 1601,  90],   2586 => [3052, 1665,  90],
        2587 => [3052, 1728,  90],   2588 => [3052, 1792,  90],   2589 => [3052, 1856,  90],
        2590 => [3111, 1856, 270],   2591 => [3111, 1792, 270],
        2592 => [3111, 1728, 270],   2593 => [3111, 1665, 270],
        2594 => [3111, 1601, 270],   2595 => [3111, 1537, 270],
        2624 => [3827, 2097, 180],   2625 => [3763, 2097, 180],
        2626 => [3699, 2097, 180],   2627 => [3635, 2097, 180],
        2628 => [3571, 2097, 180],   2629 => [3507, 2097, 180],
        2630 => [3443, 2097, 180],   2631 => [3379, 2097, 180],
        2632 => [2949, 2097, 180],   2633 => [2885, 2097, 180],   2634 => [2821, 2097, 180],
        2801 => [3603, 1529,  90],   2805 => [3603, 1593,  90],   2806 => [3603, 1657,  90],
        2809 => [3603, 1720,  90],   2810 => [3603, 1784,  90],
        2853 => [2483, 1347,   0],   2854 => [2547, 1347,   0],
        2855 => [2355, 1347,   0],   2856 => [2419, 1347,   0],
        2867 => [2867, 1347,   0],   2868 => [2803, 1347,   0],
        2869 => [2611, 1347,   0],   2870 => [2675, 1347,   0],   2871 => [2739, 1347,   0],
        2935 => [2931, 1347,   0],   2936 => [2995, 1347,   0],   2937 => [3059, 1347,   0],
        2938 => [3123, 1347,   0],   2939 => [3187, 1347,   0],   2940 => [3251, 1347,   0],
        2941 => [3315, 1347,   0],   2942 => [3379, 1347,   0],
        2943 => [3443, 1347,   0],   2944 => [3507, 1347,   0],
        2978 => [3366, 1659, 270],   2979 => [3366, 1595, 270],   2980 => [3366, 1531, 270],
        2981 => [3308, 1531,  90],   2982 => [3308, 1595,  90],   2983 => [3308, 1659,  90],
        3007 => [3603, 1848,  90],   3008 => [3661, 1848, 270],
        3009 => [3661, 1784, 270],   3010 => [3661, 1720, 270],
        3011 => [3661, 1657, 270],   3012 => [3661, 1593, 270],   3013 => [3661, 1529, 270],
        3039 => [3308, 1722,  90],   3040 => [3308, 1786,  90],   3041 => [3308, 1850,  90],
        3042 => [3366, 1850, 270],   3043 => [3366, 1786, 270],   3044 => [3366, 1722, 270],
        3045 => [3571, 1347,   0],   3046 => [3635, 1347,   0],   3047 => [3699, 1347,   0],
        3048 => [3763, 1347,   0],   3049 => [3827, 1347,   0],   3050 => [3891, 1347,   0],
    ];

    // ─── 3. ADIM: CSV'den Makine Verilerini Yükle ─────────────────────────────
    $csvPath = __DIR__ . '/machines.csv';

    if (!file_exists($csvPath)) {
        $errors[] = "❌ CSV dosyası bulunamadı: $csvPath";
    } else {
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $errors[] = "❌ CSV dosyası açılamadı.";
        } else {
            // Başlık satırını atla
            fgetcsv($handle);

            $inserted = 0;
            $updated  = 0;
            $skipped  = 0;

            $stmtCheck = $conn->prepare(
                "SELECT id FROM machines WHERE machine_no = ?"
            );
            $stmtInsert = $conn->prepare(
                "INSERT INTO machines (machine_no, brand, model, game_type, pos_x, pos_y, pos_z, rotation)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            // Mevcut makinelerde konum/rotation KORUNUR — sadece marka/model/oyun türü güncellenir.
            // Harita üzerinde elle ayarlanan konumların üzerine yazılmaması için pos_x/pos_y/rotation burada değiştirilmez.
            $stmtUpdate = $conn->prepare(
                "UPDATE machines SET brand = ?, model = ?, game_type = ?
                 WHERE machine_no = ?"
            );

            while (($row = fgetcsv($handle)) !== false) {
                // CSV sütunları: Sıra, Salon, Makine No, Marka, Model, Oyun Türü
                if (count($row) < 6) {
                    $skipped++;
                    continue;
                }

                $salon      = trim($row[1]);
                $machineNo  = trim($row[2]);
                $brand      = trim($row[3]);
                $model      = trim($row[4]);
                $gameType   = trim($row[5]);

                // Bilinmeyen salon varsa atla
                if (!isset($salonMap[$salon])) {
                    $errors[] = "⚠️ Bilinmeyen salon '{$salon}', makine no {$machineNo} atlandı.";
                    $skipped++;
                    continue;
                }

                $posZ = $salonMap[$salon];

                // Konum tablosundan x, y, rotation al (yoksa varsayılan + uyarı)
                $mn = intval($machineNo);
                if (isset($positions[$mn])) {
                    $posX   = $positions[$mn][0];
                    $posY   = $positions[$mn][1];
                    $rotDeg = $positions[$mn][2];
                } else {
                    $posX   = 50;
                    $posY   = 50;
                    $rotDeg = 0;
                    $errors[] = "⚠️ Makine {$machineNo} için konum tanımlanmamış; varsayılan konum (50,50) kullanıldı.";
                }

                // Makine zaten var mı?
                $stmtCheck->bind_param("s", $machineNo);
                $stmtCheck->execute();
                $stmtCheck->store_result();

                if ($stmtCheck->num_rows > 0) {
                    // Mevcut makineyi güncelle — konum/rotation DEĞİŞTİRİLMEZ
                    $stmtUpdate->bind_param("ssss", $brand, $model, $gameType, $machineNo);
                    $stmtUpdate->execute();
                    $updated++;
                } else {
                    // Yeni makine ekle
                    $stmtInsert->bind_param("ssssiiii", $machineNo, $brand, $model, $gameType, $posX, $posY, $posZ, $rotDeg);
                    $stmtInsert->execute();
                    $inserted++;
                }
            }

            fclose($handle);
            $stmtCheck->close();
            $stmtInsert->close();
            $stmtUpdate->close();

            $log[] = "✅ CSV işlendi: <strong>{$inserted}</strong> yeni makine eklendi, "
                   . "<strong>{$updated}</strong> mevcut makine güncellendi, "
                   . "<strong>{$skipped}</strong> satır atlandı.";

            // Özet: salon başına makine sayısı
            foreach ($salonMap as $salonName => $z) {
                $z   = intval($z);
                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM machines WHERE pos_z = ?");
                $stmt->bind_param("i", $z);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                $log[] = "📍 " . htmlspecialchars($salonName) . " (Z={$z}): <strong>" . intval($cnt) . "</strong> makine";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veritabanı Güncelle — Casino Map</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 30px; color: #333; }
        .container { max-width: 860px; margin: 0 auto; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); padding: 30px; margin-bottom: 24px; }
        h1 { font-size: 22px; color: #2d2d2d; margin-bottom: 6px; }
        h2 { font-size: 16px; color: #555; font-weight: normal; margin-bottom: 20px; }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 14px 18px; border-radius: 6px; font-size: 14px; line-height: 1.7; margin-bottom: 24px; }
        .info-box ul { padding-left: 18px; margin-top: 8px; }
        .info-box li { margin-bottom: 4px; }
        .run-btn { display: inline-block; padding: 14px 32px; background: #4CAF50; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        .run-btn:hover { background: #388e3c; }
        .back-link { display: inline-block; margin-left: 16px; color: #666; text-decoration: none; font-size: 14px; }
        .log-list { list-style: none; padding: 0; }
        .log-list li { padding: 9px 14px; border-bottom: 1px solid #f0f0f0; font-size: 14px; line-height: 1.5; }
        .log-list li:last-child { border-bottom: none; }
        .error-list { background: #fdecea; border-left: 4px solid #f44336; padding: 14px 18px; border-radius: 6px; font-size: 14px; line-height: 1.7; }
        .error-list li { margin-bottom: 4px; }
        h3 { font-size: 15px; color: #333; margin-bottom: 12px; }
        .success-banner { background: #e8f5e9; border-left: 4px solid #4CAF50; padding: 14px 18px; border-radius: 6px; font-weight: bold; color: #2e7d32; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f5f5f5; padding: 8px 10px; text-align: left; border-bottom: 2px solid #e0e0e0; }
        td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; }
        .badge { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: bold; color: white; }
        .z0 { background: #2196F3; }
        .z1 { background: #9C27B0; }
        .z2 { background: #FF9800; }
        .z3 { background: #4CAF50; }
    </style>
</head>
<body>
<div class="container">

    <div class="card">
        <h1>🔧 Veritabanı Güncelleme Aracı</h1>
        <h2>Şema migrasyonlarını uygular ve CSV'den makine verilerini yükler.</h2>

        <div class="info-box">
            <strong>Bu araç şunları yapar:</strong>
            <ul>
                <li>Yeni veritabanı sütunlarını ekler (<code>hub_sw</code>, <code>hub_sw_cable</code>, <code>brand</code>, <code>model</code>, <code>game_type</code>, grup <code>color</code>)</li>
                <li>CSV dosyasındaki tüm makineleri <code>machines</code> tablosuna yükler</li>
                <li><strong>Yeni makineler:</strong> CSV'deki salon bilgisinden salon kodu, <code>$positions</code> tablosundan konum ve rotation atanır</li>
                <li><strong>Mevcut makineler:</strong> Yalnızca marka/model/oyun türü güncellenir — harita üzerinde elle ayarlanan <code>pos_x</code>, <code>pos_y</code>, <code>rotation</code> <em>değiştirilmez</em></li>
            </ul>
            <br>
            <strong>Salon → Harita Katı eşleştirmesi:</strong>
            <ul>
                <li>YÜKSEK TAVAN &rarr; Z=0</li>
                <li>ALÇAK TAVAN &rarr; Z=1</li>
                <li>YENİ VİP SALON &rarr; Z=2</li>
                <li>ALT SALON &rarr; Z=3</li>
            </ul>
        </div>

        <form method="post">
            <button type="submit" name="run" class="run-btn">▶ Güncellemeyi Başlat</button>
            <a href="dashboard.php" class="back-link">← Panoya Dön</a>
        </form>
    </div>

    <?php if (isset($_POST['run'])): ?>

        <?php if (empty($errors) && !empty($log)): ?>
            <div class="success-banner">✅ Güncelleme tamamlandı!</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="card">
            <h3>⚠️ Hatalar</h3>
            <div class="error-list"><ul>
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($log)): ?>
        <div class="card">
            <h3>📋 İşlem Raporu</h3>
            <ul class="log-list">
                <?php foreach ($log as $entry): ?>
                    <li><?php echo $entry; /* HTML controlled entirely by server-side code; dynamic parts (names/counts) are escaped at generation time */ ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Güncel özet tablosu -->
        <div class="card">
            <h3>📊 Salona Göre Makine Özeti</h3>
            <?php
            $salonLabels = [
                0 => ['YÜKSEK TAVAN',   'z0'],
                1 => ['ALÇAK TAVAN',    'z1'],
                2 => ['YENİ VİP SALON', 'z2'],
                3 => ['ALT SALON',      'z3'],
            ];
            ?>
            <table>
                <tr>
                    <th>Salon</th>
                    <th>Makine Sayısı</th>
                    <th>Örnek Makineler</th>
                </tr>
                <?php foreach ($salonLabels as $z => [$label, $cls]): ?>
                <?php
                    $zi = intval($z);
                    $stmtCnt = $conn->prepare("SELECT COUNT(*) AS c FROM machines WHERE pos_z = ?");
                    $stmtCnt->bind_param("i", $zi);
                    $stmtCnt->execute();
                    $cnt = $stmtCnt->get_result()->fetch_assoc()['c'];
                    $stmtCnt->close();

                    $stmtSmp = $conn->prepare("SELECT machine_no FROM machines WHERE pos_z = ? ORDER BY machine_no LIMIT 5");
                    $stmtSmp->bind_param("i", $zi);
                    $stmtSmp->execute();
                    $smpRes = $stmtSmp->get_result();
                    $nos = [];
                    while ($r = $smpRes->fetch_assoc()) $nos[] = htmlspecialchars($r['machine_no']);
                    $stmtSmp->close();
                ?>
                <tr>
                    <td><span class="badge <?php echo htmlspecialchars($cls); ?>"><?php echo htmlspecialchars($label); ?></span></td>
                    <td><strong><?php echo intval($cnt); ?></strong></td>
                    <td style="color:#666;"><?php echo implode(', ', $nos); echo intval($cnt) > 5 ? ' …' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

    <?php endif; ?>
</div>
</body>
</html>
