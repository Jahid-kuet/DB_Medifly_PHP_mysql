# Drone-Based Medical Delivery Management System
# MediFly Delivery — Drone Medical Supply Delivery

Lightweight PHP + MySQL web application for coordinating drone delivery of medical supplies between hospitals. It includes role-based access (admin, hospital user, operator), delivery requests, drone/operator assignment, delivery tracking, inventory (supplies), payments, and notifications.

## Quick start (Windows + XAMPP)
1. Start XAMPP (Apache + MySQL).
2. Import the database schema:
	- phpMyAdmin: http://localhost/phpmyadmin → import `database.sql`.
	- or CLI:
	  ```cmd
	  c:\xampp\mysql\bin\mysql.exe -u root mediflydb_php < C:\xampp\htdocs\DB_PHP\database.sql
	  ```
3. Review `includes/config.php` and set DB credentials, `BASE_PATH`, and `GOOGLE_MAPS_API_KEY` if you have one.
4. (Optional migrations) Run helper scripts if your database predates these features:
	```cmd
	c:\xampp\php\php.exe scripts\add_is_approved_column.php
	c:\xampp\php\php.exe scripts\add_auth_tokens_table.php
	```
5. Open the app in your browser: http://localhost/DB_PHP

## Important files
- `includes/config.php` — DB connection, app settings and cookie/session constants.
- `database.sql` — canonical schema and seed data used by the app.
- `auth/`, `admin/`, `hospital/`, `operator/` — main application pages for each role.
- `scripts/` — helper scripts (migrations, seeders, test queries).

## Developer notes
- For local development `SESSION_COOKIE_SECURE` is false so cookies work over HTTP. Set true for production HTTPS.
- Persistent "Remember me" tokens use the `AuthTokens` table; the `includes/auth.php` code guards auto-login when the table is missing.

## Contributing / Git
This repository has been imported to GitHub. If you'd like a `.gitignore`, CI workflow, or documentation improvements I can add them.

---
Generated/updated on: 2025-10-26
