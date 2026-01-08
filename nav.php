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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    /* --- MODERN VARIABLES --- */
    :root {
        --nav-height: 64px;
        --nav-bg-glass: rgba(255, 255, 255, 0.95);
        --nav-border: rgba(0, 0, 0, 0.06);
        --drawer-bg: #ffffff;
        --text-primary: #111827;
        --text-secondary: #6b7280;
        --primary-accent: #2563eb;
        --primary-light: #eff6ff;
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    body.dark-mode {
        --nav-bg-glass: rgba(17, 24, 39, 0.95);
        --nav-border: rgba(255, 255, 255, 0.08);
        --drawer-bg: #111827;
        --text-primary: #f9fafb;
        --text-secondary: #9ca3af;
        --primary-accent: #60a5fa;
        --primary-light: rgba(59, 130, 246, 0.15);
    }

    /* GLOBAL RESET */
    body {
        font-family: 'Inter', sans-serif;
        margin: 0;
        padding-top: var(--nav-height);
    }

    /* --- TOP BAR --- */
    .navbar {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: var(--nav-height);
        background: var(--nav-bg-glass);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border-bottom: 1px solid var(--nav-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 20px;
        z-index: 1000;
    }

    /* BRAND */
    .nav-brand {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--text-primary);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 8px;
        letter-spacing: -0.02em;
    }

    .brand-icon {
        color: var(--primary-accent);
    }

    /* RIGHT ICONS */
    .nav-actions {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .icon-btn {
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 8px;
        border-radius: 8px;
        color: var(--text-secondary);
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .icon-btn:hover {
        background: var(--primary-light);
        color: var(--primary-accent);
    }

    .icon-btn svg {
        width: 20px;
        height: 20px;
        stroke-width: 2;
    }

    /* --- GLOBAL SEARCH BAR (Modernized) --- */
    .global-search-container {
        position: fixed;
        top: var(--nav-height);
        left: 0;
        right: 0;
        background: var(--drawer-bg);
        border-bottom: 1px solid var(--nav-border);
        padding: 12px 15px;
        display: none;
        /* Hidden initially */
        z-index: 998;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        animation: slideDown 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes slideDown {
        from {
            transform: translateY(-10px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .search-inner {
        display: flex;
        align-items: center;
        gap: 8px;
        max-width: 600px;
        margin: 0 auto;
    }

    .search-input {
        flex: 1;
        /* Input takes all available space */
        height: 42px;
        padding: 0 15px;
        border-radius: 8px;
        border: 1px solid var(--nav-border);
        background: var(--primary-light);
        color: var(--text-primary);
        font-size: 1rem;
        outline: none;
        transition: all 0.2s;
        -webkit-appearance: none;
    }

    .search-input:focus {
        border-color: var(--primary-accent);
        background: var(--drawer-bg);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    /* Submit Button (Magnifying Glass) */
    .search-submit-btn {
        height: 42px;
        width: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--primary-accent);
        color: #ffffff;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        flex-shrink: 0;
        /* Prevents button from squishing */
    }

    /* Cancel Button (Text) */
    .search-cancel-btn {
        background: transparent;
        border: none;
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.9rem;
        padding: 0 10px;
        height: 42px;
        cursor: pointer;
        flex-shrink: 0;
    }

    .search-cancel-btn:hover {
        color: var(--text-primary);
    }

    /* --- HAMBURGER --- */
    .menu-toggle {
        background: none;
        border: none;
        cursor: pointer;
        padding: 8px;
        margin-right: 10px;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        border-radius: 6px;
    }

    .menu-toggle:hover {
        background: var(--nav-border);
    }

    /* --- SIDE DRAWER --- */
    .side-drawer {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: 260px;
        background: var(--drawer-bg);
        box-shadow: var(--shadow-lg);
        transform: translateX(-100%);
        transition: transform 0.25s ease-in-out;
        z-index: 1001;
        display: flex;
        flex-direction: column;
        padding-top: 20px;
    }

    .nav-open .side-drawer {
        transform: translateX(0);
    }

    .drawer-header {
        padding: 0 20px 20px;
        border-bottom: 1px solid var(--nav-border);
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .drawer-close {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-secondary);
        padding: 5px;
    }

    .drawer-content {
        overflow-y: auto;
        flex: 1;
        padding: 0 10px;
    }

    .drawer-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        margin-bottom: 2px;
        text-decoration: none;
        color: var(--text-secondary);
        font-weight: 500;
        font-size: 0.95rem;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .drawer-link svg {
        width: 20px;
        height: 20px;
        stroke-width: 1.75;
    }

    .drawer-link:hover {
        background: var(--nav-border);
        color: var(--text-primary);
    }

    .drawer-link.active {
        background: var(--primary-light);
        color: var(--primary-accent);
        font-weight: 600;
    }

    .drawer-label {
        padding: 15px 12px 5px;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-secondary);
        font-weight: 700;
        opacity: 0.7;
    }

    .overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.3);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.25s;
        z-index: 999;
    }

    .nav-open .overlay {
        opacity: 1;
        pointer-events: auto;
    }
</style>

<div class="overlay" onclick="toggleMenu()"></div>

<nav class="navbar">
    <div style="display:flex; align-items:center;">
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
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
            <?= $entry_label ?>
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