# EleGuard Rail - Railway Elephant-Collision Warning System

EleGuard Rail is a web-based railway early warning and hazard monitoring system designed to reduce elephant-train collisions in Sri Lanka. It connects sightings submitted by reporters/villagers with wildlife rangers and railway controls to broadcast real-time warning alerts directly to train cab driver dashboards with audible alarms.

## Technology Stack

- **Frontend**: Semantic HTML5, Custom Vanilla CSS, JavaScript (Web Audio API for alarms)
- **Backend**: PHP (PHP Sessions for auth, PDO prepared statements)
- **Database**: MySQL (relational structure with foreign key constraints)
- **CMS**: Sanity Studio (Decoupled Node.js headless studio for public static content maintenance)
- **Local Server**: XAMPP (Apache + MySQL stack)

## Folder Structure

```
eleguard-rail/
├── index.php                      # Root redirector to public/
├── README.md                      # Project manual
├── .env.example                   # Env config template
├── app/                           # Core PHP logic
│   ├── config/                    # DB, App, and Sanity settings
│   ├── core/                      # Core classes (Database, Auth, Session, Router)
│   ├── includes/                  # Helpers, validation, uploads, Sanity Client
│   ├── layouts/                   # Global header/footer templates
│   ├── services/                  # Business logic (RiskService, UserService, AlertService, etc.)
│   └── middleware/                # Security guards (auth_required, role_required)
├── database/                      # SQL databases, DDLs, and CLI seed scripts
│   ├── eleguard_rail.sql          # DB DDL structures
│   ├── seed_data.sql              # Core static and sample logs data
│   ├── reset_database.sql         # Clean database tables
│   └── create_sample_users.php    # PHP CLI tool to seed pre-hashed users
├── docs/                          # Detailed operational documents
├── public/                        # Web root
│   ├── assets/                    # CSS, JS, and uploads
│   ├── admin/                     # Admin operations panel
│   ├── control/                   # Railway Control operations panel
│   ├── driver/                    # Train Driver warnings console
│   ├── wildlife/                  # Wildlife rangers verification console
│   └── reporter/                  # Community reporter sightings forms
├── sanity/                        # Decoupled Sanity Studio
└── tests/                         # Manual testing scripts
```

## Setup & Running Instructions

1. **Move Project**: Place the `eleguard-rail` directory inside your XAMPP root folder:
   `C:/xampp/htdocs/eleguard-rail/`
2. **Start Server**: Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. **Database Setup**:
   - Access `http://localhost/phpmyadmin/`
   - Create a database named `eleguard_rail`
   - Import `database/eleguard_rail.sql` then `database/seed_data.sql`
4. **Alternative Seeding**: Alternatively, run `database/create_sample_users.php` in a terminal/command prompt to initialize database tables and seed test accounts securely.
5. **Run Application**: Open your browser and navigate to:
   `http://localhost/eleguard-rail/`

## Login Credentials

| Role | Username / Email | Password |
| :--- | :--- | :--- |
| **Admin** | `admin@eleguard.lk` | `admin123` |
| **Railway Control** | `control@eleguard.lk` | `control123` |
| **Train Driver** | `driver@eleguard.lk` | `driver123` |
| **Wildlife Ranger** | `wildlife@eleguard.lk` | `wildlife123` |
| **Reporter** | `reporter@eleguard.lk` | `reporter123` |

## Key Features

- **Dynamic Risk Score Engine**: Assesses railway track risk, urgency level, elephant counts, and time factors to categorize sighting severity (Low, Medium, High, Critical).
- **Ranger Review & Verify**: Public sighting reports are logged as *Pending* and must be verified by wildlife rangers before becoming safety warnings.
- **Control Desk Broadcasts**: Allows railway operators to convert verified sightings into safety warnings or manually issue alerts.
- **Driver Cab Warnings**: Mobile-friendly, high-contrast dashboard for train cabs that blinks red and sounds siren sound alarms for Critical hazards.
- **Auditable Safety Logs**: Records user logins, sighting submissions, ranger confirmations, speed alerts, and driver responses.
- **Decoupled Sanity CMS**: Public landing pages, safety rules, FAQs, and emergency contacts are manageable via Sanity Studio, with a local static fallback.

## Future Advanced Improvements

These features are omitted from the MVP but proposed for future iterations:
- **GPS-Based Train tracking**: Live positioning map overlay using Leaflet/GIS.
- **SMS & WhatsApp Alerts**: Automatic notifications to drivers and track walkers via Twilio.
- **IoT Smart Sensors**: Solar-powered vibration and acoustic sensors along corridor fences.
- **AI Camera Detection**: Thermal vision cameras on locomotive front bumpers to detect wildlife.
- **GIS Map Visualization**: Hotspot heatmaps for safety analysts.
- **Offline Driver mode**: Native mobile cab application featuring offline warning caching.
