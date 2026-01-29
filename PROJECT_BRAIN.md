# Tech Portal - Project Brain

## Overview
Field technician portal for tracking jobs, pay (piece rate), expenses, and reconciliation.
**Database**: SQLite (`tech_portal.db`)
**Stack**: PHP, SQLite, Vanilla JS/CSS

## Core Modules
- **Dashboard**: `index.php` (Weekly/Daily summaries), `dashboard.php` (Stats)
- **Job Entry**: `entry.php` (Modern entry UI)
- **Text Converter**: `converter.php` (Bulk note parsing & importing)
- **Reconciliation**: `reconcile.php` (Weekly matches against CSV scrubs)
- **Geo Analytics**: `geo.php` (Maps, Trends, Efficiency)
- **Financials**: `financials.php` (Revenue vs. Expenses / Tax Estimator)

## Recent Changes (Jan 2026)
### Codebase Refinement & Architecture
- **Logic Centralization**: Consolidated all payroll and earnings logic into `functions.php` (`calculate_daily_payroll()`, `calculate_weekly_payroll()`). All reports now use the same source of truth.
- **UI Componentization**: Extracted repeating UI elements into root-level components for consistency and easier maintenance:
    - `kpi_card.php`: Metrics and summary cards.
    - `job_summary_card.php`: Standardized job list items.
- **Dynamic Configuration**: Migrated hardcoded job type codes/rates to the database (`job_types` table). `config.php` now dynamically fetches labels from the DB.
- **Codebase Streamlining**: Removed redundant/obsolete files including `smart_entry.php`, `tool_convert.php`, `import.php`, and legacy migration scripts in the `migrations/` folder.

### Job Converter (`converter.php`)
- **Persistence**: Added list persistence to the converter, allowing users to navigate away and return to their parsed job list.
- **Note Sync**: Synchronized JavaScript live-preview logic with the PHP server-side note constructor to ensure identical output.
- **Parsing**: Strict extraction for ONT/Eero serials, Wifi credentials, and TICI signal levels.

### Hosting & Reliability
- **Path Resolution**: Switched all includes to use absolute paths (`__DIR__`) to resolve "No such file or directory" errors on production hosting.
- **Component Guarding**: Added guard clauses to components to prevent "Undefined variable" errors during initial page load.

## Environment Notes
- **CLI Limitations**: PHP/SQLite CLI commands unavailable in agent environment.
- **Geocoding**: Uses Nominatim (OSM) with caching in `city_coords`.
- **Deploy**: Uses `git push` to master (cPanel automation).

## Active Issues / Watchlist
- **Data Quality**: City names vary (casing, state codes). `geo.php` handles this via normalization.
- **Root-level Assets**: To avoid hosting-specific subdirectory resolution issues, core UI components are kept in the root directory.
