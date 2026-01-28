<?php
require_once 'config.php';
require_once 'functions.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$msg = '';

// --- ENSURE TABLES AND SETTINGS EXIST ---
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS city_coords (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            city TEXT NOT NULL,
            state TEXT NOT NULL,
            lat REAL,
            lng REAL,
            UNIQUE(city, state)
        )
    ");

    // Ensure HOME_LAT/HOME_LNG settings exist
    $check = $db->query("SELECT COUNT(*) FROM rate_card WHERE rate_key = 'HOME_LAT'")->fetchColumn();
    if ($check == 0) {
        $db->exec("INSERT INTO rate_card (rate_key, amount, description) VALUES ('HOME_LAT', 0, 'Home Base Latitude')");
        $db->exec("INSERT INTO rate_card (rate_key, amount, description) VALUES ('HOME_LNG', 0, 'Home Base Longitude')");
    }
} catch (Exception $e) {
}

// --- HANDLE HOME BASE FORM SUBMISSION ---
if (isset($_POST['save_home_base'])) {
    try {
        $lat = (float) $_POST['home_lat'];
        $lng = (float) $_POST['home_lng'];
        $db->prepare("UPDATE rate_card SET amount = ? WHERE rate_key = 'HOME_LAT'")->execute([$lat]);
        $db->prepare("UPDATE rate_card SET amount = ? WHERE rate_key = 'HOME_LNG'")->execute([$lng]);
        $msg = '✅ Home base updated!';
    } catch (Exception $e) {
        $msg = '❌ Error saving home base';
    }
}

// --- AUTO-GEOCODE MISSING CITIES (limit 3 per page load to respect rate limits) ---
function geocodeCity($city, $state)
{
    $query = urlencode("$city, $state, USA");
    $url = "https://nominatim.openstreetmap.org/search?q=$query&format=json&limit=1";

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: TechPortal/1.0\r\n",
            'timeout' => 5
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
            return [(float) $data[0]['lat'], (float) $data[0]['lon']];
        }
    }
    return [null, null];
}

// --- FETCH DATA ---

// 1. Top cities by job count and revenue (case-insensitive grouping)
// Clean city names: strip trailing ", XX" state codes if already in city name
$stmt = $db->prepare("
    SELECT 
        CASE 
            WHEN TRIM(cust_city) LIKE '%, ' || UPPER(TRIM(cust_state))
            THEN TRIM(SUBSTR(TRIM(cust_city), 1, LENGTH(TRIM(cust_city)) - LENGTH(TRIM(cust_state)) - 2))
            WHEN TRIM(cust_city) LIKE '%, __'
            THEN TRIM(SUBSTR(TRIM(cust_city), 1, LENGTH(TRIM(cust_city)) - 4))
            ELSE TRIM(cust_city)
        END as city,
        UPPER(TRIM(cust_state)) as state, 
        COUNT(*) as jobs, 
        SUM(pay_amount) as revenue,
        COUNT(DISTINCT install_type) as diversity
    FROM jobs 
    WHERE user_id = ? AND install_type NOT IN ('DO', 'ND') 
          AND cust_city IS NOT NULL AND cust_city != ''
    GROUP BY LOWER(
        CASE 
            WHEN TRIM(cust_city) LIKE '%, ' || UPPER(TRIM(cust_state))
            THEN TRIM(SUBSTR(TRIM(cust_city), 1, LENGTH(TRIM(cust_city)) - LENGTH(TRIM(cust_state)) - 2))
            WHEN TRIM(cust_city) LIKE '%, __'
            THEN TRIM(SUBSTR(TRIM(cust_city), 1, LENGTH(TRIM(cust_city)) - 4))
            ELSE TRIM(cust_city)
        END
    ), LOWER(TRIM(cust_state))
    ORDER BY jobs DESC
");
$stmt->execute([$user_id]);
$city_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Job code distribution per city (top 10 cities)
$top_cities = array_slice(array_column($city_data, 'city'), 0, 10);
$job_code_dist = [];
if (!empty($top_cities)) {
    $placeholders = implode(',', array_fill(0, count($top_cities), 'LOWER(TRIM(?))'));
    $stmt = $db->prepare("
        SELECT TRIM(cust_city) as city, install_type, COUNT(*) as count
        FROM jobs 
        WHERE user_id = ? AND LOWER(TRIM(cust_city)) IN ($placeholders) AND install_type NOT IN ('DO', 'ND')
        GROUP BY LOWER(TRIM(cust_city)), install_type
        ORDER BY city, count DESC
    ");
    $stmt->execute(array_merge([$user_id], $top_cities));
    $raw_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_dist as $row) {
        $job_code_dist[$row['city']][$row['install_type']] = (int) $row['count'];
    }
}

// 3. 6-month trend per city (top 10)
$city_trends = [];
if (!empty($top_cities)) {
    $placeholders = implode(',', array_fill(0, count($top_cities), 'LOWER(TRIM(?))'));
    $stmt = $db->prepare("
        SELECT TRIM(cust_city) as city, strftime('%Y-%m', install_date) as month, COUNT(*) as jobs
        FROM jobs 
        WHERE user_id = ? AND LOWER(TRIM(cust_city)) IN ($placeholders) 
              AND install_date >= date('now', '-6 months')
        GROUP BY LOWER(TRIM(cust_city)), month
        ORDER BY city, month
    ");
    $stmt->execute(array_merge([$user_id], $top_cities));
    $raw_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_trends as $row) {
        $city_trends[$row['city']][$row['month']] = (int) $row['jobs'];
    }
}

// Generate last 6 months labels
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("-$i months"));
}

// 4. Get cached coordinates
$coords_map = [];
try {
    $stmt = $db->query("SELECT city, state, lat, lng FROM city_coords WHERE lat IS NOT NULL");
    while ($row = $stmt->fetch()) {
        $key = strtolower(trim($row['city']) . ',' . trim($row['state']));
        $coords_map[$key] = ['lat' => $row['lat'], 'lng' => $row['lng']];
    }
} catch (Exception $e) {
}

// 4.5 Auto-geocode missing cities (limit 3 per load to respect rate limits)
$geocoded_count = 0;
foreach ($city_data as $c) {
    if ($geocoded_count >= 3)
        break;

    $key = strtolower(trim($c['city']) . ',' . trim($c['state']));
    if (!isset($coords_map[$key]) && !empty($c['city']) && !empty($c['state'])) {
        // Check if already in DB (with null coords = failed before) - case insensitive
        $stmt = $db->prepare("SELECT id FROM city_coords WHERE LOWER(TRIM(city)) = LOWER(TRIM(?)) AND LOWER(TRIM(state)) = LOWER(TRIM(?))");
        $stmt->execute([trim($c['city']), trim($c['state'])]);
        $exists = $stmt->fetch();

        if (!$exists) {
            // Try to geocode
            list($lat, $lng) = geocodeCity($c['city'], $c['state']);

            // Insert into cache with normalized names (even if null coords, to avoid re-trying)
            try {
                $normalized_city = trim($c['city']);
                $normalized_state = strtoupper(trim($c['state']));
                $stmt = $db->prepare("INSERT OR REPLACE INTO city_coords (city, state, lat, lng) VALUES (?, ?, ?, ?)");
                $stmt->execute([$normalized_city, $normalized_state, $lat, $lng]);

                if ($lat && $lng) {
                    $coords_map[$key] = ['lat' => $lat, 'lng' => $lng];
                    $geocoded_count++;
                }
            } catch (Exception $e) {
            }

            // Small delay to respect rate limits
            usleep(200000); // 200ms
        }
    }
}

// 5. Get home base
$home_lat = 0;
$home_lng = 0;
try {
    $stmt = $db->query("SELECT rate_key, amount FROM rate_card WHERE rate_key IN ('HOME_LAT', 'HOME_LNG')");
    while ($row = $stmt->fetch()) {
        if ($row['rate_key'] === 'HOME_LAT')
            $home_lat = (float) $row['amount'];
        if ($row['rate_key'] === 'HOME_LNG')
            $home_lng = (float) $row['amount'];
    }
} catch (Exception $e) {
}
$has_home_base = ($home_lat != 0 && $home_lng != 0);

// Build map data with coordinates
$map_data = [];
foreach ($city_data as $c) {
    $key = strtolower(trim($c['city']) . ',' . trim($c['state']));
    $lat = $coords_map[$key]['lat'] ?? null;
    $lng = $coords_map[$key]['lng'] ?? null;

    $map_data[] = [
        'city' => $c['city'],
        'state' => $c['state'],
        'jobs' => (int) $c['jobs'],
        'revenue' => (float) $c['revenue'],
        'diversity' => (int) $c['diversity'],
        'lat' => $lat,
        'lng' => $lng
    ];
}

// Get all unique job types for the stacked chart
$all_job_types = [];
$stmt = $db->prepare("SELECT DISTINCT install_type FROM jobs WHERE user_id = ? AND install_type NOT IN ('DO', 'ND')");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch()) {
    $all_job_types[] = $row['install_type'];
}
sort($all_job_types);

// Chart colors
$chart_colors = ['#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#f43f5e', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Geo Analytics</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <link rel="shortcut icon" href="favicon.ico?v=2">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .geo-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .geo-header h2 {
            margin: 0;
        }

        .geo-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        @media (max-width: 900px) {
            .geo-grid {
                grid-template-columns: 1fr;
            }
        }

        .map-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .map-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .map-title {
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .toggle-btns {
            display: flex;
            gap: 4px;
        }

        .toggle-btn {
            padding: 4px 10px;
            font-size: 0.75rem;
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text-muted);
            border-radius: 4px;
            cursor: pointer;
        }

        .toggle-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        #map {
            height: 400px;
            background: #1a1a2e;
        }

        .leaderboard {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
        }

        .leaderboard-title {
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .city-row {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            gap: 10px;
        }

        .city-row:last-child {
            border-bottom: none;
        }

        .city-rank {
            width: 24px;
            height: 24px;
            background: var(--gradient-primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            flex-shrink: 0;
        }

        .city-info {
            flex: 1;
            min-width: 0;
        }

        .city-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .city-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .city-stats {
            text-align: right;
            flex-shrink: 0;
        }

        .city-jobs {
            font-weight: 700;
            color: var(--primary);
        }

        .city-revenue {
            font-size: 0.75rem;
            color: var(--success-text);
        }

        .sparkline-container {
            width: 60px;
            height: 20px;
            flex-shrink: 0;
        }

        .chart-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
        }

        .chart-title {
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }

        .diversity-badge {
            display: inline-block;
            padding: 2px 6px;
            background: rgba(99, 102, 241, 0.2);
            color: var(--primary);
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <?php include 'nav.php'; ?>

    <div class="container">
        <div class="geo-header">
            <h2>📍 Geo Analytics</h2>
        </div>

        <?php if (empty($city_data)): ?>
            <div class="no-data">
                <h3>No location data yet</h3>
                <p>Start logging jobs with city information to see geographic insights.</p>
            </div>
        <?php else: ?>

            <!-- Map + Leaderboard -->
            <div class="geo-grid">
                <div class="map-card">
                    <div class="map-header">
                        <span class="map-title">🗺️ Job Heatmap</span>
                        <div class="toggle-btns">
                            <button class="toggle-btn active" onclick="setMapMode('jobs')">Jobs</button>
                            <button class="toggle-btn" onclick="setMapMode('revenue')">Revenue</button>
                        </div>
                    </div>
                    <div id="map"></div>
                </div>

                <div class="leaderboard">
                    <div class="leaderboard-title">🏆 Top Cities</div>
                    <?php foreach (array_slice($city_data, 0, 10) as $idx => $city): ?>
                        <div class="city-row">
                            <div class="city-rank">
                                <?= $idx + 1 ?>
                            </div>
                            <div class="city-info">
                                <div class="city-name">
                                    <?= htmlspecialchars($city['city']) ?>
                                </div>
                                <div class="city-meta">
                                    <?= htmlspecialchars($city['state']) ?>
                                    <span class="diversity-badge">
                                        <?= $city['diversity'] ?> types
                                    </span>
                                </div>
                            </div>
                            <div class="sparkline-container">
                                <canvas id="spark-<?= $idx ?>"></canvas>
                            </div>
                            <div class="city-stats">
                                <div class="city-jobs">
                                    <?= $city['jobs'] ?> jobs
                                </div>
                                <div class="city-revenue">$
                                    <?= number_format($city['revenue'], 0) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Job Code Distribution Chart -->
            <div class="chart-section">
                <div class="chart-title">📊 Job Code Distribution by City</div>
                <div class="chart-container">
                    <canvas id="jobCodeChart"></canvas>
                </div>
            </div>

            <!-- Efficiency Scatter (if home base configured) -->
            <?php if ($has_home_base): ?>
                <div class="chart-section">
                    <div class="chart-title">🎯 Efficiency: Distance vs Revenue</div>
                    <div class="chart-container">
                        <canvas id="efficiencyChart"></canvas>
                    </div>
                </div>
            <?php else: ?>
                <div class="chart-section">
                    <div class="chart-title">🏠 Set Home Base Location</div>
                    <?php if ($msg): ?>
                        <div
                            style="padding: 10px; margin-bottom: 15px; background: rgba(34, 197, 94, 0.1); border-radius: 6px; color: var(--success-text);">
                            <?= $msg ?>
                        </div>
                    <?php endif; ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
                        Enter your home coordinates to see the Efficiency chart (distance from home vs revenue).
                        <br><small>Tip: Search your address on <a href="https://www.google.com/maps" target="_blank"
                                style="color: var(--primary);">Google Maps</a>, right-click → "What's here?" to get
                            coordinates.</small>
                    </p>
                    <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <input type="text" name="home_lat" placeholder="Latitude (e.g. 40.7128)"
                            style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-input); color: var(--text-main); width: 160px;"
                            value="<?= $home_lat != 0 ? $home_lat : '' ?>">
                        <input type="text" name="home_lng" placeholder="Longitude (e.g. -74.0060)"
                            style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-input); color: var(--text-main); width: 160px;"
                            value="<?= $home_lng != 0 ? $home_lng : '' ?>">
                        <button type="submit" name="save_home_base" class="btn btn-small">Save Home Base</button>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
        // Data from PHP
        const mapData = <?= json_encode($map_data) ?>;
        const cityTrends = <?= json_encode($city_trends) ?>;
        const months = <?= json_encode($months) ?>;
        const jobCodeDist = <?= json_encode($job_code_dist) ?>;
        const allJobTypes = <?= json_encode($all_job_types) ?>;
        const chartColors = <?= json_encode($chart_colors) ?>;
        const topCities = <?= json_encode(array_slice(array_column($city_data, 'city'), 0, 10)) ?>;
        const hasHomeBase = <?= $has_home_base ? 'true' : 'false' ?>;
        const homeLat = <?= $home_lat ?>;
        const homeLng = <?= $home_lng ?>;

        let map, heatLayer;
        let currentMode = 'jobs';

        // Initialize Leaflet Map
        function initMap() {
            // Find center from data or default to US
            let centerLat = 39.8283, centerLng = -98.5795;
            const validPoints = mapData.filter(d => d.lat && d.lng);

            if (validPoints.length > 0) {
                centerLat = validPoints.reduce((sum, d) => sum + d.lat, 0) / validPoints.length;
                centerLng = validPoints.reduce((sum, d) => sum + d.lng, 0) / validPoints.length;
            }

            map = L.map('map').setView([centerLat, centerLng], 7);

            L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; OpenStreetMap, &copy; CARTO'
            }).addTo(map);

            updateHeatmap();

            // Add markers for cities with coords
            validPoints.forEach(d => {
                L.circleMarker([d.lat, d.lng], {
                    radius: Math.min(4 + d.jobs, 15),
                    fillColor: '#6366f1',
                    color: '#fff',
                    weight: 1,
                    opacity: 1,
                    fillOpacity: 0.7
                }).addTo(map).bindPopup(`<b>${d.city}, ${d.state}</b><br>${d.jobs} jobs<br>$${d.revenue.toLocaleString()}`);
            });
        }

        function updateHeatmap() {
            if (heatLayer) map.removeLayer(heatLayer);

            const points = mapData
                .filter(d => d.lat && d.lng)
                .map(d => {
                    const intensity = currentMode === 'jobs' ? d.jobs : d.revenue / 100;
                    return [d.lat, d.lng, intensity];
                });

            if (points.length > 0) {
                heatLayer = L.heatLayer(points, {
                    radius: 35,
                    blur: 20,
                    maxZoom: 10,
                    gradient: { 0.2: '#3b82f6', 0.4: '#8b5cf6', 0.6: '#d946ef', 0.8: '#f43f5e', 1: '#f97316' }
                }).addTo(map);
            }
        }

        function setMapMode(mode) {
            currentMode = mode;
            document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            event.target.classList.add('active');
            updateHeatmap();
        }

        // Sparklines
        function initSparklines() {
            topCities.forEach((city, idx) => {
                const canvas = document.getElementById(`spark-${idx}`);
                if (!canvas) return;

                const trend = cityTrends[city] || {};
                const data = months.map(m => trend[m] || 0);

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [{
                            data: data,
                            borderColor: '#6366f1',
                            borderWidth: 1.5,
                            fill: false,
                            tension: 0.4,
                            pointRadius: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                        scales: {
                            x: { display: false },
                            y: { display: false, beginAtZero: true }
                        }
                    }
                });
            });
        }

        // Job Code Distribution Chart
        function initJobCodeChart() {
            const ctx = document.getElementById('jobCodeChart');
            if (!ctx) return;

            const datasets = allJobTypes.map((type, i) => ({
                label: type,
                data: topCities.map(city => jobCodeDist[city]?.[type] || 0),
                backgroundColor: chartColors[i % chartColors.length]
            }));

            new Chart(ctx, {
                type: 'bar',
                data: { labels: topCities, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } }
                    },
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true }
                    }
                }
            });
        }

        // Efficiency Scatter
        function initEfficiencyChart() {
            if (!hasHomeBase) return;
            const ctx = document.getElementById('efficiencyChart');
            if (!ctx) return;

            // Calculate distance for each city (simple Haversine)
            function haversine(lat1, lon1, lat2, lon2) {
                const R = 3959; // miles
                const dLat = (lat2 - lat1) * Math.PI / 180;
                const dLon = (lon2 - lon1) * Math.PI / 180;
                const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                    Math.sin(dLon / 2) * Math.sin(dLon / 2);
                return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            }

            const points = mapData
                .filter(d => d.lat && d.lng)
                .map(d => ({
                    x: haversine(homeLat, homeLng, d.lat, d.lng),
                    y: d.revenue,
                    r: Math.min(5 + d.jobs * 2, 25),
                    label: d.city
                }));

            new Chart(ctx, {
                type: 'bubble',
                data: {
                    datasets: [{
                        label: 'Cities',
                        data: points,
                        backgroundColor: 'rgba(99, 102, 241, 0.6)',
                        borderColor: '#6366f1',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => `${ctx.raw.label}: ${ctx.raw.x.toFixed(1)}mi, $${ctx.raw.y.toLocaleString()}`
                            }
                        }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Distance from Home (miles)' }, beginAtZero: true },
                        y: { title: { display: true, text: 'Revenue ($)' }, beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } }
                    }
                }
            });
        }

        // Init
        document.addEventListener('DOMContentLoaded', () => {
            if (mapData.length > 0) {
                initMap();
                initSparklines();
                initJobCodeChart();
                initEfficiencyChart();
            }
        });
    </script>
</body>

</html>