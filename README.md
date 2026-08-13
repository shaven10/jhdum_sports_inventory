# JHCSC Sports Development MIS

Sports Development Management Information System for **J.H. Cerilles State College**. Manages sports equipment inventory, borrowing, and intramural competitions in a single PHP application.

## Features

### Equipment & Inventory

- Equipment catalog with categories, maintenance tracking, and low-stock alerts
- Student borrowing requests, check-out/return workflows, and overdue reporting
- Audit logs, notifications, and analytics dashboards

### Intramurals

- **Seasons** — organize competitions by academic year
- **Teams / Houses** — college units with unit managers
- **Sports / Events** — tournament formats, venues, scoring rules, placement point schemes
- **Sport Guidelines** — per-sport/event policies (eligibility, roster, equipment, conduct) managed by administrators
- **Athletes & Rosters** — registration, import, jersey/position assignment
- **Event Coaches** — one coach per team + event + season
- **Tournament Managers** — one manager per event + season (fixtures, scheduling, scores)
- **Matches** — fixture generation, auto-scheduling, bracket advancement, calendar view
- **Standings** — per-sport and overall house rankings

## Requirements

- PHP 8.0+ (with PDO MySQL)
- MySQL 5.7+ / MariaDB 10.3+
- Apache (XAMPP recommended) with `mod_rewrite` optional

## Installation

1. Clone or copy the project into your web root, e.g. `C:\xampp\htdocs\sports_inventory`.
2. Configure the database in `config/database.php`:
  ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'jhcsc_sports_inventory');
   define('DB_USER', 'root');
   define('DB_PASS', '');
  ```
3. Run the web installer once:
  ```
   http://localhost/sports_inventory/install.php
  ```
   This creates the database and seeds default data. **Delete `install.php` after setup.**
4. Log in with the default admin account created during install, then change passwords under **Users**.

### Existing installs — migrations

Run individual migration scripts from the project root as needed:

```bash
php database/run_seasons_migrate.php
php database/run_event_coaches_migrate.php
php database/run_tabulator_migrate.php
php database/run_event_managers_migrate.php
php database/run_secretariat_migrate.php
php database/run_sport_guidelines_migrate.php
```

The tournament manager migration assigns existing Tournament Manager (`tabulator`) accounts to all events for the active season. Reassign per event under **Intramurals → Sports / Events → Tournament Managers**.

## User Roles


| Role                   | Scope        | Access                                                                                          |
| ---------------------- | ------------ | ----------------------------------------------------------------------------------------------- |
| **Administrator**      | Global       | Full system access                                                                              |
| **Sports Coordinator** | Global       | Dashboard with inventory + match results and overall standings; full intramurals administration |
| **Sports Staff**       | Global       | Dashboard with inventory + match results and overall standings; full intramurals administration |
| **Unit Manager**       | One team     | Dashboard with match results and overall standings; My Team menu for roster management          |
| **Coach**              | Team + event | Dashboard with coach assignments; Intramurals module for roster management                      |
| **Tournament Manager** | Per event    | Dashboard with match results and overall standings for assigned events                          |
| **Secretariat**        | All events   | Dashboard with match results and overall standings; Competition menu for scheduling             |
| **Student**            | Self         | Equipment borrowing only (no Intramurals module)                                                |


### Tournament Managers (per event)

Each intramural **event** (sport/category row) must have exactly one Tournament Manager for the current season.

1. Create user accounts with the **Tournament Manager** role under **Users**.
2. Open **Intramurals → Sports / Events → Tournament Managers**.
3. Assign one manager to every event — all fields are required on save.
4. Tournament Managers only see and manage matches for their assigned events. Match generation is blocked until every selected event has a manager.

**Tournament Manager permissions**


| Action                   | Scope                                      |
| ------------------------ | ------------------------------------------ |
| Generate matches         | Assigned events only                       |
| Schedule / record scores | Assigned events only                       |
| View match results       | Assigned events only                       |
| Per-sport standings      | Assigned events only                       |
| Overall standing         | Full institution-wide view                 |
| Reports                  | Schedules, results, standings, medals only |


The role is stored as `tabulator` in the database; the UI label is **Tournament Manager**.

### Sport Guidelines (per event)

Administrators manage official guidelines for each sport/event under **Intramurals → Sports / Events → Sport Guidelines** (or **Intramurals → Sport Guidelines** in the navigation menu).

Each event can have its own guidelines covering eligibility, roster rules, equipment, conduct, and other policies. Game rules, format notes, and schedule notes from Sports Management are shown on the same card for reference.

Use **Copy** on the Sports / Events list to duplicate an event’s settings (including guidelines) into a new sport row — useful when adding the same sport for another category or a variant format.


| Action          | Who                                             |
| --------------- | ----------------------------------------------- |
| Edit guidelines | Administrator, Sports Coordinator, Sports Staff |
| View guidelines | All intramurals roles (except students)         |


Run on existing installs if the column is missing:

```bash
php database/run_sport_guidelines_migrate.php
```

### Secretariat (competition operations)

Secretariat accounts support intramurals competition operations across **all events** without full admin access.

1. Create user accounts with the **Secretariat** role under **Users**.
2. Secretariat users land on the Intramurals dashboard (no inventory module access).

**Secretariat permissions**


| Action                            | Scope            |
| --------------------------------- | ---------------- |
| View all matches & results        | All events       |
| Team standings & overall standing | All events       |
| Intramurals reports               | All report types |
| Generate matches                  | All events       |
| Reschedule games                  | All events       |
| Record scores                     | All events       |


Run the migration on existing installs:

```bash
php database/run_secretariat_migrate.php
```

Demo account after migration: `secretariat` / `admin123`

### Event Coaches (per team + event)

Unit managers assign coaches under **Intramurals → Teams → [Team] → Event Coaches**. Each coach must be assigned to a **specific team + event** for the current season before they can manage rosters.


| Action                       | Scope                                        |
| ---------------------------- | -------------------------------------------- |
| View athletes / rosters      | Assigned team + event pairs only             |
| Import / edit roster details | Assigned team + event pairs only             |
| Register new athletes        | Unit manager only (not coaches)              |
| Create coach accounts        | Unit manager (own team) or intramurals staff |


Coaches without event assignments cannot access roster tools. Create coach accounts under **Users**, then assign them per event on each team.

## Project Structure

```
sports_inventory/
├── config/           # App and database configuration
├── database/         # schema.sql and migration scripts
├── includes/         # Auth, helpers, header/footer
├── assets/           # CSS, JS, images
├── equipment/        # Inventory module
├── requests/         # Borrowing requests
├── transactions/     # Check-out / return
├── intramurals/      # Intramurals module
│   ├── sports/       # Events CRUD + tournament manager assignments
│   ├── teams/        # Teams, unit managers, event coaches
│   ├── athletes/     # Athlete registration
│   ├── matches/      # Fixtures, scheduling, scores
│   ├── standings/    # Rankings
│   └── reports/      # Intramurals reports
├── users/            # User management
├── settings/         # System settings, theme, categories
└── install.php       # One-time installer (remove after use)
```

## Configuration


| Setting  | File                  | Notes                        |
| -------- | --------------------- | ---------------------------- |
| Base URL | `config/config.php`   | Default: `/sports_inventory` |
| Database | `config/database.php` | MySQL connection             |
| Timezone | `config/config.php`   | Default: `Asia/Manila`       |


## License

Internal use — J.H. Cerilles State College Sports Development Office.