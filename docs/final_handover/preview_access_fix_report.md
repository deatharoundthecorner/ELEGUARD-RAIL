# ELEGUARD RAIL — SAFE DESIGN PREVIEW ACCESS FIX REPORT

**Document Reference:** `ER-PREVIEW-ACCESS-REPORT-001`  
**Parent Directive:** `ER-PREVIEW-ACCESS-001`  
**Related Reports:** `ER-UI-PHASE-B-CORRECTIONS-REPORT-001`, `ER-DEMO-ENV-004`  
**Date:** 16 November 2026  
**Status:** **COMPLETED & VERIFIED**  
**Access URL:** `http://localhost/eleguard-rail/public/design-previews/index.html`

---

## 1. Executive Summary

During testing of the Phase B design preview gallery, direct access to `docs/final_handover/phase_b_design_previews/` returned an Apache `403 Forbidden` error. This error was caused by the existing root `.htaccess` security hardening (implemented under `ER-DEMO-ENV-004`), which intentionally denies direct web requests to `app/`, `database/`, `tests/`, `docs/`, and `scratch/`.

In accordance with directive `ER-PREVIEW-ACCESS-001`:
1. The root `.htaccess` security rule blocking `docs/` was **strictly preserved** to prevent inadvertent disclosure of internal engineering documents, schemas, and test reports.
2. A dedicated, sandboxed public preview directory was provisioned under:
   `public/design-previews/`
3. All six corrected static HTML design prototypes and the interactive gallery index were deployed to this public location in both the Desktop repository and the local XAMPP webroot.
4. Security controls were embedded directly in `public/design-previews/.htaccess` to **disable PHP script execution** and **suppress directory indexing**.
5. All seven public preview endpoints were verified to return `HTTP 200 OK`, while protected internal directories and configuration files remain strictly `HTTP 403 Forbidden`.

---

## 2. Root Cause & Architecture Analysis

### 2.1 Root Cause
The root `.htaccess` at `C:\xampp\htdocs\eleguard-rail\.htaccess` contains the following access control rule:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Deny direct access to non-public backend directories
    RewriteRule ^(app|database|tests|docs|scratch)/ - [F,L]
</IfModule>
```
This rule correctly protects internal project assets. Weakening or removing the `docs` rewrite rule to allow preview access would compromise the confidentiality of architectural specifications, database migration runbooks, and audit logs.

### 2.2 Authorized Safe Solution
Rather than weakening webroot security, static HTML design mockups were mirrored into the web-accessible `public/` directory tree under `public/design-previews/`. Because the prototypes are completely self-contained (inline styles, inline SVGs, and relative inter-file links), no dependency on internal directories is required.

---

## 3. Implementation Details

### 3.1 Directory Structure
The following directory was provisioned:
- Desktop Repository: `C:\Users\user\Desktop\Railway\eleguard-rail\public\design-previews\`
- Local XAMPP Webroot: `C:\xampp\htdocs\eleguard-rail\public\design-previews\`

### 3.2 File Deployment
The following files were deployed to `public/design-previews/`:
1. `.htaccess` (Dedicated security policy)
2. `01_admin_dashboard_preview.html` (System Admin Console)
3. `02_control_desk_preview.html` (Control Officer Workstation)
4. `03_wildlife_verification_preview.html` (Wildlife Officer Dossier)
5. `04_driver_dashboard_preview.html` (Locomotive Driver Cab Desk)
6. `05_reporter_dashboard_preview.html` (Citizen Community Portal)
7. `06_field_pwa_preview.html` (Field Ranger Mobile PWA)
8. `index.html` (Interactive Multi-Viewport Gallery)

Original source prototypes remain intact at:
`docs/final_handover/phase_b_design_previews/`

### 3.3 Hardened Security Policy (`.htaccess`)
To prevent the public preview directory from ever becoming an execution vector, a dedicated `.htaccess` was placed inside `public/design-previews/`:
```apache
# ELEGUARD RAIL - Public Design Previews Security Configuration
# Prevent directory listing and disable PHP script execution

Options -Indexes

<FilesMatch "\.(php|phtml|php[3-8]|phps)$">
    Require all denied
</FilesMatch>
```

---

## 4. Verification & Security Testing

Comprehensive automated HTTP validation was executed against the local Apache server (`http://localhost`).

### 4.1 Public Preview Endpoints (Expected: HTTP 200 OK)

| # | Endpoint URL | Status Code | Content Size | Result |
|---|---|:---:|:---:|:---:|
| 1 | `http://localhost/eleguard-rail/public/design-previews/index.html` | **200 OK** | 20,342 B | PASS |
| 2 | `http://localhost/eleguard-rail/public/design-previews/01_admin_dashboard_preview.html` | **200 OK** | 22,750 B | PASS |
| 3 | `http://localhost/eleguard-rail/public/design-previews/02_control_desk_preview.html` | **200 OK** | 17,354 B | PASS |
| 4 | `http://localhost/eleguard-rail/public/design-previews/03_wildlife_verification_preview.html` | **200 OK** | 17,264 B | PASS |
| 5 | `http://localhost/eleguard-rail/public/design-previews/04_driver_dashboard_preview.html` | **200 OK** | 11,532 B | PASS |
| 6 | `http://localhost/eleguard-rail/public/design-previews/05_reporter_dashboard_preview.html` | **200 OK** | 9,085 B | PASS |
| 7 | `http://localhost/eleguard-rail/public/design-previews/06_field_pwa_preview.html` | **200 OK** | 11,702 B | PASS |
| 8 | `http://localhost/eleguard-rail/public/design-previews/` (Auto Index) | **200 OK** | 20,342 B | PASS |

### 4.2 Protected Endpoints Security Audit (Expected: HTTP 403 Forbidden)

| # | Target Protected Resource | Status Code | Result | Security Assessment |
|---|---|:---:|:---:|:---:|
| 1 | `http://localhost/eleguard-rail/docs/final_handover/phase_b_design_previews/index.html` | **403 Forbidden** | PASS | Internal documentation remains blocked. |
| 2 | `http://localhost/eleguard-rail/docs/final_handover/phase_b_design_corrections_report.md` | **403 Forbidden** | PASS | Handover reports remain blocked. |
| 3 | `http://localhost/eleguard-rail/.env` | **403 Forbidden** | PASS | Environment configuration remains blocked. |
| 4 | `http://localhost/eleguard-rail/app/core/Database.php` | **403 Forbidden** | PASS | Backend core classes remain blocked. |
| 5 | `http://localhost/eleguard-rail/database/migrations/01_baseline.sql` | **403 Forbidden** | PASS | SQL schemas and migrations remain blocked. |
| 6 | `http://localhost/eleguard-rail/tests/run_all_tests.php` | **403 Forbidden** | PASS | Test runner infrastructure remains blocked. |

### 4.3 Script Execution Resistance Test
A synthetic script execution test was performed by deploying a temporary script `test_php_blocked.php` inside `public/design-previews/`.
- **Request:** `GET /eleguard-rail/public/design-previews/test_php_blocked.php`
- **Response:** `HTTP 403 Forbidden`
- **Result:** **CONFIRMED BLOCKED**. No PHP code can be executed from within `public/design-previews/`.

---

## 5. File Inventory & Integrity

### Checksums (Desktop Workspace vs XAMPP Mirror)

| File Name | File Size (Bytes) | SHA-256 Hash | Status |
|---|:---:|---|:---:|
| `.htaccess` | 226 | `d3db417efc8fc99a7732d8479e09fe85038c3ee594bda6822c97693d25d10526` | Identical |
| `01_admin_dashboard_preview.html` | 22,754 | `d2e3b8b5156aa89fe3e2ffbeb52d201927d7ee79eaebc50e0c9c70f911b4a6f5` | Identical |
| `02_control_desk_preview.html` | 17,360 | `5355aad1876fd3fc96f4b3d57b9f9f6bb4f001f51b41eb955652d7ed10d165d2` | Identical |
| `03_wildlife_verification_preview.html` | 17,268 | `9cddad579a4b243621d74b5c484289fb6e2681f4e6349afb761a26ebb786e344` | Identical |
| `04_driver_dashboard_preview.html` | 11,538 | `e34ee356623f8140439b542c12668802d0e9aaeefe68387aa4ae1f08d19f0f03` | Identical |
| `05_reporter_dashboard_preview.html` | 9,089 | `79746c3de895014d338cb93b4f2e7e0bbfa9bff0bacbab340cd802508a7ee52a` | Identical |
| `06_field_pwa_preview.html` | 11,724 | `98029f19a3d5748b57b879468307563cb6006349f44bee427e7a20c36e76be0e` | Identical |
| `index.html` | 20,368 | `ba9f830ace09ab80cfa91ab62889859a4471f73ba5abf67190a02d6911dae770` | Identical |

---

## 6. Access Instructions

The Phase B Natural Enterprise Design prototypes can now be viewed directly in any standard browser through local XAMPP:

- **Primary Gallery Portal:**  
  [http://localhost/eleguard-rail/public/design-previews/index.html](http://localhost/eleguard-rail/public/design-previews/index.html)

- **Direct Role Previews:**
  1. System Admin Console: [http://localhost/eleguard-rail/public/design-previews/01_admin_dashboard_preview.html](http://localhost/eleguard-rail/public/design-previews/01_admin_dashboard_preview.html)
  2. Control Desk Workstation: [http://localhost/eleguard-rail/public/design-previews/02_control_desk_preview.html](http://localhost/eleguard-rail/public/design-previews/02_control_desk_preview.html)
  3. Wildlife Verification Workspace: [http://localhost/eleguard-rail/public/design-previews/03_wildlife_verification_preview.html](http://localhost/eleguard-rail/public/design-previews/03_wildlife_verification_preview.html)
  4. Locomotive Driver Cab Desk: [http://localhost/eleguard-rail/public/design-previews/04_driver_dashboard_preview.html](http://localhost/eleguard-rail/public/design-previews/04_driver_dashboard_preview.html)
  5. Citizen Reporter Portal: [http://localhost/eleguard-rail/public/design-previews/05_reporter_dashboard_preview.html](http://localhost/eleguard-rail/public/design-previews/05_reporter_dashboard_preview.html)
  6. Field Ranger Mobile PWA: [http://localhost/eleguard-rail/public/design-previews/06_field_pwa_preview.html](http://localhost/eleguard-rail/public/design-previews/06_field_pwa_preview.html)

All prototypes are verified to load with zero errors, full responsive viewport controls (Full / 1280px / 768px / 390px), and clear synthetic demonstration disclaimers.
