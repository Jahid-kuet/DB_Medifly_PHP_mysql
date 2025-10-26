# Drone-Based Medical Delivery Management System

PHP + MySQL web application for coordinating drone deliveries of medical supplies with role-based access control.

## Features
- Secure login with password hashing and session-based role enforcement.
- Admin dashboard with CRUD management for hospitals, users, drones, supplies, delivery requests, operators, and delivery logs.
- Hospital portal to submit delivery requests and track their status end-to-end.
- Operator workspace to review assigned missions, transition deliveries through in-transit and delivered states, and leave operational notes.
- Delivery logging with automatic status change history and administrative annotations.

## Project Structure
```
includes/      Shared configuration, authentication helpers, layout
auth/          Login, registration (admin only), logout
admin/         Admin dashboards and CRUD pages
hospital/      Hospital-facing dashboards and request tools
operator/      Operator dashboards and workflow tools
home.php       Role-aware landing page
database.sql   Schema for `mediflydb_php` database
```

## Getting Started
1. Import `database.sql` into MySQL (`mediflydb_php`).
2. Place the project inside your PHP server document root (e.g., XAMPP `htdocs`).
3. Update credentials in `includes/config.php` if needed.
4. Create an initial admin user directly in the database or temporarily expose the registration form, then log in and manage data through the UI.

## Default Roles
- **Admin**: full system management, approvals, assignments.
- **Hospital**: create and track their delivery requests.
- **Operator**: manage assigned deliveries, update status, add delivery notes.

Bootstrap 5 powers the UI, so an internet connection is required for CDN assets by default.
