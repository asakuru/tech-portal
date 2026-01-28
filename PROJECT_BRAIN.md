# Tech Portal - Project Brain

## Overview
Field technician portal for tracking jobs, pay (piece rate), expenses, and reconciliation.
**Database**: SQLite (`tech_portal.db`)
**Stack**: PHP, SQLite, Vanilla JS/CSS

## Core Modules
- **Job Entry**: `entry.php`, `smart_entry.php`
- **Dashboard**: `index.php` (Weekly/Daily summaries), `dashboard.php` (Stats)
- **Reconciliation**: `reconcile.php` (Weekly matches against CSV scrubs)
- **Geo Analytics**: `geo.php` (Maps, Trends, Efficiency)

## Recent Changes (Jan 2026)
### Geo Analytics (`geo.php`)
- **New Feature**: Heatmap & City Leaderboard.
- **Data Check Fixes**:
  - Fixed duplicate cities due to case-sensitivity (Using `LOWER(TRIM(city))`).
  - Auto-strip trailing state codes (e.g., "Hillsgrove, PA" -> "Hillsgrove").
  - **Auto-Fix**: "Troy Township" jobs are automatically renamed to "Troy" on load.
  - **Schema**: Added `city_coords` table for caching lat/lng.

### Weekly Summary (`index.php`)
- **Fix**: Loop no longer breaks early on future dates, ensuring Sunday Per Diem counts even if viewed mid-week.

## Environment Notes
- **CLI Limitations**: PHP/SQLite CLI commands unavailable in agent environment.
- **Geocoding**: Uses Nominatim (OSM) with caching in `city_coords`.
- **Deploy**: Uses `git push` to master (cPanel automation).

## Active Issues / Watchlist
- **Data Quality**: City names vary (casing, state codes). `geo.php` handles this aggressively now.
- **Troy/Hillsgrove**: Specific fixes applied for these locations.
