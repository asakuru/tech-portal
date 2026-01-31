<?php
// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);
$is_admin = is_admin();
?>

<!-- Persistent Slim Sidebar -->
<aside class="side-drawer">
    <script src="offline.js"></script>
    <!-- Brand Info (Top) -->
    <a href="index.php" class="drawer-link" title="Tech Portal">
        <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
            stroke-linecap="round" stroke-linejoin="round">
            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
        </svg>
        <span style="font-weight: 800; font-size: 1.1rem; letter-spacing: -0.02em;">TECH PORTAL</span>
    </a>

    <div style="margin-top: 20px; width: 100%;">
        <div class="drawer-label">Core</div>

        <a href="index.php" class="drawer-link <?= ($current_page == 'index.php') ? 'active' : ''; ?>" title="Home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
            <span>Home</span>
        </a>

        <a href="entry.php" class="drawer-link <?= ($current_page == 'entry.php') ? 'active' : ''; ?>"
            title="Job Entry">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
            <span>Job Entry</span>
        </a>

        <a href="tools.php" class="drawer-link <?= ($current_page == 'tools.php') ? 'active' : ''; ?>" title="Search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <span>Job Search</span>
        </a>

        <a href="geo.php" class="drawer-link <?= ($current_page == 'geo.php') ? 'active' : ''; ?>" title="Map">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
            </svg>
            <span>Analytics Map</span>
        </a>

        <a href="dashboard.php" class="drawer-link <?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>"
            title="Dashboard">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="9"></rect>
                <rect x="14" y="3" width="7" height="5"></rect>
                <rect x="14" y="12" width="7" height="9"></rect>
                <rect x="3" y="16" width="7" height="5"></rect>
            </svg>
            <span>Dashboard</span>
        </a>

        <div class="drawer-label">Finance & Gear</div>

        <a href="inventory.php" class="drawer-link <?= ($current_page == 'inventory.php') ? 'active' : ''; ?>"
            title="Inventory">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path
                    d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                </path>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
            <span>Inventory</span>
        </a>

        <a href="financials.php" class="drawer-link <?= ($current_page == 'financials.php') ? 'active' : ''; ?>"
            title="Earnings">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <line x1="12" y1="1" x2="12" y2="23"></line>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
            <span>Financials</span>
        </a>

        <a href="reconcile.php" class="drawer-link <?= ($current_page == 'reconcile.php') ? 'active' : ''; ?>"
            title="Scrub">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <polyline points="9 11 12 14 22 4"></polyline>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
            </svg>
            <span>Scrub Report</span>
        </a>

        <a href="reports.php" class="drawer-link <?= ($current_page == 'reports.php') ? 'active' : ''; ?>"
            title="Reports">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            <span>Custom Reports</span>
        </a>

        <a href="vehicles.php"
            class="drawer-link <?= ($current_page == 'vehicles.php' || $current_page == 'vehicle_edit.php') ? 'active' : ''; ?>"
            title="Vehicles">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path
                    d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2">
                </path>
                <circle cx="7" cy="17" r="2"></circle>
                <circle cx="17" cy="17" r="2"></circle>
            </svg>
            <span>Fleet Mgmt</span>
        </a>

        <?php if ($is_admin): ?>
            <div class="drawer-label">System</div>
            <a href="admin.php" class="drawer-link <?= ($current_page == 'admin.php') ? 'active' : ''; ?>" title="Admin">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <span>Users/Admin</span>
            </a>
            <a href="settings.php" class="drawer-link <?= ($current_page == 'settings.php') ? 'active' : ''; ?>"
                title="Settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path
                        d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z">
                    </path>
                </svg>
                <span>Rates</span>
            </a>
            <a href="backup.php" class="drawer-link <?= ($current_page == 'backup.php') ? 'active' : ''; ?>"
                title="Backups">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M21 8v13H3V8"></path>
                    <path d="M1 3h22v5H1z"></path>
                    <path d="M10 12h4"></path>
                </svg>
                <span>Backups</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- Bottom Actions -->
    <div style="margin-top: auto; width: 100%;">
        <button class="drawer-link" onclick="toggleTheme()" style="background:transparent; border:none; cursor:pointer;"
            title="Theme">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
            <span>Toggle Theme</span>
        </button>
        <a href="index.php?logout=true" class="drawer-link" title="Logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Sign Out</span>
        </a>
    </div>
</aside>

<!-- Mobile Top Bar -->
<nav class="navbar-mobile">
    <button class="menu-toggle" onclick="toggleMobileMenu()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <line x1="4" y1="12" x2="20" y2="12"></line>
            <line x1="4" y1="6" x2="16" y2="6"></line>
            <line x1="4" y1="18" x2="12" y2="18"></line>
        </svg>
    </button>
    <a href="index.php" class="nav-brand">TECH PORTAL</a>
</nav>


<script>
    function toggleMobileMenu() { document.body.classList.toggle('nav-open'); }
    function toggleTheme() {
        document.body.classList.toggle('light-mode');
        localStorage.setItem('theme', document.body.classList.contains('light-mode') ? 'light' : 'dark');
    }
    // Theme Sync
    if (localStorage.getItem('theme') === 'light') { document.body.classList.add('light-mode'); }
</script>