<?php
// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);
$user_id = $_SESSION['user_id'] ?? 0;

// --- ROBUST ADMIN CHECK ---
// Uses centralized function from functions.php
$is_admin = is_admin();

// Label changes based on role
$entry_label = $is_admin ? 'Month to Date' : 'Entry';
?>
<div class="overlay" onclick="toggleMenu()"></div>

<nav class="navbar">
    <div style="display:flex; align-items:center; gap:12px;">
        <button class="menu-toggle" onclick="toggleMenu()">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
        <a href="index.php" class="nav-brand">
            <svg class="brand-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
            </svg>
            <span>TECH PORTAL</span>
        </a>
    </div>

    <div class="nav-actions">
        <button class="icon-btn" onclick="toggleGlobalSearch()" title="Search">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </button>

        <button class="icon-btn" onclick="toggleTheme()" title="Theme">
            <svg id="theme-icon-sun" style="display:none;" width="20" height="20" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="5"></circle>
                <line x1="12" y1="1" x2="12" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="23"></line>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                <line x1="1" y1="12" x2="3" y2="12"></line>
                <line x1="21" y1="12" x2="23" y2="12"></line>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
            <svg id="theme-icon-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
        </button>
        <a href="index.php?logout=true" class="icon-btn" title="Logout">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </a>
    </div>
</nav>

<div id="global-search-container" class="global-search-container">
    <form action="tools.php" method="get" class="search-inner">
        <input type="text" name="q" class="search-input" placeholder="Search ticket, name, address..."
            autocomplete="off">

        <button type="submit" class="search-submit-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </button>

        <button type="button" class="search-cancel-btn" onclick="toggleGlobalSearch()">
            Cancel
        </button>
    </form>
</div>

<div class="side-drawer">
    <div class="drawer-header">
        <span style="font-weight:700; color:var(--text-primary);">Navigation</span>
        <button class="drawer-close" onclick="toggleMenu()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <div class="drawer-content">
        <div class="drawer-label">Apps</div>

        <a href="index.php" class="drawer-link <?= ($current_page == 'index.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
            Home
        </a>

        <a href="entry.php" class="drawer-link <?= ($current_page == 'entry.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
            Job Entry
        </a>

        <a href="smart_entry.php" class="drawer-link <?= ($current_page == 'smart_entry.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
            Smart Paste
        </a>

        <a href="dashboard.php" class="drawer-link <?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            Dashboard
        </a>

        <a href="reports.php" class="drawer-link <?= ($current_page == 'reports.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            Reports
        </a>

        <a href="tools.php" class="drawer-link <?= ($current_page == 'tools.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            Job Search
        </a>

        <a href="reconcile.php" class="drawer-link <?= ($current_page == 'reconcile.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            Scrub Report
        </a>

        <a href="financials.php" class="drawer-link <?= ($current_page == 'financials.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="1" x2="12" y2="23"></line>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
            Financials
        </a>

        <div class="drawer-label">Tools</div>

        <a href="import.php" class="drawer-link <?= ($current_page == 'import.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            Import CSV
        </a>
        <a href="import_fuel.php" class="drawer-link <?= ($current_page == 'import_fuel.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 21h18"></path>
                <path d="M5 21V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v14"></path>
                <path d="M9 10a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2"></path>
            </svg>
            Import Fuel
        </a>
        <a href="tool_convert.php" class="drawer-link <?= ($current_page == 'tool_convert.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            Text to CSV
        </a>
        <a href="tools.php" class="drawer-link <?= ($current_page == 'tools.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            Search
        </a>
        <a href="vehicles.php"
            class="drawer-link <?= ($current_page == 'vehicles.php' || $current_page == 'vehicle_edit.php') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path
                    d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2">
                </path>
                <circle cx="7" cy="17" r="2"></circle>
                <circle cx="17" cy="17" r="2"></circle>
            </svg>
            Vehicles
        </a>

        <?php if ($is_admin): ?>
            <div class="drawer-label">System</div>

            <a href="settings.php" class="drawer-link <?= ($current_page == 'settings.php') ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path
                        d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z">
                    </path>
                </svg>
                Rates / Settings
            </a>

            <a href="backup.php" class="drawer-link <?= ($current_page == 'backup.php') ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                    <polyline points="7 3 7 8 15 8"></polyline>
                </svg>
                Backup
            </a>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleMenu() { document.body.classList.toggle('nav-open'); }

    // NEW FUNCTION: Toggle Search Bar with Focus
    function toggleGlobalSearch() {
        const el = document.getElementById('global-search-container');
        if (el.style.display === 'block') {
            el.style.display = 'none';
        } else {
            el.style.display = 'block';
            el.querySelector('input').focus();
        }
    }

    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        updateThemeIcon();
        localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
    }

    function updateThemeIcon() {
        const isDark = document.body.classList.contains('dark-mode');
        document.getElementById('theme-icon-sun').style.display = isDark ? 'block' : 'none';
        document.getElementById('theme-icon-moon').style.display = isDark ? 'none' : 'block';
    }

    // Auto-Run
    if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
    updateThemeIcon();
</script>

<!-- Progressive Enhancements -->
<script src="enhancements.js"></script>