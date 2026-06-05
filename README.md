# WPS Expense Claims Portal

A mobile-first expense claims management system for Würth Professional Solutions, built with Vue 3, PHP, and MySQL.

## Features

- **Claimant Dashboard** — Submit expense claims with auto-save drafts, receipt uploads, and real-time currency conversion
- **Finance Auditor Queue** — Review, update status, and add remarks to submitted claims
- **Claims History** — Claimants can track the status of their submitted claims
- **CSV Export** — Finance users can export all claims data to CSV
- **Status Workflow** — Claims move through: `Pending` → `Under Review` → `Approved` / `Declined`

## Tech Stack

| Layer     | Technology                |
|-----------|---------------------------|
| Frontend  | Vue 3 (CDN), Vanilla CSS  |
| Backend   | PHP 8 (REST API)          |
| Database  | MySQL / MariaDB           |
| Server    | Apache (XAMPP)             |

## Setup

1. Place the project folder in your XAMPP `htdocs` directory
2. Start Apache and MySQL from the XAMPP Control Panel
3. Import `database.sql` into your local MySQL to create the schema and seed test users
4. Open `http://localhost/wps-claims/` in your browser

## Test Login Credentials

| Role      | Email                        | Password      |
|-----------|------------------------------|---------------|
| Claimant  | `claimant1@wurth-wps.com`    | `Password123` |
| Finance   | `finance1@wurth-wps.com`     | `Password123` |

> **Note:** After importing `database.sql`, you may need to run the password hash migration to ensure `password_verify` works correctly with your local PHP version. You can do this by visiting any test account and verifying login works.

## Project Structure

```
wps-claims/
├── api.php          # REST API (login, claims CRUD, finance review, CSV export)
├── app.js           # Vue 3 application logic
├── index.html       # Single-page application shell
├── style.css        # Custom styles with Wuerth brand fonts
├── default.php      # Default PHP entry point
├── database.sql     # Schema + seed data
├── assets/
│   └── fonts/       # Wuerth brand font files (woff/woff2)
└── uploads/         # Receipt file uploads (gitignored)
```
