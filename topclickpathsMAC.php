<?php
// analytics_dashboard.php - Fixed User Agents Alignment with MAC Addresses
require_once 'config.php';
require_once 'totp_auth.php';

// --------------------------------------------
// SESSION INITIALIZATION - Get current user
// --------------------------------------------
//authSession();
//authCurrentUser();
//authRequireLogin();


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
            // Check if session has a mac_address, if not use a default
            $macAddress = isset($session['mac_address']) ? $session['mac_address'] : 'unknown_mac';
            
            foreach ($session['page_views'] as $view) {
                $view['session_id'] = $session['session_id'];
                $view['start_time'] = $session['start_time'];
                // Ensure mac_address is set
                $view['mac_address'] = $macAddress;
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
        // Ensure mac_address exists
        $mac = isset($view['mac_address']) ? $view['mac_address'] : 'unknown_mac';
        
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
        $macData[$mac]['pages'][] = isset($view['url']) ? $view['url'] : 'unknown_url';
        
        $sessionId = isset($view['session_id']) ? $view['session_id'] : 'unknown_session';
        if (!isset($macData[$mac]['sessions'][$sessionId])) {
            $macData[$mac]['sessions'][$sessionId] = [
                'pages' => [],
                'timestamps' => []
            ];
        }
        $macData[$mac]['sessions'][$sessionId]['pages'][] = isset($view['url']) ? $view['url'] : 'unknown_url';
        $macData[$mac]['sessions'][$sessionId]['timestamps'][] = isset($view['timestamp']) ? $view['timestamp'] : time();
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

/**
 * NEW FUNCTION: Get most common click paths across all MACs
 * Now returns TOP 1000 paths
 */
function getMostCommonPaths($macData, $limit = 1000) {
    $pathCounts = [];
    
    foreach ($macData as $mac) {
        if (isset($mac['full_paths']) && is_array($mac['full_paths'])) {
            foreach ($mac['full_paths'] as $path) {
                if (isset($path['path_string'])) {
                    $key = $path['path_string'];
                    if (!isset($pathCounts[$key])) {
                        $pathCounts[$key] = [
                            'count' => 0,
                            'pages' => $path['pages'],
                            'macs' => []
                        ];
                    }
                    $pathCounts[$key]['count'] = intval($pathCounts[$key]['count']) + 1;
                    if (!in_array($mac['mac_address'], $pathCounts[$key]['macs'])) {
                        $pathCounts[$key]['macs'][] = $mac['mac_address'];
                    }
                }
            }
        }
    }
    
    // Sort by count descending
    uasort($pathCounts, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    return array_slice($pathCounts, 0, $limit);
}

// Calculate most common paths across all MACs - TOP 1000
$commonPaths = getMostCommonPaths($macData, 1000);

// --- FIXED: All calculations with proper type casting ---
$totalUniquePaths = intval(count($commonPaths));

// Pagination settings
$pathsPerPage = 50;
$currentPage = isset($_GET['path_page']) ? intval($_GET['path_page']) : 1;
if ($currentPage < 1) $currentPage = 1;

// Calculate total pages - ensure integer division
if ($totalUniquePaths > 0) {
    $totalPathPages = intval(ceil($totalUniquePaths / $pathsPerPage));
} else {
    $totalPathPages = 1;
}

// Calculate offset
$offset = intval(($currentPage - 1) * $pathsPerPage);
if ($offset < 0) $offset = 0;

// Get paginated paths
$pagedPaths = [];
if ($totalUniquePaths > 0 && $offset < $totalUniquePaths) {
    $pagedPaths = array_slice($commonPaths, $offset, $pathsPerPage, true);
}

// Get the top path for footer
$topPathString = '';
$topPathCount = 0;
if (!empty($commonPaths)) {
    $topPath = reset($commonPaths);
    $topPathString = key($commonPaths);
    $topPathCount = intval($topPath['count']);
}

// Ensure $pagedPaths is always an array
if (!is_array($pagedPaths)) {
    $pagedPaths = [];
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meest Voorkomende Click Paths</title>
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
        
        /* Common paths list */
        .common-path-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }
        .common-path-item:hover {
            background: #f9f9f9;
        }
        .common-path-count {
            font-weight: 700;
            min-width: 65px;
            color: #000;
            font-size: 13px;
        }
        .common-path-macs {
            color: #555;
            min-width: 70px;
            font-size: 10px;
        }
        .common-path-pages {
            flex: 1;
        }
        .rank-badge {
            display: inline-block;
            background: #000;
            color: #fff;
            padding: 1px 8px;
            border-radius: 2px;
            font-size: 10px;
            margin-right: 5px;
        }
        .rank-badge.top1 { background: #ffd700; color: #000; }
        .rank-badge.top2 { background: #c0c0c0; color: #000; }
        .rank-badge.top3 { background: #cd7f32; color: #000; }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 15px 0;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 5px 12px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #000;
            font-size: 12px;
        }
        .pagination a:hover {
            background: #f0f0f0;
        }
        .pagination .active {
            background: #000;
            color: #fff;
            border-color: #000;
        }
        .pagination .disabled {
            color: #ccc;
            pointer-events: none;
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
            .common-path-item {
                flex-wrap: wrap;
                gap: 5px;
            }
            .common-path-count {
                min-width: 50px;
            }
        }
        @media print {
            .filters, .btn, .pagination {
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
    <h1>MEEST VOORKOMENDE CLICK PATHS</h1>
    <p class="subtitle">Click paths over alle MAC addresses heen (page -> page -> EXIT) | Top 1000</p>
    
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
            <div class="label">Unique Paths</div>
            <div class="value"><?php echo number_format($totalUniquePaths); ?></div>
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
    
    <!-- Most Common Click Paths -->
    <div class="section" style="border-color: #000000; border-width: 2px;">
        <div class="section-header">
            <h2>MEEST VOORKOMENDE CLICK PATHS</h2>
            <span class="count">Toont <?php echo count($pagedPaths); ?> van <?php echo number_format($totalUniquePaths); ?> unieke paths (Top 1000)</span>
        </div>
        
        <?php if (!empty($pagedPaths)): ?>
        <div style="display: flex; flex-direction: column;">
            <?php 
            $rank = $offset;
            foreach ($pagedPaths as $pathString => $data): 
                $rank++;
                $isHighlight = $rank <= 10;
            ?>
            <div class="common-path-item" style="<?php echo $isHighlight ? 'background: #f5f5f5;' : ''; ?>">
                <span class="common-path-count">
                    <?php if ($rank == 1): ?>
                        <span class="rank-badge top1">#1</span> <?php echo $data['count']; ?>x
                    <?php elseif ($rank == 2): ?>
                        <span class="rank-badge top2">#2</span> <?php echo $data['count']; ?>x
                    <?php elseif ($rank == 3): ?>
                        <span class="rank-badge top3">#3</span> <?php echo $data['count']; ?>x
                    <?php else: ?>
                        #<?php echo $rank; ?> <?php echo $data['count']; ?>x
                    <?php endif; ?>
                </span>
                <span class="common-path-macs">
                    <?php echo count($data['macs']); ?> MACs
                </span>
                <span class="common-path-pages">
                    <?php 
                    $pages = $data['pages'];
                    $displayPages = array_slice($pages, 0, 5);
                    $hasMore = count($pages) > 5;
                    
                    foreach ($displayPages as $idx => $page): 
                        if ($idx > 0): ?><span class="click-arrow">-></span><?php endif; ?>
                        <span class="click-path" style="font-size: 10px;"><?php echo htmlspecialchars(substr($page, 0, 30)) . (strlen($page) > 30 ? '...' : ''); ?></span>
                    <?php endforeach; 
                    
                    if ($hasMore): ?>
                        <span style="color: #999; font-size: 10px;"> (+<?php echo count($pages) - 5; ?> more)</span>
                    <?php endif; ?>
                    <span class="click-arrow">-></span>
                    <span class="exit-tag">EXIT</span>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPathPages > 1): ?>
        <div class="pagination">
            <?php if ($currentPage > 1): ?>
                <a href="?path_page=<?php echo $currentPage - 1; ?>&filter_mac=<?php echo urlencode($filterMac); ?>&filter_date=<?php echo urlencode($filterDate); ?>">Vorige</a>
            <?php else: ?>
                <span class="disabled">Vorige</span>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $currentPage - 3);
            $endPage = min($totalPathPages, $currentPage + 3);
            
            if ($startPage > 1) {
                echo '<a href="?path_page=1&filter_mac=' . urlencode($filterMac) . '&filter_date=' . urlencode($filterDate) . '">1</a>';
                if ($startPage > 2) echo '<span>...</span>';
            }
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                if ($i == $currentPage) {
                    echo '<span class="active">' . $i . '</span>';
                } else {
                    echo '<a href="?path_page=' . $i . '&filter_mac=' . urlencode($filterMac) . '&filter_date=' . urlencode($filterDate) . '">' . $i . '</a>';
                }
            }
            
            if ($endPage < $totalPathPages) {
                if ($endPage < $totalPathPages - 1) echo '<span>...</span>';
                echo '<a href="?path_page=' . $totalPathPages . '&filter_mac=' . urlencode($filterMac) . '&filter_date=' . urlencode($filterDate) . '">' . $totalPathPages . '</a>';
            }
            ?>
            
            <?php if ($currentPage < $totalPathPages): ?>
                <a href="?path_page=<?php echo $currentPage + 1; ?>&filter_mac=<?php echo urlencode($filterMac); ?>&filter_date=<?php echo urlencode($filterDate); ?>">Volgende</a>
            <?php else: ?>
                <span class="disabled">Volgende</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div style="padding:20px; text-align:center; color:#555;">Geen click paths gevonden.</div>
        <?php endif; ?>
    </div>
    
    <div class="footer">
        <?php echo number_format($totalViews); ?> total page views &bull; <?php echo number_format($totalMACs); ?> unique MAC addresses &bull; <?php echo number_format($filteredTotal); ?> filtered views
        <br>
        Meest voorkomende path: 
        <?php if (!empty($topPathString)): ?>
            <code><?php echo htmlspecialchars(substr($topPathString, 0, 60)) . (strlen($topPathString) > 60 ? '...' : ''); ?></code>
            (<?php echo $topPathCount; ?>x)
        <?php else: ?>
            <span style="color: #999;">Geen paths</span>
        <?php endif; ?>
    </div>
</div>
</body>
</html>