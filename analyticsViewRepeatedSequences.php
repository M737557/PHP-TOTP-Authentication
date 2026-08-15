<?php
// mac_top_hits_with_path.php - Top MAC Hits with Full Click Paths
require_once 'config.php';
require_once 'totp_auth.php';

// --------------------------------------------
// SESSION INITIALIZATION - Get current user
// --------------------------------------------
authSession();
authCurrentUser();
authRequireLogin();


/**
 * Delete analytics files older than 14 days
 * Runs automatically on each page load
 */
function deleteOldAnalytics($dir, $daysToKeep = 14) {
    // Check if directory exists
    if (!is_dir($dir)) {
        error_log("Analytics directory not found: {$dir}");
        return 0;
    }
    
    $files = glob($dir . '/*.json');
    if ($files === false) {
        error_log("Cannot read analytics directory: {$dir}");
        return 0;
    }
    
    $deletedCount = 0;
    $cutoffTime = strtotime("-{$daysToKeep} days");
    
    foreach ($files as $file) {
        // Skip if file doesn't exist
        if (!file_exists($file)) {
            continue;
        }
        
        $fileTime = filemtime($file);
        if ($fileTime !== false && $fileTime < $cutoffTime) {
            if (@unlink($file)) {
                $deletedCount++;
            } else {
                error_log("Could not delete old analytics file: {$file}");
            }
        }
    }
    
    return $deletedCount;
}

// Usage:
$deleted = deleteOldAnalytics(__DIR__ . '/analytics');
echo "{$deleted} old files deleted.";

// Configuration
$analyticsDir = 'analytics_chunks';
$timezone = 'UTC';
date_default_timezone_set($timezone);

/**
 * Load all JSON files from directory
 */
function loadAnalyticsFiles($dir) {
    $files = glob($dir . '/*.json');
    $allPageViews = [];
    
    foreach ($files as $file) {
        $jsonData = file_get_contents($file);
        if ($jsonData === false) continue;
        
        $data = json_decode($jsonData, true);
        if ($data === null || !isset($data['sessions'])) continue;
        
        foreach ($data['sessions'] as $session) {
            foreach ($session['page_views'] as $view) {
                $view['session_id'] = $session['session_id'];
                $view['start_time'] = $session['start_time'];
                $allPageViews[] = $view;
            }
        }
    }
    
    return $allPageViews;
}

// Load all analytics data
$pageViews = loadAnalyticsFiles($analyticsDir);

if (empty($pageViews)) {
    die('Error: No valid JSON files found in the directory.');
}

/**
 * Get filter parameters
 */
$filterMac = isset($_GET['filter_mac']) ? trim($_GET['filter_mac']) : '';
$filterDate = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';

// HARDCODED LIMIT - 15000
$limit = 15000;

/**
 * Apply filters to page views
 */
function applyFilters($views, $filterMac, $filterDate) {
    return array_filter($views, function($view) use ($filterMac, $filterDate) {
        if ($filterMac && stripos($view['mac_address'], $filterMac) === false) return false;
        if ($filterDate && date('Y-m-d', $view['timestamp']) !== $filterDate) return false;
        return true;
    });
}

$filteredViews = applyFilters($pageViews, $filterMac, $filterDate);

/**
 * Get unique dates for date filter
 */
function getUniqueDates($views) {
    $dates = [];
    foreach ($views as $view) {
        $date = date('Y-m-d', $view['timestamp']);
        $dates[$date] = $date;
    }
    ksort($dates);
    return $dates;
}

$uniqueDates = getUniqueDates($pageViews);

/**
 * Get MAC addresses with full click path analysis
 */
function getMACsWithFullPaths($views) {
    $macData = [];
    foreach ($views as $view) {
        $mac = $view['mac_address'];
        if (!isset($macData[$mac])) {
            $macData[$mac] = [
                'mac_address' => $mac,
                'page_views' => 0,
                'sessions' => [],
                'pages' => [],
                'exit_pages' => [],
                'start_pages' => []
            ];
        }
        $macData[$mac]['page_views'] = intval($macData[$mac]['page_views']) + 1;
        $macData[$mac]['pages'][] = $view['url'];
        
        $sessionId = $view['session_id'];
        if (!isset($macData[$mac]['sessions'][$sessionId])) {
            $macData[$mac]['sessions'][$sessionId] = [
                'pages' => [],
                'timestamps' => []
            ];
        }
        $macData[$mac]['sessions'][$sessionId]['pages'][] = $view['url'];
        $macData[$mac]['sessions'][$sessionId]['timestamps'][] = $view['timestamp'];
    }
    
    foreach ($macData as $mac => &$data) {
        $fullPaths = [];
        $sessionCount = 0;
        
        foreach ($data['sessions'] as $sessionId => $sessionData) {
            $pages = $sessionData['pages'];
            $sessionCount = intval($sessionCount) + 1;
            
            if (!empty($pages)) {
                $data['start_pages'][] = $pages[0];
                $data['exit_pages'][] = end($pages);
            }
            
            if (count($pages) >= 1) {
                $pathString = implode(' -> ', $pages);
                $fullPaths[] = [
                    'session_id' => $sessionId,
                    'pages' => $pages,
                    'path_string' => $pathString,
                    'path_with_exit' => $pathString . ' -> EXIT',
                    'length' => count($pages),
                    'start_page' => !empty($pages) ? $pages[0] : '',
                    'exit_page' => !empty($pages) ? end($pages) : ''
                ];
            }
        }
        
        $data['full_paths'] = $fullPaths;
        $data['total_sessions'] = $sessionCount;
        $data['unique_pages'] = count(array_unique($data['pages']));
        
        if (!empty($data['start_pages'])) {
            $startCounts = array_count_values($data['start_pages']);
            arsort($startCounts);
            $data['most_common_start'] = key($startCounts);
        } else {
            $data['most_common_start'] = '';
        }
        
        if (!empty($data['exit_pages'])) {
            $exitCounts = array_count_values($data['exit_pages']);
            arsort($exitCounts);
            $data['most_common_exit'] = key($exitCounts);
        } else {
            $data['most_common_exit'] = '';
        }
        
        unset($data['sessions']);
        unset($data['start_pages']);
        unset($data['exit_pages']);
    }
    
    uasort($macData, function($a, $b) {
        $aViews = isset($a['page_views']) ? intval($a['page_views']) : 0;
        $bViews = isset($b['page_views']) ? intval($b['page_views']) : 0;
        return $bViews - $aViews;
    });
    
    return $macData;
}

// Get MAC data with full paths
$macData = getMACsWithFullPaths($filteredViews);

// Apply hardcoded limit (15000)
$limitedMACs = array_slice($macData, 0, $limit);

// Total stats
$totalViews = count($pageViews);
$filteredTotal = count($filteredViews);
$totalMACs = count($macData);

// Get top MAC
$topMAC = null;
$topMACAddress = '';
$topMACViews = 0;
if (!empty($macData)) {
    $topMAC = reset($macData);
    if ($topMAC) {
        $topMACAddress = isset($topMAC['mac_address']) ? $topMAC['mac_address'] : '';
        $topMACViews = isset($topMAC['page_views']) ? intval($topMAC['page_views']) : 0;
    }
}

// Calculate summary statistics - ALL values explicitly cast to int
$totalPageViews = 0;
$totalSessions = 0;
$macsWithPaths = 0;
$maxViews = 0;
$maxViewsMac = '';
$longestPath = 0;
$longestPathMac = '';

foreach ($macData as $mac) {
    $pageViews = isset($mac['page_views']) ? intval($mac['page_views']) : 0;
    $sessions = isset($mac['total_sessions']) ? intval($mac['total_sessions']) : 0;
    
    $totalPageViews = intval($totalPageViews) + $pageViews;
    $totalSessions = intval($totalSessions) + $sessions;
    
    if (!empty($mac['full_paths'])) {
        $macsWithPaths = intval($macsWithPaths) + 1;
    }
    
    if ($pageViews > $maxViews) {
        $maxViews = $pageViews;
        $maxViewsMac = isset($mac['mac_address']) ? $mac['mac_address'] : '';
    }
    
    if (isset($mac['full_paths']) && is_array($mac['full_paths'])) {
        foreach ($mac['full_paths'] as $path) {
            $pathLength = isset($path['length']) ? intval($path['length']) : 0;
            if ($pathLength > $longestPath) {
                $longestPath = $pathLength;
                $longestPathMac = isset($mac['mac_address']) ? $mac['mac_address'] : '';
            }
        }
    }
}

$avgViews = 0;
if ($totalMACs > 0) {
    $avgViews = round($totalPageViews / $totalMACs, 1);
}

// Calculate path counts for display
$macPathCounts = [];
foreach ($limitedMACs as $index => $mac) {
    $pathCounts = [];
    if (isset($mac['full_paths']) && is_array($mac['full_paths'])) {
        foreach ($mac['full_paths'] as $path) {
            if (isset($path['path_string'])) {
                $key = $path['path_string'];
                if (!isset($pathCounts[$key])) {
                    $pathCounts[$key] = 0;
                }
                $pathCounts[$key] = intval($pathCounts[$key]) + 1;
            }
        }
    }
    arsort($pathCounts);
    $macPathCounts[$index] = $pathCounts;
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAC Top Hits met Click Paths</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            background: #ffffff;
            color: #000000;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            font-size: 26px;
            margin-bottom: 2px;
            font-weight: 700;
        }
        .subtitle {
            color: #555555;
            margin-bottom: 25px;
            font-size: 13px;
        }
        
        /* Top MAC Highlight */
        .highlight-box {
            border: 3px solid #000000;
            padding: 20px;
            margin-bottom: 25px;
            background: #f9f9f9;
        }
        .highlight-box .top-mac {
            font-size: 24px;
            font-weight: 700;
        }
        .highlight-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 10px;
        }
        .highlight-item {
            border: 1px solid #ccc;
            padding: 12px 15px;
            background: #ffffff;
        }
        .highlight-item .label {
            font-size: 11px;
            text-transform: uppercase;
            color: #555;
            letter-spacing: 0.5px;
        }
        .highlight-item .value {
            font-size: 18px;
            font-weight: 700;
            margin-top: 4px;
        }
        
        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 25px;
        }
        .stat-card {
            border: 1px solid #cccccc;
            padding: 14px 18px;
            background: #ffffff;
        }
        .stat-card .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555555;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 2px;
        }
        
        /* Filters */
        .filters {
            border: 1px solid #cccccc;
            padding: 16px 18px;
            margin-bottom: 25px;
            background: #f9f9f9;
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
            align-items: center;
        }
        .filters label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filters input, .filters select {
            padding: 6px 10px;
            border: 1px solid #aaaaaa;
            font-size: 12px;
            font-family: 'Courier New', Courier, monospace;
            background: #ffffff;
            color: #000000;
            min-width: 140px;
        }
        .filters input:focus, .filters select:focus {
            outline: none;
            border-color: #000000;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 6px 16px;
            background: #000000;
            color: #ffffff;
            border: 1px solid #000000;
            font-size: 12px;
            cursor: pointer;
            font-family: 'Courier New', Courier, monospace;
            text-decoration: none;
            display: inline-block;
            letter-spacing: 0.3px;
        }
        .btn:hover {
            background: #333333;
        }
        .btn-secondary {
            background: #ffffff;
            color: #000000;
            border: 1px solid #999999;
        }
        .btn-secondary:hover {
            background: #eeeeee;
        }
        
        /* Section */
        .section {
            border: 1px solid #cccccc;
            padding: 18px 20px;
            margin-bottom: 25px;
            background: #ffffff;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .section-header h2 {
            font-size: 16px;
            font-weight: 700;
        }
        .section-header .count {
            font-size: 12px;
            color: #555555;
        }
        
        /* Formatted List - MAC Cards */
        .mac-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .mac-item {
            border: 1px solid #ddd;
            padding: 14px 18px;
            background: #ffffff;
            position: relative;
        }
        .mac-item.top-item {
            border-color: #000000;
            border-width: 2px;
            background: #fffde7;
        }
        .mac-item .mac-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 6px;
        }
        .mac-item .mac-header .mac-name {
            font-weight: 700;
            font-size: 16px;
        }
        .mac-item .mac-header .mac-views {
            font-weight: 700;
            font-size: 18px;
        }
        .mac-item .mac-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 4px 15px;
            font-size: 12px;
            margin: 4px 0 6px 0;
            padding: 4px 0;
            border-top: 1px dashed #eee;
            border-bottom: 1px dashed #eee;
        }
        .mac-item .mac-details .detail-label {
            color: #555;
        }
        .mac-item .mac-details .detail-value {
            font-weight: 600;
        }
        .mac-item .mac-path {
            background: #f5f5f5;
            padding: 6px 10px;
            margin: 4px 0;
            font-size: 12px;
        }
        .mac-item .mac-path .path-label {
            color: #555;
            font-size: 10px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 2px;
        }
        
        /* Rank badges with # */
        .rank {
            display: inline-block;
            min-width: 30px;
            text-align: center;
            font-weight: 700;
            color: #000000;
            margin-right: 4px;
            font-size: 14px;
        }
        .rank-1 { 
            font-size: 18px; 
            color: #000000;
            background: #ffd700;
            padding: 0 6px;
            border-radius: 3px;
        }
        .rank-2 { 
            font-size: 16px; 
            color: #000000;
            background: #c0c0c0;
            padding: 0 6px;
            border-radius: 3px;
        }
        .rank-3 { 
            font-size: 14px; 
            color: #000000;
            background: #cd7f32;
            padding: 0 6px;
            border-radius: 3px;
        }
        .rank-normal {
            font-size: 13px;
            color: #555;
        }
        
        /* Path styling */
        .click-path {
            display: inline-block;
            background: #f0f0f0;
            padding: 2px 8px;
            margin: 1px;
            font-size: 11px;
            border-radius: 2px;
        }
        .click-arrow {
            color: #999;
            margin: 0 4px;
            font-weight: 700;
        }
        .exit-tag {
            display: inline-block;
            background: #000000;
            color: #ffffff;
            padding: 2px 8px;
            margin: 1px;
            font-size: 10px;
            font-weight: 700;
            border-radius: 2px;
        }
        .top-badge {
            display: inline-block;
            background: #000000;
            color: #ffffff;
            padding: 1px 8px;
            font-size: 10px;
            font-weight: 700;
            margin-left: 5px;
            border-radius: 2px;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #555555;
            font-size: 12px;
            border-top: 1px solid #cccccc;
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .highlight-grid {
                grid-template-columns: 1fr;
            }
            .mac-item .mac-details {
                grid-template-columns: 1fr 1fr;
            }
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-group {
                width: 100%;
            }
            .filter-group input, .filter-group select {
                flex: 1;
                min-width: 80px;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media print {
            .filters, .btn {
                display: none;
            }
            .section {
                border-color: #000000;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>MAC ADRES TOP HITS</h1>
    <p class="subtitle">Meeste page views met volledige click paths (page -> page -> EXIT) | Limit: 15000</p>
    
    <!-- TOP MAC Highlight -->
    <div class="highlight-box">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <div style="font-size: 14px; color: #555; text-transform: uppercase; letter-spacing: 1px;">Meeste Hits</div>
                <div class="top-mac">
                    <code style="font-size: 28px;"><?php echo htmlspecialchars($topMACAddress); ?></code>
                    <span class="top-badge">TOP 1</span>
                </div>
                <div style="font-size: 14px; color: #555; margin-top: 5px;">
                    <?php echo number_format($topMACViews); ?> page views
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 12px; color: #555;">Aantal MAC addresses</div>
                <div style="font-size: 32px; font-weight: 700;"><?php echo number_format($totalMACs); ?></div>
            </div>
        </div>
        
        <div class="highlight-grid">
            <div class="highlight-item">
                <div class="label">Meest bezochte startpagina</div>
                <div class="value"><code><?php echo htmlspecialchars($topMAC && isset($topMAC['most_common_start']) ? $topMAC['most_common_start'] : 'N/A'); ?></code></div>
            </div>
            <div class="highlight-item">
                <div class="label">Meest gebruikte exit pagina</div>
                <div class="value"><code><?php echo htmlspecialchars($topMAC && isset($topMAC['most_common_exit']) ? $topMAC['most_common_exit'] : 'N/A'); ?></code></div>
            </div>
            <div class="highlight-item">
                <div class="label">Langste click path</div>
                <div class="value"><?php echo intval($longestPath); ?> pages</div>
            </div>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Views</div>
            <div class="value"><?php echo number_format($totalViews); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Filtered Views</div>
            <div class="value"><?php echo number_format($filteredTotal); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active MACs</div>
            <div class="value"><?php echo number_format($totalMACs); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Gem. Views per MAC</div>
            <div class="value"><?php echo number_format($avgViews, 1); ?></div>
        </div>
    </div>
    
    <!-- Filters -->
    <form method="GET" class="filters">
        <div class="filter-group">
            <label>MAC:</label>
            <input type="text" name="filter_mac" placeholder="Zoek MAC..." value="<?php echo htmlspecialchars($filterMac); ?>">
        </div>
        <div class="filter-group">
            <label>Datum:</label>
            <select name="filter_date">
                <option value="">Alle Datums</option>
                <?php foreach ($uniqueDates as $date): ?>
                <option value="<?php echo $date; ?>" <?php echo $filterDate === $date ? 'selected' : ''; ?>><?php echo $date; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Toepassen</button>
        <a href="?" class="btn btn-secondary">Wis</a>
    </form>
    
    <!-- MAC Addresses - Formatted List with # Column -->
    <div class="section">
        <div class="section-header">
            <h2>TOP MAC ADRESSEN (meeste page views)</h2>
            <span class="count">Toont <?php echo number_format(count($limitedMACs)); ?> van <?php echo number_format($totalMACs); ?> MACs (limit: 15000)</span>
        </div>
        
        <div class="mac-list">
            <?php if (empty($limitedMACs)): ?>
            <div style="padding:30px; text-align:center; color:#555555;">Geen MAC addresses gevonden.</div>
            <?php else: ?>
            <?php foreach ($limitedMACs as $index => $mac): 
                $isTop = ($index === 0);
                #$rankNum = $index + 1;
                #$rankClass = $index < 3 ? 'rank-' . ($index + 1) : 'rank-normal';
                
                // Get path counts for this MAC
                $pathCounts = isset($macPathCounts[$index]) ? $macPathCounts[$index] : [];
                $mostCommonPath = !empty($pathCounts) ? key($pathCounts) : '';
                $mostCommonPathData = null;
                if (isset($mac['full_paths']) && is_array($mac['full_paths'])) {
                    foreach ($mac['full_paths'] as $path) {
                        if (isset($path['path_string']) && $path['path_string'] === $mostCommonPath) {
                            $mostCommonPathData = $path;
                            break;
                        }
                    }
                }
            ?>
            <div class="mac-item <?php echo $isTop ? 'top-item' : ''; ?>">
                <div class="mac-header">
                    <span class="mac-name">
                        <code><strong><?php echo htmlspecialchars($mac['mac_address']); ?></strong></code>
                        <?php if ($isTop): ?>
                        <span class="top-badge">TOP</span>
                        <?php endif; ?>
                    </span>
                    <span class="mac-views"><?php echo number_format(intval($mac['page_views'])); ?> views</span>
                </div>
                
                <div class="mac-details">
                    <div><span class="detail-label">Sessies:</span> <span class="detail-value"><?php echo number_format(intval($mac['total_sessions'])); ?></span></div>
                    <div><span class="detail-label">Unieke pages:</span> <span class="detail-value"><?php echo number_format(intval($mac['unique_pages'])); ?></span></div>
                    <div><span class="detail-label">Start:</span> <span class="detail-value"><code><?php echo htmlspecialchars(substr($mac['most_common_start'], 0, 25)); ?></code></span></div>
                    <div><span class="detail-label">Exit:</span> <span class="detail-value"><code><?php echo htmlspecialchars(substr($mac['most_common_exit'], 0, 25)); ?></code></span></div>
                </div>
                
                <div class="mac-path">
                    <span class="path-label">Meest gebruikte click path:</span>
                    <?php if ($mostCommonPathData && isset($mostCommonPathData['pages'])): ?>
                        <?php foreach ($mostCommonPathData['pages'] as $idx => $page): ?>
                            <?php if ($idx > 0): ?><span class="click-arrow">-></span><?php endif; ?>
                            <span class="click-path" style="font-size: 10px;"><?php echo htmlspecialchars(substr($page, 0, 30)) . (strlen($page) > 30 ? '...' : ''); ?></span>
                        <?php endforeach; ?>
                        <span class="click-arrow">-></span>
                        <span class="exit-tag">EXIT</span>
                        <span style="font-size: 10px; color: #999; margin-left: 5px;">
                            (<?php echo isset($pathCounts[$mostCommonPath]) ? intval($pathCounts[$mostCommonPath]) : 0; ?>x)
                        </span>
                    <?php else: ?>
                        <span style="color: #999; font-size: 11px;">Geen click paths</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($limitedMACs)): ?>
        <div style="margin-top: 15px; padding: 10px; background: #f5f5f5; font-size: 11px; color: #555;">
            <strong>Legenda:</strong> 
            <span class="click-path">Pagina</span> 
            <span class="click-arrow">-></span> klik overgang 
            <span class="exit-tag">EXIT</span> = sessie beëindigd (geen verdere clicks)
            <span style="margin-left: 15px;">TOP = Meeste hits</span>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="footer">
        <?php echo number_format($totalViews); ?> total page views &bull; <?php echo number_format($totalMACs); ?> unique MAC addresses &bull; <?php echo number_format($filteredTotal); ?> filtered views
        <br>
        Top MAC: <code><?php echo htmlspecialchars($topMACAddress); ?></code> met <?php echo number_format($topMACViews); ?> views
    </div>
</div>
</body>
</html>