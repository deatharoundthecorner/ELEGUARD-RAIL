# ELEGUARD RAIL — Developer Handover Guide

This document is the definitive developer handover guide for **ELEGUARD RAIL**. It provides step-by-step instructions for cloning, configuring, running, testing, and independently extending the platform.

---

## 1. Quick Start: System Requirements

Before setting up the project locally, verify that your development environment satisfies the following minimum prerequisites:

| Requirement | Recommended Version | Purpose |
|---|---|---|
| **Operating System** | Linux, macOS, or Windows 10/11 | Development host |
| **Web Server** | Apache 2.4+ with `mod_rewrite` enabled | Application routing and `.htaccess` protection |
| **PHP** | PHP 8.2 or later | Core application backend and REST APIs |
| **PHP Extensions** | `pdo_mysql`, `openssl`, `json`, `mbstring`, `curl` | Database connectivity, cryptography, and HTTP |
| **Database** | MariaDB 10.4+ or MySQL 8.0+ | Relational data persistence |
| **Python** *(Optional)* | Python 3.10+ | Edge AI advisory detector (`ai/detector/`) |
| **Python Packages** *(Optional)* | `onnxruntime`, `opencv-python`, `numpy` | Local YOLOX-Tiny ONNX model execution |

---

## 2. Cloning the Repository

Clone the public GitHub repository into your local workspace or web server document root:

```bash
# Clone via HTTPS
git clone https://github.com/deatharoundthecorner/ELEGUARD-RAIL.git

# Navigate into the project directory
cd ELEGUARD-RAIL
```

---

## 3. Environment Configuration

The application uses an environment file (`.env`) for configuring database connection parameters, operational flags, and encryption settings.

1. Create your local `.env` file from the provided template:
   ```bash
   cp .env.example .env
   ```

2. Open `.env` in your text editor and adjust the database credentials to match your local database server:
   ```ini
   # Database Configuration
   DB_HOST=127.0.0.1
   DB_PORT=3306          # Use 3308 if running under XAMPP alternative port
   DB_NAME=eleguard_rail
   DB_USER=root
   DB_PASS=your_password

   # Application Mode
   APP_ENV=development   # Set to 'production' in live environments
   APP_DEBUG=true

   # Edge AI Inference Mode
   AI_INFERENCE_ENABLED=true
   AI_MODEL_PATH=ai/models/yolox_tiny.onnx
   ```

---

## 4. Database Setup & Sequential Migrations

To establish a complete and functional demonstration database from scratch, execute the baseline schema followed by the six sequential migration scripts:

```bash
# 1. Create the database schema
mysql -h 127.0.0.1 -P 3306 -u root -p -e "CREATE DATABASE eleguard_rail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import the baseline schema (core tables, initial roles, geographical corridors)
mysql -h 127.0.0.1 -P 3306 -u root -p eleguard_rail < database/eleguard_rail.sql

# 3. Apply all sequential migrations in exact order
mysql -h 127.0.0.1 -P 3306 -u root -p eleguard_rail < database/migrations/01_add_gps_coordinates_to_elephant_reports.sql
mysql -h 127.0.0.1 -P 3306 -u root -p eleguard_rail < database/migrations/02_add_ai_detection_metadata_to_elephant_reports.sql
mysql -h 127.0.0.1 -P 3306 -u root -p eleguard_rail < database/migrations/03_add_temporal_intelligence_to_elephant_reports.sql
mysql -h 127.0.0.1 -P 3306 -u root -p eleguard_rail < database/migrations/04_add_field_reporting_v2_to_elephant_reports.sql
mysql -h 127.0.0.1 -P 3306 -u root -p eleguard_rail < database/migrations/05_add_verification_and_section_audits.sql
mysql -h 127.0.0.1 -P 3306 -u root -p eleguard_rail < database/migrations/06_add_payload_hash_to_elephant_reports.sql

# 4. Seed initial demonstration users across all roles
php database/create_sample_users.php
```

### Demonstration Accounts Created:
- **System Administrator:** `admin` / `admin123`
- **Railway Traffic Controller:** `control` / `control123`
- **Wildlife Officer (DWC):** `wildlife` / `wildlife123`
- **Locomotive Driver:** `driver` / `driver123`
- **Field Community Reporter:** `reporter` / `reporter123`

---

## 5. Web Server Setup & Running Locally

The codebase is built with a clean front-controller architecture. Direct public access is strictly confined to the `/public/` directory via `.htaccess` rules.

### Option A: Using Local Apache (XAMPP / WAMP / Native)
1. Place the repository inside your web root (e.g. `C:\xampp\htdocs\eleguard-rail` or `/var/www/html/eleguard-rail`).
2. Verify that Apache's `mod_rewrite` is enabled.
3. Access the application in your browser:
   ```
   http://localhost/eleguard-rail/public/
   ```

### Option B: Using PHP Built-In Development Server
For rapid local testing and development without Apache:
```bash
# Start PHP built-in server rooted at the public/ folder
php -S 127.0.0.1:8080 -t public/
```
Then navigate to: `http://127.0.0.1:8080/`

---

## 6. Running the Automated Test Suite

The platform includes a 35-suite unified regression test runner.

```bash
# Execute all test suites
php tests/run_all_tests.php
```

### Safety Features of the Test Runner:
- **Zero Impact on Operational Data:** The test runner automatically provisions an ephemeral database (`eleguard_test_tmp_<hash>`), applies all migrations, runs tests in total isolation, and drops the temporary database when finished.
- **Engine-Level Permission Checks:** Pre-flight tests verify that read-only user accounts cannot execute `INSERT`, `UPDATE`, or `DELETE` statements.
- **Headless Browser Concurrency:** Automated CDP polling loops verify client-side IndexedDB caching and synchronization without requiring external Selenium servers.

---

## 7. Understanding the Core Architecture

```
  +------------------+         +----------------------+         +-----------------------+
  | Field PWA        | ------> | REST API v2          | ------> | Database (MariaDB)    |
  | (Offline / Sync) |         | (Auth / Idempotency) |         | (Elephant Reports)    |
  +------------------+         +----------------------+         +-----------------------+
                                           |                                |
                                           v                                v
                               +----------------------+         +-----------------------+
                               | YOLOX-Tiny ONNX      |         | Wildlife Officer      |
                               | (Advisory Inference) |         | Verification Triage   |
                               +----------------------+         +-----------------------+
                                                                            |
                                                                            v
                                                                +-----------------------+
                                                                | Railway Control Desk  |
                                                                | & Driver Advisory HUD |
                                                                +-----------------------+
```

1. **Decoupled Geodetic Solvers (`app/services/GeodesicService.php`):** Computes Vincenty and Haversine inverse and direct geodesic problems. Translates observer GPS coordinates, bearing (degrees), and estimated standoff distance into estimated wildlife coordinates with Root-Sum-Square (RSS) error ellipses.
2. **Offline-First PWA Sync (`public/field/` & `app/api/v2/`):** Service Worker manages asset caching. Field observations are stored at rest in IndexedDB encrypted with AES-GCM-256 via the Web Crypto API. Upon connectivity restoration, queued records sync to the backend using client UUIDv4 tokens to ensure strict idempotency.
3. **Biological Triage FSM (`app/services/ReportVerificationService.php`):** Implements a two-tier review state machine. Wildlife officers can confirm, reject, or request structured clarification from field observers before warnings escalate.
4. **Advisory Edge Vision (`ai/detector/`):** Evaluates uploaded photos using YOLOX-Tiny ONNX (Apache 2.0 license). Returns scalar detection confidence scores. AI outputs are non-blocking advisory aids; human officer review remains mandatory.
5. **Corridor Diurnal Risk Model (`app/services/TemporalRiskService.php`):** Dynamically scores elephant collision probability based on a 7-phase circadian continuum (elevated crepuscular/nocturnal activity) and spatial proximity decay.

---

## 8. Guidance for Independent Forking & Development

The ELEGUARD RAIL founding team has frozen development. If you or your organization choose to fork and extend this repository, we recommend the following engineering roadmap:

### Step 1: Containerization (Docker)
- Create a `Dockerfile` and `docker-compose.yml` packaging PHP 8.2-FPM, Apache, and MariaDB into reproducible containers.

### Step 2: Live Sensor Network Ingestion
- Implement a gateway service (e.g. MQTT / WebSockets) to ingest live telemetry from trackside thermal/optical camera stations or solar-powered acoustic seismic sensors.

### Step 3: Hardware In-Cab Integration
- Integrate physical GPS transponder hardware (NMEA 0183 protocol) and VHF radio data links into the Driver Advisory interface for real-time corridor position updates without cellular dependency.

### Step 4: Production Hardening
- Introduce asset minification (e.g. Vite or Webpack) for frontend ES6 modules.
- Add Redis/Memcached for spatial cache acceleration of high-frequency track queries.

---

## 9. Contact & Licensing Notice

This software is distributed under the [MIT License](LICENSE). You are free to fork, adapt, commercialize, or build upon this work, subject to retaining the original copyright notice and disclaimers.

For full architectural records and security threat models, consult the [docs/development/](docs/development/) directory.
