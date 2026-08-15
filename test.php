<?php
// analytics_cart_analysis.php - DSA Optimized Cart & Exit Page Analytics
require_once 'config.php';
require_once 'totp_auth.php';

// --------------------------------------------
// SESSION INITIALIZATION
// --------------------------------------------
authSession();
authCurrentUser();
authRequireLogin();

// HARDCODED LIMIT - Maximaal 15000 records weergeven
define('MAX_DISPLAY_LIMIT', 15000);

/**
 * Delete old analytics files
 */
function deleteOldAnalytics($dir, $daysToKeep = 14) {
    if (!is_dir($dir)) return 0;
    $files = glob($dir . '/*.json');
    $deletedCount = 0;
    $cutoffTime = strtotime("-{$daysToKeep} days");
    foreach ($files as $file) {
        if (!file_exists($file)) continue;
        $fileTime = filemtime($file);
        if ($fileTime !== false && $fileTime < $cutoffTime) {
            if (@unlink($file)) $deletedCount++;
        }
    }
    return $deletedCount;
}

$deleted = deleteOldAnalytics(__DIR__ . '/analytics');

// Configuration
$analyticsDir = 'analytics_chunks';
$timezone = 'UTC';
date_default_timezone_set($timezone);

// ============================================
// DSA: CONFIGURATIE - WINKELMAND & EXIT PAGES
// ============================================

// URL patterns die het winkelmandje aangeven
$cartPatterns = [
    '/cart',
    '/shopping-cart',
    '/winkelwagen',
    '/cart/',
    '/winkelwagen/',
    '/basket',
    '/shopping_bag',
    '/checkout/cart',
    '/mandje',
    '/winkelmand',
    '/winkelmandje'
];

// URL patterns die productpaginas aangeven
$productPatterns = [
    '/product/',
    '/p/',
    '/item/',
    '/producten/',
    '/shop/',
    '/products/',
    '/catalog/',
    '/category/',
    '/product-detail/',
    '/details/'
];

// URL patterns die checkout/afrekenen aangeven
$checkoutPatterns = [
    '/checkout',
    '/afrekenen',
    '/order',
    '/bestellen',
    '/payment',
    '/betaling',
    '/checkout/',
    '/order-confirmation',
    '/bestelling-bevestigen'
];

// ============================================
// DSA: PRODUCT MAPPING - URL naar product naam
// ============================================
$productMapping = [
    // Smartphones
    'iphone-15-pro' => 'iPhone 15 Pro Max',
    'iphone-15' => 'iPhone 15 Pro',
    'iphone-14' => 'iPhone 14',
    'samsung-s24' => 'Samsung Galaxy S24 Ultra',
    'samsung-s23' => 'Samsung Galaxy S23',
    'google-pixel-8' => 'Google Pixel 8 Pro',
    'oneplus-12' => 'OnePlus 12',
    
    // Laptops
    'macbook-pro-m3' => 'MacBook Pro M3',
    'macbook-air-m2' => 'MacBook Air M2',
    'dell-xps-16' => 'Dell XPS 16',
    'dell-xps-15' => 'Dell XPS 15',
    'lenovo-thinkpad' => 'Lenovo ThinkPad X1 Carbon',
    'asus-zenbook' => 'ASUS ZenBook 14',
    'hp-spectre' => 'HP Spectre x360',
    
    // Tablets
    'ipad-pro-m2' => 'iPad Pro M2',
    'ipad-air' => 'iPad Air',
    'ipad-mini' => 'iPad Mini',
    'samsung-tab-s9' => 'Samsung Galaxy Tab S9',
    
    // Audio
    'airpods-pro-2' => 'AirPods Pro 2',
    'airpods-max' => 'AirPods Max',
    'sony-wh-1000xm5' => 'Sony WH-1000XM5',
    'sony-wf-1000xm5' => 'Sony WF-1000XM5',
    'bose-qc-ultra' => 'Bose QuietComfort Ultra',
    'beats-studio-buds' => 'Beats Studio Buds+',
    'jbl-flip-6' => 'JBL Flip 6',
    'sonos-era-100' => 'Sonos Era 100',
    'sonos-move' => 'Sonos Move',
    
    // Wearables
    'apple-watch-9' => 'Apple Watch Series 9',
    'apple-watch-ultra' => 'Apple Watch Ultra 2',
    'samsung-watch-6' => 'Samsung Galaxy Watch 6',
    'garmin-forerunner' => 'Garmin Forerunner 265',
    
    // Cameras
    'sony-a7-iv' => 'Sony A7 IV',
    'sony-a7-iii' => 'Sony A7 III',
    'canon-r6' => 'Canon EOS R6 Mark II',
    'canon-r8' => 'Canon EOS R8',
    'nikon-z8' => 'Nikon Z8',
    'gopro-hero-12' => 'GoPro Hero 12',
    'insta360-x3' => 'Insta360 X3',
    'djI-pocket-3' => 'DJI Pocket 3',
    
    // Accessoires
    'logitech-mx-keys' => 'Logitech MX Keys S',
    'logitech-mx-master-3s' => 'Logitech MX Master 3S',
    'anker-737-charger' => 'Anker 737 Charger',
    'otterbox-defender' => 'OtterBox Defender',
    'mophie-powerstation' => 'Mophie Powerstation',
    'belkin-wireless' => 'Belkin Wireless Charger',
    'shure-sm7b' => 'Shure SM7B',
    'focusrite-scarlett' => 'Focusrite Scarlett 2i2',
    
    // Drones
    'djI-mini-4-pro' => 'DJI Mini 4 Pro',
    'djI-air-3' => 'DJI Air 3',
    'djI-mavic-3' => 'DJI Mavic 3 Pro',
    
    // Monitors
    'lg-ultrafine-5k' => 'LG UltraFine 5K',
    'samsung-odyssey' => 'Samsung Odyssey G9',
    'dell-ultrasharp' => 'Dell UltraSharp U2723QE',
    
    // Gaming
    'ps5' => 'PlayStation 5',
    'xbox-series-x' => 'Xbox Series X',
    'nintendo-switch' => 'Nintendo Switch OLED',
    'steam-deck' => 'Steam Deck OLED',
    'gaming-chair' => 'Secretlab Titan Evo',
    'mechanical-keyboard' => 'Ducky One 3 Mini',
    'gaming-mouse' => 'Razer DeathAdder V3',
];

// ============================================
// DSA: LOAD ANALYTICS DATA
// ============================================
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

$pageViews = loadAnalyticsFiles($analyticsDir);

if (empty($pageViews)) {
    die('Error: No valid JSON files found in the directory.');
}

// ============================================
// DSA: HELPER FUNCTIES
// ============================================
function urlMatchesPattern($url, $patterns) {
    $urlLower = strtolower($url);
    foreach ($patterns as $pattern) {
        if (stripos($urlLower, strtolower($pattern)) !== false) {
            return true;
        }
    }
    return false;
}

function isCartPage($url) {
    global $cartPatterns;
    return urlMatchesPattern($url, $cartPatterns);
}

function isProductPage($url) {
    global $productPatterns;
    return urlMatchesPattern($url, $productPatterns);
}

function isCheckoutPage($url) {
    global $checkoutPatterns;
    return urlMatchesPattern($url, $checkoutPatterns);
}

function extractProductName($url) {
    global $productMapping;
    $urlLower = strtolower($url);
    
    // Check exact matches first
    foreach ($productMapping as $pattern => $product) {
        if (stripos($urlLower, strtolower($pattern)) !== false) {
            return $product;
        }
    }
    
    // Extract from URL if not in mapping
    $parts = explode('/', $url);
    foreach ($parts as $part) {
        $clean = preg_replace('/[^a-zA-Z0-9-]/', '', $part);
        if (!empty($clean) && strlen($clean) > 2) {
            $stopWords = ['product', 'p', 'item', 'shop', 'products', 'catalog', 'category', 'detail', 'details', 'page'];
            if (!in_array(strtolower($clean), $stopWords)) {
                return ucwords(str_replace('-', ' ', $clean));
            }
        }
    }
    
    return 'Unknown Product';
}

// ============================================
// DSA: CART & EXIT PAGE ANALYSIS
// ============================================

/**
 * Find all cart interactions with product attribution
 * Uses: Hash map O(n) + Session grouping
 */
function analyzeCartInteractions($views, $filterProduct = '') {
    // Group views by session using hash map
    $sessionViews = [];
    foreach ($views as $view) {
        $sessionId = $view['session_id'];
        if (!isset($sessionViews[$sessionId])) {
            $sessionViews[$sessionId] = [];
        }
        $sessionViews[$sessionId][] = $view;
    }
    
    // Process each session
    $cartData = [];
    $sessionCartData = [];
    
    foreach ($sessionViews as $sessionId => $views) {
        // Sort by timestamp
        usort($views, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        
        $lastProductPage = null;
        $lastProductIndex = -1;
        $cartFound = false;
        $cartEntries = [];
        $productsInSession = [];
        
        for ($i = 0; $i < count($views); $i++) {
            $view = $views[$i];
            $url = $view['url'];
            
            // Check product page
            if (isProductPage($url)) {
                $lastProductPage = $view;
                $lastProductIndex = $i;
                $productName = extractProductName($url);
                $productsInSession[$productName] = true;
            }
            
            // Check cart page
            if (isCartPage($url)) {
                $cartFound = true;
                $cartEntries[] = [
                    'cart_url' => $url,
                    'cart_timestamp' => $view['timestamp'],
                    'product_page_before' => $lastProductPage,
                    'product_index' => $lastProductIndex,
                    'is_checkout_after' => false
                ];
            }
            
            // Check if there's a checkout after cart
            if ($cartFound && isCheckoutPage($url)) {
                if (!empty($cartEntries)) {
                    $lastCart = array_key_last($cartEntries);
                    $cartEntries[$lastCart]['is_checkout_after'] = true;
                }
            }
        }
        
        // Process cart entries for this session
        $sessionProducts = array_keys($productsInSession);
        foreach ($cartEntries as $entry) {
            if ($entry['product_page_before'] !== null) {
                $product = extractProductName($entry['product_page_before']['url']);
                
                // Apply product filter
                if ($filterProduct && stripos($product, $filterProduct) === false) continue;
                
                $cartData[] = [
                    'session_id' => $sessionId,
                    'product' => $product,
                    'all_products_in_session' => $sessionProducts,
                    'exit_page_url' => $entry['product_page_before']['url'],
                    'exit_page_timestamp' => $entry['product_page_before']['timestamp'],
                    'cart_url' => $entry['cart_url'],
                    'cart_timestamp' => $entry['cart_timestamp'],
                    'mac_address' => $view['mac_address'],
                    'user_agent' => $view['user_agent'],
                    'time_to_cart' => $entry['cart_timestamp'] - $entry['product_page_before']['timestamp'],
                    'has_checkout' => $entry['is_checkout_after'],
                    'session_product_count' => count($sessionProducts)
                ];
            }
        }
    }
    
    return $cartData;
}

// ============================================
// DSA: STATISTICS CALCULATIONS
// ============================================

/**
 * Calculate product sales from cart data
 */
function calculateProductSales($cartData) {
    $productSales = [];
    $productSessions = [];
    
    foreach ($cartData as $data) {
        $product = $data['product'];
        $sessionId = $data['session_id'];
        
        if (!isset($productSales[$product])) {
            $productSales[$product] = 0;
            $productSessions[$product] = [];
        }
        $productSales[$product]++;
        
        if (!isset($productSessions[$product][$sessionId])) {
            $productSessions[$product][$sessionId] = true;
        }
    }
    
    arsort($productSales);
    
    $result = [];
    foreach ($productSales as $product => $count) {
        $result[] = [
            'product' => $product,
            'cart_adds' => $count,
            'unique_sessions' => count($productSessions[$product])
        ];
    }
    
    return $result;
}

/**
 * Calculate hourly cart activity
 */
function calculateHourlyCartActivity($cartData) {
    $hourlyData = [];
    
    foreach ($cartData as $data) {
        $hour = date('Y-m-d H:00', $data['cart_timestamp']);
        $product = $data['product'];
        
        if (!isset($hourlyData[$hour])) {
            $hourlyData[$hour] = [
                'hour' => $hour,
                'total_cart_adds' => 0,
                'total_checkouts' => 0,
                'products' => []
            ];
        }
        
        $hourlyData[$hour]['total_cart_adds']++;
        
        if ($data['has_checkout']) {
            $hourlyData[$hour]['total_checkouts']++;
        }
        
        if (!isset($hourlyData[$hour]['products'][$product])) {
            $hourlyData[$hour]['products'][$product] = 0;
        }
        $hourlyData[$hour]['products'][$product]++;
    }
    
    ksort($hourlyData);
    return $hourlyData;
}

/**
 * Calculate cart abandonment rate per product
 */
function calculateCartAbandonment($cartData) {
    $productStats = [];
    
    foreach ($cartData as $data) {
        $product = $data['product'];
        
        if (!isset($productStats[$product])) {
            $productStats[$product] = [
                'total_carts' => 0,
                'checkouts' => 0
            ];
        }
        
        $productStats[$product]['total_carts']++;
        if ($data['has_checkout']) {
            $productStats[$product]['checkouts']++;
        }
    }
    
    $result = [];
    foreach ($productStats as $product => $stats) {
        $abandonmentRate = $stats['total_carts'] > 0 
            ? round((($stats['total_carts'] - $stats['checkouts']) / $stats['total_carts']) * 100, 1)
            : 0;
        
        $result[] = [
            'product' => $product,
            'total_carts' => $stats['total_carts'],
            'checkouts' => $stats['checkouts'],
            'abandonment_rate' => $abandonmentRate,
            'conversion_rate' => $stats['total_carts'] > 0 
                ? round(($stats['checkouts'] / $stats['total_carts']) * 100, 1)
                : 0
        ];
    }
    
    // Sort by abandonment rate (highest first)
    usort($result, function($a, $b) {
        return $b['abandonment_rate'] - $a['abandonment_rate'];
    });
    
    return $result;
}

// ============================================
// DSA: GET FILTER PARAMETERS
// ============================================
$filterDate = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filterProduct = isset($_GET['filter_product']) ? trim($_GET['filter_product']) : '';

// HARDCODED LIMIT - 15000
$limit = MAX_DISPLAY_LIMIT;

// Apply date filter
$filteredViews = $pageViews;
if ($filterDate) {
    $filteredViews = array_filter($filteredViews, function($view) use ($filterDate) {
        return date('Y-m-d', $view['timestamp']) === $filterDate;
    });
}

// ============================================
// DSA: EXECUTE ANALYSIS
// ============================================

// Analyze cart interactions - max 15000 records
$cartData = analyzeCartInteractions($filteredViews, $filterProduct);

// Limit data to 15000 hardcoded
if (count($cartData) > MAX_DISPLAY_LIMIT) {
    $cartData = array_slice($cartData, 0, MAX_DISPLAY_LIMIT);
}

// Calculate statistics
$productSales = calculateProductSales($cartData);
$hourlyActivity = calculateHourlyCartActivity($cartData);
$abandonmentStats = calculateCartAbandonment($cartData);

// Get top products - max 15000
$topProducts = array_slice($productSales, 0, MAX_DISPLAY_LIMIT);

// Get unique dates for filter
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

// Get unique products for filter - max 15000
$uniqueProductsList = array_column($productSales, 'product');
sort($uniqueProductsList);
if (count($uniqueProductsList) > MAX_DISPLAY_LIMIT) {
    $uniqueProductsList = array_slice($uniqueProductsList, 0, MAX_DISPLAY_LIMIT);
}

// Stats
$totalCartAdds = count($cartData);
$uniqueSessions = count(array_unique(array_column($cartData, 'session_id')));
$totalCheckouts = 0;
foreach ($cartData as $data) {
    if ($data['has_checkout']) $totalCheckouts++;
}
$abandonmentRate = $totalCartAdds > 0 
    ? round((($totalCartAdds - $totalCheckouts) / $totalCartAdds) * 100, 1)
    : 0;

// ============================================
// DSA: DISPLAY FUNCTIONS
// ============================================

// Function to truncate data for display
function truncateForDisplay($data, $maxLength = 80) {
    if (strlen($data) <= $maxLength) return $data;
    return substr($data, 0, $maxLength) . '...';
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winkelmand Analyse - Product Sales</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', Courier, monospace;
            background: #ffffff;
            color: #000000;
            padding: 20px;
            line-height: 1.5;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { font-size: 26px; margin-bottom: 2px; font-weight: 700; }
        .subtitle { 
            color: #555555; 
            margin-bottom: 25px; 
            font-size: 13px;
        }
        .limit-info {
            display: inline-block;
            background: #f0f0f0;
            padding: 2px 12px;
            border: 1px solid #999;
            font-size: 11px;
            margin-left: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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
        .stat-card .sub-value {
            font-size: 14px;
            color: #555555;
            margin-top: 4px;
        }
        .stat-card.warning { border-color: #cc6600; }
        .stat-card.warning .value { color: #cc6600; }
        .stat-card.success { border-color: #006600; }
        .stat-card.success .value { color: #006600; }
        .stat-card.danger { border-color: #cc0000; }
        .stat-card.danger .value { color: #cc0000; }
        
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
        }
        .btn:hover { background: #333333; }
        .btn-secondary {
            background: #ffffff;
            color: #000000;
            border: 1px solid #999999;
        }
        .btn-secondary:hover { background: #eeeeee; }
        
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
        .limit-badge {
            font-size: 10px;
            color: #666;
            background: #f0f0f0;
            padding: 2px 10px;
            border: 1px solid #ccc;
        }
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 8px 10px;
            background: #f0f0f0;
            font-weight: 700;
            border-bottom: 2px solid #000000;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        td {
            padding: 6px 10px;
            border-bottom: 1px solid #e0e0e0;
            word-break: break-all;
            font-size: 12px;
        }
        tr:hover td { background: #f5f5f5; }
        .rank {
            display: inline-block;
            min-width: 26px;
            text-align: center;
            font-weight: 700;
            color: #000000;
        }
        .rank-1 { font-size: 15px; }
        .rank-2 { font-size: 14px; }
        .rank-3 { font-size: 13px; }
        
        .badge {
            display: inline-block;
            padding: 2px 10px;
            border: 1px solid #000000;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-cart { border-color: #006600; color: #006600; }
        .badge-checkout { border-color: #cc6600; color: #cc6600; }
        .badge-abandon { border-color: #cc0000; color: #cc0000; }
        .badge-product { border-color: #0000cc; color: #0000cc; }
        
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .progress-bar {
            background: #e0e0e0;
            height: 4px;
            margin-top: 4px;
            overflow: hidden;
        }
        .progress-bar .fill {
            height: 100%;
            background: #000000;
        }
        .progress-bar .fill.success { background: #006600; }
        .progress-bar .fill.warning { background: #cc6600; }
        .progress-bar .fill.danger { background: #cc0000; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-success { color: #006600; font-weight: 700; }
        .text-warning { color: #cc6600; font-weight: 700; }
        .text-danger { color: #cc0000; font-weight: 700; }
        code {
            font-size: 12px;
            background: #f0f0f0;
            padding: 1px 4px;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #555555;
            font-size: 12px;
            border-top: 1px solid #cccccc;
            margin-top: 10px;
        }
        
        .truncated-note {
            color: #cc6600;
            font-size: 11px;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .two-col { grid-template-columns: 1fr; }
            .filters { flex-direction: column; align-items: stretch; }
            .filter-group { width: 100%; }
            .filter-group input, .filter-group select { flex: 1; min-width: 80px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>WINKELMAND ANALYSE</h1>
    <p class="subtitle">
        Product attributie via winkelmand & exit pages
        | <?php echo number_format($totalCartAdds); ?> winkelmand toevoegingen
        <span class="limit-info">MAX: <?php echo number_format(MAX_DISPLAY_LIMIT); ?> records</span>
    </p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Winkelmand Toevoegingen</div>
            <div class="value"><?php echo number_format($totalCartAdds); ?></div>
            <?php if (count($cartData) >= MAX_DISPLAY_LIMIT): ?>
            <div class="sub-value truncated-note">Gelimiteerd tot <?php echo number_format(MAX_DISPLAY_LIMIT); ?></div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="label">Unieke Sessies</div>
            <div class="value"><?php echo number_format($uniqueSessions); ?></div>
        </div>
        <div class="stat-card success">
            <div class="label">Afgeronde Checkouts</div>
            <div class="value" style="color: #006600;"><?php echo number_format($totalCheckouts); ?></div>
        </div>
        <div class="stat-card danger">
            <div class="label">Winkelmand Verlaten</div>
            <div class="value" style="color: #cc0000;"><?php echo $abandonmentRate; ?>%</div>
            <div class="sub-value"><?php echo number_format($totalCartAdds - $totalCheckouts); ?> verlaten manden</div>
        </div>
    </div>
    
    <!-- Filters -->
    <form method="GET" class="filters">
        <div class="filter-group">
            <label>Datum:</label>
            <select name="filter_date">
                <option value="">Alle Datums</option>
                <?php foreach ($uniqueDates as $date): ?>
                <option value="<?php echo $date; ?>" <?php echo $filterDate === $date ? 'selected' : ''; ?>><?php echo $date; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Product:</label>
            <select name="filter_product">
                <option value="">Alle Producten</option>
                <?php foreach ($uniqueProductsList as $product): ?>
                <option value="<?php echo htmlspecialchars($product); ?>" <?php echo $filterProduct === $product ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(truncateForDisplay($product, 30)); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Toepassen</button>
        <a href="?" class="btn btn-secondary">Wissen</a>
        <span style="font-size:11px; color:#666; margin-left:auto;">
            Limit: <?php echo number_format(MAX_DISPLAY_LIMIT); ?> records max
        </span>
    </form>
    
    <!-- Hourly Cart Activity -->
    <div class="section" style="border-color: #006600;">
        <div class="section-header">
            <h2>UURLIJKSE WINKELMAND ACTIVITEIT</h2>
            <span class="count"><?php echo count($hourlyActivity); ?> uren</span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Uur</th>
                        <th class="text-right">Winkelmand Toevoegingen</th>
                        <th class="text-right">Checkouts</th>
                        <th class="text-right">Conversie %</th>
                        <th>Top Producten</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hourlyActivity)): ?>
                    <tr><td colspan="5" class="text-center" style="padding:30px; color:#555555;">Geen data gevonden</td></tr>
                    <?php else: ?>
                    <?php 
                    $maxHourly = 0;
                    foreach ($hourlyActivity as $data) {
                        if ($data['total_cart_adds'] > $maxHourly) $maxHourly = $data['total_cart_adds'];
                    }
                    $maxHourly = $maxHourly > 0 ? $maxHourly : 1;
                    
                    // Limit hourly display
                    $hourlyDisplay = array_slice($hourlyActivity, 0, MAX_DISPLAY_LIMIT);
                    ?>
                    <?php foreach ($hourlyDisplay as $hour => $data): ?>
                    <?php 
                    $convRate = $data['total_cart_adds'] > 0 
                        ? round(($data['total_checkouts'] / $data['total_cart_adds']) * 100, 1)
                        : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo date('H:00', strtotime($hour)); ?></strong></td>
                        <td class="text-right">
                            <span class="text-success"><?php echo number_format($data['total_cart_adds']); ?></span>
                            <div class="progress-bar">
                                <div class="fill success" style="width: <?php echo ($data['total_cart_adds'] / $maxHourly * 100); ?>%;"></div>
                            </div>
                        </td>
                        <td class="text-right">
                            <span class="text-warning"><?php echo number_format($data['total_checkouts']); ?></span>
                        </td>
                        <td class="text-right">
                            <?php if ($convRate > 0): ?>
                            <span class="text-success"><?php echo $convRate; ?>%</span>
                            <?php else: ?>
                            <span style="color:#999;">0%</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            arsort($data['products']);
                            $top3 = array_slice($data['products'], 0, 3);
                            $display = [];
                            foreach ($top3 as $product => $count) {
                                $display[] = '<span class="badge badge-product">' . htmlspecialchars(truncateForDisplay($product, 20)) . '(' . $count . ')</span>';
                            }
                            echo implode(' ', $display);
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($hourlyActivity) > MAX_DISPLAY_LIMIT): ?>
                    <tr>
                        <td colspan="5" class="text-center truncated-note">
                            ... en nog <?php echo number_format(count($hourlyActivity) - MAX_DISPLAY_LIMIT); ?> uren (gelimiteerd)
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Top Products -->
    <div class="two-col">
        <div class="section" style="border-color: #006600;">
            <div class="section-header">
                <h2>TOP PRODUCTEN IN WINKELMAND</h2>
                <span class="count"><?php echo number_format(count($productSales)); ?> producten</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Product</th>
                            <th class="text-right">Toevoegingen</th>
                            <th class="text-right">Sessies</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topProducts)): ?>
                        <tr><td colspan="4" class="text-center" style="padding:30px; color:#555555;">Geen producten gevonden</td></tr>
                        <?php else: ?>
                        <?php $maxSales = $topProducts[0]['cart_adds']; ?>
                        <?php foreach ($topProducts as $index => $item): ?>
                        <tr>
                            <td><span class="rank <?php echo $index < 3 ? 'rank-' . ($index + 1) : ''; ?>"><?php echo $index + 1; ?></span></td>
                            <td><strong><?php echo htmlspecialchars(truncateForDisplay($item['product'], 40)); ?></strong></td>
                            <td class="text-right">
                                <span class="text-success"><?php echo number_format($item['cart_adds']); ?></span>
                                <div class="progress-bar">
                                    <div class="fill success" style="width: <?php echo ($item['cart_adds'] / $maxSales * 100); ?>%;"></div>
                                </div>
                            </td>
                            <td class="text-right"><?php echo number_format($item['unique_sessions']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($productSales) > MAX_DISPLAY_LIMIT): ?>
                        <tr>
                            <td colspan="4" class="text-center truncated-note">
                                ... en nog <?php echo number_format(count($productSales) - MAX_DISPLAY_LIMIT); ?> producten (gelimiteerd)
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Abandonment Analysis -->
        <div class="section" style="border-color: #cc0000;">
            <div class="section-header">
                <h2>WINKELMAND VERLATEN PER PRODUCT</h2>
                <span class="count"><?php echo number_format(count($abandonmentStats)); ?> producten</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Product</th>
                            <th class="text-right">Totaal</th>
                            <th class="text-right">Checkouts</th>
                            <th class="text-right">Verlaten %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $abandonDisplay = array_slice($abandonmentStats, 0, min(MAX_DISPLAY_LIMIT, 100));
                        if (empty($abandonDisplay)): 
                        ?>
                        <tr><td colspan="5" class="text-center" style="padding:30px; color:#555555;">Geen data gevonden</td></tr>
                        <?php else: ?>
                        <?php foreach ($abandonDisplay as $index => $item): ?>
                        <tr>
                            <td><span class="rank"><?php echo $index + 1; ?></span></td>
                            <td><strong><?php echo htmlspecialchars(truncateForDisplay($item['product'], 30)); ?></strong></td>
                            <td class="text-right"><?php echo number_format($item['total_carts']); ?></td>
                            <td class="text-right text-success"><?php echo number_format($item['checkouts']); ?></td>
                            <td class="text-right">
                                <?php if ($item['abandonment_rate'] > 70): ?>
                                <span class="text-danger"><?php echo $item['abandonment_rate']; ?>%</span>
                                <?php elseif ($item['abandonment_rate'] > 40): ?>
                                <span class="text-warning"><?php echo $item['abandonment_rate']; ?>%</span>
                                <?php else: ?>
                                <span class="text-success"><?php echo $item['abandonment_rate']; ?>%</span>
                                <?php endif; ?>
                                <div class="progress-bar">
                                    <div class="fill <?php echo $item['abandonment_rate'] > 70 ? 'danger' : ($item['abandonment_rate'] > 40 ? 'warning' : 'success'); ?>" 
                                         style="width: <?php echo $item['abandonment_rate']; ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Detailed Cart Entries -->
    <div class="section">
        <div class="section-header">
            <h2>WINKELMAND DETAILS</h2>
            <span class="count">
                <?php echo number_format(min(count($cartData), MAX_DISPLAY_LIMIT)); ?> entries
                <?php if (count($cartData) > MAX_DISPLAY_LIMIT): ?>
                <span class="limit-badge">+<?php echo number_format(count($cartData) - MAX_DISPLAY_LIMIT); ?> meer</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Exit Page</th>
                        <th>Cart Page</th>
                        <th class="text-right">Tijd naar Cart</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $displayData = array_slice($cartData, 0, min(MAX_DISPLAY_LIMIT, 500));
                    if (empty($displayData)): 
                    ?>
                    <tr><td colspan="6" class="text-center" style="padding:30px; color:#555555;">Geen winkelmand data gevonden</td></tr>
                    <?php else: ?>
                    <?php foreach ($displayData as $index => $data): ?>
                    <tr>
                        <td><span class="rank"><?php echo $index + 1; ?></span></td>
                        <td>
                            <span class="badge badge-product"><?php echo htmlspecialchars(truncateForDisplay($data['product'], 25)); ?></span>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars(truncateForDisplay($data['exit_page_url'], 40)); ?></code>
                        </td>
                        <td>
                            <span class="badge badge-cart">CART</span>
                            <code><?php echo htmlspecialchars(truncateForDisplay($data['cart_url'], 30)); ?></code>
                        </td>
                        <td class="text-right">
                            <?php 
                            $time = $data['time_to_cart'];
                            if ($time < 60) {
                                echo $time . 's';
                            } elseif ($time < 3600) {
                                echo floor($time / 60) . 'm ' . ($time % 60) . 's';
                            } else {
                                echo floor($time / 3600) . 'h ' . floor(($time % 3600) / 60) . 'm';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($data['has_checkout']): ?>
                            <span class="badge badge-checkout">CHECKOUT</span>
                            <?php else: ?>
                            <span class="badge badge-abandon">VERLATEN</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($cartData) > 500): ?>
                    <tr>
                        <td colspan="6" class="text-center truncated-note">
                            ... en nog <?php echo number_format(count($cartData) - 500); ?> winkelmand toevoegingen (gelimiteerd tot 500 weergegeven)
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="footer">
        <?php echo number_format(count($pageViews)); ?> total page views | 
        <?php echo number_format($totalCartAdds); ?> winkelmand toevoegingen |
        <?php echo number_format($totalCheckouts); ?> afgeronde checkouts |
        <?php echo $abandonmentRate; ?>% verlaten rate |
        Max weergave: <?php echo number_format(MAX_DISPLAY_LIMIT); ?> records
    </div>
</div>
</body>
</html>