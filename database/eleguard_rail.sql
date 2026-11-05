CREATE DATABASE IF NOT EXISTS eleguard_rail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eleguard_rail;

-- --------------------------------------------------
-- Table: users
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'control_officer', 'driver', 'wildlife_officer', 'reporter') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------
-- Table: railway_sections
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS railway_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_name VARCHAR(150) NOT NULL,
    district VARCHAR(100) NOT NULL,
    start_location VARCHAR(150) NOT NULL,
    end_location VARCHAR(150) NOT NULL,
    risk_level ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------
-- Table: hotspots
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS hotspots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    hotspot_name VARCHAR(150) NOT NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    risk_level ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES railway_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------
-- Table: elephant_reports
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS elephant_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    reported_by INT NOT NULL,
    elephant_count INT NOT NULL,
    location_description VARCHAR(255) NOT NULL,
    urgency_level ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL,
    risk_score ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Low',
    report_status ENUM('Pending', 'Verified', 'Rejected', 'Converted to Alert', 'Resolved') DEFAULT 'Pending',
    description TEXT,
    image_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    FOREIGN KEY (section_id) REFERENCES railway_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------
-- Table: alerts
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NULL,
    section_id INT NOT NULL,
    alert_level ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL,
    alert_message TEXT NOT NULL,
    alert_status ENUM('Active', 'Resolved', 'False Alert') DEFAULT 'Active',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    FOREIGN KEY (report_id) REFERENCES elephant_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (section_id) REFERENCES railway_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------
-- Table: driver_acknowledgements
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS driver_acknowledgements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_id INT NOT NULL,
    driver_id INT NOT NULL,
    action_status ENUM('Acknowledged', 'Slowing Down', 'Stopped', 'False Alert') NOT NULL,
    note TEXT NULL,
    acknowledged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------
-- Table: incident_history
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS incident_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    incident_type VARCHAR(100) NOT NULL,
    description TEXT,
    incident_date DATE NOT NULL,
    severity ENUM('Low', 'Medium', 'High', 'Critical') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES railway_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------
-- Table: system_logs
-- --------------------------------------------------
CREATE TABLE IF NOT EXISTS system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
