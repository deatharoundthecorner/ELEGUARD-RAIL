# Technical Setup & Installation Guide

This guide describes how to configure the EleGuard Rail system locally using XAMPP (Apache, PHP, MySQL) and Sanity CMS.

## 1. Local Environment Requirements

Ensure you have installed:
- **XAMPP** (with PHP 8.0+ and MySQL/MariaDB)
- **Node.js** (v18+ for running Sanity Studio CMS)

## 2. Directory Placement

1. Place the `eleguard-rail` project folder directly into your XAMPP web root directory:
   - On Windows: `C:/xampp/htdocs/eleguard-rail/`
2. Ensure the file path structure is precisely matching this:
   - `C:/xampp/htdocs/eleguard-rail/public/index.php` (Landing page)

## 3. Database Initialization

### Method A: Using phpMyAdmin (Visual Interface)
1. Open your browser and navigate to:
   `http://localhost/phpmyadmin/`
2. Click **New** in the left sidebar and create a database named:
   `eleguard_rail`
   *(Use collation: `utf8mb4_unicode_ci`)*
3. Select the `eleguard_rail` database, click the **Import** tab, browse for `database/eleguard_rail.sql`, and click **Go**.
4. Repeat the import step for the seed file `database/seed_data.sql` to populate default data.

### Method B: Using command Line / CLI
1. Open your terminal/command prompt.
2. Run the MySQL command line tool to create the schema:
   ```bash
   mysql -u root -p -e "CREATE DATABASE eleguard_rail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p eleguard_rail < database/eleguard_rail.sql
   mysql -u root -p eleguard_rail < database/seed_data.sql
   ```

## 4. Run Password Seeder Script

To ensure the test credentials use secure PHP hashes, run the user seeding script:
1. Open command prompt.
2. Navigate to the database folder:
   ```cmd
   cd C:\xampp\htdocs\eleguard-rail\database
   ```
3. Run the CLI script:
   ```cmd
   php create_sample_users.php
   ```
This updates the passwords for `admin@eleguard.lk`, `driver@eleguard.lk`, etc. using secure hashes compatible with `password_verify()`.

## 5. Web Access

Start Apache and MySQL from the XAMPP Control Panel. Access the app in your browser at:
`http://localhost/eleguard-rail/`

The root page will automatically forward you to `http://localhost/eleguard-rail/public/`.
